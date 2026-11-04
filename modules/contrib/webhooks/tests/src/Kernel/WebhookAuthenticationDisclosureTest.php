<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webhooks\Controller\WebhookController;
use Drupal\webhooks\Entity\WebhookConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests authentication failure responses, logs, and stored error messages.
 */
#[Group('webhooks')]
class WebhookAuthenticationDisclosureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'serialization', 'dblog', 'webhooks', 'webhook',
  ];

  private const TOKEN = 'configured-token-regression-fixture';
  private const SECRET = 'configured-secret-regression-fixture';
  private const BODY = '{"message":"authentication regression"}';
  private const SUPPLIED_TOKEN = 'incorrect-token-regression-fixture';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['webhooks', 'webhook']);
    $this->installEntitySchema('webhook');
    $this->installSchema('dblog', ['watchdog']);
    $this->container->get('router.builder')->rebuild();

    WebhookConfig::create([
      'id' => 'authentication_test',
      'label' => 'Authentication test',
      'type' => 'incoming',
      'payload_url' => '',
      'events' => [],
      'content_type' => WebhookConfig::CONTENT_TYPE_JSON,
      'status' => TRUE,
    ])->save();
  }

  /**
   * Provides authentication methods and diagnostic surfaces.
   */
  public static function failureCases(): iterable {
    foreach (['token', 'hub', 'base64'] as $method) {
      foreach (['response', 'log', 'stored error'] as $surface) {
        yield $method . ': ' . $surface => [$method, $surface];
      }
    }
  }

  /**
   * Tests rejected credentials are not echoed to any error surface.
   */
  #[DataProvider('failureCases')]
  public function testFailuresDoNotDiscloseCredentials(string $method, string $surface): void {
    $response = $this->receive($method, FALSE);
    $this->assertSame(401, $response->getStatusCode());

    if ($surface === 'response') {
      $diagnostic = (string) $response->getBody();
    }
    elseif ($surface === 'log') {
      $rows = $this->container->get('database')->select('watchdog', 'w')
        ->fields('w', ['message', 'variables'])
        ->condition('type', 'webhooks')
        ->execute()->fetchAll();
      $this->assertNotEmpty($rows);
      $diagnostic = serialize($rows);
    }
    else {
      $storage = $this->container->get('entity_type.manager')->getStorage('webhook');
      $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
      $this->assertCount(1, $ids);
      $entity = $storage->load(reset($ids));
      $this->assertFalse($entity->isVerified());
      $diagnostic = $entity->getError();
    }

    $this->assertNotEmpty($diagnostic);
    foreach ([
      self::TOKEN,
      self::SECRET,
      self::SUPPLIED_TOKEN,
      'sha256=' . str_repeat('0', 64),
      base64_encode(str_repeat("\0", 32)),
      hash_hmac('sha256', self::BODY, self::SECRET),
      base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, TRUE)),
    ] as $sensitive) {
      $this->assertStringNotContainsString($sensitive, $diagnostic, $surface . ' must not contain authentication material.');
    }
  }

  /**
   * Provides valid token and signature methods.
   */
  public static function validCases(): iterable {
    foreach (['token', 'hub', 'base64'] as $method) {
      yield $method => [$method];
    }
  }

  /**
   * Tests valid authentication continues to work.
   */
  #[DataProvider('validCases')]
  public function testValidCredentialsAreAccepted(string $method): void {
    $response = $this->receive($method, TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('OK', (string) $response->getBody());
  }

  /**
   * Invokes the real controller and receiver with synthetic credentials.
   */
  private function receive(string $method, bool $valid): ResponseInterface {
    $config = $this->container->get('config.factory')->getEditable('webhooks.webhook.authentication_test');
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($method === 'token') {
      $config->set('token', self::TOKEN)->set('secret', '');
      $server['HTTP_X_TEST_TOKEN'] = $valid ? self::TOKEN : self::SUPPLIED_TOKEN;
    }
    else {
      // Use active configuration directly so this shared test covers both the
      // 3.0.x stored-secret reader and the 4.0.x config-factory reader.
      $config->set('secret', self::SECRET)->set('signature_algorithm', 'sha256');
      if ($method === 'hub') {
        $config->set('signature_header', 'X-Hub-Signature-256');
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . ($valid
          ? hash_hmac('sha256', self::BODY, self::SECRET)
          : str_repeat('0', 64));
      }
      else {
        $config->set('signature_header', 'X-WC-Webhook-Signature');
        $server['HTTP_X_WC_WEBHOOK_SIGNATURE'] = $valid
          ? base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, TRUE))
          : base64_encode(str_repeat("\0", 32));
      }
    }
    $config->save();
    $this->container->get('entity_type.manager')->getStorage('webhook_config')->resetCache();
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('/webhook/authentication_test', 'POST', [], [], [], $server, self::BODY));
    try {
      return WebhookController::create($this->container)->receive('authentication_test');
    }
    finally {
      $stack->pop();
    }
  }

}
