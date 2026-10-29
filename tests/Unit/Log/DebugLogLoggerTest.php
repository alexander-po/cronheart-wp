<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Log;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Log\DebugLogLogger;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class DebugLogLoggerTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000001';
    private const TOKEN = 'cmk_0000000000000000000000000000000000000000000';

    /** @var list<string> */
    private array $lines = [];

    public function test_nothing_is_written_while_the_debug_log_is_off(): void
    {
        $this->logger(enabled: false)->warning('cron-monitor ping failed', ['action' => 'start']);

        self::assertSame([], $this->lines);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quietLevels(): array
    {
        return [
            'notice' => [LogLevel::NOTICE],
            'info' => [LogLevel::INFO],
            'debug' => [LogLevel::DEBUG],
            'unknown' => ['verbose'],
        ];
    }

    #[DataProvider('quietLevels')]
    public function test_levels_below_warning_are_not_written(string $level): void
    {
        $this->logger()->log($level, 'cron-monitor ping failed');

        self::assertSame([], $this->lines);
    }

    public function test_a_failed_ping_record_is_one_line_with_its_allow_listed_fields(): void
    {
        $this->logger()->warning('cron-monitor ping failed', [
            'monitor_uuid_hash' => '0123456789abcdef',
            'action' => 'start',
            'attempts' => 3,
            'last_status' => null,
            'last_error' => 'Connection refused',
        ]);

        self::assertSame(
            ["cronheart: cron-monitor ping failed (monitor_uuid_hash='0123456789abcdef', action='start', attempts=3, last_status=null, last_error='Connection refused')"],
            $this->lines,
        );
    }

    public function test_no_uuid_token_or_full_url_survives_in_the_message_or_the_context(): void
    {
        $this->logger()->error('ping to https://user:secret@cronheart.example.test:8443/ping/'.self::UUID.'/start?x=1 failed', [
            'last_error' => 'cURL error 7 for http://cronheart.example.test/ping/'.strtoupper(self::UUID).' with Bearer '.self::TOKEN,
            'error' => "line one\nforged: line two\r",
            'monitor_uuid' => self::UUID,
            'url' => 'https://cronheart.example.test/ping/'.self::UUID,
            'trace' => ['frame' => self::UUID],
        ]);

        self::assertCount(1, $this->lines);
        $line = $this->lines[0];
        self::assertStringNotContainsStringIgnoringCase(self::UUID, $line);
        self::assertStringNotContainsString('cmk_', $line);
        self::assertStringNotContainsString('secret', $line);
        self::assertStringNotContainsString('/ping/', $line);
        self::assertStringNotContainsString('8443', $line);
        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString("\r", $line);
        self::assertStringContainsString('ping to https://cronheart.example.test failed', $line);
        self::assertStringContainsString("last_error='cURL error 7 for http://cronheart.example.test with Bearer {api_key}'", $line);
        self::assertStringContainsString("error='line one forged: line two '", $line);
        self::assertStringNotContainsString('monitor_uuid=', $line);
        self::assertStringNotContainsString('url=', $line);
        self::assertStringNotContainsString('trace', $line);
    }

    public function test_a_url_keeps_only_its_scheme_and_host(): void
    {
        $this->logger()->warning('a http://cronheart.example.test b https://u:p@[::1]:8443/x c ftp://@cronheart.example.test/y d https://u:pa@ss@cronheart.example.test/z?q=a@b e');

        self::assertSame(['cronheart: a http://cronheart.example.test b https://[::1] c ftp://cronheart.example.test d https://cronheart.example.test e'], $this->lines);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quotedProxyPasswords(): array
    {
        return [
            'a space' => ['sec ret'],
            'a hash' => ['sec#ret'],
            'an at sign' => ['sec@ret'],
        ];
    }

    #[DataProvider('quotedProxyPasswords')]
    public function test_a_quoted_url_loses_its_whole_userinfo(string $password): void
    {
        $this->logger()->warning('cron-monitor ping failed', [
            'last_error' => "cURL error (5): Unsupported proxy syntax in 'http://puser:".$password."@proxy.example.test:3128': Could not resolve proxy",
        ]);

        self::assertSame(
            ["cronheart: cron-monitor ping failed (last_error='cURL error (5): Unsupported proxy syntax in 'http://proxy.example.test': Could not resolve proxy')"],
            $this->lines,
        );
    }

    public function test_a_uuid_outside_a_url_is_replaced_in_any_case(): void
    {
        $mixedCase = 'ABCDEF01'.substr(self::UUID, 8);
        $this->logger()->warning('bad uuid '.$mixedCase.' and '.strtolower($mixedCase));

        self::assertSame(['cronheart: bad uuid {uuid} and {uuid}'], $this->lines);
    }

    public function test_a_non_scalar_under_an_allow_listed_key_is_dropped(): void
    {
        $this->logger()->warning('cron-monitor ping failed', [
            'last_error' => ['nested' => self::UUID],
            'error' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'stringable';
                }
            },
            'attempts' => 2,
        ]);

        self::assertSame(['cronheart: cron-monitor ping failed (attempts=2)'], $this->lines);
    }

    public function test_the_message_parameter_stays_untyped_for_psr_log_1(): void
    {
        $message = (new \ReflectionMethod(DebugLogLogger::class, 'log'))->getParameters()[1];

        self::assertSame('message', $message->getName());
        self::assertNull($message->getType());
    }

    public function test_a_line_is_capped_at_500_bytes(): void
    {
        $this->logger()->warning('cron-monitor ping failed', ['last_error' => str_repeat('x', 2000)]);

        self::assertSame(500, \strlen($this->lines[0]));
    }

    public function test_the_cap_does_not_split_a_multi_byte_character(): void
    {
        $this->logger()->warning(str_repeat('é', 300));

        self::assertLessThanOrEqual(500, \strlen($this->lines[0]));
        self::assertGreaterThan(490, \strlen($this->lines[0]));
        self::assertSame(1, preg_match('//u', $this->lines[0]));
    }

    public function test_a_ping_that_fails_after_its_retries_writes_exactly_one_line(): void
    {
        $client = Client::create(new Configuration(
            endpoint: 'http://127.0.0.1:1',
            timeoutSeconds: 1.0,
            retries: 2,
            allowInsecureEndpoint: true,
        ), $this->logger());

        $result = $client->start(self::UUID);

        self::assertFalse($result->delivered);
        self::assertSame(3, $result->attempts);
        self::assertCount(1, $this->lines);
        self::assertStringStartsWith('cronheart: cron-monitor ping failed (monitor_uuid_hash=', $this->lines[0]);
        self::assertStringContainsString("action='start', attempts=3", $this->lines[0]);
        self::assertStringNotContainsString(self::UUID, $this->lines[0]);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unloggedBySdkStatuses(): array
    {
        return [
            'not found' => [404],
            'payment required' => [402],
            'redirect' => [302],
        ];
    }

    #[DataProvider('unloggedBySdkStatuses')]
    public function test_a_ping_the_sdk_returns_unlogged_writes_exactly_one_rejected_line(int $status): void
    {
        $mixedCase = 'ABCDEF01'.substr(self::UUID, 8);

        $result = $this->clientAnswering([new Response($status)])->fail($mixedCase, 'body');

        self::assertFalse($result->delivered);
        self::assertSame(
            ["cronheart: cron-monitor ping rejected (monitor_uuid_hash='".substr(hash('sha256', strtolower($mixedCase)), 0, 16)."', action='fail', attempts=1, last_status=".$status.')'],
            $this->lines,
        );
    }

    public function test_a_server_error_after_every_attempt_writes_only_the_sdk_line(): void
    {
        $result = $this->clientAnswering([new Response(503), new Response(503), new Response(503)])->heartbeat(self::UUID);

        self::assertSame(3, $result->attempts);
        self::assertCount(1, $this->lines);
        self::assertStringStartsWith('cronheart: cron-monitor ping failed (', $this->lines[0]);
        self::assertStringContainsString('action=null, attempts=3, last_status=503', $this->lines[0]);
    }

    public function test_a_throwing_log_writer_never_reaches_the_caller(): void
    {
        $throwing = new DebugLogLogger(
            enabled: static fn (): bool => true,
            write: static fn (string $line) => throw new \RuntimeException('disk full'),
        );

        $rejected = $this->clientAnswering([new Response(404)], $throwing)->start(self::UUID);
        $unreachable = Client::create(new Configuration(endpoint: 'http://127.0.0.1:1', timeoutSeconds: 1.0, retries: 0, allowInsecureEndpoint: true), $throwing)->start(self::UUID);

        self::assertSame(404, $rejected->statusCode);
        self::assertFalse($unreachable->delivered);
    }

    public function test_a_disabled_client_writes_nothing(): void
    {
        Client::disabled()->heartbeat(self::UUID);

        self::assertSame([], $this->lines);
    }

    /**
     * @param list<Response> $answers
     */
    private function clientAnswering(array $answers, ?DebugLogLogger $logger = null): Client
    {
        $logger ??= $this->logger();
        $factory = new Psr17Factory();
        $sdk = new CronMonitorClient(
            new Configuration(endpoint: 'https://cronheart.example.test', retries: 2),
            new FakeHttpClient($answers),
            $factory,
            $factory,
            $logger,
            static function (float $seconds): void {
            },
        );

        $client = \Closure::bind(static fn (): Client => new Client($sdk, $logger), null, Client::class)();
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    private function logger(bool $enabled = true): DebugLogLogger
    {
        return new DebugLogLogger(
            enabled: static fn (): bool => $enabled,
            write: function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }
}
