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
   *   The webhook token.
   * @param string $token_received
   *   The webhook token from the headers.
   */
  public function __construct($token, $token_received) {
    $message = sprintf(
      'The received token "%s" does not match the generated signature "%s".',
      $token,
      $token_received
    );
    parent::__construct($message);
  }

}
