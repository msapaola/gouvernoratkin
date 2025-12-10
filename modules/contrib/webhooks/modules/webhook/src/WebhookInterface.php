<?php

namespace Drupal\webhook;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface defining a webhook entity type.
 */
interface WebhookInterface extends ContentEntityInterface {

  /**
   * Gets the webhook title.
   *
   * @return string
   *   Title of the webhook.
   */
  public function getTitle();

  /**
   * Sets the webhook title.
   *
   * @param string $title
   *   The webhook title.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setTitle($title);

  /**
   * Gets the webhook creation timestamp.
   *
   * @return int
   *   Creation timestamp of the webhook.
   */
  public function getCreatedTime();

  /**
   * Sets the webhook creation timestamp.
   *
   * @param int $timestamp
   *   The webhook creation timestamp.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setCreatedTime($timestamp);

}
