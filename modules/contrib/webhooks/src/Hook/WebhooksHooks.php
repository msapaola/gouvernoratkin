<?php

declare(strict_types=1);

namespace Drupal\webhooks\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\webhooks\Webhook;

/**
 * Hook implementations for webhooks module.
 */
class WebhooksHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match): string|\Stringable|array|null {
    switch ($route_name) {
      case 'help.page.webhooks':
        $output = '';
        $output .= '<h3>' . t('Webhooks') . '</h3>';
        $output .= '<p>' . t('User-defined HTTP callbacks.') . '</p>';
        return $output;
    }
    return NULL;
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['entity', $entity->getEntityType()->id(), 'create']);
    $user = User::load(\Drupal::currentUser()->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
          'entity' => $serializer->normalize($entity),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['entity', $entity->getEntityType()->id(), 'update']);
    $user = User::load(\Drupal::currentUser()->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
          'entity' => $serializer->normalize($entity),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['entity', $entity->getEntityType()->id(), 'delete']);
    $user = User::load(\Drupal::currentUser()->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
          'entity' => $serializer->normalize($entity),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');

    $event = implode(':', ['system', 'cron']);
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_file_download().
   */
  #[Hook('file_download')]
  public function fileDownload($uri): array|int|null {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['system', 'file_download']);
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'uri' => $serializer->normalize($uri),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
    return NULL;
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled($modules, $is_syncing): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['system', 'modules_installed']);
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'modules' => $serializer->normalize($modules),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_user_cancel().
   */
  #[Hook('user_cancel')]
  public function userCancel($edit, UserInterface $account, $method): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['system', 'user_cancel']);
    $user = User::load($account->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_user_login().
   */
  #[Hook('user_login')]
  public function userLogin(UserInterface $account): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['system', 'user_login']);
    $user = User::load($account->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_user_logout().
   */
  #[Hook('user_logout')]
  public function userLogout(AccountInterface $account): void {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');

    $event = implode(':', ['system', 'user_logout']);
    $user = User::load($account->id());
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
          'user' => $serializer->normalize($user),
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

  /**
   * Implements hook_cache_flush().
   */
  #[Hook('cache_flush')]
  public function cacheFlush() {
    /** @var \Drupal\webhooks\WebhooksService $webhooks_service */
    $webhooks_service = \Drupal::service('webhooks.service');

    $event = implode(':', ['system', 'cache_flush']);
    $webhook_configs = $webhooks_service->loadMultipleByEvent($event);

    /** @var \Drupal\webhooks\Entity\WebhookConfig $webhook_config */
    foreach ($webhook_configs as $webhook_config) {
      $webhook = new Webhook(
        [
          'event' => $event,
        ],
        [],
        $event,
        $webhook_config->getContentType()
      );
      $webhooks_service->send($webhook_config, $webhook);
    }
  }

}
