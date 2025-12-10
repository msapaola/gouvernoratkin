<?php

namespace Drupal\webhooks\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\webhooks\Entity\WebhookConfig;
use Drupal\webhooks\Event\ReceiveEvent;
use Drupal\webhooks\Event\WebhookEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A Webhook dispatcher that dispatches webhooks on CRON run.
 *
 * @QueueWorker(
 *   id = "webhooks_dispatcher",
 *   title = @Translation("Webhooks Dispatcher"),
 *   cron = {"time" = 60}
 * )
 */
final class WebhookDispatcher extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * WebhookDispatcher constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EventDispatcherInterface $event_dispatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    $webhook_config = WebhookConfig::load($data['id']);
    if ($webhook_config) {
      /** @var \Drupal\webhooks\Event\ReceiveEvent $event */
      $event = $this->eventDispatcher->dispatch(
        new ReceiveEvent($webhook_config, $data['webhook']),
        WebhookEvents::RECEIVE
      );
      if (!$event->getWebhook()->getStatus()) {
        throw new SuspendQueueException();
      }
    }
  }

}
