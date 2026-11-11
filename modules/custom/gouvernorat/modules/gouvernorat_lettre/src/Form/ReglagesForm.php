<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Réglages de la lettre d'information.
 */
class ReglagesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_reglages';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['gouvernorat_lettre.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('gouvernorat_lettre.settings');
    $pret = \Drupal::service('gouvernorat_lettre.expediteur')->estPret();

    $form['etat'] = [
      '#type' => 'item',
      '#title' => 'Mot de passe de la boîte d’envoi',
      '#markup' => $pret
        ? 'Renseigné dans settings.php.'
        : '<strong>Absent.</strong> Ajoutez dans settings.php : <code>$settings[\'gouvernorat_lettre_mot_de_passe\'] = \'…\';</code>',
    ];
    $form['expediteur'] = [
      '#type' => 'email',
      '#title' => 'Adresse expéditrice',
      '#default_value' => $config->get('expediteur'),
      '#required' => TRUE,
    ];
    $form['nom_expediteur'] = [
      '#type' => 'textfield',
      '#title' => "Nom de l'expéditeur",
      '#default_value' => $config->get('nom_expediteur'),
      '#required' => TRUE,
    ];
    $form['repondre_a'] = [
      '#type' => 'email',
      '#title' => 'Adresse de réponse',
      '#description' => 'Les réponses des lecteurs arrivent à cette adresse.',
      '#default_value' => $config->get('repondre_a'),
    ];
    $form['serveur'] = [
      '#type' => 'details',
      '#title' => 'Serveur de messagerie',
    ];
    $form['serveur']['hote'] = [
      '#type' => 'textfield',
      '#title' => 'Serveur SMTP',
      '#default_value' => $config->get('hote'),
      '#required' => TRUE,
    ];
    $form['serveur']['port'] = [
      '#type' => 'number',
      '#title' => 'Port',
      '#default_value' => $config->get('port'),
      '#required' => TRUE,
    ];
    $form['serveur']['chiffrement'] = [
      '#type' => 'select',
      '#title' => 'Chiffrement',
      '#options' => ['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)'],
      '#default_value' => $config->get('chiffrement'),
    ];
    $form['serveur']['utilisateur'] = [
      '#type' => 'textfield',
      '#title' => 'Identifiant',
      '#default_value' => $config->get('utilisateur'),
      '#required' => TRUE,
    ];
    $form['quota_heure'] = [
      '#type' => 'number',
      '#title' => 'Courriels par heure au maximum',
      '#description' => "Au-delà, l'hébergeur de messagerie peut bloquer la boîte. Le reste part l'heure suivante, automatiquement.",
      '#min' => 10,
      '#max' => 5000,
      '#default_value' => $config->get('quota_heure'),
      '#required' => TRUE,
    ];
    $form['adresse_test'] = [
      '#type' => 'email',
      '#title' => "Adresse pour l'envoi de test",
      '#description' => "Proposée par défaut sur l'onglet « Envoyer » d'une lettre.",
      '#default_value' => $config->get('adresse_test'),
    ];
    $form['presentation'] = [
      '#type' => 'textarea',
      '#title' => "Présentation sur la page d'inscription",
      '#default_value' => $config->get('presentation'),
      '#rows' => 3,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('gouvernorat_lettre.settings');
    foreach (['expediteur', 'nom_expediteur', 'repondre_a', 'hote', 'chiffrement', 'utilisateur', 'adresse_test', 'presentation'] as $cle) {
      $config->set($cle, trim((string) $form_state->getValue($cle)));
    }
    $config->set('port', (int) $form_state->getValue('port'));
    $config->set('quota_heure', (int) $form_state->getValue('quota_heure'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
