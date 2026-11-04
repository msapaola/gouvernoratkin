# Incoming webhooks with ECA

Enable ECA and your preferred ECA modeler to process incoming webhooks without
writing an event subscriber. ECA is an optional integration; Webhooks can run
without it. Install ECA 3 with Composer if it is not already available:

```sh
composer require 'drupal/eca:^3'
drush en eca
```

Create an active incoming webhook under **Configuration → Web services →
Webhooks**. In an ECA model, add **Webhook received** (`webhook_receive`) and
choose that webhook's configuration ID, or **All webhooks**. Connect conditions
and actions to process the incoming data. Save and enable the model.

The webhook selector identifies the incoming endpoint. It does not select an
outgoing event or a node content type.

## Authentication and processing

Webhooks checks configured HMAC signatures or legacy tokens before ECA runs.
Requests that fail those checks do not execute the receive model. An endpoint
with no authentication configured can also execute a model; receiving an event
alone is not proof that the request was signed.

With non-blocking processing disabled, ECA runs during the incoming request.
With it enabled, the request is verified and queued first; ECA runs when the
`webhooks_dispatcher` queue worker processes it, normally through cron. The
raw body and payload are retained in the queued receipt. WooCommerce activation
pings deliberately do not execute receive models.

## Tokens

Tokens are available beneath `[event:webhook:…]`:

| Token | Value |
| --- | --- |
| `[event:webhook:id]` | Incoming webhook configuration ID |
| `[event:webhook:uuid]` | Delivery UUID |
| `[event:webhook:payload]` | Raw request body; JSON fallback for synthetic events |
| `[event:webhook:raw_body]` | Exact request body, before decoding; empty if no body was received |
| `[event:webhook:event_name]` | Sender's event name, if provided |
| `[event:webhook:content_type]` | Incoming content type |
| `[event:webhook:status]` | Processing status |
| `[event:webhook:headers:x-example]` | Received header value, using its lowercase name |
| `[event:webhook:config:label]` | Incoming webhook label |
| `[event:webhook:config:secret]` | Boolean indicating a configured signing secret |
| `[event:webhook:config:token]` | Boolean indicating a configured legacy token |
| `[event:webhook:verified]` | Legacy receipt flag; TRUE also for unsigned webhooks |

The 3.0.x payload token retains its existing string contract. Parse that body
with ECA actions when you need individual JSON properties. Header values may
be arrays.

The configuration flags do not contain secret values. Header and payload
tokens contain received data. `raw_body` preserves whitespace, escaping and
other byte differences that would be lost by decoding and encoding JSON again.

## Existing models and configuration

The plugin supplies schema for `eca.event.plugin.webhook_receive`, including
`webhook_id` and the legacy `verify_payload` boolean. Existing model
configuration with `verify_payload: false` can still be imported and saved.
That setting has no effect on authentication; configured checks always run
before the event is dispatched.

The legacy **Verify payload** checkbox is displayed as checked and disabled
to reflect the receiver behavior; it cannot bypass configured authentication.
Its previously saved value is retained when editing a model. The legacy
`verified` token remains TRUE for compatibility and must not be used to decide
whether cryptographic authentication occurred.

Token metadata now describes the supported `[event:webhook:…]` paths. A
standalone `[webhook:…]` root is not provided.

After updating the module, rebuild Drupal's caches so ECA's plugin manager and
token browser see the schema and token metadata changes. No data update is
needed for existing model configuration.

## Outgoing workflows

This plugin handles incoming receipts. It does not provide an ECA action to
send a configured outgoing Webhooks subscription or ECA events for send/error
notifications. Those are separate extensions. ECA's own HTTP request action
can send requests directly, but does not automatically use a Webhooks
subscription's signing or delivery identities.
