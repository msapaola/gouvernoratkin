# Webhook metadata header migration

The canonical metadata headers use the vendor-neutral `Webhook-` prefix.
3.0.x introduces the names it supports alongside deprecated `X-Drupal-`
aliases. 4.0.x emits only the neutral names and accepts legacy names on input.

| Meaning | Canonical name | Legacy incoming fallback |
| --- | --- | --- |
| Event type | `Webhook-Event` | `X-Drupal-Event` |
| Destination delivery UUID | `Webhook-Delivery-Id` | `X-Drupal-Delivery` |
| Event occurrence UUID (4.0.x) | `Webhook-Event-Id` | `X-Drupal-Event-Id` |
| HTTP attempt UUID (4.0.x) | `Webhook-Attempt-Id` | `X-Drupal-Attempt-Id` |

The previously proposed `X-Drupal-Delivery-Id` name was not published and is
superseded by `Webhook-Delivery-Id`; there is no intermediate migration step.

## Branch behavior

3.0.x sends both `Webhook-Event` and `X-Drupal-Event` with the same event type,
and both `Webhook-Delivery-Id` and `X-Drupal-Delivery` with the same delivery
UUID. This gives receivers a migration period while retaining existing
integrations. This change does not add event-occurrence or attempt UUIDs to
3.0.x.

4.0.x sends `Webhook-Event`, `Webhook-Event-Id`, `Webhook-Delivery-Id` and
`Webhook-Attempt-Id`. It does not send the corresponding legacy metadata
headers. Both canonical and legacy metadata names are removed from event
templates before destination requests are prepared, then synchronized with
the webhook object's identifiers after SEND listeners run.

## Incoming precedence

For each supported field, a non-empty canonical header takes precedence over
its legacy counterpart. Missing or empty canonical values fall back to the
legacy name. Conflicting values use the canonical value; the request is not
rejected because the two names differ. Header names are case-insensitive.

Without a supplied delivery ID, a UUID is generated as before. Event type and
optional identity defaults retain their existing behavior. Received metadata
is exposed through the canonical headers in the webhook object. 3.0.x also
exposes its legacy event/delivery aliases with matching values.

## Updating integrations

Update receivers to read canonical names first and retain the old names as
fallbacks while older senders remain. Update custom senders to use the neutral
names. Existing 3.0.x consumers can continue reading the legacy aliases while
they migrate.

Custom SEND subscribers should use `setEvent()`, `setUuid()` and, on 4.0.x,
`setEventUuid()`/`setAttemptUuid()` to update metadata. Reserved header values
are synchronized with those object properties before HTTP transport.

PHP method names, ECA's `uuid`, `event_uuid` and `attempt_uuid` tokens where
supported, payload/configuration keys and log context keys are unchanged.
ECA models that directly read legacy header names should move to canonical
header names or use the corresponding webhook metadata tokens.

## Authentication and retries

Authentication headers retain their existing protocol names. In particular,
`X-Hub-Signature` and `X-Hub-Signature-256` remain supported for existing HMAC
integrations; legacy token authentication is unchanged. The metadata rename
does not change signed body bytes or the authentication scheme.

3.0.x still generates a new delivery UUID for each `send()` call. In 4.0.x,
retries of a prepared delivery preserve its delivery UUID, destination, body
and signature, while `Webhook-Attempt-Id` changes per HTTP request.

[RFC 6648](https://www.rfc-editor.org/rfc/rfc6648.html#section-3) discourages
`X-` prefixes for newly defined protocol parameters. It does not require
existing third-party headers to be renamed. The `Webhook-` names here define
this module's metadata contract.
