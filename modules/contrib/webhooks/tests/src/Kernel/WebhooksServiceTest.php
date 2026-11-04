<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Exception\WebhookIncomingEndpointNotFoundException;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Exception\WebhookMismatchTokenException;
use Drupal\webhooks\Webhook;
use Drupal\webhooks\WebhooksService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for WebhooksService.
 *
 * Covers every executable line of src/WebhooksService.php via the real
 * Drupal container, config entity storage, request stack, queue, and event
 * dispatcher. Guzzle HTTP calls are replaced with a mock handler so no
 * real network connections are made.
 *
 * @group webhooks
 */
#[CoversClass(WebhooksService::class)]
class WebhooksServiceTest extends KernelTestBase {

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

  // Machine names for fixture WebhookConfig entities.
  const INCOMING_BASIC        = 'incoming_basic';
  const INCOMING_SECRET       = 'incoming_secret';
  const INCOMING_TOKEN        = 'incoming_token';
  const INCOMING_NON_BLOCKING = 'incoming_non_blocking';
  const OUTGOING_EVENT        = 'outgoing_event';
  const OUTGOING_SECRET       = 'outgoing_secret';
  const OUTGOING_INACTIVE     = 'outgoing_inactive';
  const TEST_EVENT            = 'entity:test:create';
  // cspell:disable-next-line
  const TEST_SECRET = 'superSecretValue123';
  const TEST_TOKEN  = 'myLegacyToken';

  /**
   * The webhooks service under test.
   *
   * @var \Drupal\webhooks\WebhooksService
   */
  protected WebhooksService $service;

  /**
   * The state service for reading subscriber side-effects.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * A reusable test payload.
   *
   * @var array
   */
  protected array $payload = ['key' => 'value'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // User entity schema is required because hook_entity_insert fires when
    // WebhookConfig entities are saved and calls User::load().
    $this->installEntitySchema('user');
    $this->installConfig(['webhooks']);
    // Routes (e.g. entity.webhook_config.edit_form) are used by the logger
    // calls inside send() and receive(); rebuild so they're resolvable.
    $this->container->get('router.builder')->rebuild();

    // NOTE: we do NOT push a base request here. KernelTestBase::bootKernel()
    // calls DrupalKernel::preHandle() which already pushes a request with a
    // session onto the stack.  Adding another session-less request on top
    // would break KernelTestBase::tearDown()'s session-cleanup logic.
    $this->state   = $this->container->get('state');
    $this->service = $this->container->get('webhooks.service');

    // Clear state keys written by the test subscriber so that tests running
    // in the same process (no separate-process isolation) don't inherit stale
    // values from a previous test method.
    $this->state->deleteMultiple([
      'onWebhookSend',
      'onWebhookReceive',
      'onWebhookReceiveError',
      'webhooks_test_force_failure',
    ]);

    $this->createFixtures();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Pop any session-less requests pushed by individual test methods so
    // that KernelTestBase::tearDown() can find the session-bearing boot
    // request and flush it without throwing SessionNotFoundException.
    if (isset($this->container)) {
      $stack = $this->container->get('request_stack');
      while (
        ($r = $stack->getCurrentRequest()) !== NULL
        && !$r->hasSession()
      ) {
        $stack->pop();
      }
    }
    parent::tearDown();
  }

