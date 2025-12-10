<?php

namespace Drupal\webhooks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Webhooks settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webhooks_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['webhooks.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['reliable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use a reliable queue for non-blocking webhooks'),
      '#description' => $this->t('Reliable queues preserve the order of messages and <strong>guarantee</strong> that every item will be executed at least once, other queues will do a <strong>best effort</strong> to preserve order in messages and to execute them at least once.'),
      '#default_value' => $this->config('webhooks.settings')->get('reliable'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('webhooks.settings')
      ->set('reliable', $form_state->getValue('reliable'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
