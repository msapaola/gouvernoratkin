<?php

namespace Drupal\webhooks\Plugin\ECA\Event;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\Token;
use Drupal\eca\Entity\Objects\EcaEvent;
use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Plugin implementation of the ECA Events for Webhooks.
 *
 * @EcaEvent(
 *   id = "webhook_receive",
 *   event_name = "webhook.receive",
 *   event_class = "Drupal\webhooks\Event\ReceiveEvent",
 *   subscriber_priority = 0,
 *   label = @Translation("Webhook received"),
 *   description = @Translation("Fires when an incoming webhook passes its configured authentication checks."),
 *   eca_version_introduced = "2.0.0"
 * )
 */
class WebhookEvent extends EventBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function definitions(): array {
    return [
      'webhook_receive' => [
        'label' => 'Webhook received',
        'event_name' => WebhookEvents::RECEIVE,
        'event_class' => ReceiveEvent::class,
        'description' => new TranslatableMarkup('Fires when an incoming webhook passes its configured authentication checks.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function generateWildcard(string $eca_config_id, EcaEvent $ecaEvent): string {
    $configuration = $ecaEvent->getConfiguration();
    return empty($configuration['webhook_id']) || $configuration['webhook_id'] === '*' ? '*' : $configuration['webhook_id'];
  }

  /**
   * {@inheritdoc}
   */
  public static function appliesForWildcard(Event $event, string $event_name, string $wildcard): bool {
    if (!$event instanceof ReceiveEvent) {
      return FALSE;
    }
    return $wildcard === '*' || $event->getWebhookConfig()->id() === $wildcard;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'webhook_id' => '*',
      'verify_payload' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    // Build options for webhook selection.
    $options = ['*' => $this->t('All webhooks')];

    try {
      $webhook_storage = $this->entityTypeManager->getStorage('webhook_config');
      $webhook_configs = $webhook_storage->loadByProperties(['type' => 'incoming']);

      foreach ($webhook_configs as $webhook_config) {
        $options[$webhook_config->id()] = $webhook_config->label();
      }
    }
    catch (\Exception $e) {
      // If there's an error loading webhooks, just show the "All" option.
    }

    $form['webhook_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Webhook'),
      '#default_value' => $this->configuration['webhook_id'] ?: '*',
      '#options' => $options,
      '#description' => $this->t('Select which webhook to respond to, or "All webhooks" to respond to any incoming webhook.'),
      '#required' => TRUE,
    ];

    $form['verify_payload'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify payload'),
      '#default_value' => TRUE,
      '#disabled' => TRUE,
      '#description' => $this->t('Configured signatures and tokens are always checked before this event runs. This legacy setting has no effect.'),
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['webhook_id'] = $form_state->getValue('webhook_id');
    // Keep existing model configuration; authentication is enforced upstream.
  }

  /**
   * Build webhook data for tokens.
   *
   * @return array
   *   The webhook event data.
   */
  protected function buildWebhookData(): array {
    $event = $this->getEvent();
    if (!($event instanceof ReceiveEvent)) {
      return [];
    }

    $webhook = $event->getWebhook();
    $config = $event->getWebhookConfig();

    // Signature and token checks already ran in
    // WebhooksService::receive() before this event was dispatched.
    $verified = TRUE;
    $body = $webhook->getBody();
    $payload = $body !== ''
      ? $body
      : json_encode($webhook->getPayload());

    return [
      'id' => $config->id(),
      'uuid' => $webhook->getUuid(),
      'payload' => $payload,
      'raw_body' => $body,
      'event_name' => $webhook->getEvent(),
      'content_type' => $webhook->getContentType(),
      'status' => $webhook->getStatus(),
      'verified' => $verified,
      'config' => [
        'label' => $config->label(),
        'id' => $config->id(),
        'secret' => !empty($config->getSecret()),
        'token' => !empty($config->getToken()),
        'payload_url' => $config->getPayloadUrl(),
      ],
      'headers' => $webhook->getHeaders(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  #[Token(
    name: 'event',
    description: 'The event.',
    properties: [
      new Token(
        name: 'machine_name',
        description: 'The machine name of the ECA event.',
      ),
      new Token(
        name: 'webhook',
        description: 'The received webhook.',
        properties: [
          new Token(
            name: 'id',
            description: 'The webhook configuration ID.',
          ),
          new Token(
            name: 'uuid',
            description: 'The webhook delivery UUID.',
          ),
          new Token(
            name: 'payload',
            description: 'The posted request body, with JSON fallback for synthetic events.',
          ),
          new Token(
            name: 'raw_body',
            description: 'The exact request body before decoding.',
          ),
          new Token(
            name: 'event_name',
            description: 'The webhook event name.',
          ),
          new Token(
            name: 'content_type',
            description: 'The webhook content type.',
          ),
          new Token(
            name: 'status',
            description: 'The webhook processing status.',
          ),
          new Token(
            name: 'verified',
            description: 'Legacy receipt flag; TRUE also for unsigned webhooks.',
          ),
          new Token(
            name: 'config',
            description: 'The webhook configuration.',
            properties: [
              new Token(
                name: 'label',
                description: 'The webhook configuration label.',
              ),
              new Token(
                name: 'id',
                description: 'The webhook configuration ID.',
              ),
              new Token(
                name: 'secret',
                description: 'Whether a secret is configured.',
              ),
              new Token(
                name: 'token',
                description: 'Whether a token is configured.',
              ),
              new Token(
                name: 'payload_url',
                description: 'The webhook payload URL.',
              ),
            ],
          ),
          new Token(
            name: 'headers',
            description: 'The webhook headers.',
            properties: [
              new Token(
                name: '*',
                description: 'Individual header values by header name.',
              ),
            ],
          ),
        ],
      ),
    ],
  )]
  protected function buildEventData(): array {
    return [
      'machine_name' => $this->eventName(),
      'webhook' => $this->buildWebhookData(),
    ];
  }

}
