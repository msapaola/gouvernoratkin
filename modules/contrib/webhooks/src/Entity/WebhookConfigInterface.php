<?php

namespace Drupal\webhooks\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for defining Webhook entities.
 */
interface WebhookConfigInterface extends ConfigEntityInterface {

  /**
   * Get the id.
   *
   * @return string
   *   The identifier string.
   */
  public function getId();

  /**
   * Get the label.
   *
   * @return string
   *   The label string.
   */
  public function getLabel();

  /**
   * Get the payload URL.
   *
   * @return string
   *   The payload URL.
   */
  public function getPayloadUrl();

  /**
   * Get the events.
   *
   * @return string
   *   The events listening on.
   */
  public function getEvents();

  /**
   * Get the content type.
   *
   * @return string
   *   The content type string, eg. 'json', 'xml' or 'x-www-form-urlencoded'.
   */
  public function getContentType();

  /**
   * Get last usage time.
   *
   * @return int
   *   The last usage time.
   */
  public function getLastUsage();

  /**
   * Check last response.
   *
   * @return bool
   *   If the last response was ok true, otherwise false.
   */
  public function hasResponseOk();

  /**
   * Get the type of the referenced entity.
   *
   * @return string
   *   The type string.
   */
  public function getRefEntityType();

  /**
   * Get the id of the referenced entity.
   *
   * @return string
   *   The id string.
   */
  public function getRefEntityId();

  /**
   * Get the secret.
   *
   * @return string
   *   The secret string.
   */
  public function getSecret();

  /**
   * Get the signature header name.
   *
   * @return string
   *   The HTTP header that carries the HMAC signature.
   */
  public function getSignatureHeader();

  /**
   * Get the HMAC algorithm for unprefixed signatures.
   *
   * @return string
   *   The hash_hmac() algorithm name, e.g. sha256.
   */
  public function getSignatureAlgorithm();

  /**
   * Is non-blocking?
   *
   * @return bool
   *   Whether the webhook is non-blocking.
   */
  public function isNonBlocking();

}
