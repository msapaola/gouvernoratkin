<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\SendEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Drupal\webhooks\Webhook;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the delivery header migration through receive and send paths.
 */
#[Group('webhooks')]
#[RunTestsInSeparateProcesses]
class DeliveryHeaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'webhooks'];

  /**
   * Captured requests; the transport never makes external HTTP requests.
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'webhooks']);
    $this->container->get('router.builder')->rebuild();
    User::create(['uid' => 0, 'name' => 'anonymous'])->save();
    WebhookConfig::create([
      'id' => 'incoming_headers',
      'label' => 'Incoming headers',
      'type' => 'incoming',
      'content_type' => 'application/json',
      'events' => [],
      'token' => '',
      'secret' => '',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Tests canonical precedence and compatibility with older senders.
   */
  #[DataProvider('incomingHeaders')]
  public function testReceiveHeaderPrecedence(array $headers, ?string $expected): void {
    $request = Request::create('/webhook/incoming_headers', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $webhook = $this->container->get('webhooks.service')->receive('incoming_headers');
    }
    finally {
      $stack->pop();
    }
    if ($expected !== NULL) {
      $this->assertSame($expected, $webhook->getUuid());
    }
    $this->assertNotEmpty($webhook->getUuid());
    $this->assertSame($webhook->getUuid(), $webhook->getHeaders()['webhook-delivery-id']);
    $this->assertSame($webhook->getUuid(), $webhook->getHeaders()['x-drupal-delivery']);
  }

  /**
   * Incoming headers including case differences and conflicting values.
   *
   * @return array<string, array{array<string, string>, ?string}>
   *   Headers and expected delivery UUID; NULL allows a generated UUID.
   */
  public static function incomingHeaders(): array {
    return [
      'new name' => [['Webhook-Delivery-Id' => 'new'], 'new'],
      'old name' => [['X-Drupal-Delivery' => 'old'], 'old'],
      'both equal' => [[
        'Webhook-Delivery-Id' => 'same',
        'X-Drupal-Delivery' => 'same',
      ], 'same',
      ],
      'new name wins conflicts' => [[
        'Webhook-Delivery-Id' => 'new',
        'X-Drupal-Delivery' => 'old',
      ], 'new',
      ],
      'empty new falls back' => [[
        'Webhook-Delivery-Id' => '',
        'X-Drupal-Delivery' => 'old',
      ], 'old',
      ],
      'mixed case' => [[
        'wEbHoOk-DeLiVeRy-Id' => 'new',
        'X-DRUPAL-DELIVERY' => 'old',
      ], 'new',
      ],
      'no delivery headers' => [[], NULL],
    ];
  }

  /**
   * Tests canonical event metadata takes precedence over legacy names.
   */
  #[DataProvider('incomingEventHeaders')]
  public function testReceiveEventMetadata(array $headers, string $expected): void {
    $request = Request::create('/webhook/incoming_headers', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $webhook = $this->container->get('webhooks.service')->receive('incoming_headers');
    }
    finally {
      $stack->pop();
    }
    $this->assertSame($expected, $webhook->getEvent());
    $this->assertSame($expected, $webhook->getHeaders()['webhook-event']);
    $this->assertSame($expected, $webhook->getHeaders()['x-drupal-event']);
  }

  /**
   * Canonical, legacy, conflicting and empty incoming metadata.
   *
   * @return array<string, array{array<string, string>, string}>
   *   Header values and the expected event type.
   */
  public static function incomingEventHeaders(): array {
    return [
      'neutral names' => [[
        'Webhook-Event' => 'new',
      ], 'new',
      ],
      'legacy names' => [[
        'X-Drupal-Event' => 'old',
      ], 'old',
      ],
      'neutral names win' => [[
        'Webhook-Event' => 'new',
        'X-Drupal-Event' => 'old',
      ], 'new',
      ],
      'empty neutral values fall back' => [[
        'Webhook-Event' => '',
        'X-Drupal-Event' => 'old',
      ], 'old',
      ],
    ];
  }

  /**
   * Tests actual outgoing headers after listeners customize a delivery.
   */
  public function testOutgoingHeaderContract(): void {
    $configuration = WebhookConfig::create([
      'id' => 'outgoing_headers',
      'label' => 'Outgoing headers',
      'type' => 'outgoing',
      'payload_url' => 'https://receiver.example/webhook',
      'content_type' => 'application/json',
      'events' => [],
      'secret' => '',
      'status' => TRUE,
    ]);
    $configuration->save();
    $this->container->get('event_dispatcher')->addListener(WebhookEvents::SEND, static function (SendEvent $event): void {
      $event->getWebhook()->addHeaders([
        'X-Drupal-Delivery' => 'stale-old',
        'Webhook-Delivery-Id' => 'stale-new',
        'Webhook-Event' => 'stale-new-type',
        'X-Drupal-Event' => 'stale-old-type',
      ]);
    });
    $webhook = new Webhook(['value' => 'payload'], [
      'X-Drupal-Delivery' => 'constructor-old',
      'Webhook-Delivery-Id' => 'constructor-new',
    ], 'example.updated');
    $service = $this->container->get('webhooks.service');
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('https://drupal.example'));
    try {
      $service->send($configuration, $webhook);
      $first_uuid = $webhook->getUuid();
      $service->send($configuration, $webhook);
    }
    finally {
      $stack->pop();
    }
    $this->assertCount(2, $this->history);
    foreach ($this->history as $transaction) {
      $request = $transaction['request'];
      $id = $request->getHeaderLine('Webhook-Delivery-Id');
      $this->assertNotEmpty($id);
      $this->assertNotSame('stale-new', $id);
      $this->assertSame('example.updated', $request->getHeaderLine('Webhook-Event'));
      $this->assertSame($request->getHeader('Webhook-Event'), $request->getHeader('X-Drupal-Event'));
      $this->assertSame(['value' => 'payload'], json_decode((string) $request->getBody(), TRUE));
      $this->assertSame($request->getHeader('Webhook-Delivery-Id'), $request->getHeader('X-Drupal-Delivery'));
    }
    $this->assertSame($webhook->getUuid(), $this->history[1]['request']->getHeaderLine('Webhook-Delivery-Id'));
    $this->assertNotSame($first_uuid, $webhook->getUuid());
  }

}
