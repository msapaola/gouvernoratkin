<?php

namespace Drupal\webhook\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webhook\Entity\Webhook;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\ReceiveErrorEvent;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\SendEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Drupal\webhooks\Webhook as IncomingWebhook;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Webhook event subscriber.
 */
class WebhookSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('webhooks');
  }

  /**
   * Webhook send event handler.
   *
   * @param \Drupal\webhooks\Event\SendEvent $event
   *   Response event.
   */
  public function onWebhookSend(SendEvent $event) {
  }

  /**
   * Webhook receive event handler.
   *
   * @param \Drupal\webhooks\Event\ReceiveEvent $event
   *   Response event.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function onWebhookReceive(ReceiveEvent $event) {
    $this->saveWebhookEntity(
      $event->getWebhook(),
      $event->getWebhookConfig(),
      TRUE,
      ''
    );
  }

  /**
   * Persist inbound webhooks that failed verification.
   *
   * @param \Drupal\webhooks\Event\ReceiveErrorEvent $event
   *   Receive error event.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function onWebhookReceiveError(ReceiveErrorEvent $event) {
    $this->saveWebhookEntity(
      $event->getWebhook(),
      $event->getWebhookConfig(),
      FALSE,
      $event->getError()->getMessage()
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WebhookEvents::SEND => ['onWebhookSend'],
      WebhookEvents::RECEIVE => ['onWebhookReceive'],
      WebhookEvents::RECEIVE_ERROR => ['onWebhookReceiveError'],
    ];
  }

  /**
   * Create a webhook content entity from an inbound request.
   *
   * @param \Drupal\webhooks\Webhook $webhook
   *   The inbound webhook value object.
   * @param \Drupal\webhooks\Entity\WebhookConfig $webhook_config
   *   Incoming webhook configuration.
   * @param bool $verified
   *   TRUE if signature or token verification passed.
   * @param string $error
   *   Optional verification error message.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveWebhookEntity(
    IncomingWebhook $webhook,
    WebhookConfig $webhook_config,
    $verified,
    $error = '',
  ) {
    $body = $webhook->getBody();
    if ($body === '') {
      $empty = 'Posted request body was empty; payload was not '
        . 'rebuilt from decoded JSON.';
      $error = $error === '' ? $empty : $error . ' ' . $empty;
    }
    $webhook_config_id = $webhook_config->id();
    $signature = $webhook->getSignature(
      $webhook_config->getSignatureHeader()
    );
    $entity = Webhook::create([
      'title' => $this->t('Webhook @uuid', ['@uuid' => $webhook->getUuid()]),
      'webhook_config' => $webhook_config_id,
      'headers' => json_encode($webhook->getHeaders()),
      'payload' => $body,
      'payload_length' => strlen($body),
      'signature' => $signature,
      'verified' => $verified,
      'error' => $error,
      'created' => time(),
    ]);
    $entity->save();

    $this->logger->notice(
      'Stored inbound webhook %id for config %config (verified: @verified).',
      [
        '%id' => $entity->id(),
        '%config' => $webhook_config_id,
        '@verified' => $verified ? 'yes' : 'no',
        'link' => $entity->toLink($this->t('View'))->toString(),
      ]
    );
  }

}
