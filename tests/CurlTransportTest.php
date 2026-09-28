<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use PHPUnit\Framework\TestCase;
use WebhookAdmin\Client;
use WebhookAdmin\Exception\ApiException;
use WebhookAdmin\Exception\ConnectionException;
use WebhookAdmin\Exception\NotFoundException;
use WebhookAdmin\Exception\TimeoutException;
use WebhookAdmin\Http\CurlTransport;
use WebhookAdmin\Http\Request;

/** The default transport (cURL) against `php -S`. */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $url = '';

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        self::$url = "http://127.0.0.1:$port";
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/server.php'];
        self::$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc !== null) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    private static function client(array $options = []): Client
    {
        return new Client('sk_test_abc', ['base_url' => self::$url, ...$options]);
    }

    public function testSuccessAndHeaders(): void
    {
        self::assertSame(['id' => 'msg_ok'], self::client()->messages->get('msg_ok'));
        $r = (new CurlTransport())->send(new Request('POST', self::$url . '/v1/echo', ['authorization' => 'Bearer k', 'content-type' => 'application/json', 'user-agent' => 'ua/1'], '{"x":"日本"}', 5));
        self::assertSame(202, $r->status);
        self::assertSame('application/json', $r->header('Content-Type'));
        $echo = json_decode($r->body, true);
        self::assertSame(['POST', 'Bearer k', 'application/json', 'ua/1', '{"x":"日本"}'], [$echo['method'], $echo['authorization'], $echo['content_type'], $echo['user_agent'], $echo['body']]);
    }

    public function testErrorResponse(): void
    {
        try {
            self::client()->messages->get('nope');
            self::fail('no error');
        } catch (NotFoundException $e) {
            self::assertSame([404, 'no such message', 'req_404'], [$e->status, $e->getMessage(), $e->requestId]);
        }
    }

    public function testRedirectIsNotFollowed(): void
    {
        try {
            self::client(['max_retries' => 0])->messages->get('redirect');
            self::fail('no error');
        } catch (ApiException $e) {
            self::assertSame(302, $e->status);
        }
    }

    public function testTimeout(): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Request timed out after 0.2 s');
        self::client(['max_retries' => 0, 'timeout' => 0.2])->messages->get('slow');
    }

    public function testConnectionRefused(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        try {
            self::client(['max_retries' => 0, 'base_url' => 'http://' . $name])->messages->get('m');
            self::fail('no error');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertStringStartsWith('Connection error: ', $e->getMessage());
        }
    }
}
