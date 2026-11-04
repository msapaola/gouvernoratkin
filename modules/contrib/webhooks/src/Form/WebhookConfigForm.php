<?php

namespace Drupal\webhooks\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\WebhooksService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Webhook Config Form.
 *
 * @package Drupal\webhooks\Form
 */
final class WebhookConfigForm extends EntityForm {

  /**
   * Webhook service.
   *
   * @var \Drupal\webhooks\WebhooksService
   */
  protected $webhookService;

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The events.
   *
   * @var array
   */
  protected $events = [];

  /**
   * The entity hooks.
   *
   * @var string[]
   */
  protected $entityHooks = [
    'create',
    'update',
    'delete',
  ];

  /**
   * The system hooks.
   *
   * @var string[]
   */
  protected $systemHooks = [
    'cron',
    'file_download',
    'modules_installed',
    'user_cancel',
    'user_login',
    'user_logout',
    'cache_flush',
  ];

  /**
   * Webhook Config Form constructor.
   *
   * @param \Drupal\webhooks\WebhooksService $webhookService
   *   The webhook service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    WebhooksService $webhookService,
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->webhookService = $webhookService;
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('webhooks.service'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $webhook_config->label(),
      '#description' => $this->t('Easily recognizable name for your webhook.'),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $webhook_config->id(),
      '#machine_name' => [
        'exists' => '\Drupal\webhooks\Entity\WebhookConfig::load',
      ],
      '#disabled' => !$webhook_config->isNew(),
    ];
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => [
        'incoming' => $this->t('Incoming'),
        'outgoing' => $this->t('Outgoing'),
      ],
      '#default_value' => $webhook_config->getType() ? $webhook_config->getType() : 'outgoing',
      '#description' => $this->t('The type of webhook. <strong>Incoming webhooks</strong> receive HTTP events. <strong>Outgoing webhooks</strong> post new events to the configured URL.'),
      '#required' => TRUE,
      '#disabled' => !$webhook_config->isNew(),
    ];
    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t("Content Type"),
      '#description' => $this->t("The Content Type of your webhook."),
      '#options' => [
        WebhookConfig::CONTENT_TYPE_JSON => $this->t('application/json'),
        WebhookConfig::CONTENT_TYPE_XML => $this->t('application/xml'),
        WebhookConfig::CONTENT_TYPE_WWW_FORM_URLENCODED => $this->t('application/x-www-form-urlencoded'),
        WebhookConfig::CONTENT_TYPE_YAML => $this->t('application/yaml'),
      ],
      '#default_value' => $webhook_config->getContentType(),
    ];

    $stored_secret = $this->getStoredSecret();
    $secret_overridden = $this->isSecretOverridden();
    $form['secret'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'placeholder' => $this->t('Secret'),
      ],
      '#title' => $this->t('Secret'),
      '#maxlength' => 255,
      '#description' => $secret_overridden
        ? $this->t('This secret is provided by a configuration override and is not stored in the database.')
        : $this->t('Optional. Leave empty to supply the secret as a settings.php override of %name. For <strong>incoming webhooks</strong> this secret is provided by the remote website. For <strong>outgoing webhooks</strong> this secret should be used for the incoming hook configuration on the remote website.', [
          '%name' => 'webhooks.webhook.' . ($webhook_config->id() ?: 'MACHINE_NAME'),
        ]),
      '#default_value' => $stored_secret,
      '#disabled' => $secret_overridden,
    ];
    $signature_header = $webhook_config->getSignatureHeader();
    if ($signature_header === '' && $stored_secret !== '') {
      $signature_header = WebhookConfig::DEFAULT_SIGNATURE_HEADER;
    }
    $form['signature_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signature header'),
      '#maxlength' => 255,
      '#description' => $this->t('HTTP header that holds the HMAC generated with the secret (for example %hub or %woo).', [
        '%hub' => WebhookConfig::DEFAULT_SIGNATURE_HEADER,
        '%woo' => 'X-WC-Webhook-Signature',
      ]),
      '#default_value' => $signature_header,
    ];
    $form['signature_algorithm'] = [
      '#type' => 'select',
      '#title' => $this->t('Signature algorithm'),
      '#options' => [
        'sha256' => $this->t('SHA-256'),
        'sha1' => $this->t('SHA-1'),
        'sha384' => $this->t('SHA-384'),
        'sha512' => $this->t('SHA-512'),
      ],
      '#description' => $this->t(
        'Hash used when the signature is a raw HMAC (for example WooCommerce). Hub-style values such as %example still take the algorithm from the header. WooCommerce defaults to SHA-256 unless a filter changes it.',
        [
          '%example' => 'sha256=<hex>',
        ]
      ),
      '#default_value' => $webhook_config->getSignatureAlgorithm(),
    ];
    $form['token'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'placeholder' => $this->t('Token'),
      ],
      '#title' => $this->t('Token'),
      '#maxlength' => 255,
      '#description' => $this->t('For <strong>incoming webhooks</strong> this secret is provided by the remote website. For <strong>outgoing webhooks</strong> this secret should be used for the incoming hook configuration on the remote website.'),
      '#default_value' => $webhook_config->getToken(),
    ];
    $form['incoming'] = [
      '#title' => $this->t('Incoming Webhook Settings'),
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#states' => [
        'expanded' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
        'enabled' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
        'required' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
        'collapsed' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
        'disabled' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
        'optional' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
      ],
    ];
    $form['incoming']['non_blocking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Non-blocking'),
      '#default_value' => $webhook_config->isNonBlocking(),
      '#description' => $this->t('Non-blocking <strong>incoming webhooks</strong> are stored in a queue for later processing.'),
    ];
    $form['outgoing'] = [
      '#title' => $this->t('Outgoing Webhook Settings'),
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#states' => [
        'expanded' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
        'enabled' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
        'required' => [
          ':input[name="type"]' => ['value' => 'outgoing'],
        ],
        'collapsed' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
        'disabled' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
        'optional' => [
          ':input[name="type"]' => ['value' => 'incoming'],
        ],
      ],
    ];
    $form['outgoing']['payload_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Payload URL'),
      '#attributes' => [
        'placeholder' => $this->t('http://example.com/post'),
      ],
      '#default_value' => $webhook_config->getPayloadUrl(),
      '#maxlength' => 255,
      '#description' => $this->t('Target URL for your payload. Only used on <strong>outgoing webhooks</strong>.'),
    ];
    $form['outgoing']['events'] = [
      '#title' => $this->t('Enabled Events'),
      '#type' => 'tableselect',
      '#header' => [
        'type' => $this->t('Hook / Event'),
        'event' => $this->t('Machine name'),
      ],
      '#description' => $this->t("The Events you want to send to the endpoint."),
      '#options' => $this->eventOptions(),
      '#default_value' => $webhook_config->isNew() ? [] : $webhook_config->getEvents(),
    ];
    if ($webhook_config->getType() === 'incoming') {
      unset($form['outgoing']);
    }
    if ($webhook_config->getType() === 'outgoing') {
      unset($form['incoming']);
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Active"),
      '#description' => $this->t("Shows if the webhook is active or not."),
      '#default_value' => $webhook_config->isNew() ? TRUE : $webhook_config->status(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('type') == 'incoming') {
      // payload_url is required but not used on incoming webhooks.
      // Skipping the value entirely could break data model assumptions.
      $form_state->setValue('payload_url', 'http://example.com/webhook');
    }
    elseif ($form_state->isValueEmpty('payload_url')) {
      $form_state->setErrorByName('payload_url', $this->t('Outgoing webhooks require a Payload URL'));
    }

    if ($form_state->getValue('type') == 'outgoing' && $this->isEmptyList($form_state->getValue('events'))) {
      $form_state->setErrorByName('events', $this->t('Outgoing webhooks require one or more events to operate.'));
    }

    $form_state->setValue(
      'secret',
      trim((string) $form_state->getValue('secret'))
    );
    $form_state->setValue(
      'signature_header',
      trim((string) $form_state->getValue('signature_header'))
    );
    $form_state->setValue(
      'signature_algorithm',
      strtolower(trim((string) $form_state->getValue('signature_algorithm')))
    );
    $allowed_algorithms = ['sha256', 'sha1', 'sha384', 'sha512'];
    $algorithm = $form_state->getValue('signature_algorithm');
    if ($algorithm !== '' && !in_array($algorithm, $allowed_algorithms, TRUE)) {
      $form_state->setErrorByName(
        'signature_algorithm',
        $this->t('Select a valid signature algorithm.')
      );
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = $this->entity;
    // Keep the stored secret when the field is empty or overridden so a
    // settings.php secret is not written to active configuration.
    if ($this->isSecretOverridden() || $form_state->isValueEmpty('secret')) {
      $webhook_config->set('secret', $this->getStoredSecret());
    }
    $status = $webhook_config->save();

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t(
          'Created the %label Webhook.',
          [
            '%label' => $webhook_config->label(),
          ]
        ));
        break;

      default:
        $this->messenger()->addStatus($this->t(
          'Saved the %label Webhook.',
          [
            '%label' => $webhook_config->label(),
          ]
        ));
    }
    /** @var \Drupal\Core\Url $url */
    $url = $webhook_config->toUrl('collection');
    $form_state->setRedirectUrl($url);
    return $status;
  }

  /**
   * Generate a list of available events.
   *
   * @return array
   *   Array of string identifiers for outgoing event options.
   */
  protected function eventOptions() {
    $entity_types = $this->entityTypeManager->getDefinitions();

    $options = [];
    foreach ($entity_types as $entity_type => $definition) {
      if ($definition->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        foreach ($this->entityHooks as $hook) {
          $options['entity:' . $entity_type . ':' . $hook] = [
            'type' => $this->t('Hook: %entity_label', ['%entity_label' => ucfirst($definition->getLabel())]),
            'event' => 'entity:' . $entity_type . ':' . $hook,
          ];
        }
      }
    }

    foreach ($this->systemHooks as $hook) {
      $options['system:' . $hook] = [
        'type' => $this->t('Hook: %hook', ['%hook' => ucfirst($hook)]),
        'event' => 'system:' . $hook,
      ];
    }

    $this->moduleHandler->alter('webhooks_event_info', $options);
    return $options;
  }

  /**
   * Secret stored in config, ignoring settings.php overrides.
   *
   * @return string
   *   The override-free secret, or an empty string.
   */
  protected function getStoredSecret() {
    $id = $this->entity->id();
    if ($this->entity->isNew() || !$id) {
      return '';
    }
    /** @var \Drupal\webhooks\Entity\WebhookConfig|null $stored */
    $stored = $this->entityTypeManager
      ->getStorage('webhook_config')
      ->loadOverrideFree($id);
    if (!$stored) {
      return '';
    }
    return (string) $stored->get('secret');
  }

  /**
   * Whether the secret is provided by a configuration override.
   *
   * @return bool
   *   TRUE when webhooks.webhook.{id} has a secret override.
   */
  protected function isSecretOverridden() {
    $id = $this->entity->id();
    if (!$id) {
      return FALSE;
    }
    return $this->configFactory
      ->get('webhooks.webhook.' . $id)
      ->hasOverrides('secret');
  }

  /**
   * Identifies if an array of form values is empty.
   *
   * FormState::isValueEmpty() does not handle tableselect or #tree submissions.
   *
   * @param array $list
   *   Array of key value pairs. keys are identifiers, values are 0 if empty or
   *   the same value as the key if checked on.
   *
   * @return bool
   *   TRUE if empty, FALSE otherwise.
   */
  protected function isEmptyList(array $list) {
    return count(array_filter($list)) == 0;
  }

}
