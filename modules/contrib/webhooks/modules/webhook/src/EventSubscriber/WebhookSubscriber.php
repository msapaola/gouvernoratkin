<?php

namespace Drupal\webhook\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webhook\Entity\Webhook;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\SendEvent;
use Drupal\webhooks\Event\WebhookEvents;
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
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
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
    $webhook = Webhook::create([
      'title' => $this->t('Webhook @uuid', ['@uuid' => $event->getWebhook()->getUuid()]),
      'headers' => json_encode($event->getWebhook()->getHeaders()),
      'payload' => json_encode($event->getWebhook()->getPayload()),
      'created' => time(),
    ]);
    $webhook->save();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WebhookEvents::SEND => ['onWebhookSend'],
      WebhookEvents::RECEIVE => ['onWebhookReceive'],
    ];
  }

}
