# Site Builder Guide

This guide covers everything a site builder needs to install the Webhooks module
and configure outgoing and incoming webhook endpoints through the Drupal
administration UI.

---

## Requirements

- Drupal 10 or 11
- Drupal core **Serialization** module (enabled by default in most installations)

---

## Installation

Install the module with Composer and enable it with Drush or the Extend UI:

```bash
composer require drupal/webhooks
drush en webhooks
```

To also persist received webhook payloads as content entities, enable the
optional submodule:

```bash
drush en webhook
```

---

## Permissions

| Permission | What it allows |
|---|---|
| `administer webhooks` | Create, edit, delete, and toggle all webhook configurations. |
| `access webhook overview` | *(webhook submodule)* View the list of stored incoming webhook records at `/admin/content/webhook`. |

Grant `administer webhooks` to trusted administrator roles only; it controls
access to secrets and target URLs.

---

## Administration UI

Navigate to **Administration › Configuration › Web services › Webhooks**
(`/admin/config/services/webhook`) to see the webhook configuration listing.

From the listing you can:

- Add a new webhook configuration.
- Toggle the active state of any existing webhook.
- Edit or delete existing configurations.

### Global settings

Visit `/admin/config/services/webhook/settings` to configure:

| Setting | Description |
|---|---|
| **Use a reliable queue for non-blocking webhooks** | When enabled, Drupal's reliable queue backend guarantees at-least-once delivery and preserves message order. When disabled, a best-effort queue is used. |

---

## Webhook configuration fields

Both outgoing and incoming webhooks share a common set of base fields.

