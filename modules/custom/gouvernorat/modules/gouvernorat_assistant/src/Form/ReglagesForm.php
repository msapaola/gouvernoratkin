<?php

namespace Drupal\gouvernorat_assistant\Form;

use Anthropic\Client;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gouvernorat_assistant\Service\Assistant;

/**
 * Réglages de l'assistant virtuel : /admin/config/services/assistant.
 */
class ReglagesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gouvernorat_assistant_reglages';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gouvernorat_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gouvernorat_assistant.settings');

    // État des prérequis, pour savoir d'un coup d'œil pourquoi l'assistant
    // n'apparaît pas sur le site.
    $etats = [
      class_exists(Client::class)
        ? $this->t('Bibliothèque Anthropic installée.')
        : $this->t('Bibliothèque Anthropic absente : lancer « composer require anthropic-ai/sdk » sur le serveur.'),
      Assistant::cle() !== ''
        ? $this->t("Clé d'API présente.")
        : $this->t("Clé d'API absente : l'ajouter dans settings.php (\$settings['gouvernorat_assistant_cle_api']), jamais dans le dépôt."),
    ];
    $form['etat'] = [
      '#type' => 'item',
      '#title' => $this->t('État'),
      '#markup' => '<ul><li>' . implode('</li><li>', $etats) . '</li></ul>',
    ];

    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Afficher l'assistant sur le site"),
      '#description' => $this->t("Décocher pour le retirer immédiatement de toutes les pages."),
      '#default_value' => $config->get('active'),
    ];
    $form['message_accueil'] = [
      '#type' => 'textarea',
      '#title' => $this->t("Message d'accueil"),
      '#default_value' => $config->get('message_accueil'),
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['limite_heure'] = [
      '#type' => 'number',
      '#title' => $this->t('Questions par heure et par visiteur'),
      '#min' => 1,
      '#max' => 200,
      '#default_value' => $config->get('limite_heure'),
      '#required' => TRUE,
    ];
    $form['limite_jour_total'] = [
      '#type' => 'number',
      '#title' => $this->t('Questions par jour pour tout le site'),
      '#description' => $this->t("Plafond de sécurité pour la facture : au-delà, l'assistant invite à revenir le lendemain."),
      '#min' => 1,
      '#max' => 100000,
      '#default_value' => $config->get('limite_jour_total'),
      '#required' => TRUE,
    ];
    $form['modele'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Modèle Claude'),
      '#description' => $this->t('Identifiant du modèle utilisé. Par défaut : claude-haiku-5-5 (rapide et économique).'),
      '#default_value' => $config->get('modele'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('gouvernorat_assistant.settings')
      ->set('active', (bool) $form_state->getValue('active'))
      ->set('message_accueil', trim($form_state->getValue('message_accueil')))
      ->set('limite_heure', (int) $form_state->getValue('limite_heure'))
      ->set('limite_jour_total', (int) $form_state->getValue('limite_jour_total'))
      ->set('modele', trim($form_state->getValue('modele')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
