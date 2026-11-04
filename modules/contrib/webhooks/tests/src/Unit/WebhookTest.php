<?php

declare(strict_types=1);

namespace Drupal\Tests\webhooks\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webhooks\Exception\WebhookMismatchSignatureException;
use Drupal\webhooks\Webhook;

/**
 * Unit tests for Webhook signature helpers.
 *
 * @group webhooks
 * @coversDefaultClass \Drupal\webhooks\Webhook
 */
class WebhookTest extends UnitTestCase {

  /**
   * Tests getSignature reads a named header after normalizing the name.
   *
   * @covers ::getSignature
   * @covers ::normalizeHeaderName
   */
  public function testGetSignatureNormalizesHeaderName(): void {
    $sig = 'sha256=abc';
    $webhook = new Webhook([], ['X_Hub_Signature_256' => $sig]);

    $this->assertSame(
      $sig,
      $webhook->getSignature('X-Hub-Signature-256')
    );
    $this->assertSame(
      $sig,
      $webhook->getSignature(' X-Hub-Signature-256 ')
    );
  }

  /**
   * Tests getSignature falls back to SHA-1 Hub header.
   *
   * @covers ::getSignature
   */
  public function testGetSignatureFallsBackToSha1HubHeader(): void {
    $sig = 'sha1=def';
    $webhook = new Webhook([], ['X-Hub-Signature' => $sig]);

    $this->assertSame($sig, $webhook->getSignature('X-Hub-Signature-256'));
  }

  /**
   * Tests verify throws when the signature header is missing.
   *
   * @covers ::verify
   */
  public function testVerifyRejectsMissingSignature(): void {
    $this->expectException(WebhookMismatchSignatureException::class);
    $this->expectExceptionMessage('Webhook signature verification failed.');
    Webhook::verify('secret', '{"a":1}', '');
  }

  /**
   * Tests verify accepts a WooCommerce-style base64 HMAC.
   *
   * @covers ::verify
   */
  public function testVerifyAcceptsWooCommerceBase64Signature(): void {
    $payload = '{"a":1}';
    $secret = 'secret';
    $signature = base64_encode(hash_hmac('sha256', $payload, $secret, TRUE));

    $this->assertTrue(Webhook::verify($secret, $payload, $signature));
  }

  /**
   * Tests verify uses the configured algorithm for base64 signatures.
   *
   * @covers ::verify
   */
  public function testVerifyUsesConfiguredAlgorithmForBase64Signature(): void {
    $payload = '{"a":1}';
    $secret = 'secret';
    $signature = base64_encode(hash_hmac('sha1', $payload, $secret, TRUE));

    $this->assertTrue(Webhook::verify($secret, $payload, $signature, 'sha1'));
    $this->expectException(WebhookMismatchSignatureException::class);
    Webhook::verify($secret, $payload, $signature, 'sha256');
  }

  /**
   * Tests verify still accepts Hub-style algorithm=hexdigest signatures.
   *
   * @covers ::verify
   */
  public function testVerifyAcceptsHubSha256HexDigest(): void {
    $payload = '{"a":1}';
    $secret = 'secret';
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $this->assertTrue(Webhook::verify($secret, $payload, $signature, 'sha1'));
  }

  /**
   * Tests verify HTML-entity-decodes the secret before HMAC.
   *
   * @covers ::verify
   */
  public function testVerifyDecodesHtmlEntitiesInSecret(): void {
    $payload = '{"a":1}';
    $secret = 'a&b"c\'d';
    $stored = htmlspecialchars($secret, ENT_QUOTES);
    $signature = base64_encode(hash_hmac('sha256', $payload, $secret, TRUE));

    $this->assertTrue(Webhook::verify($stored, $payload, $signature));
  }

  /**
   * Tests verify rejects a mismatched WooCommerce-style signature.
   *
   * @covers ::verify
   */
  public function testVerifyRejectsMismatchedWooCommerceSignature(): void {
    $payload = '{"a":1}';
    try {
      Webhook::verify('secret', $payload, 'not-a-valid-hmac====');
      $this->fail('Expected WebhookMismatchSignatureException.');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertStringNotContainsString($payload, $e->getMessage());
      $this->assertStringNotContainsString('Payload:', $e->getMessage());
      $this->assertSame(strlen($payload), $e->getPayloadLength());
    }
  }

  /**
   * Tests verify trims whitespace around the secret.
   *
   * @covers ::verify
   */
  public function testVerifyTrimsSecret(): void {
    $payload = '{"a":1}';
    $secret = 'secret';
    $signature = base64_encode(hash_hmac('sha256', $payload, $secret, TRUE));

    $this->assertTrue(Webhook::verify('  secret  ', $payload, $signature));
  }

  /**
   * Tests verify retries HMAC with the undecoded secret.
   *
   * @covers ::verify
   */
  public function testVerifyRetriesUndecodedSecret(): void {
    $payload = '{"a":1}';
    $stored = 'a&amp;b';
    $signature = base64_encode(hash_hmac('sha256', $payload, $stored, TRUE));

    $this->assertTrue(Webhook::verify($stored, $payload, $signature));
  }

  /**
   * Tests verify hashes the trimmed WooCommerce body, not a re-encode.
   *
   * @covers ::verify
   */
  public function testVerifyHashesExactWooCommerceBodyNotReencoded(): void {
    $body = '  {"id":1,"meta":{},"permalink":"http:\/\/shop.example\/p"}  ';
    $trimmed = trim($body);
    $secret = 'secret';
    $signature = base64_encode(hash_hmac('sha256', $trimmed, $secret, TRUE));

    $this->assertTrue(Webhook::verify($secret, $body, $signature));

    $reencoded = json_encode(json_decode($trimmed, TRUE));
    $this->assertNotSame($trimmed, $reencoded);
    $this->expectException(WebhookMismatchSignatureException::class);
    Webhook::verify($secret, $reencoded, $signature);
  }

  /**
   * Tests Hub signatures use the exact body bytes, including whitespace.
   *
   * @covers ::verify
   */
  public function testVerifyHubSignatureDoesNotTrimBody(): void {
    $payload = "{\"a\":1}\n";
    $secret = 'secret';
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $this->assertTrue(Webhook::verify($secret, $payload, $signature));
    $this->expectException(WebhookMismatchSignatureException::class);
    Webhook::verify($secret, trim($payload), $signature);
  }

  /**
   * Tests verify rejects a signature when no secret is configured.
   *
   * @covers ::verify
   */
  public function testVerifyRejectsSignatureWithoutSecret(): void {
    $payload = '{"email":"pii@example.com"}';
    $this->expectException(WebhookMismatchSignatureException::class);
    $this->expectExceptionMessage('A signature was received but no secret is configured.');
    try {
      Webhook::verify('  ', $payload, 'ew0j1UEVEJemwZo9R56DuVu15dVoWX6n5BrxOhPYmS4=');
    }
    catch (WebhookMismatchSignatureException $e) {
      $this->assertStringNotContainsString($payload, $e->getMessage());
      $this->assertSame(strlen($payload), $e->getPayloadLength());
      throw $e;
    }
  }

}
