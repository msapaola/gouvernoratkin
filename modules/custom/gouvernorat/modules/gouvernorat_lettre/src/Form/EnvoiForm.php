<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Onglet « Envoyer » d'une lettre : essai, puis envoi à tous les abonnés.
 */
class EnvoiForm extends FormBase {

  /**
   * Onglet visible sur les lettres seulement.
   */
  public static function acces(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'lettre')
      ->andIf(AccessResult::allowedIfHasPermission($account, 'gerer gouvernorat lettre'))
      ->addCacheableDependency($node);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_envoi';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('nid', $node->id());
    $form['#cache']['max-age'] = 0;
    $envois = \Drupal::service('gouvernorat_lettre.envois');
    $totaux = \Drupal::service('gouvernorat_lettre.abonnes')->compter();
    $bilan = $envois->bilan((int) $node->id());

    if (!\Drupal::service('gouvernorat_lettre.expediteur')->estPret()) {
      $this->messenger()->addWarning("Le mot de passe de la boîte d'envoi n'est pas renseigné dans settings.php : aucun courriel ne peut partir.");
    }

    $form['bilan'] = [
      '#type' => 'item',
      '#title' => 'Situation',
      '#markup' => sprintf(
        'Abonnés confirmés : <strong>%d</strong>. Cette lettre : <strong>%d</strong> envoyée(s), <strong>%d</strong> en attente, <strong>%d</strong> échec(s).',
        $totaux['inscrits'], $bilan['envoyes'], $bilan['attente'], $bilan['echecs']
      ),
    ];

    $form['essai'] = [
      '#type' => 'details',
      '#title' => "1. Envoyer un essai",
      '#open' => TRUE,
    ];
    $form['essai']['adresse'] = [
      '#type' => 'email',
      '#title' => 'Adresse',
      '#default_value' => $this->config('gouvernorat_lettre.settings')->get('adresse_test') ?: $this->currentUser()->getEmail(),
      '#description' => 'Relisez la lettre dans votre messagerie, sur ordinateur et sur téléphone, avant de l’envoyer à tous.',
    ];
    $form['essai']['envoyer_essai'] = [
      '#type' => 'submit',
      '#value' => "Envoyer l'essai",
      '#submit' => ['::essai'],
      '#limit_validation_errors' => [['adresse']],
    ];

