<?php

namespace Drupal\webhooks\Exception;

/**
 * Class Webhook Mismatch Token Exception.
 *
 * @package Drupal\webhooks\Exception
 */
class WebhookMismatchTokenException extends \Exception {

  /**
   * MismatchSignatureException constructor.
   *
   * @param string $token
   *   The configured token. Accepted for backwards compatibility, never logged.
   * @param string $token_received
   *   The supplied token. Accepted for backwards compatibility, never logged.
   */
  public function __construct($token, $token_received) {
    parent::__construct('Webhook token verification failed.');
  }

}
