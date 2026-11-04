<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel\Plugin\ECA\Event;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\eca\Entity\Objects\EcaEvent;
use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Drupal\webhooks\Plugin\ECA\Event\WebhookEvent;
use Drupal\webhooks\Webhook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Kernel tests for the webhook ECA event plugin.
 */
#[Group('webhooks')]
#[CoversClass(WebhookEvent::class)]
#[RunTestsInSeparateProcesses]
class WebhookEventTest extends KernelTestBase {

  /**
   * Incoming webhook fixture id.
   */
  const INCOMING_ID = 'incoming_eca';

  /**
   * Incoming webhook with secret.
   */
  const INCOMING_SECRET_ID = 'incoming_eca_secret';

  /**
   * Outgoing webhook fixture id.
   */
  const OUTGOING_ID = 'outgoing_eca';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'modeler_api',
    'eca',
    'webhooks',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists(EventBase::class)) {
      $this->markTestSkipped('The ECA module is required to test the webhook ECA event plugin.');
    }
    parent::setUp();

    // User entity schema is required because hook_entity_insert fires when
    // WebhookConfig entities are saved and calls User::load().
    $this->installEntitySchema('user');
    $this->installConfig(['webhooks']);
    $this->container->get('router.builder')->rebuild();

    WebhookConfig::create([
      'id'           => self::INCOMING_ID,
      'label'        => 'Incoming ECA',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::INCOMING_SECRET_ID,
      'label'        => 'Incoming ECA Secret',
      'payload_url'  => 'https://example.com/hook',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'            => 'private',
      'signature_header'  => WebhookConfig::DEFAULT_SIGNATURE_HEADER,
      'token'             => 'legacy-token',
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::OUTGOING_ID,
      'label'        => 'Outgoing ECA',
      'payload_url'  => 'https://example.com/outgoing',
      'type'         => 'outgoing',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();
  }

  /**
   * Instantiates the webhook ECA event plugin.
   *
   * @param array $configuration
   *   Plugin configuration overrides.
   *
   * @return \Drupal\webhooks\Plugin\ECA\Event\WebhookEvent
   *   The plugin instance.
   */
  private function createPlugin(array $configuration = []): WebhookEvent {
    $plugin = $this->container
      ->get('plugin.manager.eca.event')
      ->createInstance('webhook_receive', $configuration);
    $this->assertInstanceOf(WebhookEvent::class, $plugin);
    return $plugin;
  }

  /**
   * Tests definitions() returns the receive event mapping.
   */
  public function testDefinitions(): void {
    $definitions = WebhookEvent::definitions();
    $this->assertArrayHasKey('webhook_receive', $definitions);
    $this->assertSame(WebhookEvents::RECEIVE, $definitions['webhook_receive']['event_name']);
    $this->assertSame(ReceiveEvent::class, $definitions['webhook_receive']['event_class']);
  }

  /**
   * Tests default configuration values.
   */
  public function testDefaultConfiguration(): void {
    $config = $this->createPlugin()->defaultConfiguration();
    $this->assertSame('*', $config['webhook_id']);
    $this->assertTrue($config['verify_payload']);
  }

  /**
   * Tests generateWildcard for all / empty / specific webhook ids.
   */
  #[DataProvider('providerGenerateWildcard')]
  public function testGenerateWildcard(array $eca_configuration, string $expected): void {
    $ecaEvent = $this->createMock(EcaEvent::class);
    $ecaEvent->method('getConfiguration')->willReturn($eca_configuration);

    $this->assertSame(
      $expected,
      $this->createPlugin()->generateWildcard('eca.model.test', $ecaEvent)
    );
  }

  /**
   * Data provider for testGenerateWildcard().
   *
   * @return array<string, array{array, string}>
   *   Configuration and expected wildcard.
   */
  public static function providerGenerateWildcard(): array {
    return [
      'missing webhook_id' => [[], '*'],
      'all webhooks' => [['webhook_id' => '*'], '*'],
      'empty webhook_id' => [['webhook_id' => ''], '*'],
      'specific webhook' => [['webhook_id' => self::INCOMING_ID], self::INCOMING_ID],
    ];
  }

  /**
   * Tests appliesForWildcard matching logic.
   */
  public function testAppliesForWildcard(): void {
    $config = WebhookConfig::load(self::INCOMING_ID);
    $event = new ReceiveEvent($config, new Webhook(['a' => 1]));

    $this->assertTrue(WebhookEvent::appliesForWildcard($event, WebhookEvents::RECEIVE, '*'));
    $this->assertTrue(WebhookEvent::appliesForWildcard($event, WebhookEvents::RECEIVE, self::INCOMING_ID));
    $this->assertFalse(WebhookEvent::appliesForWildcard($event, WebhookEvents::RECEIVE, 'other_id'));

    $this->assertFalse(WebhookEvent::appliesForWildcard(new Event(), WebhookEvents::RECEIVE, '*'));
    $this->assertFalse(WebhookEvent::appliesForWildcard(new Event(), WebhookEvents::RECEIVE, 'other_id'));
  }

  /**
   * Tests the configuration form lists incoming webhooks only.
   */
  public function testBuildConfigurationFormListsIncomingWebhooks(): void {
    $plugin = $this->createPlugin();
    $form = $plugin->buildConfigurationForm([], new FormState());

    $this->assertArrayHasKey('webhook_id', $form);
    $options = $form['webhook_id']['#options'];
    $this->assertSame('All webhooks', (string) $options['*']);
    $this->assertSame('Incoming ECA', (string) $options[self::INCOMING_ID]);
    $this->assertArrayHasKey(self::INCOMING_SECRET_ID, $options);
    $this->assertArrayNotHasKey(self::OUTGOING_ID, $options);
    $this->assertArrayHasKey('verify_payload', $form);
    $this->assertTrue($form['verify_payload']['#disabled']);
    $this->assertTrue($form['verify_payload']['#default_value']);
  }

  /**
   * Tests the form still builds when webhook storage cannot be loaded.
   */
  public function testBuildConfigurationFormHandlesStorageException(): void {
    $plugin = $this->createPlugin(['webhook_id' => '']);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willThrowException(new \RuntimeException('storage unavailable'));
    $property = new \ReflectionProperty($plugin, 'entityTypeManager');
    $property->setValue($plugin, $entityTypeManager);

    $form = $plugin->buildConfigurationForm([], new FormState());
    $this->assertCount(1, $form['webhook_id']['#options']);
    $this->assertArrayHasKey('*', $form['webhook_id']['#options']);
    $this->assertSame('*', $form['webhook_id']['#default_value']);
  }

  /**
   * Tests submitConfigurationForm stores selected values.
   */
  public function testSubmitConfigurationForm(): void {
    $plugin = $this->createPlugin(['verify_payload' => FALSE]);
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('webhook_id', self::INCOMING_ID);
    $form_state->setValue('verify_payload', TRUE);
    $plugin->submitConfigurationForm($form, $form_state);

    $this->assertSame(self::INCOMING_ID, $plugin->getConfiguration()['webhook_id']);
    $this->assertFalse($plugin->getConfiguration()['verify_payload']);
  }

  /**
   * Tests token data built from a ReceiveEvent.
   */
  public function testBuildWebhookAndEventData(): void {
    $config = WebhookConfig::load(self::INCOMING_SECRET_ID);
    $webhook = new Webhook(
      ['hello' => 'world'],
      ['X-Test' => 'yes'],
      'entity:node:insert',
      'application/json',
    );
    $plugin = $this->createPlugin();
    $plugin->setEvent(new ReceiveEvent($config, $webhook));

    $buildWebhook = new \ReflectionMethod($plugin, 'buildWebhookData');
    $data = $buildWebhook->invoke($plugin);

    $this->assertSame(self::INCOMING_SECRET_ID, $data['id']);
    $this->assertSame($webhook->getUuid(), $data['uuid']);
    $this->assertSame(json_encode(['hello' => 'world']), $data['payload']);
    $this->assertSame('entity:node:insert', $data['event_name']);
    $this->assertSame('application/json', $data['content_type']);
    $this->assertTrue($data['status']);
    $this->assertTrue($data['verified']);
    $this->assertSame('Incoming ECA Secret', $data['config']['label']);
    $this->assertTrue($data['config']['secret']);
    $this->assertTrue($data['config']['token']);
    $this->assertSame('https://example.com/hook', $data['config']['payload_url']);
    $this->assertSame('yes', $data['headers']['X-Test']);

    $buildEvent = new \ReflectionMethod($plugin, 'buildEventData');
    $event_data = $buildEvent->invoke($plugin);
    $this->assertSame($plugin->eventName(), $event_data['machine_name']);
    $this->assertSame($data, $event_data['webhook']);
  }

  /**
   * Tests the payload token keeps the posted body, not a re-encode.
   */
  public function testBuildWebhookDataUsesPostedBody(): void {
    $config = WebhookConfig::load(self::INCOMING_SECRET_ID);
    $body = '{"permalink":"https://example.com/p","name":"Widget\u00ae"}';
    $webhook = new Webhook(
      json_decode($body, TRUE),
      ['X-Test' => 'yes'],
      'entity:node:insert',
      'application/json',
    );
    $webhook->setBody($body);
    $plugin = $this->createPlugin();
    $plugin->setEvent(new ReceiveEvent($config, $webhook));

    $buildWebhook = new \ReflectionMethod($plugin, 'buildWebhookData');
    $data = $buildWebhook->invoke($plugin);

    $this->assertSame($body, $data['payload']);
    $this->assertSame($body, $data['raw_body']);
    $this->assertNotSame(
      json_encode(json_decode($body, TRUE)),
      $data['payload']
    );
  }

  /**
   * Tests buildWebhookData returns empty when the event is not a receive event.
   */
  public function testBuildWebhookDataWithoutReceiveEvent(): void {
    $plugin = $this->createPlugin();
    $plugin->setEvent(new Event());
    $method = new \ReflectionMethod($plugin, 'buildWebhookData');
    $this->assertSame([], $method->invoke($plugin));
  }

}
