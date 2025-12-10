<?php

namespace Drupal\webhooks;

use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Webhook receivers catch incoming events and trigger an internal event.
 *
 * The internal event allows any module in the Drupal site to react to remote
 * operations.
 *
 * @package Drupal\webhooks
 */
interface WebhookSerializerInterface extends EncoderInterface, DecoderInterface {
}