    $nouveaux = $totaux['inscrits'] - $bilan['envoyes'] - $bilan['attente'] - $bilan['echecs'];
    $form['tous'] = [
      '#type' => 'details',
      '#title' => '2. Envoyer à tous les abonnés',
      '#open' => TRUE,
    ];
    if (!$node->isPublished()) {
      $form['tous']['note'] = [
        '#markup' => '<p>La lettre peut rester non publiée sur le site : elle est envoyée par courriel uniquement.</p>',
      ];
    }
    if ($nouveaux <= 0) {
      $form['tous']['rien'] = [
        '#markup' => '<p>Tous les abonnés confirmés ont déjà reçu (ou vont recevoir) cette lettre.</p>',
      ];
    }
    else {
      $form['tous']['confirmation'] = [
        '#type' => 'checkbox',
        '#title' => sprintf("J'ai relu l'essai et j'envoie cette lettre à %d abonné(s).", $nouveaux),
      ];
      $form['tous']['envoyer'] = [
        '#type' => 'submit',
        '#value' => 'Envoyer à tous',
        '#button_type' => 'primary',
        '#submit' => ['::tous'],
        '#states' => ['disabled' => [':input[name="confirmation"]' => ['checked' => FALSE]]],
      ];
    }
    if ($bilan['attente'] > 0) {
      $form['tous']['reprendre'] = [
        '#type' => 'submit',
        '#value' => 'Reprendre les envois en attente',
        '#submit' => ['::reprendre'],
        '#limit_validation_errors' => [],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $declencheur = $form_state->getTriggeringElement()['#submit'][0] ?? '';
    if ($declencheur === '::essai' && !$form_state->getValue('adresse')) {
      $form_state->setErrorByName('adresse', "Indiquez l'adresse qui recevra l'essai.");
    }
    if ($declencheur === '::tous' && !$form_state->getValue('confirmation')) {
      $form_state->setErrorByName('confirmation', 'Cochez la case pour confirmer l’envoi à tous les abonnés.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Chaque bouton a son propre traitement (#submit).
  }

  /**
   * Envoi d'un essai.
   */
  public function essai(array &$form, FormStateInterface $form_state): void {
    $node = $this->lettre($form_state);
    $adresse = (string) $form_state->getValue('adresse');
    if (\Drupal::service('gouvernorat_lettre.envois')->essai($node, $adresse)) {
      $this->messenger()->addStatus("Essai envoyé à $adresse.");
    }
    else {
      $this->messenger()->addError("L'essai n'a pas pu partir. Le détail est dans le journal (Rapports › Messages récents).");
    }
  }

  /**
   * Mise en file, puis envoi immédiat du premier lot.
   */
  public function tous(array &$form, FormStateInterface $form_state): void {
    $node = $this->lettre($form_state);
    $nombre = \Drupal::service('gouvernorat_lettre.envois')->programmer($node, (int) $this->currentUser()->id());
    $this->messenger()->addStatus("Lettre mise en file pour $nombre abonné(s).");
    $this->lot();
  }

  /**
   * Relance des envois en attente.
   */
  public function reprendre(array &$form, FormStateInterface $form_state): void {
    $this->lot();
  }

  /**
   * Lance l'envoi par lots, avec barre de progression.
   */
  protected function lot(): void {
    batch_set([
      'title' => 'Envoi de la lettre',
      'init_message' => 'Préparation…',
      'progress_message' => '',
      'operations' => [[[static::class, 'operation'], []]],
      'finished' => [static::class, 'termine'],
    ]);
  }

  /**
   * Une étape du lot : quelques secondes d'envoi.
   */
  public static function operation(array &$context): void {
    $envois = \Drupal::service('gouvernorat_lettre.envois');
    if (!isset($context['sandbox']['depart'])) {
      $context['sandbox']['depart'] = $envois->restants();
      $context['results'] = ['envoyes' => 0, 'echecs' => 0, 'quota' => FALSE, 'restants' => 0];
    }
    $bilan = $envois->traiter(8);
    $context['results']['envoyes'] += $bilan['envoyes'];
    $context['results']['echecs'] += $bilan['echecs'];
    $context['results']['quota'] = $bilan['quota'];
    $context['results']['restants'] = $bilan['restants'];
    $context['message'] = sprintf('%d courriel(s) envoyé(s), %d restant(s).', $context['results']['envoyes'], $bilan['restants']);

    $bloque = $bilan['quota'] || ($bilan['envoyes'] === 0 && $bilan['echecs'] > 0) || ($bilan['envoyes'] + $bilan['echecs'] === 0);
    if ($bilan['restants'] === 0 || $bloque) {
      $context['finished'] = 1;
    }
    else {
      $depart = max(1, $context['sandbox']['depart']);
      $context['finished'] = min(0.99, 1 - $bilan['restants'] / $depart);
    }
  }

  /**
   * Fin du lot.
   */
  public static function termine(bool $succes, array $resultats): void {
    $messenger = \Drupal::messenger();
    $messenger->addStatus(sprintf('%d courriel(s) envoyé(s).', $resultats['envoyes'] ?? 0));
    if (!empty($resultats['echecs'])) {
      $messenger->addWarning(sprintf('%d échec(s) : ils seront retentés automatiquement. Détail dans le journal (Rapports › Messages récents).', $resultats['echecs']));
    }
    if (!empty($resultats['restants'])) {
      $messenger->addStatus(sprintf(
        '%d courriel(s) restent en file%s : ils partiront automatiquement lors des prochaines tâches planifiées (cron).',
        $resultats['restants'],
        !empty($resultats['quota']) ? " (limite d'envois par heure atteinte)" : ''
      ));
    }
  }

  /**
   * La lettre affichée.
   */
  protected function lettre(FormStateInterface $form_state): NodeInterface {
    return \Drupal::entityTypeManager()->getStorage('node')->load($form_state->get('nid'));
  }

}
