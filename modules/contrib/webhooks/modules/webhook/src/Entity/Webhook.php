<?php

namespace Drupal\webhook\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\webhook\WebhookInterface;

/**
 * Defines the webhook entity class.
 *
 * @ContentEntityType(
 *   id = "webhook",
 *   label = @Translation("Webhook"),
 *   label_collection = @Translation("Webhooks"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\webhook\WebhookListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\webhook\Form\WebhookForm",
 *       "edit" = "Drupal\webhook\Form\WebhookForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     }
 *   },
 *   base_table = "webhook",
 *   admin_permission = "access webhook overview",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "title",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "add-form" = "/admin/content/webhook/add",
 *     "canonical" = "/webhook/{webhook}",
 *     "edit-form" = "/admin/content/webhook/{webhook}/edit",
 *     "delete-form" = "/admin/content/webhook/{webhook}/delete",
 *     "collection" = "/admin/content/webhook"
 *   },
 * )
 */
class Webhook extends ContentEntityBase implements WebhookInterface {

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title) {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isVerified() {
    return (bool) $this->get('verified')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setVerified($verified) {
    $this->set('verified', $verified);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getError() {
    return (string) $this->get('error')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setError($error) {
    $this->set('error', $error);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWebhookConfigId() {
    return (string) $this->get('webhook_config')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWebhookConfigId($webhook_config_id) {
    $this->set('webhook_config', $webhook_config_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSignature() {
    return (string) $this->get('signature')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSignature($signature) {
    $this->set('signature', $signature);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPayloadLength() {
    return (int) $this->get('payload_length')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPayloadLength($payload_length) {
    $this->set('payload_length', $payload_length);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of the webhook entity.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['headers'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Headers'))
      ->setDescription(t('The headers of the webhook entity.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -5,
        'settings' => [
          'rows' => 12,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['webhook_config'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Webhook config'))
      ->setDescription(t('Machine name of the incoming webhook configuration.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Payload'))
      ->setDescription(t('The posted request body (not re-encoded JSON).'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -5,
        'settings' => [
          'rows' => 12,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payload_length'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Payload length'))
      ->setDescription(t('Byte length of the posted request body.'))
      ->setRequired(FALSE)
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['signature'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Signature'))
      ->setDescription(t('HMAC value from the configured signature header.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['verified'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Verified'))
      ->setDescription(t('Whether signature or token verification passed.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Error'))
      ->setDescription(t('Verification error message, if any.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 16,
        'settings' => [
          'rows' => 4,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the webhook was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
