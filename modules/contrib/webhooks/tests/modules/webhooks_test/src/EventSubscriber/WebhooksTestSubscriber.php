<?php

namespace Drupal\webhooks_test\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\State;
use Drupal\webhooks\Event\ReceiveErrorEvent;
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

    $webhook        = $event->getWebhook();
    $webhook_config = $event->getWebhookConfig();
    $this->state->set(__FUNCTION__ . '_webhook', $webhook);
    $this->state->set(__FUNCTION__ . '_webhook_config', $webhook_config);

    // Inject a custom header during the send event to prove that headers
    // added by event subscribers are included in the outgoing HTTP request.
    $webhook->addHeaders(['X-Custom-Test-Header' => 'custom-test-value']);

    $this->messenger->addStatus(print_r($webhook_config, TRUE));
    $this->messenger->addStatus(print_r($webhook, TRUE));
  }

  /**
   * Webhook receive event handler.
   *
   * When the state key 'webhooks_test_force_failure' is TRUE this handler
   * marks the webhook as failed so that kernel tests can exercise the
   * warning-log branch in WebhooksService::receive().
   *
   * @param \Drupal\webhooks\Event\ReceiveEvent $event
   *   Response event.
   */
  public function onWebhookReceive(ReceiveEvent $event) {
    $this->state->set(__FUNCTION__, TRUE);

    $webhook        = $event->getWebhook();
    $webhook_config = $event->getWebhookConfig();
    $this->state->set(__FUNCTION__ . '_webhook', $webhook);
    $this->state->set(__FUNCTION__ . '_webhook_config', $webhook_config);

    if ($this->state->get('webhooks_test_force_failure')) {
      $webhook->setStatus(FALSE);
    }

    $this->messenger->addStatus(print_r($webhook_config, TRUE));
    $this->messenger->addStatus(print_r($webhook, TRUE));
  }

  /**
   * Webhook receive-error event handler.
   *
   * @param \Drupal\webhooks\Event\ReceiveErrorEvent $event
   *   Receive error event.
   */
  public function onWebhookReceiveError(ReceiveErrorEvent $event) {
    $this->state->set(__FUNCTION__, TRUE);
    $this->state->set(__FUNCTION__ . '_webhook', $event->getWebhook());
    $this->state->set(__FUNCTION__ . '_webhook_config', $event->getWebhookConfig());
    $this->state->set(__FUNCTION__ . '_error', $event->getError());
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

}
