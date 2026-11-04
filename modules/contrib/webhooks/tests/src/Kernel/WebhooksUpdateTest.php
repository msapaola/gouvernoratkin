<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Entity\WebhookConfig;

/**
 * Kernel tests for webhooks update hooks.
 *
 * @group webhooks
 */
class WebhooksUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'webhooks',
  ];

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
   * Tests webhooks_update_10001 sets defaults when a secret exists.
   */
  public function testUpdate10001SetsDefaultSignatureHeader(): void {
    WebhookConfig::create([
      'id'           => 'has_secret',
      'label'        => 'Has Secret',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => 'shared-secret',
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => 'no_secret',
      'label'        => 'No Secret',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();

    $module_path = $this->container
      ->get('extension.list.module')
      ->getPath('webhooks');
    require_once $this->root . '/' . $module_path . '/webhooks.install';
    webhooks_update_10001();
    $this->container->get('config.factory')->reset();

    $this->assertEquals(
      WebhookConfig::DEFAULT_SIGNATURE_HEADER,
      $this->config('webhooks.webhook.has_secret')->get('signature_header')
    );
    $this->assertEquals(
      WebhookConfig::DEFAULT_SIGNATURE_ALGORITHM,
      $this->config('webhooks.webhook.has_secret')->get('signature_algorithm')
    );
    $this->assertEmpty(
      $this->config('webhooks.webhook.no_secret')->get('signature_header')
    );
    $this->assertEmpty(
      $this->config('webhooks.webhook.no_secret')->get('signature_algorithm')
    );
  }

}
