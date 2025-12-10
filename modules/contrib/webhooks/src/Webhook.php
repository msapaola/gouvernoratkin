<?php

namespace Drupal\webhooks;

use Drupal\Component\Uuid\Php as Uuid;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Exception\WebhookMismatchTokenException;

/**
 * Class Webhook .
 *
 * @package Drupal\webhooks
 */
class Webhook {

  /**
   * The webhooks headers.
   *
   * @var array
   */
  protected $headers;

  /**
   * The webhook payload.
   *
   * @var array
   */
  protected $payload;

  /**
   * The unique id.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The event.
   *
   * @var string
   */
  protected $event;

  /**
   * The content type.
   *
   * @var string
   */
  protected $contentType;

  /**
   * The secret.
   *
   * @var string
   */
  protected $secret;

  /**
   * Processing status.
   *
   * @var bool
   */
  protected $status;

  /**
   * Webhook constructor.
   *
   * @param array $payload
   *   The payload that is being send with the webhook.
   * @param array $headers
   *   The headers that are being send with the webhook.
   * @param string $event
   *   The event that is acted upon.
   * @param string $content_type
   *   The content type of the payload.
   */
  public function __construct(
    array $payload = [],
    array $headers = [],
    $event = 'default',
    $content_type = 'application/json',
  ) {
    $this->setPayload($payload);
    $this->setHeaders($headers);
    $this->setEvent($event);
    $this->setContentType($content_type);

    $uuid = new Uuid();
    $this->setUuid($uuid->generate());

    // Default to success.
    $this->setStatus(TRUE);
  }

  /**
   * Get the headers.
   *
   * @return array
   *   The headers array.
   */
  public function getHeaders() {
    return $this->headers;
  }

  /**
   * Set the headers.
   *
   * @param array $headers
   *   A headers array.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setHeaders(array $headers) {
    // RequestStack returns the Header-Value as an array.
    foreach ($headers as $key => $value) {
      if (is_array($value)) {
        $headers[$key] = reset($value);
      }
    }
    $this->headers = $headers;
    return $this;
  }

  /**
   * Add to the headers.
   *
   * @param array $headers
   *   A headers array.
   *
   * @return Webhook
   *   The webhook.
   */
  public function addHeaders(array $headers) {
    foreach ($headers as $name => $value) {
      $this->headers[strtolower($name)] = $value;
    }
    return $this;
  }

  /**
   * Get the payload.
   *
   * @return array
   *   The payload array.
   */
  public function getPayload() {
    return $this->payload;
  }

  /**
   * Set the payload.
   *
   * @param array $payload
   *   A payload array.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setPayload(array $payload) {
    $this->payload = $payload;
    return $this;
  }

  /**
   * Add to the payload.
   *
   * @param array $payload
   *   A payload array.
   *
   * @return Webhook
   *   The webhook.
   */
  public function addPayload(array $payload) {
    $this->payload = array_merge(
      $this->payload,
      $payload
    );
    $this->setSecret($this->secret);
    return $this;
  }

  /**
   * Get the unique id.
   *
   * @return string
   *   the uuid string.
   */
  public function getUuid() {
    return $this->uuid;
  }

  /**
   * Set the unique id.
   *
   * @param string $uuid
   *   A uuid string.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setUuid($uuid) {
    $this->uuid = $uuid;
    $this->addHeaders([
      'X-Drupal-Delivery' => $uuid,
    ]);
    return $this;
  }

  /**
   * Get the event.
   *
   * @return string
   *   The event string.
   */
  public function getEvent() {
    return $this->event;
  }

  /**
   * Set the event.
   *
   * @param string $event
   *   An event string in the form of entity:entity_type:action,
   *   e.g. 'entity:user:create', 'entity:user:update' or 'entity:user:delete'.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setEvent($event) {
    $this->event = $event;
    $this->addHeaders([
      'X-Drupal-Event' => $event,
    ]);
    return $this;
  }

  /**
   * Get the content type.
   *
   * @return string
   *   The content type string.
   */
  public function getContentType() {
    return $this->contentType;
  }

  /**
   * Get the content type.
   *
   * @return string
   *   The content type string.
   */
  public function getMimeType() {
    return explode('/', $this->contentType)[0];
  }

