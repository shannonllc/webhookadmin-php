<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Exception\WebhookVerificationException;
use WebhookAdmin\Webhook;

/** A verifier with a fixed clock. */
final class FixedClockWebhook extends Webhook
{
    public int $at = 0;

    protected function now(): int
    {
        return $this->at;
    }
}

/**
 * Receiver-side verification. The vectors are signed by the Worker, the Node SDK and the official Standard Webhooks
 * library, with results decided by the Node SDK (scripts/sdk-vectors.mts in the monorepo).
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /** @return array<string, array{array<string, mixed>}> */
    public static function vectors(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/testdata/webhook_vectors.json'), true);
        $out = [];
        foreach ($data['vectors'] as $v) {
            $out[$v['name']] = [$v];
        }
        return $out;
    }

    private static function at(int $now, string $secret = self::SECRET, int $tolerance = 300): FixedClockWebhook
    {
        $w = new FixedClockWebhook($secret, $tolerance);
        $w->at = $now;
        return $w;
    }

    #[DataProvider('vectors')]
    public function testVectors(array $v): void
    {
        $w = self::at($v['now'], $v['secret'], $v['tolerance'] ?? 300);
        if ($v['expect'] === null) {
            try {
                $w->verify($v['payload'], $v['headers']);
                self::fail('verified');
            } catch (WebhookVerificationException $e) {
                self::assertSame($v['error'], $e->getMessage());
            }
            return;
        }
        self::assertSame($v['expect'], $w->verify($v['payload'], $v['headers']));
    }

    private static function good(): array
    {
        return self::vectors()['signed by the node sdk'][0];
    }

    public function testSignMatchesNodeAndWorker(): void
    {
        $n = 0;
        foreach (self::vectors() as $name => [$v]) {
            if (in_array($name, ['signed by the node sdk', 'signed by the worker', 'signed by the official library', 'standard webhooks spec vector'], true)) {
                $h = $v['headers'];
                self::assertSame($h['webhook-signature'], (new Webhook($v['secret']))->sign($h['webhook-id'], (int) $h['webhook-timestamp'], $v['payload']));
                $n++;
            }
        }
        self::assertSame(4, $n);
        $v = self::good();
        $at = (new \DateTimeImmutable())->setTimestamp((int) $v['headers']['webhook-timestamp']);
        self::assertSame($v['headers']['webhook-signature'], (new Webhook(self::SECRET))->sign($v['headers']['webhook-id'], $at, $v['payload']));
    }

    public function testHeaderForms(): void
    {
        $v = self::good();
        $h = $v['headers'];
        $w = self::at($v['now']);
        $upper = ['Webhook-Id' => $h['webhook-id'], 'WEBHOOK-TIMESTAMP' => [$h['webhook-timestamp']], 'webhook-signature' => $h['webhook-signature']];
        self::assertSame($v['expect'], $w->verify($v['payload'], $upper));
        $server = ['HTTP_WEBHOOK_ID' => $h['webhook-id'], 'HTTP_WEBHOOK_TIMESTAMP' => $h['webhook-timestamp'], 'HTTP_WEBHOOK_SIGNATURE' => $h['webhook-signature'], 'REQUEST_METHOD' => 'POST'];
        self::assertSame($v['expect'], $w->verify($v['payload'], $server));
        $psr7 = new class ($h) {
            public function __construct(private array $h)
            {
            }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->h as $k => $v) {
                    if (strcasecmp($k, $name) === 0) {
                        return $v;
                    }
                }
                return '';
            }
        };
        self::assertSame($v['expect'], $w->verify($v['payload'], $psr7));
        $this->expectExceptionMessage('Missing required headers');
        $w->verify($v['payload'], [...$h, 'webhook-id' => []]);
    }

    public function testPsr7MissingHeader(): void
    {
        $empty = new class () {
            public function getHeaderLine(string $name): string
            {
                return '';
            }
        };
        $this->expectException(WebhookVerificationException::class);
        self::at(0)->verify('{}', $empty);
    }

    public function testNotJsonKeepsCause(): void
    {
        $v = self::vectors()['signed body that is not JSON'][0];
        try {
            self::at($v['now'])->verify($v['payload'], $v['headers']);
            self::fail('verified');
        } catch (WebhookVerificationException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testDefaultToleranceAndClock(): void
    {
        $w = new Webhook(self::SECRET);
        self::assertSame(300, $w->tolerance);
        $now = time();
        $sig = $w->sign('msg_now', $now, '{}');
        self::assertSame([], $w->verify('{}', ['webhook-id' => 'msg_now', 'webhook-timestamp' => (string) $now, 'webhook-signature' => $sig]));
    }

    public function testSecretChecks(): void
    {
        foreach (['' => 'required', 'whsec_***' => 'not valid base64'] as $secret => $msg) {
            try {
                new Webhook((string) $secret);
                self::fail('accepted');
            } catch (WebhookAdminException $e) {
                self::assertNotInstanceOf(WebhookVerificationException::class, $e);
                self::assertStringContainsString($msg, $e->getMessage());
            }
        }
        // unpadded base64 is accepted, like atob in the Node SDK
        self::assertInstanceOf(Webhook::class, new Webhook('whsec_dGhpcyBpcyBhbm90aGVyIHNlY3JldCBrZXk'));
    }
}
