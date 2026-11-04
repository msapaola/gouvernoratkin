<?php

namespace Drupal\webhooks;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\ReceiveErrorEvent;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\SendErrorEvent;
use Drupal\webhooks\Event\SendEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Drupal\webhooks\Exception\WebhookIncomingEndpointNotFoundException;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Exception\WebhookMismatchTokenException;
use GuzzleHttp\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Serializer;

/**
 * Class WebhookService.
 *
 * @package Drupal\webhooks
 */
class WebhooksService implements WebhookDispatcherInterface, WebhookReceiverInterface, WebhookSerializerInterface {

  use StringTranslationTrait;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * The Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The Uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Webhook service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * The http client.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * WebhooksService constructor.
   *
   * @param \GuzzleHttp\Client $client
   *   The http client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The logger channel factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The Uuid service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory object.
   */
  public function __construct(
    Client $client,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
    EventDispatcherInterface $event_dispatcher,
    EntityTypeManagerInterface $entity_type_manager,
    Serializer $serializer,
    UuidInterface $uuid,
    ConfigFactoryInterface $config_factory,
    QueueFactory $queueFactory,
  ) {
    $this->client = $client;
    $this->logger = $logger_factory->get('webhooks');
    $this->requestStack = $request_stack;
    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypeManager = $entity_type_manager;
    $this->serializer = $serializer;
    $this->uuid = $uuid;
    $this->config = $config_factory->get('webhooks.settings');
    $this->queue = $queueFactory->get('webhooks_dispatcher', $this->config->get('reliable'));
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultipleByEvent($event, $type = 'outgoing') {
    $query = $this->entityTypeManager->getStorage('webhook_config')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('events', $event, 'CONTAINS')
      ->condition('type', $type, '=');
    $ids = $query->execute();
    return $this->entityTypeManager->getStorage('webhook_config')
      ->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function triggerEvent(Webhook $webhook, $event) {
    // Load all webhooks for the occurring event.
    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_configs = $this->loadMultipleByEvent($event);

    foreach ($webhook_configs as $webhook_config) {
      // Send the Webhook object.
      $this->send($webhook_config, $webhook);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function send(WebhookConfig $webhook_config, Webhook $webhook) {
    $webhook->setUuid($this->uuid->generate());

    // Dispatch Webhook Send event.
    $this->eventDispatcher->dispatch(
      new SendEvent($webhook_config, $webhook),
      WebhookEvents::SEND
    );
    // Keep canonical metadata and legacy aliases synchronized after SEND.
    $webhook->setUuid($webhook->getUuid());
    $webhook->setEvent($webhook->getEvent());

    $body = $this->encode(
      $webhook->getPayload(),
      $webhook->getMimeSubType()
    );

    if ($secret = $webhook_config->getSecret()) {
      $webhook->setSecret($secret);
      $webhook->setSignature($body);
    }

    try {
      $timeout = $this->resolveTimeout($webhook_config->getPayloadUrl());
      $this->client->post(
        $webhook_config->getPayloadUrl(),
        [
          'headers' => $webhook->getHeaders(),
          'body'    => $body,
          // Workaround for blocking local to local requests,
          // there will be 'Dispatch Failed' errors in the logs.
          'timeout' => $timeout,
        ]
      );
    }
    catch (\Exception $e) {
      // Dispatch Webhook Send error event.
      $this->eventDispatcher->dispatch(
        new SendErrorEvent($webhook_config, $webhook, $e),
        WebhookEvents::SEND_ERROR
      );

      $this->logger->error(
        'Dispatch Failed. Subscriber %subscriber on Webhook %uuid for Event %event: @message', [
          '%subscriber' => $webhook_config->id(),
          '%uuid' => $webhook->getUuid(),
          '%event' => $webhook->getEvent(),
          '@message' => $e->getMessage(),
          'link' => Link::createFromRoute(
            $this->t('Edit Webhook'),
            'entity.webhook_config.edit_form', [
              'webhook_config' => $webhook_config->id(),
            ]
          )->toString(),
        ]
      );
      $webhook->setStatus(FALSE);
    }

    // Log the sent webhook.
    $this->logger->info(
      'Webhook Dispatched. Subscriber %subscriber on Webhook %uuid for Event %event. Payload: @payload', [
        '%subscriber' => $webhook_config->id(),
        '%uuid' => $webhook->getUuid(),
        '%event' => $webhook->getEvent(),
        '@payload' => $this->encode($webhook->getPayload(), $webhook->getMimeSubType()),
        'link' => Link::createFromRoute(
          $this->t('Edit Webhook'),
          'entity.webhook_config.edit_form', [
            'webhook_config' => $webhook_config->id(),
          ]
        )->toString(),
      ]
    );
  }

  /**
   * Resolves the Guzzle timeout for an outgoing webhook request.
   *
   * When Drupal dispatches a webhook to its own host, the PHP process blocks
   * waiting for a response from itself and the request never completes. A
   * short 0.1-second timeout is applied in that case so the dispatch does
   * not stall indefinitely.
   *
   * The comparison uses parse_url() for an exact host match instead of
   * strpos(), which would incorrectly treat a subdomain such as
   * erp.example.fr as local when the Drupal host is example.fr.
   *
   * @param string $url
   *   The outgoing payload URL.
   *
   * @return float
   *   0.1 when the payload host exactly matches the current request host,
   *   0.0 for any other host (Guzzle default: wait as long as needed, or
   *   honour the http_client timeout from settings.php).
   */
  protected function resolveTimeout(string $url): float {
    $currentHost = $this->requestStack->getCurrentRequest()->getHost();
    $payloadHost = parse_url($url, PHP_URL_HOST);

    return $payloadHost === $currentHost ? 0.1 : 0.0;
  }

  /**
   * {@inheritdoc}
   */
  public function receive($name) {
    // We only receive webhook requests when a webhook configuration exists
    // with a matching machine name.
    $query = $this->entityTypeManager->getStorage('webhook_config')->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $name)
      ->condition('type', 'incoming')
      ->condition('status', 1);
    $ids = $query->execute();
    if (!array_key_exists($name, $ids)) {
      throw new WebhookIncomingEndpointNotFoundException($name);
    }

    $request = $this->requestStack->getCurrentRequest();
    $format  = $request->getContentTypeFormat();
    // Capture posted bytes before decode. HMAC and storage use this
    // string, not json_encode() of the decoded array.
    $body = (string) $request->getContent();
    // Symfony Serializer has no decoder for real form-urlencoded
    // bodies (format "form"). Skip decode for those, empty bodies,
    // and any format the serializer does not support.
    if (
      $body === ''
      || $format === 'form'
      || $format === 'x-www-form-urlencoded'
      || !$this->supportsDecoding((string) $format)
    ) {
      $payload = $request->request->all();
    }
    else {
      $payload = $this->decode(
        $body,
        $format
      );
    }

    $webhook = new Webhook(
      (array) $payload,
      $request->headers->all(),
      '',
      $request->headers->get('Content-Type')
    );
    $webhook->setBody($body);

    // Prefer the new name; retain the deprecated header for older senders.
    $drupal_uuid = $request->headers->get('Webhook-Delivery-Id')
      ?: $request->headers->get('X-Drupal-Delivery');
    if ($drupal_uuid) {
      $webhook->setUuid($drupal_uuid);
    }

    $drupal_event = $request->headers->get('Webhook-Event')
      ?: $request->headers->get('X-Drupal-Event');
    if ($drupal_event) {
      $webhook->setEvent($drupal_event);
    }

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = $this->entityTypeManager->getStorage('webhook_config')
      ->load($name);

    // WooCommerce deliver_ping() posts webhook_id=<digits> with no
    // X-WC-Webhook-* headers and requires HTTP 200. Skip HMAC and
    // do not dispatch RECEIVE so subscribers do not treat this as
    // an order.
    if ($this->isWooCommerceActivationPing($request, $body)) {
      return $webhook;
    }

    // Verify in both cases: the webhook_config contains a secret
    // and/or the webhook contains a signature.
    $header_name = $webhook_config->getSignatureHeader();
    $signature = '';
    if ($header_name !== '') {
      $from_request = $request->headers->get($header_name);
      if (is_string($from_request) && trim($from_request) !== '') {
        $signature = trim($from_request);
      }
    }
    if ($signature === '') {
      $signature = $webhook->getSignature($header_name);
    }
    try {
      if ($webhook_config->getSecret() || $signature) {
        Webhook::verify(
          $webhook_config->getSecret(),
          $webhook->getBody(),
          $signature,
          $webhook_config->getSignatureAlgorithm()
        );
      }
      // Legacy webhooks often only use a secret token.
      elseif ($webhook_config->getToken() || $webhook->getToken()) {
        Webhook::verifyToken($webhook_config->getToken(), $webhook->getToken());
      }
    }
    catch (WebhookMismatchSignatureException | WebhookMismatchTokenException $e) {
      $this->eventDispatcher->dispatch(
        new ReceiveErrorEvent($webhook_config, $webhook, $e),
        WebhookEvents::RECEIVE_ERROR
      );
      $payload_length = $e instanceof WebhookMismatchSignatureException
        ? $e->getPayloadLength()
        : strlen($webhook->getBody());
      $this->logger->error(
        'Unauthorized. @type mismatch for Webhook Subscriber %name: @message. Payload length: @length bytes.',
        [
          '@type' => $e instanceof WebhookMismatchSignatureException
            ? 'Signature'
            : 'Token',
          '%name' => $webhook_config->id(),
          '@message' => $e->getMessage(),
          '@length' => $payload_length,
          'link' => Link::createFromRoute(
            $this->t('Edit Webhook'),
            'entity.webhook_config.edit_form', [
              'webhook_config' => $webhook_config->id(),
            ]
          )->toString(),
        ]
      );
      throw $e;
    }

    if ($webhook_config->isNonBlocking()) {
      $this->queue->createItem(['id' => $name, 'webhook' => $webhook]);
    }
    else {
      // Dispatch Webhook Receive event.
      $this->eventDispatcher->dispatch(
        new ReceiveEvent($webhook_config, $webhook),
        WebhookEvents::RECEIVE
      );
    }

    if (!$webhook->getStatus()) {
      $this->logger->warning(
        'Processing Failure. Subscriber %subscriber on Webhook %uuid for Event %event. Payload: @payload', [
          '%subscriber' => $webhook_config->id(),
          '%uuid' => $webhook->getUuid(),
          '%event' => $webhook->getEvent(),
          '@payload' => $this->encode($webhook->getPayload(), $webhook->getMimeSubType()),
          'link' => Link::createFromRoute(
            $this->t('Edit Webhook'),
            'entity.webhook_config.edit_form', [
              'webhook_config' => $webhook_config->id(),
            ]
          )->toString(),
        ]
      );
    }

    return $webhook;
  }

  /**
   * Whether the request is a WooCommerce webhook activation ping.
   *
   * Matches only the exact form body webhook_id=<digits> with no
   * X-WC-Webhook-* headers, including an empty signature header.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param string $body
   *   Raw request body; not trimmed.
   *
   * @return bool
   *   TRUE when this request is the activation ping.
   */
  protected function isWooCommerceActivationPing(Request $request, string $body): bool {
    if (!preg_match('/^webhook_id=[0-9]+$/', $body)) {
      return FALSE;
    }
    foreach ($request->headers->keys() as $name) {
      if (str_starts_with(strtolower((string) $name), 'x-wc-webhook-')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Set the serializer to use when normalizing/encoding an object.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   */
  public function setSerializer(Serializer $serializer) {
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public function encode(mixed $data, string $format, array $context = []): string {
    return $this->serializer->encode($data, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format): bool {
    return $this->serializer->supportsEncoding($format);
  }

  /**
   * {@inheritdoc}
   */
  public function decode(string $data, string $format, array $context = []): mixed {
    return $this->serializer->decode($data, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding(string $format): bool {
    return $this->serializer->supportsDecoding($format);
  }

}
