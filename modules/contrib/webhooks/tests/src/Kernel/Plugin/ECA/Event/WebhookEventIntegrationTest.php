<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel\Plugin\ECA\Event;

use Drupal\eca\Entity\Eca;
use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\eca_test_array\Plugin\Action\ArrayWrite;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Exception\WebhookMismatchTokenException;
use Drupal\webhooks\Plugin\ECA\Event\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests model persistence, tokens, authentication and queued ECA execution.
 */
#[Group('webhooks')]
#[CoversClass(WebhookEvent::class)]
#[RunTestsInSeparateProcesses]
class WebhookEventIntegrationTest extends KernelTestBase {

  /**
   * Request bytes that change if decoded and encoded again.
   */
  private const BODY = " { \"order_id\": 42, \"url\": \"https:\\/\\/example.com\\/p\", \"name\": \"Widget\\u00ae\" }\n";

  /**
   * Signing key used only by the test fixture.
   */
  private const SECRET = 'eca-test-signing-key';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'modeler_api',
    'eca',
    'eca_test_array',
    'webhooks',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists(EventBase::class)) {
      $this->markTestSkipped('ECA is required to test incoming webhook models.');
    }
    Eca::setTesting();
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'eca', 'webhooks']);
    $this->container->get('router.builder')->rebuild();
    User::create(['uid' => 0, 'name' => 'anonymous'])->save();
    foreach (['one', 'two'] as $id) {
      WebhookConfig::create([
        'id' => $id,
        'label' => $id,
        'type' => 'incoming',
        'content_type' => 'application/json',
        'events' => [],
        'token' => '',
        'secret' => '',
        'status' => TRUE,
      ])->save();
    }
    ArrayWrite::$array = [];
  }

  /**
   * Tests strict schema, model reloads and actual ECA token replacement.
   */
  public function testModelPersistenceAndExecution(): void {
    $model = $this->createModel();
    $storage = $this->container->get('entity_type.manager')->getStorage('eca');
    $storage->resetCache([$model->id()]);
    $model = $storage->load($model->id());
    $this->assertInstanceOf(Eca::class, $model);
    $this->assertSame('one', $model->getEcaEvent('selected')->getConfiguration()['webhook_id']);
    $this->receive();

    $this->assertSame('one|header-value|' . self::BODY, $this->recordedValue('selected'));
    $this->assertSame(self::BODY, $this->recordedValue('raw_selected'));
    $this->assertSame(self::BODY, $this->recordedValue('raw_all'));
    $this->assertNotSame(json_encode(json_decode(self::BODY, TRUE)), $this->recordedValue('raw_selected'));

    ArrayWrite::$array = [];
    $this->receive('two');
    $this->assertSame('two|header-value|' . self::BODY, $this->recordedValue('all'));
    $this->assertArrayNotHasKey('selected', ArrayWrite::$array);
    $this->assertArrayNotHasKey('raw_selected', ArrayWrite::$array);
  }

  /**
   * Tests the token browser describes tokens beneath their supported root.
   */
  public function testTokenBrowserMetadata(): void {
    $plugin = $this->container->get('plugin.manager.eca.event')->createInstance('webhook_receive');
    $paths = [];
    $collect = static function (array $tokens, string $prefix = '') use (&$collect, &$paths): void {
      foreach ($tokens as $token) {
        $path = $prefix . $token->name;
        $paths[] = $path;
        $collect($token->properties, $path . ':');
      }
    };
    $collect($plugin->getTokens());
    $this->assertContains('event:webhook:raw_body', $paths);
    $this->assertContains('event:webhook:payload', $paths);
    $this->assertContains('event:webhook:headers:*', $paths);
    $this->assertNotContains('webhook', $paths);
    $this->assertContains('event:webhook:verified', $paths);
  }

  /**
   * Tests non-blocking receipts execute ECA only when the queue is processed.
   */
  public function testQueuedReceiptPreservesBody(): void {
    WebhookConfig::load('one')->set('non_blocking', TRUE)->save();
    $this->createModel();
    $this->receive();
    $this->assertSame([], ArrayWrite::$array);
    $queue = $this->container->get('queue')->get('webhooks_dispatcher');
    $item = $queue->claimItem();
    $this->assertInstanceOf(\stdClass::class, $item);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('webhooks_dispatcher');
    $worker->processItem($item->data);
    $this->assertSame(self::BODY, $this->recordedValue('raw_selected'));
    $this->assertStringStartsWith('one|header-value|', $this->recordedValue('selected'));
    $queue->deleteItem($item);
  }

  /**
   * Tests imported legacy configuration cannot bypass token authentication.
   */
  public function testLegacySettingCannotDisableTokenVerification(): void {
    WebhookConfig::load('one')->set('token', 'test-token')->save();
    $this->createModel(['verify_payload' => FALSE]);
    try {
      $this->receive(headers: ['HTTP_X_DRUPAL_TOKEN' => 'wrong-token']);
      $this->fail('The mismatched token should be rejected before ECA runs.');
    }
    catch (WebhookMismatchTokenException) {
      $this->assertSame([], ArrayWrite::$array);
    }
    $this->receive(headers: ['HTTP_X_DRUPAL_TOKEN' => 'test-token']);
    $this->assertArrayHasKey('selected', ArrayWrite::$array);
  }

  /**
   * Tests missing and incorrect signatures never execute ECA models.
   */
  public function testSignatureVerificationBeforeEca(): void {
    WebhookConfig::load('one')
      ->set('secret', self::SECRET)
      ->set('signature_header', WebhookConfig::DEFAULT_SIGNATURE_HEADER)
      ->save();
    $this->createModel(['verify_payload' => FALSE]);
    foreach (['', 'sha256=incorrect'] as $signature) {
      try {
        $this->receive(headers: ['HTTP_X_HUB_SIGNATURE_256' => $signature]);
        $this->fail('Invalid signatures must be rejected before ECA runs.');
      }
      catch (WebhookMismatchSignatureException) {
        $this->assertSame([], ArrayWrite::$array);
      }
    }
    $signature = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
    $this->receive(headers: ['HTTP_X_HUB_SIGNATURE_256' => $signature]);
    $this->assertSame(self::BODY, $this->recordedValue('raw_selected'));
  }

  /**
   * Tests raw body tokens remain empty when the received body is empty.
   */
  public function testEmptyRawBody(): void {
    $this->createModel();
    $event = NULL;
    $this->container->get('event_dispatcher')->addListener(WebhookEvents::RECEIVE, static function (ReceiveEvent $received) use (&$event): void {
      $event = $received;
    });
    $this->receive(body: '');
    $this->assertArrayHasKey('selected', ArrayWrite::$array);
    $plugin = $this->container->get('plugin.manager.eca.event')->createInstance('webhook_receive');
    $plugin->setEvent($event);
    $this->assertSame('', $plugin->getData('event')->get('webhook')->get('raw_body')->getValue());
    $tokens = $this->container->get('eca.service.token');
    $tokens->addTokenDataProvider($plugin);
    $this->assertSame('', (string) $tokens->replaceClear('[event:webhook:raw_body]'));
  }

  /**
   * Reads the latest ECA action result after model execution.
   *
   * @param string $key
   *   The recorded action key.
   *
   * @return string
   *   The replaced token value.
   */
  private function recordedValue(string $key): string {
    $this->assertArrayHasKey($key, ArrayWrite::$array);
    return (string) ArrayWrite::$array[$key];
  }

  /**
   * Saves a real model; KernelTestBase keeps strict schema checking enabled.
   *
   * @param array $configuration
   *   Additional event configuration, including imported legacy settings.
   *
   * @return \Drupal\eca\Entity\Eca
   *   The saved model.
   */
  private function createModel(array $configuration = []): Eca {
    $events = $actions = [];
    foreach (['all' => '*', 'selected' => 'one'] as $key => $id) {
      $events[$key] = [
        'plugin' => 'webhook_receive',
        'label' => $key,
        'configuration' => ['webhook_id' => $id] + $configuration,
        'successors' => [['id' => $key, 'condition' => '']],
      ];
      $actions[$key] = [
        'plugin' => 'eca_test_array_write',
        'label' => $key,
        'configuration' => [
          'key' => $key,
          'value' => '[event:webhook:id]|[event:webhook:headers:x-example]|[event:webhook:payload]',
        ],
        'successors' => [['id' => 'raw_' . $key, 'condition' => '']],
      ];
      $actions['raw_' . $key] = [
        'plugin' => 'eca_test_array_write',
        'label' => 'Capture raw body',
        'configuration' => [
          'key' => 'raw_' . $key,
          'value' => '[event:webhook:raw_body]',
        ],
        'successors' => [],
      ];
    }
    $model = Eca::create([
      'id' => 'webhook_incoming',
      'status' => TRUE,
      'langcode' => 'en',
      'events' => $events,
      'actions' => $actions,
      'conditions' => [],
      'gateways' => [],
    ]);
    $model->save();
    return $model;
  }

  /**
   * Runs a request through the actual incoming receiver and ECA processor.
   *
   * @param string $id
   *   The incoming webhook configuration ID.
   * @param string $body
   *   The exact request body.
   * @param array $headers
   *   Additional server header values.
   */
  private function receive(string $id = 'one', string $body = self::BODY, array $headers = []): void {
    $request = Request::create('/webhook/' . $id, 'POST', [], [], [], $headers + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_EXAMPLE' => 'header-value',
    ], $body);
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $this->container->get('webhooks.service')->receive($id);
    }
    finally {
      $stack->pop();
    }
  }

}