| Field | Required | Description |
|---|---|---|
| **Label** | Yes | Human-readable name shown in the admin listing. |
| **Machine name** | Yes | Unique identifier; auto-generated from the label. Cannot be changed after creation. |
| **Type** | Yes | `Outgoing` or `Incoming`. Cannot be changed after creation. |
| **Content Type** | No | Serialization format for the payload body. |
| **Secret** | No | Shared HMAC secret. Usually supplied as a `settings.php` override of `webhooks.webhook.{id}` `secret` so it is not stored in the database or exported config. For WooCommerce this is the webhook Secret on that specific webhook, not the REST API consumer secret. See [Signature verification](incoming-webhooks.md#signature-verification). |
| **Signature header** | No | HTTP header that carries the HMAC. Use `X-WC-Webhook-Signature` for WooCommerce. Existing webhooks that already store a secret still default to `X-Hub-Signature-256` when the header is empty. |
| **Signature algorithm** | No | Hash used for raw (base64) HMAC values. Defaults to SHA-256. Hub-style `algorithm=hexdigest` values still use the prefix. |
| **Token** | No | Used for legacy token-based verification. |
| **Active** | Yes | When unchecked, the configuration is saved but ignored at runtime. |

---

## Outgoing webhooks

An outgoing webhook fires when a configured Drupal event occurs and POSTs the
serialized payload to a remote URL.

### Creating an outgoing webhook

1. Go to `/admin/config/services/webhook/add`.
2. Set **Type** to `Outgoing`.
3. Fill in the **Payload URL** — the remote endpoint that will receive the POST.
4. Choose a **Content Type** for the payload body (default: `application/json`).
5. Optionally enter a **Secret**. If provided, every request will include
   `X-Hub-Signature` (SHA-1) and `X-Hub-Signature-256` (SHA-256) headers so the
   receiver can verify the payload has not been tampered with.
6. Select one or more **Enabled Events** from the table.
7. Ensure **Active** is checked.
8. Save.

### Supported content types

| Value | Format |
|---|---|
| `application/json` | JSON (default) |
| `application/xml` | XML |
| `application/x-www-form-urlencoded` | URL-encoded form data |
| `application/yaml` | YAML |

### Built-in event types

#### Entity events

These fire for every Drupal content entity type installed on the site.
The event name pattern is `entity:{entity_type}:{action}`.

| Example event | When it fires |
|---|---|
| `entity:node:create` | A node is saved for the first time |
| `entity:node:update` | An existing node is saved |
| `entity:node:delete` | A node is deleted |
| `entity:user:create` | A user account is created |
| `entity:taxonomy_term:update` | A taxonomy term is updated |

Any content entity type registered with Drupal generates its own `create`,
`update`, and `delete` events automatically.

#### System events

| Event | When it fires |
|---|---|
| `system:cron` | Drupal cron runs |
| `system:cache_flush` | Caches are cleared |
| `system:file_download` | A managed file is downloaded |
| `system:modules_installed` | One or more modules are installed |
| `system:user_cancel` | A user account is cancelled |
| `system:user_login` | A user logs in |
| `system:user_logout` | A user logs out |

Developers can register custom events; see the [Developer Guide](developer.md).

### Payload structure

For entity events the JSON payload contains three top-level keys:

```json
{
  "event": "entity:node:create",
  "user": { "...normalized user fields..." },
  "entity": { "...normalized entity fields..." }
}
```

For system events the payload includes the `event` key plus relevant context
(for example, `uri` for `system:file_download` or `modules` for
`system:modules_installed`).

### Request headers

Every outgoing request includes the following custom headers:

| Header | Value |
|---|---|
| `Webhook-Delivery-Id` | UUID generated for this specific delivery |
| `X-Drupal-Delivery` | Deprecated alias containing the same delivery UUID |
| `Webhook-Event` | The event machine name, e.g. `entity:node:create` |
| `X-Drupal-Event` | Deprecated alias containing the same event type |
| `Content-Type` | The configured content type |
| `X-Hub-Signature` | `sha1=<HMAC-SHA1>` — only when a secret is configured |
| `X-Hub-Signature-256` | `sha256=<HMAC-SHA256>` — only when a secret is configured |

See [Webhook header migration](delivery-header.md) for the deprecated header,
incoming precedence and compatibility across branches.

---

## Incoming webhooks

An incoming webhook exposes a Drupal endpoint that external systems can POST to.
When a request arrives the module verifies it and dispatches a Symfony event so
other modules can process the payload.

!!! important "Processing requires a subscriber"
    Creating an incoming webhook config only makes Drupal receive and verify the
    request. A custom module must subscribe to `WebhookEvents::RECEIVE`
    (`webhook.receive`) to process the payload. Alternatively, enable the
    optional `webhook` submodule to store received payloads for review.

For endpoint setup, signature and token verification options, and queue
behavior, see [Incoming Webhooks](incoming-webhooks.md).

ECA can process receipts through its **Webhook received** event. See
[Incoming webhooks with ECA](eca.md) for setup, tokens and model compatibility.

---

## Viewing stored incoming webhooks (webhook submodule)

If the optional `webhook` submodule is enabled, inbound webhooks are
automatically saved as content entities, including requests that fail
signature or token verification. Failed requests still return `401`; the
saved entity is for debugging only. Open a record to see whether
verification passed and any error message.

These records can be viewed at
**Administration › Content › Webhooks** (`/admin/content/webhook`).

Each record stores:

- Title (generated from the delivery UUID)
- Incoming webhook configuration machine name
- Whether verification passed
- Verification error message (when verification failed)
- Raw headers (JSON-encoded)
- Raw payload (the posted body string, not a re-encoded array)
- Payload length in bytes
- Received HMAC signature (for re-running verification)
- Created timestamp

Failed verification is also written to the `webhooks` log channel with a
**View** link to the stored record. The log does not include the payload.
Copy the payload from the entity field or SQL, not from HTML that may
encode `&`. Compare SHA-256 or length to the HTTP body, not pretty JSON.

---

## Configuration management

Webhook configurations are config entities with the file prefix
`webhooks.webhook.*`. They export to and import from standard Drupal
configuration YAML, making them fully portable via `drush config:export` /
`config:import`, Features, or Drupal Recipes.

Example config file name: `webhooks.webhook.github_push.yml`

Keep HMAC secrets out of exported YAML. Leave `secret` empty on the
config entity and override it in `settings.php`:

```php
$config['webhooks.webhook.github_push']['secret'] = getenv('WEBHOOK_SECRET');
```

Saving the webhook form does not write that override into active
configuration.

---

## Logging and troubleshooting

The module logs all activity to the `webhooks` logger channel, visible at
**Administration › Reports › Recent log messages** with type `webhooks`.

| Log level | When it appears |
|---|---|
| `info` | A webhook was dispatched or received successfully |
| `notice` | An inbound request was stored as a `webhook` content entity (submodule) |
| `warning` | An incoming webhook was received but processing failed |
| `error` | An outgoing dispatch failed, or inbound signature/token verification failed |

### Common issues

**Dispatch failed errors for local-to-local requests**
: The service uses a very short timeout (0.1 s) when the payload URL resolves
  to the same host as the current request. This is intentional to avoid blocking
  the PHP request cycle. Use non-blocking mode for local testing.

**Signature mismatch on incoming webhooks**
: Verify that the secret stored in Drupal matches the secret configured on the
  sending system exactly, including case and whitespace. For WooCommerce,
  copy the Secret from that webhook (Settings → Advanced → Webhooks), not
  the REST API consumer secret. Confirm the **Signature header** matches the
  header the sender uses, and that **Signature algorithm** matches senders
  that send a raw HMAC (WooCommerce uses SHA-256 by default). If the
  received signature in the error is empty, the request did not include that
  header (or a proxy stripped it). Hub signatures use `algorithm=hexdigest`
  (for example `sha256=<hex>`) over the exact request body. WooCommerce
  sends a base64 HMAC of `trim(wp_json_encode($payload))` in
  `X-WC-Webhook-Signature`. Drupal hashes that posted string; parsing and
  re-encoding JSON usually fails verification. The 401 response does not
  include the payload; watchdog logs the payload length in bytes. If the
  `webhook` submodule is enabled, the request is stored as an unverified
  webhook entity at **Administration › Content › Webhooks** with the posted
  body, byte length, and received signature so verification can be replayed.
  The log entry includes a **View** link to that record.

  WooCommerce’s save-time activation ping is not a signed delivery. It posts
  `webhook_id=<id>` with no `X-WC-Webhook-*` headers and succeeds only on
  HTTP 200. That ping is accepted without HMAC; a later JSON delivery with
  no signature is still a 401.

**Incoming webhook returns 404**
: Confirm that an active incoming `webhook_config` entity exists with a machine
  name that exactly matches the URL path segment.

**Queue items not processed**
: Ensure cron is running. Enable the reliable queue setting if items are being
  dropped. Check the `webhooks` log channel for `SuspendQueueException` entries
  that indicate a processing failure.
