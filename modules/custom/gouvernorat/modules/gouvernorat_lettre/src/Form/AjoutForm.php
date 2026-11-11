<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Ajout d'adresses par la rédaction (personnes ayant déjà donné leur accord).
 */
class AjoutForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_ajout';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['adresses'] = [
      '#type' => 'textarea',
      '#title' => 'Adresses de courriel',
      '#description' => 'Une adresse par ligne (les virgules et points-virgules sont aussi acceptés). Ces personnes sont inscrites directement, sans courriel de confirmation.',
      '#rows' => 10,
      '#required' => TRUE,
    ];
    $form['accord'] = [
      '#type' => 'checkbox',
      '#title' => "Ces personnes ont demandé à recevoir la lettre d'information (formulaire papier, évènement, courriel…).",
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Ajouter',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $abonnes = \Drupal::service('gouvernorat_lettre.abonnes');
    $valides = [];
    $invalides = [];
    foreach (preg_split('/[\s,;]+/', (string) $form_state->getValue('adresses'), -1, PREG_SPLIT_NO_EMPTY) as $saisie) {
      $courriel = $abonnes->normaliser($saisie);
      $courriel ? $valides[$courriel] = $courriel : $invalides[] = $saisie;
    }
    if ($invalides) {
      $form_state->setErrorByName('adresses', 'Adresses non valides : ' . implode(', ', array_slice($invalides, 0, 20)));
    }
    $form_state->set('valides', array_values($valides));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nombre = \Drupal::service('gouvernorat_lettre.abonnes')->ajouter($form_state->get('valides'));
    $this->messenger()->addStatus($nombre . ' adresse(s) ajoutée(s). Les autres étaient déjà inscrites.');
    $form_state->setRedirect('gouvernorat_lettre.abonnes');
  }

}
