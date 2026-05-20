<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Api;

use Cronheart\WP\Api\Client;
use CronMonitor\Client\Configuration;

final class ClientTest extends \PHPUnit\Framework\TestCase
{
    public function test_client_is_constructible_with_default_configuration(): void
    {
        // Smoke test: the bundled SDK resolves through the plugin's
        // autoloader (no Strauss prefixing yet — deferred pending a
        // first reported collision, see Client.php docblock), and
        // `CronMonitorClient::create()` builds with no arguments
        // (default SaaS endpoint + bundled cURL transport).
        $client = new Client();

        self::assertInstanceOf(Client::class, $client);
    }

    public function test_client_swallows_unreachable_endpoint_into_failed_ping_result(): void
    {
        // End-to-end of the never-throw contract: even with a
        // deliberately broken endpoint (port 1 is reserved and
        // refuses TCP), `Client::heartbeat()` returns a synthetic
        // failed PingResult instead of escaping an exception.
        // Mirrors the SDK's CurlPsr18ClientTest assertion at the
        // wrapper boundary.
        $client = new Client(new Configuration(
            endpoint: 'http://127.0.0.1:1',
            timeoutSeconds: 1.0,
            retries: 0,
            allowInsecureEndpoint: true,
        ));

        $result = $client->heartbeat('11111111-1111-4111-8111-111111111111');

        self::assertFalse($result->delivered);
    }
}
