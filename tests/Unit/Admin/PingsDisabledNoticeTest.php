<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\PingsDisabledNotice;
use Cronheart\WP\Api\PingsDisabledReason;
use Cronheart\WP\Config\Resolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PingsDisabledNoticeTest extends TestCase
{
    private const ENDPOINT = 'http://user:secret@cronheart.example.test';

    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->alias(static fn (string $text): string => '[escaped]'.$text);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_register_hooks_the_render_into_admin_notices(): void
    {
        $notice = new PingsDisabledNotice(PingsDisabledReason::CurlMissing, $this->resolver(fromConstant: true));
        $added = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$added): bool {
            $added[] = [$hook, $callback];

            return true;
        });

        $notice->register();

        self::assertSame([['admin_notices', [$notice, 'render']]], $added);
    }

    /**
     * @return array<string, array{0: PingsDisabledReason, 1: bool, 2: string}>
     */
    public static function reasons(): array
    {
        return [
            'curl missing' => [PingsDisabledReason::CurlMissing, false, 'the PHP cURL extension (curl) is not loaded on this server'],
            'client unavailable' => [PingsDisabledReason::ClientUnavailable, false, 'its ping client could not start on this server'],
            'endpoint rejected in wp-config' => [PingsDisabledReason::EndpointRejected, true, 'the endpoint set by CRONHEART_ENDPOINT in wp-config.php is not accepted'],
            'endpoint rejected in the option' => [PingsDisabledReason::EndpointRejected, false, 'the endpoint stored in the cronheart_endpoint option is not accepted'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_render_shows_an_escaped_error_notice_naming_the_cause(PingsDisabledReason $reason, bool $fromConstant, string $cause): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->render(new PingsDisabledNotice($reason, $this->resolver($fromConstant)));

        self::assertStringStartsWith('<div class="notice notice-error"><p>[escaped]Cronheart is not sending pings: ', $html);
        self::assertStringContainsString($cause, $html);
        self::assertStringEndsWith('Until then, your Cronheart monitors will alert that these jobs stopped checking in.</p></div>', $html);
        self::assertStringNotContainsString('cronheart.example.test', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    public function test_render_prints_nothing_for_a_user_who_cannot_manage_options(): void
    {
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);

        self::assertSame('', $this->render(new PingsDisabledNotice(PingsDisabledReason::EndpointRejected, $this->resolver(fromConstant: true))));
    }

    private function render(PingsDisabledNotice $notice): string
    {
        ob_start();
        $notice->render();

        return (string) ob_get_clean();
    }

    private function resolver(bool $fromConstant): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => $fromConstant && Resolver::ENDPOINT_CONSTANT === $name ? self::ENDPOINT : null,
            optionReader: static fn (string $name): ?string => !$fromConstant && Resolver::ENDPOINT_OPTION === $name ? self::ENDPOINT : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}
