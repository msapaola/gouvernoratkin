<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gouvernorat_lettre\Controller\LettreController;

/**
 * Page « Lettre d'information » : présentation et inscription.
 */
class InscriptionForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_inscription';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('gouvernorat_lettre.settings');
    $form['#attributes']['class'][] = 'lettre-inscription';
    $form['#cache']['tags'] = $config->getCacheTags();
    $form['presentation'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $config->get('presentation'),
      '#attributes' => ['class' => ['lettre-presentation']],
    ];
    $form['courriel'] = [
      '#type' => 'email',
      '#title' => 'Votre adresse de courriel',
      '#required' => TRUE,
      '#maxlength' => 254,
      '#attributes' => ['autocomplete' => 'email', 'placeholder' => 'nom@exemple.com'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => "M'inscrire",
      '#button_type' => 'primary',
    ];
    $form['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => "Vous recevrez un courriel pour confirmer votre inscription. Votre adresse ne sert qu'à l'envoi de la lettre ; vous pouvez vous désabonner à tout moment.",
      '#attributes' => ['class' => ['lettre-note']],
      '#weight' => 110,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $controleur = \Drupal::classResolver(LettreController::class);
    [$code, $message] = $controleur->traiterDemande((string) $form_state->getValue('courriel'), $this->getRequest());
    $code === 200 ? $this->messenger()->addStatus($message) : $this->messenger()->addError($message);
  }

}
