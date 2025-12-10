<?php

namespace Drupal\webhooks;

use Drupal\webhooks\Entity\WebhookConfig;

/**
 * Webhook dispatchers control triggering outbound webhook events.
 *
 * @package Drupal\webhooks
 */
interface WebhookDispatcherInterface {

  /**
   * Load multiple WebhookConfigs by event.
   *
   * @param string $event
   *   An event string in the form of entity:entity_type:action,
   *   e.g. 'entity:user:create', 'entity:user:update' or 'entity:user:delete'.
   * @param string $type
   *   A type string, e.g. 'outgoing' or 'incoming'.
   *
   * @return \Drupal\webhooks\Entity\WebhookConfigInterface[]
   *   An array of WebhookConfig entities.
   */
  public function loadMultipleByEvent($event, $type = 'outgoing');

  /**
   * Trigger all webhook subscriptions associated with the given event.
   *
   * @param \Drupal\webhooks\Webhook $webhook
   *   The webhook object.
   * @param string $event
   *   Identifier of a particular webhook event, e.g. entity:node:create,
   *   entity:user:update or entity:taxonomy_term:delete.
   */
  public function triggerEvent(Webhook $webhook, $event);

  /**
   * Send a webhook.
   *
   * @param \Drupal\webhooks\Entity\WebhookConfig $webhook_config
   *   A webhook config entity.
   * @param \Drupal\webhooks\Webhook $webhook
   *   A webhook object.
   */
  public function send(WebhookConfig $webhook_config, Webhook $webhook);

}
