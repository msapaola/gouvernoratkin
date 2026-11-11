<?php

namespace Drupal\gouvernorat_lettre\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\gouvernorat_lettre\Service\Abonnes;

/**
 * Suppression d'un abonné.
 */
class SupprimerForm extends ConfirmFormBase {

  /**
   * Abonné à supprimer.
   */
  protected ?object $abonne = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gouvernorat_lettre_supprimer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $aid = 0): array {
    $this->abonne = \Drupal::database()->select(Abonnes::TABLE, 'a')
      ->fields('a')
      ->condition('aid', $aid)
      ->execute()
      ->fetchObject() ?: NULL;
    if (!$this->abonne) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return 'Supprimer ' . $this->abonne->courriel . " de la liste de la lettre d'information ?";
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return "L'adresse est effacée et ne recevra plus de lettre.";
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return 'Supprimer';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('gouvernorat_lettre.abonnes');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('gouvernorat_lettre.abonnes')->supprimer((int) $this->abonne->aid);
    $this->messenger()->addStatus($this->abonne->courriel . ' a été supprimé de la liste.');
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