  /**
   * Creates all WebhookConfig fixtures needed by the test methods.
   *
   * Each entity is fully specified so PHP's array-union operator (+) is not
   * used with keys that already exist in a base array, which would silently
   * keep the base value rather than the override value.
   */
  private function createFixtures(): void {
    WebhookConfig::create([
      'id'           => self::INCOMING_BASIC,
      'label'        => 'Incoming Basic',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::INCOMING_SECRET,
      'label'        => 'Incoming Secret',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'            => self::TEST_SECRET,
      'signature_header'  => WebhookConfig::DEFAULT_SIGNATURE_HEADER,
      'status'            => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::INCOMING_TOKEN,
      'label'        => 'Incoming Token',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'token'        => self::TEST_TOKEN,
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::INCOMING_NON_BLOCKING,
      'label'        => 'Incoming Non-Blocking',
      'payload_url'  => '',
      'type'         => 'incoming',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'non_blocking' => TRUE,
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::OUTGOING_EVENT,
      'label'        => 'Outgoing Event',
      'payload_url'  => 'http://example.com/webhook',
      'type'         => 'outgoing',
      'events'       => [self::TEST_EVENT],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::OUTGOING_SECRET,
      'label'        => 'Outgoing Secret',
      'payload_url'  => 'http://example.com/webhook',
      'type'         => 'outgoing',
      'events'       => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'            => self::TEST_SECRET,
      'signature_header'  => WebhookConfig::DEFAULT_SIGNATURE_HEADER,
      'status'            => 1,
    ])->save();

    WebhookConfig::create([
      'id'           => self::OUTGOING_INACTIVE,
      'label'        => 'Outgoing Inactive',
      'payload_url'  => 'http://example.com/webhook',
      'type'         => 'outgoing',
      'events'       => [self::TEST_EVENT],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'       => '',
      'status'       => 0,
    ])->save();
  }

  /**
   * Swaps the internal Guzzle client on the service for a mock handler.
   *
   * @param \GuzzleHttp\Handler\MockHandler $handler
   *   Pre-loaded mock responses consumed in FIFO order.
   */
  private function swapGuzzleClient(MockHandler $handler): void {
    $stack  = HandlerStack::create($handler);
    $client = new Client(['handler' => $stack]);
    $prop   = new \ReflectionProperty(WebhooksService::class, 'client');
    $prop->setAccessible(TRUE);
    $prop->setValue($this->service, $client);
  }

