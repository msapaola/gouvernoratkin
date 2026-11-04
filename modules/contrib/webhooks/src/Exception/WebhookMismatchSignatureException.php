<?php

declare(strict_types=1);

namespace Drupal\webhooks\Exception;

/**
 * Class Webhook Mismatch Signature Exception.
 *
 * @package Drupal\webhooks\Exception
 */
class WebhookMismatchSignatureException extends \Exception {

  /**
   * Length of the raw payload that was hashed, in bytes.
   *
   * @var int
   */
  protected $payloadLength;

  /**
   * MismatchSignatureException constructor.
   *
   * @param string $signature_received
   *   The received signature. Never included in the exception message.
   * @param string $signature_generated
   *   The generated signature. Never included in the exception message.
   * @param int $payload_length
   *   Byte length of the raw payload. Never include the payload itself.
   * @param string $message
   *   Optional override for the exception message.
   */
  public function __construct($signature_received, $signature_generated, $payload_length = 0, $message = '') {
    $this->payloadLength = (int) $payload_length;
    if ($message === '') {
      $message = 'Webhook signature verification failed.';
    }
    parent::__construct($message);
  }

  /**
   * Get the hashed payload length in bytes.
   *
   * @return int
   *   The payload length.
   */
  public function getPayloadLength() {
    return $this->payloadLength;
  }

}
