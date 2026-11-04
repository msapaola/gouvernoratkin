<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webhooks\Controller\WebhookController;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\WebhooksService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the controller does not expose custom authentication diagnostics.
 */
#[Group('webhooks')]
class WebhookControllerAuthenticationTest extends UnitTestCase {

  /**
   * Tests the public response remains generic even for custom exception text.
   */
  public function testAuthenticationResponseDoesNotExposeExceptionDetails(): void {
    $service = $this->createMock(WebhooksService::class);
    $service->method('receive')->willThrowException(new WebhookMismatchSignatureException(
      'supplied-signature-fixture',
      'generated-signature-fixture',
      0,
      'Custom diagnostic containing sensitive material.',
    ));
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $controller = new WebhookController(
      $service,
      $this->createMock(EntityTypeManagerInterface::class),
      $logger_factory,
      $this->createMock(MessengerInterface::class),
    );

    $response = $controller->receive('test');
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Unauthorized.', (string) $response->getBody());
  }

}
