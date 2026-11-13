<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\Links;
use Cronheart\WP\Admin\SetupNotice;
use Cronheart\WP\Config\Resolver;
use PHPUnit\Framework\TestCase;

final class SetupNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->alias(static fn (string $path): string => 'https://site.example.test/wp-admin/'.$path);
        Functions\when('add_query_arg')->alias(static fn (string $key, string $value, string $url): string => $url.'?'.$key.'='.$value);
        Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url.'&_wpnonce=nonce-for-'.$action);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function tearDown(): void
    {
        unset($_GET['_wpnonce'], $_GET[SetupNotice::DISMISS_PARAM]);
        Monkey\tearDown();
    }

    public function test_register_hooks_the_dismiss_handler_and_the_notice(): void
    {
        $notice = new SetupNotice($this->resolver(null));
        $added = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$added): bool {
            $added[] = [$hook, $callback];

            return true;
        });

        $notice->register();

        self::assertSame([['admin_init', [$notice, 'handle_dismiss']], ['admin_notices', [$notice, 'render']]], $added);
    }

    public function test_it_shows_on_the_plugins_and_dashboard_screens_with_the_ref_signup_link(): void
    {
        Functions\when('get_user_meta')->justReturn('');

        foreach (['plugins', 'dashboard'] as $id) {
            Functions\when('get_current_screen')->justReturn((object) ['id' => $id]);

            $html = $this->render(new SetupNotice($this->resolver(null)));

            self::assertStringContainsString('Cronheart is installed.', $html, $id);
            self::assertStringContainsString('To finish setup: create a free account, add a monitor and paste its UUID, so you hear about it when WP-Cron stops.', $html, $id);
            self::assertStringContainsString('href="'.Links::REGISTER.'"', $html, $id);
            self::assertStringContainsString('options-general.php?page=cronheart', $html, $id);
            self::assertStringContainsString('/wp-admin/'.('plugins' === $id ? 'plugins.php' : 'index.php').'?cronheart_dismiss_setup=1&_wpnonce=nonce-for-cronheart_dismiss_setup', $html, $id);
        }
    }

    public function test_it_stays_off_every_other_screen(): void
    {
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'settings_page_cronheart']);

        self::assertSame('', $this->render(new SetupNotice($this->resolver(null))));
    }

    public function test_it_stays_off_without_a_screen(): void
    {
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_current_screen')->justReturn(null);

        self::assertSame('', $this->render(new SetupNotice($this->resolver(null))));
    }

    public function test_it_is_hidden_from_users_who_cannot_manage_options(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'plugins']);

        self::assertSame('', $this->render(new SetupNotice($this->resolver(null))));
    }

    public function test_it_resolves_itself_once_a_uuid_is_saved(): void
    {
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'plugins']);

        self::assertSame('', $this->render(new SetupNotice($this->resolver('00000000-0000-0000-0000-000000000000'))));
    }

    public function test_it_stays_off_when_a_resolver_filter_throws(): void
    {
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'plugins']);
        $resolver = new Resolver(
            constantReader: static fn (string $name): mixed => null,
            optionReader: static function (string $name): mixed {
                throw new \RuntimeException('third-party option code failed');
            },
            filterApplier: static fn (string $name, array $value) => $value,
        );

        self::assertSame('', $this->render(new SetupNotice($resolver)));
    }

    public function test_a_dismissed_notice_stays_hidden_for_that_user(): void
    {
        Functions\expect('get_user_meta')->once()->with(7, SetupNotice::DISMISS_META, true)->andReturn('1');
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'plugins']);

        self::assertSame('', $this->render(new SetupNotice($this->resolver(null))));
    }

    public function test_dismiss_with_a_valid_nonce_stores_the_choice_for_the_current_user(): void
    {
        $_GET['_wpnonce'] = 'good';
        $_GET[SetupNotice::DISMISS_PARAM] = '1';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\expect('wp_verify_nonce')->once()->with('good', SetupNotice::DISMISS_NONCE)->andReturn(1);
        Functions\expect('update_user_meta')->once()->with(7, SetupNotice::DISMISS_META, '1');

        (new SetupNotice($this->resolver(null)))->handle_dismiss();
        $this->addToAssertionCount(1);
    }

    public function test_dismiss_with_a_bad_nonce_stores_nothing(): void
    {
        $_GET['_wpnonce'] = 'bad';
        $_GET[SetupNotice::DISMISS_PARAM] = '1';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\expect('update_user_meta')->never();

        (new SetupNotice($this->resolver(null)))->handle_dismiss();
        $this->addToAssertionCount(1);
    }

    public function test_dismiss_without_the_parameter_stores_nothing(): void
    {
        $_GET['_wpnonce'] = 'good';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\expect('update_user_meta')->never();

        (new SetupNotice($this->resolver(null)))->handle_dismiss();
        $this->addToAssertionCount(1);
    }

    public function test_dismiss_by_a_user_who_cannot_manage_options_stores_nothing(): void
    {
        $_GET['_wpnonce'] = 'good';
        $_GET[SetupNotice::DISMISS_PARAM] = '1';
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('update_user_meta')->never();

        (new SetupNotice($this->resolver(null)))->handle_dismiss();
        $this->addToAssertionCount(1);
    }

    private function render(SetupNotice $notice): string
    {
        ob_start();
        $notice->render();

        return (string) ob_get_clean();
    }

    private function resolver(?string $uuid): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): mixed => null,
            optionReader: static fn (string $name): mixed => Resolver::HEARTBEAT_OPTION === $name ? $uuid : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}
