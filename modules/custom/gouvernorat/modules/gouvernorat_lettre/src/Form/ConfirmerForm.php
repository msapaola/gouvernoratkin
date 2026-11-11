<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Validation de l'inscription.
 *
 * Le lien du courriel mène à un bouton plutôt que de confirmer directement :
 * certaines messageries ouvrent les liens pour les analyser, ce qui
 * confirmerait l'inscription à l'insu du destinataire.
 */
class ConfirmerForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_confirmer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $jeton = ''): array {
    \Drupal::service('page_cache_kill_switch')->trigger();
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'lettre-inscription';
    $abonne = \Drupal::service('gouvernorat_lettre.abonnes')->parJeton($jeton);
    if (!$abonne) {
      $form['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => "Ce lien n'est plus valable : la demande a expiré ou l'adresse a été désabonnée. Vous pouvez refaire une demande d'inscription.",
      ];
      $form['lien'] = [
        '#type' => 'link',
        '#title' => "S'inscrire à la lettre d'information",
        '#url' => Url::fromRoute('gouvernorat_lettre.page'),
        '#attributes' => ['class' => ['btn', 'btn-primary']],
      ];
      return $form;
    }
    if ((int) $abonne->statut === 1) {
      $form['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => "Votre inscription est confirmée : vous recevrez la prochaine lettre d'information à l'adresse " . $abonne->courriel . '.',
      ];
      return $form;
    }
    $form_state->set('jeton', $jeton);
    $form['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => "Pour recevoir la lettre d'information du Gouvernorat à l'adresse " . $abonne->courriel . ', confirmez votre inscription.',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Confirmer mon inscription',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (\Drupal::service('gouvernorat_lettre.abonnes')->confirmer((string) $form_state->get('jeton'))) {
      $this->messenger()->addStatus("Merci ! Votre inscription est confirmée. Vous recevrez la prochaine lettre d'information.");
    }
    $form_state->setRedirect('<front>');
  }

}
