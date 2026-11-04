<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webhooks\Webhook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests canonical delivery headers and deprecated aliases.
 */
#[Group('webhooks')]
#[CoversClass(Webhook::class)]
class DeliveryHeaderTest extends UnitTestCase {

  /**
   * Tests constructor and UUID updates remove stale delivery header variants.
   */
  #[DataProvider('headerVariants')]
  public function testDeliveryHeadersFollowUuid(array $headers): void {
    $webhook = new Webhook([], $headers + ['X-Trace' => 'keep']);
    $this->assertDeliveryHeaders($webhook);
    $webhook->setHeaders($headers + ['X-Trace' => 'keep']);
    $webhook->setUuid('first-delivery');
    $this->assertDeliveryHeaders($webhook);
    $this->assertSame('keep', $webhook->getHeaders()['X-Trace']);
    $webhook->setUuid('second-delivery');
    $this->assertDeliveryHeaders($webhook);
    $this->assertSame('second-delivery', $webhook->getUuid());
  }

  /**
   * Tests event type setters synchronize canonical and legacy header names.
   */
  public function testEventHeadersFollowEventType(): void {
    $webhook = new Webhook([], [
      'wEbHoOk-EvEnT' => 'stale-new',
      'X-DRUPAL-EVENT' => 'stale-old',
    ], 'example.created');
    $webhook->setEvent('example.updated');
    $headers = array_filter($webhook->getHeaders(), static fn(string $name): bool => in_array(strtolower($name), [
      'webhook-event',
      'x-drupal-event',
    ], TRUE), ARRAY_FILTER_USE_KEY);
    $this->assertSame([
      'webhook-event' => 'example.updated',
      'x-drupal-event' => 'example.updated',
    ], $headers);
  }

  /**
   * Header names supplied by callers, including conflicting case variants.
   *
   * @return array<string, array{array<string, string>}>
   *   Header fixtures.
   */
  public static function headerVariants(): array {
    return [
      'none' => [[]],
      'old uppercase' => [['X-Drupal-Delivery' => 'old']],
      'old lowercase' => [['x-drupal-delivery' => 'old']],
      'new uppercase' => [['Webhook-Delivery-Id' => 'new']],
      'new lowercase' => [['webhook-delivery-id' => 'new']],
      'mixed conflicts' => [[
        'X-Drupal-Delivery' => 'old',
        'x-drupal-delivery' => 'other-old',
        'wEbHoOk-Delivery-Id' => 'new',
        'webhook-delivery-id' => 'other-new',
      ],
      ],
    ];
  }

  /**
   * Checks that the delivery header map agrees with the object metadata.
   *
   * @param \Drupal\webhooks\Webhook $webhook
   *   The webhook under test.
   */
  private function assertDeliveryHeaders(Webhook $webhook): void {
    $headers = array_filter($webhook->getHeaders(), static fn(string $name): bool => in_array(strtolower($name), [
      'webhook-delivery-id',
      'x-drupal-delivery',
    ], TRUE), ARRAY_FILTER_USE_KEY);
    $this->assertSame([
      'webhook-delivery-id' => $webhook->getUuid(),
      'x-drupal-delivery' => $webhook->getUuid(),
    ], $headers);
  }

}
