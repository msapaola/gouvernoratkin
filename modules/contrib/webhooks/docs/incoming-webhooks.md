# Incoming Webhooks

Incoming webhooks expose Drupal endpoints that external systems can POST to.
When a request arrives, the module loads the matching active incoming
`webhook_config`, verifies the signature or token, decodes the payload, and
dispatches a Symfony event for custom processing.

## Create an incoming webhook

1. Go to `/admin/config/services/webhook/add`.
2. Set **Type** to `Incoming`.
3. Enter a **Label** and note the generated **Machine name**. This becomes the
   URL path segment.
4. Choose the payload **Content Type**. Use `application/json` unless the
   sending system requires another supported format.
5. Enter a **Secret** when the sender can sign requests with HMAC. Use
   **Token** only for legacy systems that cannot send HMAC signatures.
6. Enter the **Signature header** the sender uses (default
   `X-Hub-Signature-256`, or `X-WC-Webhook-Signature` for WooCommerce).
7. Choose the **Signature algorithm** the sender uses (default SHA-256)
   when the header value is a raw HMAC rather than `algorithm=hexdigest`.
8. Check **Non-blocking** to queue the payload instead of processing it during
   the HTTP request.
9. Ensure **Active** is checked.
10. Save.

!!! important "Processing requires custom code"
    Creating an incoming webhook config only makes Drupal receive and verify the
    request. To act on the payload, write an event subscriber for
    `WebhookEvents::RECEIVE`. See
    [Process Incoming Webhooks](process-incoming-webhooks.md).

## Endpoint URL

The incoming webhook endpoint is:

```text
POST /webhook/{machine_name}
```

For example, a webhook with the machine name `acme_order_updates` listens at:

```text
POST /webhook/acme_order_updates
```

The module only accepts the request when an active incoming `webhook_config`
entity exists with a machine name that exactly matches the path segment.
Requests to unknown machine names return `404`.

## Signature verification

When a **Secret** is set on the `webhook_config`, the inbound request must
include an HMAC in the configured **Signature header**. Existing webhooks
that already had a secret are updated to `X-Hub-Signature-256`.

Two signature formats are accepted:

- **Hub-style** `algorithm=hexdigest`, for example
  `X-Hub-Signature-256: sha256=<HMAC-SHA256>` (the default) or
  `X-Hub-Signature: sha1=<HMAC-SHA1>`. The algorithm is taken from the
  prefix.
- **Raw base64 HMAC**, for example WooCommerce
  `X-WC-Webhook-Signature: <base64>`. Set **Signature header** to
  `X-WC-Webhook-Signature` and **Signature algorithm** to SHA-256
  (WooCommerce’s default unless a filter changes it). WooCommerce signs
  the exact posted string `trim(wp_json_encode($payload))`. Drupal HMACs
  that same body (`trim()` of `php://input`); do not parse JSON and
  re-encode it first. Use the **webhook Secret** from that WooCommerce
  webhook (WooCommerce → Settings → Advanced → Webhooks), not the REST
  API consumer secret. Secrets that contain HTML entities (`&`, `'`, `"`)
  are decoded the same way WordPress does (`ENT_QUOTES`) before HMAC.

The module reads the configured header first. If that header is a Hub
signature header and is missing, it falls back to `X-Hub-Signature-256`
then `X-Hub-Signature`. If the values do not match, or the header is
missing, the endpoint returns `401 Unauthorized`. When the optional
`webhook` submodule is enabled, the request is still stored as an
unverified webhook entity for debugging. The stored `payload` is the
posted body (the hashed string), with `signature` and `payload_length`
for replay. Compare SHA-256 or byte length of that field to the HTTP
body; do not compare pretty-printed JSON or `json_encode()` of a decoded
array. The `webhooks` log channel records the mismatch (payload length
only) and a **View** link to the stored record.

Verification also runs when the incoming request itself carries a signature
in the configured header even if no secret is stored on the config, so
configure the secret on both sides.

WooCommerce sends an activation ping when the webhook is saved:
`application/x-www-form-urlencoded` body `webhook_id=<id>` and no
`X-WC-Webhook-*` headers. That request returns **200** without HMAC
verification and does not dispatch `WebhookEvents::RECEIVE`. A 401, 400,
or 202 fails the ping in wp-admin. Real deliveries always include
`X-WC-Webhook-Signature` and are still verified; a JSON body with no
signature still returns **401**.

## Token verification

When no secret is set but a **Token** is configured, the module scans request
headers for any header whose name matches the pattern `X-{Name}-Token` and
compares its value to the stored token. This is less secure than HMAC
signatures and should only be used for senders that cannot sign requests.

## Blocking and non-blocking processing

**Blocking (default):** `WebhookEvents::RECEIVE` subscribers run during the
HTTP request. Keep subscribers fast so the sender receives a timely `200 OK`.

**Non-blocking:** The request is accepted immediately and the decoded `Webhook`
object is placed in the `webhooks_dispatcher` queue. Drupal dispatches
`WebhookEvents::RECEIVE` later when cron processes the queue (up to 60 seconds
of processing per cron invocation). Use this mode for slow work such as updating
several entities, calling external APIs, or triggering downstream jobs.

Enable **Use a reliable queue for non-blocking webhooks** at
`/admin/config/services/webhook/settings` when at-least-once delivery and
message ordering are required. This setting only affects new queue items;
existing items continue with the previously configured backend.
