<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Form\WebhookConfigForm;

/**
 * Kernel tests for the webhook config form secret handling.
 *
 * @group webhooks
 */
class WebhookConfigFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'webhooks',
  ];

  const WEBHOOK_ID = 'incoming_override';

  const OVERRIDE_SECRET = 'override-secret';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // User entity schema is required because hook_entity_insert fires when
    // WebhookConfig entities are saved and calls User::load().
    $this->installEntitySchema('user');
    $this->installConfig(['webhooks']);
  }

  /**
   * Saving the edit form must not persist a settings.php secret override.
   */
  public function testFormSaveDoesNotPersistOverriddenSecret(): void {
    WebhookConfig::create([
      'id'                 => self::WEBHOOK_ID,
      'label'              => 'Incoming Override',
      'payload_url'        => 'http://example.com/webhook',
      'type'               => 'incoming',
      'events'             => [],
      'content_type'       => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'             => '',
      'signature_header'   => 'X-WC-Webhook-Signature',
      'signature_algorithm' => 'sha256',
      'status'             => 1,
    ])->save();

    $GLOBALS['config']['webhooks.webhook.' . self::WEBHOOK_ID]['secret'] = self::OVERRIDE_SECRET;
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')
      ->getStorage('webhook_config')
      ->resetCache([self::WEBHOOK_ID]);

    $entity = WebhookConfig::load(self::WEBHOOK_ID);
    $this->assertInstanceOf(WebhookConfig::class, $entity);
    $this->assertSame(self::OVERRIDE_SECRET, $entity->getSecret());

    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('webhook_config', 'edit');
    $this->assertInstanceOf(WebhookConfigForm::class, $form_object);
    $form_object->setEntity($entity);

    $form_state = new FormState();
    $form_state->disableRedirect();
    $form_state->setValues([
      'label'                => 'Incoming Override',
      'id'                   => self::WEBHOOK_ID,
      'type'                 => 'incoming',
      'content_type'         => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'               => '',
      'signature_header'     => 'X-WC-Webhook-Signature',
      'signature_algorithm'  => 'sha256',
      'token'                => '',
      'non_blocking'         => 0,
      'status'               => 1,
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertFalse(
      $form_state->hasAnyErrors(),
      (string) print_r($form_state->getErrors(), TRUE)
    );

    $stored = $this->container->get('config.storage')
      ->read('webhooks.webhook.' . self::WEBHOOK_ID);
    $this->assertSame('', $stored['secret']);

    $this->container->get('entity_type.manager')
      ->getStorage('webhook_config')
      ->resetCache([self::WEBHOOK_ID]);
    $reloaded = WebhookConfig::load(self::WEBHOOK_ID);
    $this->assertInstanceOf(WebhookConfig::class, $reloaded);
    $this->assertSame(self::OVERRIDE_SECRET, $reloaded->getSecret());
  }

}
