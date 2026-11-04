# Webhooks

The **Webhooks** module adds user-defined HTTP callbacks to Drupal 10 and 11.
It acts as both an outgoing webhook dispatcher and an incoming webhook receiver,
letting Drupal sites communicate with external services in real time.

## What the module does

| Direction | What happens |
|-----------|-------------|
| **Outgoing** | When a configured Drupal event fires — such as a node being saved or a user logging in — the module serializes the relevant data and POSTs it to a remote URL. |
| **Incoming** | External systems POST to a Drupal endpoint (`/webhook/{machine_name}`). The module verifies the request and dispatches a Symfony event so any other module can process the payload. |

Webhook configurations are config entities — they travel through Drupal's
standard configuration management workflow (import/export, Recipes, Features).

## Where to start

**Site builders** who want to configure webhooks through the Drupal admin UI
should start with the [Site Builder Guide](site-builder.md).

**Developers** who need to react to incoming payloads or trigger custom outgoing
events in code should start with the [Developer Guide](developer.md).

## Documentation sections

<div class="grid cards" markdown>

- **[Site Builder Guide](site-builder.md)**

    Install the module, grant permissions, and create outgoing and incoming
    webhook configurations through the administration UI.

- **[Incoming Webhooks](incoming-webhooks.md)**

    Configure incoming endpoints, understand signature and token verification,
    and choose between blocking and queued processing.

- **[Process Incoming Webhooks](process-incoming-webhooks.md)**

    Step-by-step walkthrough: build a Symfony event subscriber that processes
    a verified incoming payload and creates or updates a Drupal entity.

- **[Developer Guide](developer.md)**

    Service layer, events, payload objects, signatures, Drush commands, and
    the optional `webhook` storage submodule.

</div>

## Module packages

| Module | Purpose |
|--------|---------|
| `webhooks` | Core dispatcher/receiver. Provides the `webhook_config` config entity and `webhooks.service`. |
| `webhook` *(optional)* | Persists every received webhook as a content entity viewable in the Drupal admin UI. |

## Requirements

- Drupal 10 or 11
- Drupal core **Serialization** module (enabled by default in most installations)