  /**
   * Pushes a synthetic POST request on top of the request stack.
   *
   * The request is created with the body passed as the seventh argument
   * of Request::create() because Request::setContent() does not exist in
   * Symfony 7. The request has no session by design; tearDown() pops it
   * before parent::tearDown() tries to flush the boot-request session.
   *
   * @param string $body
   *   Raw encoded body.
   * @param array $server
   *   Extra SERVER variables, e.g. ['HTTP_X_DRUPAL_EVENT' => 'test'].
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The newly pushed request.
   */
  private function pushIncomingRequest(
    string $body,
    array $server = [],
  ): Request {
    $server += ['CONTENT_TYPE' => 'application/json'];
    // Pass $body as the seventh (content) argument – no setContent() needed.
    $request = Request::create('/', 'POST', [], [], [], $server, $body);
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * Tests that the webhooks service is resolved from the container.
   */
  public function testServiceIsResolvedFromContainer(): void {
    $this->assertInstanceOf(WebhooksService::class, $this->service);
  }

  /**
   * Tests that loadMultipleByEvent returns configs matching the event.
   */
  public function testLoadMultipleByEventReturnsMatchingConfigs(): void {
    $results = $this->service->loadMultipleByEvent(self::TEST_EVENT);

    $this->assertArrayHasKey(self::OUTGOING_EVENT, $results);
    $this->assertCount(1, $results);
  }

  /**
   * Tests that inactive configs are excluded from loadMultipleByEvent.
   */
  public function testLoadMultipleByEventExcludesInactiveConfigs(): void {
    $results = $this->service->loadMultipleByEvent(self::TEST_EVENT);

    $this->assertArrayNotHasKey(self::OUTGOING_INACTIVE, $results);
  }

  /**
   * Tests loadMultipleByEvent returns empty for unknown events.
   */
  public function testLoadMultipleByEventReturnsEmptyForUnknownEvent(): void {
    $results = $this->service->loadMultipleByEvent('no_such_event');

    $this->assertEmpty($results);
  }

  /**
   * Tests triggerEvent sends to each matching config.
   */
  public function testTriggerEventSendsToEachMatchingConfig(): void {
    $this->swapGuzzleClient(
      new MockHandler([new GuzzleResponse(200)])
    );

    $webhook = new Webhook($this->payload);
    $this->service->triggerEvent($webhook, self::TEST_EVENT);

    // The test subscriber records state on WebhookEvents::SEND.
    $this->assertTrue($this->state->get('onWebhookSend'));
  }

  /**
   * Tests send assigns a new UUID to the webhook.
   */
  public function testSendAssignsNewUuidToWebhook(): void {
    $this->swapGuzzleClient(new MockHandler([new GuzzleResponse(200)]));
    $config       = WebhookConfig::load(self::OUTGOING_EVENT);
    $webhook      = new Webhook($this->payload);
    $originalUuid = $webhook->getUuid();

    $this->service->send($config, $webhook);

    // send() always overwrites the UUID with a freshly generated value.
    $this->assertNotEquals($originalUuid, $webhook->getUuid());
  }

  /**
   * Tests send dispatches the send event.
   */
  public function testSendDispatchesSendEvent(): void {
    $this->swapGuzzleClient(new MockHandler([new GuzzleResponse(200)]));
    $config  = WebhookConfig::load(self::OUTGOING_EVENT);
    $webhook = new Webhook($this->payload);

    $this->service->send($config, $webhook);

    $this->assertTrue($this->state->get('onWebhookSend'));
  }

  /**
   * Tests send with secret computes signature headers.
   */
  public function testSendWithSecretComputesSignatureHeaders(): void {
    $this->swapGuzzleClient(new MockHandler([new GuzzleResponse(200)]));
    $config  = WebhookConfig::load(self::OUTGOING_SECRET);
    $webhook = new Webhook($this->payload);

    $this->service->send($config, $webhook);

    $this->assertNotEmpty($webhook->getSignature());
  }

  /**
   * Tests send on HTTP exception sets status false.
   */
  public function testSendOnHttpExceptionSetsStatusFalse(): void {
    $this->swapGuzzleClient(new MockHandler([
      new RequestException(
        'Connection refused',
        new GuzzleRequest('POST', 'http://example.com/webhook'),
      ),
    ]));
    $config  = WebhookConfig::load(self::OUTGOING_EVENT);
    $webhook = new Webhook($this->payload);

    $this->service->send($config, $webhook);

    $this->assertFalse($webhook->getStatus());
  }

  /**
   * Tests receive throws for unknown incoming webhook.
   */
  public function testReceiveThrowsForUnknownIncomingWebhook(): void {
    $this->pushIncomingRequest(json_encode($this->payload));

    $this->expectException(WebhookIncomingEndpointNotFoundException::class);
    $this->service->receive('no_such_webhook');
  }

  /**
   * Tests synchronous receive dispatches receive event.
   */
  public function testReceiveSynchronousDispatchesReceiveEvent(): void {
    $this->pushIncomingRequest(
      json_encode($this->payload),
      [
        'HTTP_X_DRUPAL_DELIVERY' => 'delivery-uuid',
        'HTTP_X_DRUPAL_EVENT'    => self::TEST_EVENT,
      ],
    );

    $webhook = $this->service->receive(self::INCOMING_BASIC);

    $this->assertInstanceOf(Webhook::class, $webhook);
    $this->assertTrue($this->state->get('onWebhookReceive'));
  }

  /**
   * Tests receive verifies HMAC signature.
   */
  public function testReceiveVerifiesHmacSignature(): void {
    $body = json_encode($this->payload);
    $sig  = 'sha256=' . hash_hmac('sha256', $body, self::TEST_SECRET);

    $this->pushIncomingRequest(
      $body,
      [
        'HTTP_X_DRUPAL_DELIVERY'   => 'delivery-uuid',
        'HTTP_X_DRUPAL_EVENT'      => self::TEST_EVENT,
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
      ],
    );

    $webhook = $this->service->receive(self::INCOMING_SECRET);

    $this->assertInstanceOf(Webhook::class, $webhook);
  }

  /**
   * Tests receive verifies HMAC from a custom signature header.
   */
  public function testReceiveVerifiesCustomSignatureHeader(): void {
    WebhookConfig::create([
      'id'                => 'incoming_custom_header',
      'label'             => 'Incoming Custom Header',
      'payload_url'       => '',
      'type'              => 'incoming',
      'events'            => [],
      'content_type'      => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'            => self::TEST_SECRET,
      'signature_header'  => 'X-Acme-Signature',
      'status'            => 1,
    ])->save();

    $body = json_encode($this->payload);
    $sig  = 'sha256=' . hash_hmac('sha256', $body, self::TEST_SECRET);

    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_ACME_SIGNATURE' => $sig],
    );

    $webhook = $this->service->receive('incoming_custom_header');

    $this->assertInstanceOf(Webhook::class, $webhook);
  }

