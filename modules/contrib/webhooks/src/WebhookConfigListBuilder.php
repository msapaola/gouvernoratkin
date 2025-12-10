<?php

namespace Drupal\webhooks;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Webhook entities.
 */
class WebhookConfigListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity) {
    $operations = parent::getOperations($entity);
    $operations['toggle_active'] = [
      'title' => $entity->status() ? t('Deactivate') : t('Activate'),
      'weight' => 50,
      'url' => Url::fromRoute(
        'webhooks.webhook_toggle_active',
        [
          'id' => $entity->id(),
        ]
      ),
    ];
    uasort($operations, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Webhook'),
      'url' => $this->t('URL'),
      'id' => $this->t('Machine name'),
      'type' => $this->t('Type'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperations(EntityInterface $entity) {
    return [
      '#type' => 'operations',
      '#links' => $this->getOperations($entity),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    return [
      'label' => $entity->label(),
      'url' => (new Url('webhooks.webhook_receive', ['incoming_webhook_name' => $entity->id()]))->setAbsolute()->toString(),
      'id' => $entity->id(),
      'type' => ucfirst($entity->getType()),
      'status' => $entity->status() ? $this->t('Active') : $this->t('Inactive'),
    ] + parent::buildRow($entity);
  }

}
