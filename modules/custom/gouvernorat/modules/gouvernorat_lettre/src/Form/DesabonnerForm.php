<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Désabonnement depuis le lien en bas de chaque lettre.
 */
class DesabonnerForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_desabonner';
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
        '#value' => "Cette adresse n'est plus inscrite : vous ne recevrez plus la lettre d'information.",
      ];
      return $form;
    }
    $form_state->set('jeton', $jeton);
    $form['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => "Ne plus recevoir la lettre d'information à l'adresse " . $abonne->courriel . ' ? Votre adresse sera effacée de notre liste.',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Me désabonner',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('gouvernorat_lettre.abonnes')->desabonner((string) $form_state->get('jeton'));
    $this->messenger()->addStatus("Vous êtes désabonné. Vous ne recevrez plus la lettre d'information.");
    $form_state->setRedirect('<front>');
  }

}