  /**
   * Tests receive verifies a WooCommerce-style base64 signature.
   */
  public function testReceiveVerifiesWooCommerceSignature(): void {
    $this->createWooCommerceIncomingConfig();

    $body = '  {"id":1,"meta":{},"permalink":"http:\/\/shop.example\/p"}  ';
    $sig  = base64_encode(hash_hmac('sha256', trim($body), self::TEST_SECRET, TRUE));

    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_WC_WEBHOOK_SIGNATURE' => $sig],
    );

    $webhook = $this->service->receive('incoming_woocommerce');

    $this->assertInstanceOf(Webhook::class, $webhook);
    $this->assertSame($body, $webhook->getBody());
  }

  /**
   * Tests receive accepts the WooCommerce activation ping without HMAC.
   */
  public function testReceiveAcceptsWooCommerceActivationPing(): void {
    $this->createWooCommerceIncomingConfig();
    $this->pushIncomingRequest(
      'webhook_id=42',
      ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
    );

    $webhook = $this->service->receive('incoming_woocommerce');

    $this->assertInstanceOf(Webhook::class, $webhook);
    $this->assertSame('webhook_id=42', $webhook->getBody());
    $this->assertNull($this->state->get('onWebhookReceive'));
    $this->assertNull($this->state->get('onWebhookReceiveError'));
  }

  /**
   * Tests a ping-shaped body with a WooCommerce topic header is verified.
   */
  public function testReceiveRejectsPingBodyWithWooCommerceTopicHeader(): void {
    $this->createWooCommerceIncomingConfig();
    $this->pushIncomingRequest(
      'webhook_id=42',
      [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
      ],
    );

    try {
      $this->service->receive('incoming_woocommerce');
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
    }
  }

  /**
   * Tests a ping-shaped body with a bad WooCommerce signature is rejected.
   */
  public function testReceiveRejectsPingBodyWithBadWooCommerceSignature(): void {
    $this->createWooCommerceIncomingConfig();
    $this->pushIncomingRequest(
      'webhook_id=42',
      [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'not-a-valid-hmac',
      ],
    );

    try {
      $this->service->receive('incoming_woocommerce');
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
    }
  }

  /**
   * Tests extra form fields are not treated as the activation ping.
   */
  public function testReceiveRejectsPingBodyWithExtraFormFields(): void {
    $this->createWooCommerceIncomingConfig();
    $this->pushIncomingRequest(
      'webhook_id=12&foo=1',
      ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
    );

    try {
      $this->service->receive('incoming_woocommerce');
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
    }
  }

  /**
   * Create an incoming webhook_config for WooCommerce HMAC tests.
   */
  private function createWooCommerceIncomingConfig(): void {
    WebhookConfig::create([
      'id'                   => 'incoming_woocommerce',
      'label'                => 'Incoming WooCommerce',
      'payload_url'          => '',
      'type'                 => 'incoming',
      'events'               => [],
      'content_type'         => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'               => self::TEST_SECRET,
      'signature_header'     => 'X-WC-Webhook-Signature',
      'signature_algorithm'  => 'sha256',
      'status'               => 1,
    ])->save();
  }

  /**
   * Tests receive accepts SHA-1 Hub header when SHA-256 is configured.
   */
  public function testReceiveFallsBackToSha1HubHeader(): void {
    $body = json_encode($this->payload);
    $sig  = 'sha1=' . hash_hmac('sha1', $body, self::TEST_SECRET);

    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_HUB_SIGNATURE' => $sig],
    );

    $webhook = $this->service->receive(self::INCOMING_SECRET);

    $this->assertInstanceOf(Webhook::class, $webhook);
  }

  /**
   * Tests receive finds a CGI-style underscored signature header.
   */
  public function testReceiveVerifiesUnderscoredSignatureHeader(): void {
    $body = json_encode($this->payload);
    $sig  = 'sha256=' . hash_hmac('sha256', $body, self::TEST_SECRET);

    $request = Request::create(
      '/',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      $body
    );
    $request->headers->set('X_Hub_Signature_256', $sig);
    $this->container->get('request_stack')->push($request);

    $webhook = $this->service->receive(self::INCOMING_SECRET);

    $this->assertInstanceOf(Webhook::class, $webhook);
  }

  /**
   * Tests receive rejects a missing signature when a secret is configured.
   */
  public function testReceiveRejectsMissingSignatureHeader(): void {
    $this->pushIncomingRequest(json_encode($this->payload));

    try {
      $this->service->receive(self::INCOMING_SECRET);
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
      $this->assertInstanceOf(
        WebhookMismatchSignatureException::class,
        $this->state->get('onWebhookReceiveError_error')
      );
    }
  }

  /**
   * Tests receive rejects a mismatched HMAC signature.
   */
  public function testReceiveRejectsMismatchedSignature(): void {
    $body = json_encode($this->payload);
    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef'],
    );

    try {
      $this->service->receive(self::INCOMING_SECRET);
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertStringNotContainsString($body, $e->getMessage());
      $this->assertStringNotContainsString('Payload:', $e->getMessage());
      $this->assertSame(strlen($body), $e->getPayloadLength());
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
      $this->assertInstanceOf(
        Webhook::class,
        $this->state->get('onWebhookReceiveError_webhook')
      );
    }
  }

  /**
   * Tests receive rejects a signature when no secret is configured.
   */
  public function testReceiveRejectsSignatureWithoutSecret(): void {
    $body = json_encode($this->payload);
    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef'],
    );

    try {
      $this->service->receive(self::INCOMING_BASIC);
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertSame(
        'A signature was received but no secret is configured.',
        $e->getMessage()
      );
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
    }
  }

  /**
   * Tests receive rejects a mismatched token.
   */
  public function testReceiveRejectsMismatchedToken(): void {
    $this->pushIncomingRequest(
      json_encode($this->payload),
      ['HTTP_X_DRUPAL_TOKEN' => 'wrong-token'],
    );

    try {
      $this->service->receive(self::INCOMING_TOKEN);
      $this->fail('Expected WebhookMismatchTokenException.');
    }
    catch (WebhookMismatchTokenException $e) {
      $this->assertNull($this->state->get('onWebhookReceive'));
      $this->assertTrue($this->state->get('onWebhookReceiveError'));
    }
  }

  /**
   * Tests receive verifies legacy token.
   */
  public function testReceiveVerifiesLegacyToken(): void {
    $this->pushIncomingRequest(
      json_encode($this->payload),
      [
        'HTTP_X_DRUPAL_DELIVERY' => 'delivery-uuid',
        'HTTP_X_DRUPAL_EVENT'    => self::TEST_EVENT,
        'HTTP_X_DRUPAL_TOKEN'    => self::TEST_TOKEN,
      ],
    );

    $webhook = $this->service->receive(self::INCOMING_TOKEN);

    $this->assertInstanceOf(Webhook::class, $webhook);
  }

  /**
   * Tests non-blocking receive creates queue item.
   */
  public function testReceiveNonBlockingCreatesQueueItem(): void {
    $this->pushIncomingRequest(
      json_encode($this->payload),
      [
        'HTTP_X_DRUPAL_DELIVERY' => 'delivery-uuid',
        'HTTP_X_DRUPAL_EVENT'    => self::TEST_EVENT,
      ],
    );

    $this->service->receive(self::INCOMING_NON_BLOCKING);

    $queue = $this->container->get('queue')->get('webhooks_dispatcher');
    $this->assertGreaterThan(0, $queue->numberOfItems());
  }

  /**
   * Tests receive logs warning when webhook status is false.
   */
  public function testReceiveLogsWarningWhenWebhookStatusIsFalse(): void {
    // The test subscriber reads this flag and calls $webhook->setStatus(FALSE).
    $this->state->set('webhooks_test_force_failure', TRUE);

    $this->pushIncomingRequest(
      json_encode($this->payload),
      [
        'HTTP_X_DRUPAL_DELIVERY' => 'delivery-uuid',
        'HTTP_X_DRUPAL_EVENT'    => self::TEST_EVENT,
      ],
    );

    $webhook = $this->service->receive(self::INCOMING_BASIC);

    // The warning branch is covered; verify the status flag as the observable.
    $this->assertFalse($webhook->getStatus());
  }

}
