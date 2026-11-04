# Process Incoming Webhooks

Incoming webhook processing is implemented with a Symfony event subscriber.
The Webhooks module handles the HTTP endpoint, signature or token verification,
and payload decoding. Your custom module subscribes to `WebhookEvents::RECEIVE`
and updates local Drupal data from the verified payload.

This example receives order status updates from an external system and creates
or updates a custom Drupal content entity type named `acme_order`.

## Example payload

The sender posts JSON to `/webhook/acme_order_updates`:

```json
{
  "external_id": "ord_10001",
  "label": "Order 10001",
  "status": "paid",
  "total": "149.95"
}
```

In this example, the custom entity type has these fields:

| Field | Purpose |
|---|---|
| `remote_id` | Unique ID from the external system. |
| `name` | Entity label. |
| `remote_status` | Current status from the sender. |
| `remote_total` | Current total from the sender. |

Replace `acme_order` and the field names with your own entity type and fields.

## Create the subscriber

Create `src/EventSubscriber/OrderWebhookSubscriber.php` in your custom module:

```php
<?php

declare(strict_types=1);

namespace Drupal\acme_orders\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Processes incoming order webhooks.
 */
final class OrderWebhookSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an order webhook subscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WebhookEvents::RECEIVE => 'onWebhookReceive',
    ];
  }

  /**
   * Creates or updates an order entity from a verified webhook payload.
   *
   * @param \Drupal\webhooks\Event\ReceiveEvent $event
   *   The receive event.
   */
  public function onWebhookReceive(ReceiveEvent $event): void {
    $webhook_config = $event->getWebhookConfig();
    if ($webhook_config->id() !== 'acme_order_updates') {
      return;
    }

    $webhook = $event->getWebhook();
    $payload = $webhook->getPayload();

    try {
      $external_id = $this->requireString($payload, 'external_id');
      $label       = $this->requireString($payload, 'label');
      $status      = $this->requireString($payload, 'status');
      $total       = $payload['total'] ?? NULL;

      $storage = $this->entityTypeManager->getStorage('acme_order');
      $matches = $storage->loadByProperties([
        'remote_id' => $external_id,
      ]);

      $order = $matches ? reset($matches) : $storage->create([
        'remote_id' => $external_id,
      ]);

      $order->set('name', $label);
      $order->set('remote_status', $status);

      if ($total !== NULL) {
        $order->set('remote_total', $total);
      }

      $order->save();
    }
    catch (\Throwable $exception) {
      $this->loggerFactory
        ->get('acme_orders')
        ->error('Incoming order webhook failed: @message', [
          '@message' => $exception->getMessage(),
        ]);

      $webhook->setStatus(FALSE);
    }
  }

  /**
   * Gets a required string value from a webhook payload.
   *
   * @param array $payload
   *   The webhook payload.
   * @param string $key
   *   The required payload key.
   *
   * @return string
   *   The payload value.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the payload value is missing or empty.
   */
  private function requireString(array $payload, string $key): string {
    $value = $payload[$key] ?? NULL;
    if (!is_string($value) || $value === '') {
      throw new \UnexpectedValueException(sprintf(
        'Missing or invalid "%s" value.',
        $key,
      ));
    }

    return $value;
  }

}
```

!!! note "Queued retries"
    Calling `$webhook->setStatus(FALSE)` marks processing as failed. In
    non-blocking mode the queue worker throws `SuspendQueueException` so the
    item is retried on the next cron run instead of being deleted.

## Register the subscriber

Add the subscriber to `acme_orders.services.yml`. Use `autowire: true` so
Drupal resolves the constructor dependencies automatically by type hint:

```yaml
services:
  acme_orders.order_webhook_subscriber:
    class: Drupal\acme_orders\EventSubscriber\OrderWebhookSubscriber
    autowire: true
    tags:
      - { name: event_subscriber }
```

Clear caches after adding the service:

```bash
drush cr
```

## Configure the incoming endpoint

Create an incoming webhook configuration with:

| Setting | Value |
|---|---|
| **Machine name** | `acme_order_updates` |
| **Type** | `Incoming` |
| **Content Type** | `application/json` |
| **Secret** | Shared secret from the sender |
| **Signature header** | Header that carries the HMAC (default `X-Hub-Signature-256`, or `X-WC-Webhook-Signature` for WooCommerce) |
| **Signature algorithm** | Hash for raw HMAC values (default SHA-256) |
| **Non-blocking** | Checked for queued processing, unchecked for synchronous |
| **Active** | Checked |

The public endpoint becomes:

```text
POST /webhook/acme_order_updates
```

## Test the webhook

Build a signed test request using the same secret stored on the incoming
webhook config:

```bash
body='{"external_id":"ord_10001","label":"Order 10001","status":"paid","total":"149.95"}'
secret='change-me'
signature="sha256=$(printf '%s' "$body" \
  | openssl dgst -sha256 -hmac "$secret" -binary | xxd -p -c 256)"

curl -X POST 'https://example.com/webhook/acme_order_updates' \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $signature" \
  --data "$body"
```

A successful request returns `200 OK`. If the webhook is non-blocking, run
cron before checking whether the `acme_order` entity was created or updated:

```bash
drush cron
```

## Processing flow

1. The sender POSTs to `/webhook/acme_order_updates`.
2. `WebhookController` calls `webhooks.service:receive()`.
3. The service loads the active incoming `webhook_config` with machine name
   `acme_order_updates`. Returns `404` if none exists.
4. The posted body string is verified against the HMAC signature (WooCommerce:
   `trim(wp_json_encode($payload))`; Hub: exact bytes). Returns `401` on
   mismatch. Do not parse and re-encode JSON for HMAC.
5. The body is decoded into a `Webhook` value object.
6. The module dispatches `WebhookEvents::RECEIVE` immediately (blocking) or
   places the item in the `webhooks_dispatcher` queue (non-blocking).
7. `OrderWebhookSubscriber::onWebhookReceive()` creates or updates the entity.
