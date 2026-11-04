<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel\Plugin\QueueWorker;

use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Plugin\QueueWorker\WebhookDispatcher;
use Drupal\webhooks\Webhook;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Kernel tests for the webhooks_dispatcher queue worker.
 *
 * @group webhooks
 */
#[CoversClass(WebhookDispatcher::class)]
class WebhookDispatcherTest extends KernelTestBase {

  /**
   * Machine name of the incoming webhook fixture.
   */
  const INCOMING_ID = 'incoming_dispatcher';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'webhooks',
    'webhooks_test',
  ];

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // User entity schema is required because hook_entity_insert fires when
    // WebhookConfig entities are saved and calls User::load().
    $this->installEntitySchema('user');
    $this->installConfig(['webhooks']);
    $this->container->get('router.builder')->rebuild();

    $this->state = $this->container->get('state');
    $this->state->deleteMultiple([
      'onWebhookReceive',
      'webhooks_test_force_failure',
    ]);

    WebhookConfig::create([
      'id'           => self::INCOMING_ID,
      'label'        => 'Incoming Dispatcher',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();
  }

  /**
   * Instantiates the queue worker from the plugin manager.
   *
   * @return \Drupal\webhooks\Plugin\QueueWorker\WebhookDispatcher
   *   The worker plugin.
   */
  private function createWorker(): WebhookDispatcher {
    $worker = $this->container
      ->get('plugin.manager.queue_worker')
      ->createInstance('webhooks_dispatcher');
    $this->assertInstanceOf(WebhookDispatcher::class, $worker);
    return $worker;
  }

  /**
   * Tests processItem dispatches RECEIVE for a known webhook config.
   */
  public function testProcessItemDispatchesReceiveEvent(): void {
    $webhook = new Webhook(['key' => 'value']);
    $this->createWorker()->processItem([
      'id'      => self::INCOMING_ID,
      'webhook' => $webhook,
    ]);

    $this->assertTrue($this->state->get('onWebhookReceive'));
    $this->assertSame(
      $webhook,
      $this->state->get('onWebhookReceive_webhook')
    );
  }

  /**
   * Tests processItem is a no-op when the webhook config is missing.
   */
  public function testProcessItemIgnoresMissingConfig(): void {
    $this->createWorker()->processItem([
      'id'      => 'does_not_exist',
      'webhook' => new Webhook(['key' => 'value']),
    ]);

    $this->assertNull($this->state->get('onWebhookReceive'));
  }

  /**
   * Tests processItem suspends the queue when processing fails.
   */
  public function testProcessItemThrowsWhenWebhookStatusIsFalse(): void {
    $this->state->set('webhooks_test_force_failure', TRUE);

    $this->expectException(SuspendQueueException::class);
    $this->createWorker()->processItem([
      'id'      => self::INCOMING_ID,
      'webhook' => new Webhook(['key' => 'value']),
    ]);
  }

}
