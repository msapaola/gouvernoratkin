<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webhook\Entity\Webhook as WebhookEntity;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Exception\WebhookIncomingEndpointNotFoundException;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Webhook;
use Drupal\webhooks\WebhooksService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for persisting webhook entities on receive errors.
 *
 * @group webhooks
 */
class WebhookReceiveErrorEntityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'dblog',
    'webhooks',
    'webhook',
  ];

  const INCOMING_SECRET = 'incoming_secret';

  // cspell:disable-next-line
  const TEST_SECRET = 'superSecretValue123';

  /**
   * The webhooks service.
   *
   * @var \Drupal\webhooks\WebhooksService
   */
  protected WebhooksService $service;

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

    $this->installEntitySchema('user');
    $this->installEntitySchema('webhook');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['webhooks']);
    $this->container->get('router.builder')->rebuild();
    $this->service = $this->container->get('webhooks.service');

    WebhookConfig::create([
      'id'               => self::INCOMING_SECRET,
      'label'            => 'Incoming Secret',
      'payload_url'      => '',
      'type'             => 'incoming',
      'events'           => [],
      'content_type'     => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'           => self::TEST_SECRET,
      'signature_header' => WebhookConfig::DEFAULT_SIGNATURE_HEADER,
      'status'           => 1,
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
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
   * Pushes a synthetic POST request on top of the request stack.
   *
   * @param string $body
   *   Raw encoded body.
   * @param array $server
   *   Extra SERVER variables.
   */
  private function pushIncomingRequest(string $body, array $server = []): void {
    $server += ['CONTENT_TYPE' => 'application/json'];
    $request = Request::create('/', 'POST', [], [], [], $server, $body);
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Loads all webhook content entities.
   *
   * @return \Drupal\webhook\Entity\Webhook[]
   *   Loaded webhook entities keyed by ID.
   */
  private function loadWebhookEntities(): array {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('webhook');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Tests HMAC failure still persists an unverified webhook entity.
   */
  public function testFailedVerificationCreatesUnverifiedEntity(): void {
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
      $entities = $this->loadWebhookEntities();
      $this->assertCount(1, $entities);
      $entity = reset($entities);
      $this->assertInstanceOf(WebhookEntity::class, $entity);
      $this->assertFalse($entity->isVerified());
      $this->assertNotEmpty($entity->getError());
      $this->assertStringNotContainsString($body, $entity->getError());
      $this->assertSame($body, $entity->get('payload')->value);
      $this->assertSame(strlen($body), $entity->getPayloadLength());
      $this->assertSame('sha256=deadbeef', $entity->getSignature());
      $this->assertSame(self::INCOMING_SECRET, $entity->getWebhookConfigId());
      $this->assertStringContainsString(
        'x-hub-signature-256',
        strtolower($entity->get('headers')->value)
      );

      $logs = $this->loadWebhookLogs();
      $this->assertNotEmpty($logs);
      $mismatch = $this->findLog($logs, '@type mismatch');
      $this->assertNotNull($mismatch);
      $vars = unserialize($mismatch->variables);
      $this->assertSame('Signature', $vars['@type']);
      $this->assertSame(self::INCOMING_SECRET, $vars['%name']);
      $this->assertSame(strlen($body), (int) $vars['@length']);
      $this->assertStringNotContainsString($body, $mismatch->message);
      $this->assertStringNotContainsString($body, (string) $vars['@message']);

      $stored = $this->findLog($logs, 'Stored inbound webhook');
      $this->assertNotNull($stored);
      $stored_vars = unserialize($stored->variables);
      $this->assertSame((string) $entity->id(), (string) $stored_vars['%id']);
      $this->assertSame('no', $stored_vars['@verified']);
      $this->assertNotEmpty($stored->link);
    }
  }

  /**
   * Tests a valid request persists a verified webhook entity.
   */
  public function testSuccessfulReceiveCreatesVerifiedEntity(): void {
    $body = json_encode($this->payload);
    $sig = 'sha256=' . hash_hmac('sha256', $body, self::TEST_SECRET);
    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_HUB_SIGNATURE_256' => $sig],
    );

    $this->service->receive(self::INCOMING_SECRET);

    $entities = $this->loadWebhookEntities();
    $this->assertCount(1, $entities);
    $entity = reset($entities);
    $this->assertTrue($entity->isVerified());
    $this->assertSame('', $entity->getError());
    $this->assertSame($body, $entity->get('payload')->value);
    $this->assertSame(strlen($body), $entity->getPayloadLength());
    $this->assertSame($sig, $entity->getSignature());
    $this->assertSame(self::INCOMING_SECRET, $entity->getWebhookConfigId());
  }

  /**
   * Tests a Woo-like compact body is hashed and stored unchanged.
   */
  public function testWooCommerceBodyIsStoredWithoutReencoding(): void {
    WebhookConfig::create([
      'id'                  => 'incoming_woocommerce',
      'label'               => 'Incoming WooCommerce',
      'payload_url'         => '',
      'type'                => 'incoming',
      'events'              => [],
      'content_type'        => WebhookConfig::CONTENT_TYPE_JSON,
      'secret'              => self::TEST_SECRET,
      'signature_header'    => 'X-WC-Webhook-Signature',
      'signature_algorithm' => 'sha256',
      'status'              => 1,
    ])->save();

    $body = '  {"permalink":"https://example.com/p","name":"Widget\u00ae"}  ';
    $trimmed = trim($body);
    $reencoded = json_encode(json_decode($trimmed, TRUE));
    $this->assertNotSame($trimmed, $reencoded);
    $sig = base64_encode(
      hash_hmac('sha256', $trimmed, self::TEST_SECRET, TRUE)
    );

    $this->pushIncomingRequest(
      $body,
      ['HTTP_X_WC_WEBHOOK_SIGNATURE' => $sig],
    );

    $this->service->receive('incoming_woocommerce');

    $entities = $this->loadWebhookEntities();
    $this->assertCount(1, $entities);
    $entity = reset($entities);
    $this->assertTrue($entity->isVerified());
    $this->assertSame($body, $entity->get('payload')->value);
    $this->assertSame(strlen($body), $entity->getPayloadLength());
    $this->assertSame($sig, $entity->getSignature());
    $this->assertNotSame($reencoded, $entity->get('payload')->value);
    $this->assertTrue(Webhook::verify(
      self::TEST_SECRET,
      $entity->get('payload')->value,
      $entity->getSignature(),
      'sha256'
    ));
  }

  /**
   * Tests an empty POST body is stored empty, not rebuilt JSON.
   */
  public function testEmptyBodyIsNotRebuiltFromPayload(): void {
    $this->pushIncomingRequest(
      '',
      ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef'],
    );

    try {
      $this->service->receive(self::INCOMING_SECRET);
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $entities = $this->loadWebhookEntities();
      $this->assertCount(1, $entities);
      $entity = reset($entities);
      $this->assertSame('', (string) $entity->get('payload')->value);
      $this->assertSame(0, $entity->getPayloadLength());
      $this->assertStringContainsString(
        'Posted request body was empty',
        $entity->getError()
      );
    }
  }

  /**
   * Tests an unknown endpoint does not persist a webhook entity.
   */
  public function testUnknownEndpointDoesNotCreateEntity(): void {
    $this->pushIncomingRequest(json_encode($this->payload));

    try {
      $this->service->receive('no_such_webhook');
      $this->fail('Expected WebhookIncomingEndpointNotFoundException.');
    }
    catch (WebhookIncomingEndpointNotFoundException $e) {
      $this->assertCount(0, $this->loadWebhookEntities());
    }
  }

  /**
   * Loads watchdog rows from the webhooks channel.
   *
   * @return object[]
   *   Watchdog records.
   */
  private function loadWebhookLogs(): array {
    return $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w', ['message', 'variables', 'type', 'link'])
      ->condition('type', 'webhooks')
      ->execute()
      ->fetchAll();
  }

  /**
   * Finds the first log whose message contains a substring.
   *
   * @param object[] $logs
   *   Watchdog records.
   * @param string $needle
   *   Substring to match against the message template.
   *
   * @return object|null
   *   The matching row, or NULL.
   */
  private function findLog(array $logs, string $needle): ?object {
    foreach ($logs as $log) {
      if (str_contains($log->message, $needle)) {
        return $log;
      }
    }
    return NULL;
  }

}
