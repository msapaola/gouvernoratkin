<?php

namespace Drupal\webhooks\Event;

use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Webhook;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class Send Event.
 *
 * @package Drupal\webhooks\Event
 */
class SendErrorEvent extends Event {

  /**
   * The webhook.
   *
   * @var \Drupal\webhooks\Webhook
   */
  protected $webhook;

  /**
   * The webhook configuration.
   *
   * @var \Drupal\webhooks\Entity\WebhookConfig
   */
  protected $webhookConfig;

  /**
   * The error.
   *
   * @var \Exception
   */
  protected $e;

  /**
   * SendEvent constructor.
   *
   * @param \Drupal\webhooks\Entity\WebhookConfig $webhook_config
   *   A webhook configuration entity.
   * @param \Drupal\webhooks\Webhook $webhook
   *   A webhook.
   * @param \Exception $e
   *   An error.
   */
  public function __construct(
    WebhookConfig $webhook_config,
    Webhook $webhook,
    \Exception $e,
  ) {
    $this->webhookConfig = $webhook_config;
    $this->webhook = $webhook;
    $this->e = $e;
  }

  /**
   * Get the webhooks.
   *
   * @return \Drupal\webhooks\Webhook
   *   A webhook.
   */
  public function getWebhook() {
    return $this->webhook;
  }

  /**
   * Get the webhook configuration.
   *
   * @return \Drupal\webhooks\Entity\WebhookConfig
   *   A webhook configuration.
   */
  public function getWebhookConfig() {
    return $this->webhookConfig;
  }

  /**
   * Get the error.
   *
   * @return \Exception
   *   An error.
   */
  public function getError() {
    return $this->e;
  }

}
