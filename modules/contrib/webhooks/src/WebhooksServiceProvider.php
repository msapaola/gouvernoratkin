<?php

namespace Drupal\webhooks;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Webhooks service provider class.
 */
class WebhooksServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->has('http_middleware.negotiation') && is_a($container->getDefinition('http_middleware.negotiation')->getClass(), '\Drupal\Core\StackMiddleware\NegotiationMiddleware', TRUE)) {
      $container
        ->getDefinition('http_middleware.negotiation')
        ->addMethodCall('registerFormat', ['yaml', ['application/yaml']]);
    }
  }

}
