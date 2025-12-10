<?php

namespace Drupal\Tests\webhooks\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Webhook;

/**
 * Test description.
 *
 * @group webhooks
 */
class WebhooksTest extends BrowserTestBase {

  const WEBHOOK_ID_INCOMING = 'webhook_id_incoming';

  const WEBHOOK_ID_OUTGOING = 'webhook_id_outgoing';

  const WEBHOOK_ID_INCOMING_VERIFIED = 'webhook_id_incoming_verified';

  const WEBHOOK_ID_OUTGOING_VERIFIED = 'webhook_id_outgoing_verified';

  // cspell:disable-next-line
  const WEBHOOK_SECRET = 'iepooleiDahF3eimeikooC2iep1ahqua';

  const WEBHOOK_ID_OUTGOING_XML = 'webhook_id_outgoing_xml';

  const WEBHOOK_ID_INCOMING_XML = 'webhook_id_incoming_xml';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webhooks', 'webhooks_test'];

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * The webhook service.
   *
   * @var \Drupal\webhooks\WebhooksService
   */
  protected $webhookService;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The uuid.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The payload.
   *
   * @var string[]
   */
  protected $payload = ['payload' => 'attribute'];

  /**
   * The headers.
   *
   * @var string[]
   */
  protected $headers = ['header-type' => 'header-value'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->state = $this->container->get('state');
    $this->uuid = $this->container->get('uuid');
    $this->webhookService = $this->container->get('webhooks.service');

    // Create an incoming webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_INCOMING,
      'label' => 'Webhook Incoming',
      'uuid' => $this->uuid->generate(),
      'payload_url' => '',
      'type' => 'incoming',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret' => '',
      'status' => 1,
    ])->save();

    // Create an outgoing webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_OUTGOING,
      'label' => 'Webhook Outgoing',
      'uuid' => $this->uuid->generate(),
      'payload_url' => Url::fromRoute('webhooks.webhook_receive', ['incoming_webhook_name' => self::WEBHOOK_ID_INCOMING])->setAbsolute(TRUE)->toString(),
      'type' => 'outgoing',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret' => '',
      'status' => 1,
    ])->save();

    // Create an incoming webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_INCOMING_VERIFIED,
      'label' => 'Webhook Incoming Verified',
      'uuid' => $this->uuid->generate(),
      'payload_url' => '',
      'type' => 'incoming',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret' => self::WEBHOOK_SECRET,
      'status' => 1,
    ])->save();

    // Create an outgoing webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_OUTGOING_VERIFIED,
      'label' => 'Webhook Outgoing Verified',
      'uuid' => $this->uuid->generate(),
      'payload_url' => Url::fromRoute('webhooks.webhook_receive', ['incoming_webhook_name' => self::WEBHOOK_ID_INCOMING_VERIFIED])->setAbsolute(TRUE)->toString(),
      'type' => 'outgoing',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'secret' => self::WEBHOOK_SECRET,
      'status' => 1,
    ])->save();

    // Create an incoming webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_INCOMING_XML,
      'label' => 'Webhook Incoming XML',
      'uuid' => $this->uuid->generate(),
      'payload_url' => '',
      'type' => 'incoming',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_XML,
      'secret' => '',
      'status' => 1,
    ])->save();

    // Create an outgoing webhook.
    WebhookConfig::create([
      'id' => self::WEBHOOK_ID_OUTGOING_XML,
      'label' => 'Webhook Outgoing XML',
      'uuid' => $this->uuid->generate(),
      'payload_url' => Url::fromRoute('webhooks.webhook_receive', ['incoming_webhook_name' => self::WEBHOOK_ID_INCOMING_XML])->setAbsolute(TRUE)->toString(),
      'type' => 'outgoing',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_XML,
      'secret' => '',
      'status' => 1,
    ])->save();
  }

  /**
   * Test creation of incoming webhook.
   */
  public function testIncomingCreated() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_INCOMING);

    $this->assertInstanceOf(WebhookConfig::class, $webhook_config);
  }

  /**
   * Test creation of outgoing webhook.
   */
  public function testOutgoingCreated() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);

    $this->assertInstanceOf(WebhookConfig::class, $webhook_config);
  }

  /**
   * Test outgoing webhook.
   */
  public function testEventSend() {
    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload);

    $this->webhookService->send($webhook_config, $webhook);

    $this->assertEquals($this->state->get('onWebhookSend'), TRUE);
  }

  /**
   * Test incoming webhook.
   *
   * @group dev
   */
  public function testEventReceive() {
    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload);

    $this->webhookService->send($webhook_config, $webhook);

    $webhook_received = $this->state->get('onWebhookReceive');
    $this->assertEquals($webhook_received, TRUE);
  }

  /**
   * Test webhook payload.
   */
  public function testPayload() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload);

    $this->webhookService->send($webhook_config, $webhook);
    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');

    $this->assertEquals($webhook_received->getPayload(), $this->payload);
  }

  /**
   * Test webhook headers.
   */
  public function testHeaders() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload, $this->headers);

    $this->webhookService->send($webhook_config, $webhook);
    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');
    $headers_received = $webhook_received->getHeaders();

    // Additional custom headers.
    $intersection = array_intersect($headers_received, $this->headers);
    $this->assertEquals($intersection, $this->headers);

    // Check for X-Drupal-Delivery header.
    $this->assertEquals($headers_received['x-drupal-delivery'], $webhook->getUuid());

    // Check for X-Drupal-Event header.
    $this->assertEquals($headers_received['x-drupal-event'], $webhook->getEvent());
  }

  /**
   * Test webhook signature.
   */
  public function testSignature() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING_VERIFIED);
    $webhook = new Webhook($this->payload);

    $this->webhookService->send($webhook_config, $webhook);

    // Make sure the secret has been set.
    $this->assertEquals($webhook_config->getSecret(), self::WEBHOOK_SECRET);

    $webhook_receive = $this->state->get('onWebhookReceive');

    // This succeeds if the webhook has been verified and accepted.
    $this->assertEquals($webhook_receive, TRUE);

    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');
    // Verify signature.
    $this->assertEquals($webhook_received->getSignature(), $webhook->getSignature());
  }

  /**
   * Test webhook content type JSON.
   */
  public function testContentTypeJson() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload);
    $webhook->setContentType(WebhookConfig::CONTENT_TYPE_JSON);

    $this->webhookService->send($webhook_config, $webhook);

    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');
    $this->assertEquals($webhook_received->getContentType(), WebhookConfig::CONTENT_TYPE_JSON);
  }

  /**
   * Test webhook content type XML.
   */
  public function testContentTypeXml() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING_XML);
    $webhook = new Webhook($this->payload);
    $webhook->setContentType(WebhookConfig::CONTENT_TYPE_XML);

    $this->webhookService->send($webhook_config, $webhook);

    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');
    $this->assertEquals($webhook_received->getContentType(), WebhookConfig::CONTENT_TYPE_XML);
  }

  /**
   * Test webhook delivery id matches uuid.
   */
  public function testDeliveryIdUuid() {
    $webhook_config = WebhookConfig::load(self::WEBHOOK_ID_OUTGOING);
    $webhook = new Webhook($this->payload);

    $this->webhookService->send($webhook_config, $webhook);

    /** @var \Drupal\webhooks\Webhook $webhook_received */
    $webhook_received = $this->state->get('onWebhookReceive_webhook');
    $this->assertEquals($webhook_received->getUuid(), $webhook->getUuid());
  }

}
