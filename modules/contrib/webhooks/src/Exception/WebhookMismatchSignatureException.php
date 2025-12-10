<?php

namespace Drupal\webhooks\Exception;

/**
 * Class Webhook Mismatch Signature Exception.
 *
 * @package Drupal\webhooks\Exception
 */
class WebhookMismatchSignatureException extends \Exception {

  /**
   * MismatchSignatureException constructor.
   *
   * @param string $signature_received
   *   The received signature.
   * @param string $signature_generated
   *   The generated signature.
   * @param string $payload
   *   The webhook payload.
   */
  public function __construct($signature_received, $signature_generated, $payload = '') {
    $message = sprintf(
      'The received signature "%s" does not match the generated signature "%s". Payload: "%s"',
      $signature_received,
      $signature_generated,
      $payload
    );
    parent::__construct($message);
  }

}
