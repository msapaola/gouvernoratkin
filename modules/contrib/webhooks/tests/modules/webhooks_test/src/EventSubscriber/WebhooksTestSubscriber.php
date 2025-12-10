<?php

namespace Drupal\webhooks_test\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\State;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\SendEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Webhooks Test event subscriber.
 */
class WebhooksTestSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\State\State $state
   *   The object State.
   */
  public function __construct(MessengerInterface $messenger, State $state) {
    $this->messenger = $messenger;
    $this->state = $state;
  }

  /**
   * Webhook send event handler.
   *
   * @param \Drupal\webhooks\Event\SendEvent $event
   *   Response event.
   */
  public function onWebhookSend(SendEvent $event) {
    $this->state->set(__FUNCTION__, TRUE);

    $webhook = $event->getWebhook();
    $webhook_config = $event->getWebhookConfig();
    $this->state->set(__FUNCTION__ . '_webhook', $webhook);
    $this->state->set(__FUNCTION__ . '_webhook_config', $webhook_config);

    $this->messenger->addStatus(print_r($webhook_config, TRUE));
    $this->messenger->addStatus(print_r($webhook, TRUE));
  }

  /**
   * Webhook receive event handler.
   *
   * @param \Drupal\webhooks\Event\ReceiveEvent $event
   *   Response event.
   */
  public function onWebhookReceive(ReceiveEvent $event) {
    $this->state->set(__FUNCTION__, TRUE);

    $webhook = $event->getWebhook();
    $webhook_config = $event->getWebhookConfig();
    $this->state->set(__FUNCTION__ . '_webhook', $webhook);
    $this->state->set(__FUNCTION__ . '_webhook_config', $webhook_config);

    $this->messenger->addStatus(print_r($webhook_config, TRUE));
    $this->messenger->addStatus(print_r($webhook, TRUE));
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
