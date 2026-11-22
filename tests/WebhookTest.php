<?php

declare(strict_types=1);

namespace Reedapt\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reedapt\Client;
use Reedapt\Exception\WebhookVerificationException;
use Reedapt\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private string $body;

    protected function setUp(): void
    {
        $this->body = json_encode(['id' => 'evt_1', 'type' => 'dub.completed', 'created_at' => 'x', 'data' => ['dub' => ['id' => 'job_1']]], JSON_THROW_ON_ERROR);
    }

    private static function sign(string $body, ?int $t = null, string $secret = self::SECRET): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    #[Test]
    public function a_correctly_signed_delivery_verifies_and_parses(): void
    {
        $event = Webhook::constructEvent($this->body, self::sign($this->body), self::SECRET);
        $this->assertSame('dub.completed', $event['type']);
        $this->assertSame('evt_1', $event['id']);
    }

    #[Test]
    public function the_client_exposes_the_same_check(): void
    {
        $client = new Client('rk_test_x');
        $this->assertSame('evt_1', $client->webhooks->constructEvent($this->body, self::sign($this->body), self::SECRET)['id']);
        $client->webhooks->verify($this->body, self::sign($this->body), self::SECRET);
    }

    #[Test]
    public function a_changed_body_is_refused(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(str_replace('job_1', 'job_2', $this->body), self::sign($this->body), self::SECRET);
    }

    #[Test]
    public function re_encoded_json_is_refused(): void
    {
        $reencoded = json_encode(json_decode($this->body, true), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($reencoded, self::sign($this->body), self::SECRET);
    }

    #[Test]
    public function the_wrong_secret_is_refused(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($this->body, self::sign($this->body, null, 'whsec_other'), self::SECRET);
    }

    #[Test]
    public function an_old_signature_is_refused_within_a_configurable_tolerance(): void
    {
        $old = time() - 600;
        try {
            Webhook::verify($this->body, self::sign($this->body, $old), self::SECRET);
            $this->fail('Expected an exception.');
        } catch (WebhookVerificationException $e) {
            $this->assertStringContainsString('too old', $e->getMessage());
        }
        Webhook::verify($this->body, self::sign($this->body, $old), self::SECRET, 900);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_missing_or_malformed_header_is_refused(): void
    {
        foreach ([[null, 'missing'], ['nonsense', 'malformed'], ['t=abc,v1=00', 'malformed']] as [$header, $word]) {
            try {
                Webhook::verify($this->body, $header, self::SECRET);
                $this->fail('Expected an exception.');
            } catch (WebhookVerificationException $e) {
                $this->assertStringContainsString($word, $e->getMessage());
            }
        }
    }

    #[Test]
    public function any_one_of_several_v1_signatures_may_match(): void
    {
        [$t, $v1] = explode(',', self::sign($this->body));
        Webhook::verify($this->body, "{$t},v1=".str_repeat('0', 64).",{$v1}", self::SECRET);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_dub_checked_delivery_parses_to_the_dub_and_its_quality_report(): void
    {
        $quality = ['dub_id' => 'job_1', 'checked' => true, 'listened' => true, 'lines' => 10, 'fixed' => 1, 'to_review' => 0, 'flagged' => []];
        $body = json_encode(['id' => 'evt_2', 'type' => 'dub.checked', 'created_at' => 'x', 'data' => ['dub' => ['id' => 'job_1'], 'quality' => $quality]], JSON_THROW_ON_ERROR);
        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);
        $this->assertSame('dub.checked', $event['type']);
        $this->assertSame($quality, $event['data']['quality']);
    }

    #[Test]
    public function a_signed_body_that_is_not_json_is_refused(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::constructEvent('not json', self::sign('not json'), self::SECRET);
    }
}
