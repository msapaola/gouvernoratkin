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

  /**
   * Whether signature or token verification passed.
   *
   * @return bool
   *   TRUE if verification succeeded.
   */
  public function isVerified();

  /**
   * Sets whether verification passed.
   *
   * @param bool $verified
   *   TRUE if verification succeeded.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setVerified($verified);

  /**
   * Gets the verification error message, if any.
   *
   * @return string
   *   The error message, or an empty string.
   */
  public function getError();

  /**
   * Sets the verification error message.
   *
   * @param string $error
   *   The error message.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setError($error);

  /**
   * Gets the incoming webhook config machine name.
   *
   * @return string
   *   The webhook_config entity ID, or an empty string.
   */
  public function getWebhookConfigId();

  /**
   * Sets the incoming webhook config machine name.
   *
   * @param string $webhook_config_id
   *   The webhook_config entity ID.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setWebhookConfigId($webhook_config_id);

  /**
   * Gets the received HMAC signature.
   *
   * @return string
   *   The signature header value, or an empty string.
   */
  public function getSignature();

  /**
   * Sets the received HMAC signature.
   *
   * @param string $signature
   *   The signature header value.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setSignature($signature);

  /**
   * Gets the posted body length in bytes.
   *
   * @return int
   *   Byte length of the stored payload.
   */
  public function getPayloadLength();

  /**
   * Sets the posted body length in bytes.
   *
   * @param int $payload_length
   *   Byte length of the stored payload.
   *
   * @return \Drupal\webhook\WebhookInterface
   *   The called webhook entity.
   */
  public function setPayloadLength($payload_length);

}
