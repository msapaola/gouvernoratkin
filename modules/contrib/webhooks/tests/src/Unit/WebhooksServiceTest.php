<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webhooks\WebhooksService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

/**
 * Unit tests for WebhooksService pure proxy methods and resolveTimeout().
 *
 * Verifies that the local-request detection compares hosts exactly so that
 * a subdomain such as erp.example.fr is never mistakenly treated as local
 * when the Drupal host is example.fr.
 *
 * Also covers the serializer proxy methods (encode, decode,
 * supportsEncoding, supportsDecoding, setSerializer) which simply delegate
 * to the injected Symfony Serializer and need no Drupal container.
 *
 * @group webhooks
 * @coversDefaultClass \Drupal\webhooks\WebhooksService
 */
class WebhooksServiceTest extends UnitTestCase {

  /**
   * Tests that resolveTimeout() returns the expected value.
   *
   * @param string $currentHost
   *   The hostname of the current Drupal request.
   * @param string $payloadUrl
   *   The outgoing payload URL.
   * @param float $expectedTimeout
   *   Expected Guzzle timeout value.
   *
   * @covers ::resolveTimeout
   * @dataProvider providerResolveTimeout
   */
  public function testResolveTimeout(
    string $currentHost,
    string $payloadUrl,
    float $expectedTimeout,
  ): void {
    $request = Request::create('http://' . $currentHost . '/');

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);

    // Instantiate without running the real constructor so we only need the
    // request stack dependency, which is all resolveTimeout() uses.
    $service = $this->getMockBuilder(WebhooksService::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $stackProp = new \ReflectionProperty(WebhooksService::class, 'requestStack');
    $stackProp->setAccessible(TRUE);
    $stackProp->setValue($service, $requestStack);

    $method = new \ReflectionMethod(WebhooksService::class, 'resolveTimeout');
    $method->setAccessible(TRUE);

    $this->assertSame($expectedTimeout, $method->invoke($service, $payloadUrl));
  }

  /**
   * Data provider for testResolveTimeout().
   *
   * @return array<string, array{string, string, float}>
   *   Host, payload URL, and expected timeout tuples keyed by scenario name.
   */
  public static function providerResolveTimeout(): array {
    return [
      'same host is treated as local'                       => [
        'example.com',
        'http://example.com/webhooks/receive/foo',
        0.1,
      ],
      'subdomain of current host is NOT local'              => [
        'example.com',
        'https://erp.example.com/api/users',
        0.0,
      ],
      'subdomain erp.example.fr vs example.fr is NOT local' => [
        'example.fr',
        'https://erp.example.fr/api/index.php/users',
        0.0,
      ],
      'completely different host is not local'              => [
        'drupal.mysite.org',
        'https://dolibarr.mysite.org/api/index.php/users',
        0.0,
      ],
      'localhost to localhost is local'                     => [
        'localhost',
        'http://localhost:8080/webhooks/receive/bar',
        0.1,
      ],
      'localhost does not match a remote host'              => [
        'localhost',
        'https://erp.example.fr/api/users',
        0.0,
      ],
    ];
  }

  /**
   * Returns a stub WebhooksService with the given serializer injected.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer to inject via reflection.
   *
   * @return \Drupal\webhooks\WebhooksService
   *   The partial mock with only the serializer wired up.
   */
  private function buildServiceWithSerializer(Serializer $serializer): WebhooksService {
    $service = $this->getMockBuilder(WebhooksService::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $prop = new \ReflectionProperty(WebhooksService::class, 'serializer');
    $prop->setAccessible(TRUE);
    $prop->setValue($service, $serializer);

    return $service;
  }

  /**
   * Returns a real Serializer equipped with a JSON encoder/decoder.
   *
   * @return \Symfony\Component\Serializer\Serializer
   *   A Serializer with JsonEncoder support.
   */
  private function makeJsonSerializer(): Serializer {
    return new Serializer([], [new JsonEncoder()]);
  }

  /**
   * @covers ::setSerializer
   */
  public function testSetSerializerReplacesInjectedSerializer(): void {
    $original    = $this->makeJsonSerializer();
    $replacement = $this->makeJsonSerializer();

    $service = $this->buildServiceWithSerializer($original);
    $service->setSerializer($replacement);

    $prop = new \ReflectionProperty(WebhooksService::class, 'serializer');
    $prop->setAccessible(TRUE);
    $this->assertSame($replacement, $prop->getValue($service));
  }

  /**
   * @covers ::encode
   */
  public function testEncodeDelegatesToSerializer(): void {
    $service = $this->buildServiceWithSerializer($this->makeJsonSerializer());

    $this->assertSame('{"a":1}', $service->encode(['a' => 1], 'json'));
  }

  /**
   * @covers ::decode
   */
  public function testDecodeDelegatesToSerializer(): void {
    $service = $this->buildServiceWithSerializer($this->makeJsonSerializer());

    $this->assertSame(['a' => 1], $service->decode('{"a":1}', 'json'));
  }

  /**
   * @covers ::supportsEncoding
   */
  public function testSupportsEncodingDelegatesToSerializer(): void {
    $service = $this->buildServiceWithSerializer($this->makeJsonSerializer());

    $this->assertTrue($service->supportsEncoding('json'));
    $this->assertFalse($service->supportsEncoding('yaml'));
  }

  /**
   * @covers ::supportsDecoding
   */
  public function testSupportsDecodingDelegatesToSerializer(): void {
    $service = $this->buildServiceWithSerializer($this->makeJsonSerializer());

    $this->assertTrue($service->supportsDecoding('json'));
    $this->assertFalse($service->supportsDecoding('yaml'));
  }

}