  /**
   * Get the content type.
   *
   * @return string
   *   The content type string.
   */
  public function getMimeSubType() {
    return explode('/', $this->contentType)[1];
  }

  /**
   * Set the content type.
   *
   * @param string $content_type
   *   A content type string, e.g. 'application/json' or 'application/xml'.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setContentType($content_type) {
    $this->contentType = $content_type;
    $this->addHeaders([
      'Content-Type' => $content_type,
    ]);
    return $this;
  }

  /**
   * Get the secret.
   *
   * @return string
   *   The secret string.
   */
  public function getSecret() {
    return $this->secret;
  }

  /**
   * Set the secret.
   *
   * @param string $secret
   *   A secret string.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setSecret($secret) {
    $this->secret = $secret;
    return $this;
  }

  /**
   * Retrieve the current Webhook status.
   *
   * @return bool
   *   TRUE indicates no errors, FALSE indicates an error occurred.
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * Set the status.
   *
   * @param bool $status
   *   New status value.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setStatus($status) {
    $this->status = $status;
    return $this;
  }

  /**
   * Get the payload signature from headers.
   *
   * @return string
   *   The signature string, e.g.
   *   sha1=de7c9b85b8b78aa6bc8a7a36f70a90701c9db4d9 or
   *   sha256=e7835683d84a9bf0e44befd318eaf78ad631a820c4a17a8b916f5f79bed30901
   */
  public function getSignature() {
    $headers = $this->getHeaders();
    $headers = array_change_key_case($headers, CASE_LOWER);
    // Use the more secure SHA256 hash if available.
    if (isset($headers['x-hub-signature-256'])) {
      return $headers['x-hub-signature-256'];
    }
    // Otherwise use the legacy SHA1 hash for backwards compatibility.
    elseif (isset($headers['x-hub-signature'])) {
      return $headers['x-hub-signature'];
    }
    return '';
  }

  /**
   * Get the secret token from headers.
   *
   * @return string
   *   The token string from the webhook header.
   */
  public function getToken() {
    foreach ($this->getHeaders() as $name => $value) {
      if (preg_match('/X-([a-z0-9]{1,})-Token/i', $name)) {
        return $value;
      }
    }
    return '';
  }

  /**
   * Set the payload signature.
   *
   * @param string $body
   *   The encoded request body.
   *
   * @return Webhook
   *   The webhook.
   */
  public function setSignature($body) {
    $this->addHeaders([
      // Add the SHA256 signature for improved security.
      'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, $this->secret, FALSE),
      // Keep the SHA1 signature for backwards compatibility.
      'X-Hub-Signature' => 'sha1=' . hash_hmac('sha1', $body, $this->secret, FALSE),
    ]);
    return $this;
  }

  /**
   * Verify the webhook with the stored secret.
   *
   * @param string $secret
   *   The webhook secret.
   * @param string $payload
   *   The raw webhook payload.
   * @param string $signature
   *   The webhook signature.
   *
   * @return bool
   *   Boolean TRUE for success.
   *
   * @throws \Drupal\webhooks\Exception\WebhookMismatchSignatureException
   *   Throws exception if signatures do not match.
   */
  public static function verify($secret, $payload, $signature) {
    [$algorithm, $user_string] = explode('=', $signature);
    $known_string = hash_hmac($algorithm, $payload, $secret);
    if (!hash_equals($known_string, $user_string)) {
      throw new WebhookMismatchSignatureException($user_string, $algorithm . '=' . $known_string, $payload);
    }
    return TRUE;
  }

  /**
   * Verify the webhook with the stored token.
   *
   * @param string $token
   *   The webhook token.
   * @param string $token_received
   *   The webhook token from the headers.
   *
   * @return bool
   *   Boolean TRUE for success.
   *
   * @throws \Drupal\webhooks\Exception\WebhookMismatchTokenException
   *   Throws exception if signatures do not match.
   */
  public static function verifyToken($token, $token_received) {
    if ($token !== $token_received) {
      throw new WebhookMismatchTokenException($token, $token_received);
    }
    return TRUE;
  }

}
