<?php

namespace Drupal\webhooks\Encoder;

use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Webhooks form encoder.
 */
class FormEncoder implements EncoderInterface, DecoderInterface {

  public const FORMAT = 'form';

  public const ALTERNATIVE_FORMAT = 'x-www-form-urlencoded';

  /**
   * {@inheritdoc}
   */
  public function encode(mixed $data, string $format, array $context = []): string {
    return 'payload=' . urlencode(json_encode($data));
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format): bool {
    if (self::FORMAT === $format) {
      return TRUE;
    }
    if (self::ALTERNATIVE_FORMAT === $format) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function decode(string $data, string $format, array $context = []): mixed {
    return json_decode(urldecode(substr($data, strlen('payload='), strlen($data))), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding(string $format): bool {
    if (self::FORMAT === $format) {
      return TRUE;
    }
    if (self::ALTERNATIVE_FORMAT === $format) {
      return TRUE;
    }
    return FALSE;
  }

}
