<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\Ajax;
use Cronheart\WP\Admin\EventList;
use Cronheart\WP\Admin\SettingsPage;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    private const VALID_UUID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_sanitize_uuid_accepts_valid_v4_uuid_and_lowercases_it(): void
    {
        self::assertSame(self::VALID_UUID, SettingsPage::sanitize_uuid(self::VALID_UUID));
        self::assertSame(self::VALID_UUID, SettingsPage::sanitize_uuid(strtoupper(self::VALID_UUID)));
    }

    public function test_sanitize_uuid_accepts_empty_string_as_explicit_suppression(): void
    {
        // Empty string is the SDK's `#[Monitor(uuid: '')]` and the
        // resolver's empty-constant-as-suppression equivalent. The
        // admin form must allow it without raising an error notice
        // so an operator can deliberately disable monitoring.
        Functions\expect('add_settings_error')->never();

        self::assertSame('', SettingsPage::sanitize_uuid(''));
        self::assertSame('', SettingsPage::sanitize_uuid('   '));
    }

    public function test_sanitize_uuid_drops_invalid_input_and_surfaces_settings_error(): void
    {
        Functions\expect('add_settings_error')
            ->once()
            ->with(
                Resolver::HEARTBEAT_OPTION,
                'cronheart_invalid_uuid',
                self::stringContains('valid v4 UUID'),
            );
        // esc_html__ is the WP escape helper for translated strings;
        // Brain Monkey returns the input unchanged.
        Functions\when('esc_html__')->returnArg();
        Functions\when('__')->returnArg();

        self::assertSame('', SettingsPage::sanitize_uuid('not-a-uuid'));
    }

    public function test_sanitize_uuid_returns_empty_for_non_string_input(): void
    {
        // Settings API can pass anything from `$_POST`; defensive
        // type-guard returns empty without a settings-error notice
        // (since the form never offered an array/null path).
        Functions\expect('add_settings_error')->never();

        self::assertSame('', SettingsPage::sanitize_uuid(['unexpected']));
        self::assertSame('', SettingsPage::sanitize_uuid(null));
        self::assertSame('', SettingsPage::sanitize_uuid(42));
    }

    public function test_register_attaches_menu_and_settings_hooks(): void
    {
        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('admin_init')->once();

        $this->buildPage()->register();

        // Brain Monkey verifies expectations on tearDown — PHPUnit's
        // own assertion counter does not see them. Bump the counter
        // so PHPUnit does not flag the test as risky.
        $this->addToAssertionCount(2);
    }

    public function test_add_menu_registers_options_page_with_manage_options_capability(): void
    {
        Functions\when('__')->returnArg();
        Functions\expect('add_options_page')
            ->once()
            ->with(
                'Cronheart',
                'Cronheart',
                'manage_options',
                SettingsPage::MENU_SLUG,
                self::isType('array'),
            )
            ->andReturn('cronheart-page-hook');

        $this->buildPage()->add_menu();

        $this->addToAssertionCount(1);
    }

    public function test_register_settings_wires_both_sections_and_fields(): void
    {
        Functions\when('__')->returnArg();

        // Heartbeat setting/section/field (unchanged) ...
        Functions\expect('register_setting')
            ->once()
            ->with(
                SettingsPage::OPTION_GROUP,
                Resolver::HEARTBEAT_OPTION,
                self::callback(static fn (array $args): bool => 'string' === $args['type']
                    && \is_array($args['sanitize_callback'])
                    && '' === $args['default']),
            );
        Functions\expect('add_settings_section')
            ->once()
            ->with(
                SettingsPage::HEARTBEAT_SECTION,
                'Site heartbeat',
                self::isType('array'),
                SettingsPage::MENU_SLUG,
            );
        Functions\expect('add_settings_field')
            ->once()
            ->with(
                Resolver::HEARTBEAT_OPTION,
                'Monitor UUID',
                self::isType('array'),
                SettingsPage::MENU_SLUG,
                SettingsPage::HEARTBEAT_SECTION,
            );

        // ... plus the new API connection setting/section/field.
        Functions\expect('register_setting')
            ->once()
            ->with(
                SettingsPage::OPTION_GROUP,
                Resolver::API_TOKEN_OPTION,
                self::callback(static fn (array $args): bool => 'string' === $args['type']
                    && \is_array($args['sanitize_callback'])
                    && '' === $args['default']),
            );
        Functions\expect('add_settings_section')
            ->once()
            ->with(
                SettingsPage::API_SECTION,
                'cronheart.com connection',
                self::isType('array'),
                SettingsPage::MENU_SLUG,
            );
        Functions\expect('add_settings_field')
            ->once()
            ->with(
                Resolver::API_TOKEN_OPTION,
                'API token',
                self::isType('array'),
                SettingsPage::MENU_SLUG,
                SettingsPage::API_SECTION,
            );

        $this->buildPage()->register_settings();

        $this->addToAssertionCount(6);
    }

    public function test_sanitize_api_token_accepts_a_cmk_token(): void
    {
        Functions\when('get_option')->justReturn('');
        unset($_POST[SettingsPage::API_TOKEN_CLEAR_FIELD]);

        $token = 'cmk_'.str_repeat('a', 43);
        self::assertSame($token, SettingsPage::sanitize_api_token($token));
    }

    public function test_sanitize_api_token_empty_submit_keeps_existing(): void
    {
        Functions\when('get_option')->justReturn('cmk_existing_token_value');
        unset($_POST[SettingsPage::API_TOKEN_CLEAR_FIELD]);
        Functions\expect('add_settings_error')->never();

        self::assertSame('cmk_existing_token_value', SettingsPage::sanitize_api_token(''));
        self::assertSame('cmk_existing_token_value', SettingsPage::sanitize_api_token('   '));
    }

    public function test_sanitize_api_token_rejects_non_cmk_without_wiping_existing(): void
    {
        Functions\when('get_option')->justReturn('cmk_existing_token_value');
        Functions\when('esc_html__')->returnArg();
        unset($_POST[SettingsPage::API_TOKEN_CLEAR_FIELD]);
        Functions\expect('add_settings_error')
            ->once()
            ->with(
                Resolver::API_TOKEN_OPTION,
                'cronheart_invalid_api_token',
                self::stringContains('cmk_'),
            );

        // A bad replacement attempt must NOT destroy the good stored token.
        self::assertSame('cmk_existing_token_value', SettingsPage::sanitize_api_token('not-a-token'));
    }

    public function test_sanitize_api_token_clear_checkbox_wipes_the_token(): void
    {
        Functions\when('get_option')->justReturn('cmk_existing_token_value');
        $_POST[SettingsPage::API_TOKEN_CLEAR_FIELD] = '1';

        try {
            self::assertSame('', SettingsPage::sanitize_api_token(''));
        } finally {
            unset($_POST[SettingsPage::API_TOKEN_CLEAR_FIELD]);
        }
    }

    public function test_render_api_token_field_never_echoes_the_stored_token(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_option')->justReturn('cmk_super_secret_value_1234567890');

        ob_start();
        $this->buildPage()->render_api_token_field();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('cmk_super_secret_value_1234567890', $html, 'the stored token must never be rendered');
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString(SettingsPage::API_TOKEN_CLEAR_FIELD, $html, 'a stored token offers a remove checkbox');
    }

    public function test_render_api_intro_shows_wp_config_notice_when_constant_set(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();

        $resolver = $this->resolverWithConstants([Resolver::API_TOKEN_CONSTANT => 'cmk_from_wp_config']);

        ob_start();
        $this->buildPage($resolver)->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString(Resolver::API_TOKEN_CONSTANT, $html);
        self::assertStringContainsString('wp-config.php', $html);
        self::assertStringNotContainsString('cmk_from_wp_config', $html, 'the constant value must not be echoed');
    }

    public function test_render_heartbeat_field_renders_monitor_dropdown_when_listing_succeeds(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('get_option')->justReturn('');
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );

        $monitors = [
            $this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports'),
            $this->makeMonitor('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Hourly sync'),
        ];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('— Do not monitor —', $html);
        self::assertStringContainsString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $html);
        self::assertStringContainsString('Nightly reports', $html);
        self::assertStringContainsString('Hourly sync', $html);
        self::assertStringNotContainsString('<input type="text"', $html);
    }

    public function test_render_heartbeat_field_preselects_the_saved_monitor(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('get_option')->justReturn('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );

        $monitors = [
            $this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports'),
            $this->makeMonitor('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Hourly sync'),
        ];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        self::assertStringContainsString("selected='selected'", $html);
        self::assertStringNotContainsString('(not in this account)', $html);
    }

    public function test_render_heartbeat_field_keeps_an_unknown_saved_uuid_selectable(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('get_option')->justReturn('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );

        $monitors = [
            $this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports'),
        ];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        // The saved-but-unlisted UUID must survive a form save rather than
        // being silently dropped to the blank option.
        self::assertStringContainsString('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $html);
        self::assertStringContainsString('(not in this account)', $html);
    }

    public function test_render_heartbeat_field_falls_back_to_text_input_when_listing_fails(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('get_option')->justReturn('');

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            static function (string $token): ManagementClient {
                throw new AuthenticationException('bad token');
            },
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<input type="text"', $html);
        self::assertStringNotContainsString('<select', $html);
    }

    public function test_render_heartbeat_field_uses_text_input_and_skips_fetch_without_a_token(): void
    {
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_option')->justReturn('');

        $listerCalled = false;
        $page = $this->buildPage(
            null,
            static function (string $token) use (&$listerCalled): ManagementClient {
                $listerCalled = true;

                throw new \LogicException('the management client factory must not run without a token');
            },
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<input type="text"', $html);
        self::assertStringNotContainsString('<select', $html);
        self::assertFalse($listerCalled, 'the lister must not run when no API token is configured');
    }

    public function test_render_api_intro_reports_connected_monitor_count(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number, string $domain = ''): string => 1 === $number ? $single : $plural
        );

        $monitors = [$this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-success', $html);
        self::assertStringContainsString('Connected', $html);
    }

    public function test_render_api_intro_shows_warning_notice_when_authentication_fails(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            static function (string $token): ManagementClient {
                throw new AuthenticationException('bad token');
            },
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('authenticate', $html);
        self::assertStringNotContainsString('Upgrade your plan', $html);
    }

    public function test_render_api_intro_shows_upgrade_link_on_plan_restriction(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            static function (string $token): ManagementClient {
                throw new PlanRestrictionException('plan too low', 'https://cronheart.com/billing/upgrade');
            },
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('Upgrade your plan', $html);
        self::assertStringContainsString('https://cronheart.com/billing/upgrade', $html);
    }

    public function test_render_api_intro_handles_rate_limit_with_a_retry_notice(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            static function (string $token): ManagementClient {
                throw new RateLimitException('slow down', 30);
            },
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('rate-limiting', $html);
    }

    public function test_render_falls_back_with_generic_notice_on_transport_failure(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_option')->justReturn('');

        // The catch-all \Throwable arm — any ApiException subclass other
        // than the three named ones (here a transport failure), plus the
        // misconfigured-endpoint \RuntimeException, lands here.
        $lister = static function (string $token): ManagementClient {
            throw new ApiTransportException('connection refused');
        };

        $intro = $this->buildPage($this->resolverWithApiToken('cmk_'.str_repeat('a', 43)), $lister);
        ob_start();
        $intro->render_api_intro();
        $introHtml = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $introHtml);
        self::assertStringContainsString('Could not reach', $introHtml);
        self::assertStringNotContainsString('Upgrade your plan', $introHtml);

        $field = $this->buildPage($this->resolverWithApiToken('cmk_'.str_repeat('a', 43)), $lister);
        ob_start();
        $field->render_heartbeat_field();
        $fieldHtml = (string) ob_get_clean();

        self::assertStringContainsString('<input type="text"', $fieldHtml);
        self::assertStringNotContainsString('<select', $fieldHtml);
    }

    public function test_render_api_intro_reports_an_account_with_no_monitors(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning([]),
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-info', $html);
        self::assertStringContainsString('no monitors yet', $html);
        self::assertStringNotContainsString('notice-success', $html);
    }

    public function test_render_api_intro_renders_the_account_plan_card(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number, string $domain = ''): string => 1 === $number ? $single : $plural
        );

        $monitors = [$this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors, $this->accountWire('Starter', 50, 10)),
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('cronheart-account-card', $html);
        self::assertStringContainsString('Plan: Starter', $html);
        self::assertStringContainsString('Monitors: 10 of 50 used (40 remaining)', $html);
        self::assertStringContainsString('API rate limit: 119 of 120 requests remaining', $html);
        self::assertStringNotContainsString('close to your monitor limit', $html);
    }

    public function test_account_card_shows_upgrade_nudge_when_budget_nearly_exhausted(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number, string $domain = ''): string => 1 === $number ? $single : $plural
        );

        $monitors = [$this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors, $this->accountWire('Starter', 20, 18)),
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('close to your monitor limit', $html);
        self::assertStringContainsString('Upgrade your plan for more monitors', $html);
        self::assertStringContainsString('https://cronheart.com', $html);
    }

    public function test_account_card_absent_when_account_fetch_fails_but_page_still_renders(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number, string $domain = ''): string => 1 === $number ? $single : $plural
        );

        $monitors = [$this->makeMonitor('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryMonitorsOkAccountFails($monitors),
        );

        ob_start();
        $page->render_api_intro();
        $html = (string) ob_get_clean();

        // The monitor listing succeeded, so the connection notice still
        // shows; the account card is simply absent and nothing fatals.
        self::assertStringContainsString('notice-success', $html);
        self::assertStringContainsString('Connected', $html);
        self::assertStringNotContainsString('cronheart-account-card', $html);
        self::assertStringNotContainsString('Plan: ', $html);
    }

    public function test_picker_option_includes_the_monitor_status(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('get_option')->justReturn('');
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );

        $monitors = [$this->makeMonitor(self::VALID_UUID, 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        ob_start();
        $page->render_heartbeat_field();
        $html = (string) ob_get_clean();

        // makeMonitor() uses MonitorStatus::Up — its label appears in the
        // option text alongside the name and UUID.
        self::assertStringContainsString('Nightly reports — Up — '.self::VALID_UUID, $html);
    }

    public function test_render_lists_account_monitors_with_lifecycle_actions(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('get_option')->justReturn('');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );

        $monitors = [$this->makeMonitor(self::VALID_UUID, 'Nightly reports')];

        $page = $this->buildPage(
            $this->resolverWithApiToken('cmk_'.str_repeat('a', 43)),
            $this->factoryReturning($monitors),
        );

        // Populate the listing the way a real render would (the section
        // callbacks fetch it); do_settings_sections is stubbed, so prime it
        // through the heartbeat field, then render the page shell.
        ob_start();
        $page->render_heartbeat_field();
        ob_get_clean();

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('cronheart-monitors', $html);
        self::assertStringContainsString('data-cronheart-uuid="'.self::VALID_UUID.'"', $html);
        self::assertStringContainsString('data-cronheart-op="pause"', $html);
        self::assertStringContainsString('data-cronheart-op="snooze"', $html);
        self::assertStringContainsString('data-cronheart-op="unsnooze"', $html);
        self::assertStringContainsString('cronheart-snooze-duration', $html);
    }

    public function test_enqueue_assets_skips_other_admin_screens(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('add_options_page')->justReturn('settings_page_cronheart');
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_localize_script')->never();

        $page = $this->buildPage(null, null, '/plugins/cronheart/cronheart.php');
        $page->add_menu();
        $page->enqueue_assets('index.php');

        $this->addToAssertionCount(1);
    }

    public function test_enqueue_assets_loads_on_the_cronheart_screen(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('add_options_page')->justReturn('settings_page_cronheart');
        Functions\when('plugins_url')->alias(static fn (string $path, string $file): string => 'https://example.test/'.$path);
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-admin/'.$path);
        Functions\when('wp_create_nonce')->justReturn('nonce-value');
        Functions\expect('wp_enqueue_style')->once();
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_localize_script')
            ->once()
            ->with(
                'cronheart-admin',
                'cronheartAdmin',
                self::callback(static fn (array $data): bool => 'nonce-value' === $data['nonce']
                    && Ajax::ACTION === $data['action']
                    && \is_array($data['i18n'])),
            );

        $page = $this->buildPage(null, null, '/plugins/cronheart/cronheart.php');
        $page->add_menu();
        $page->enqueue_assets('settings_page_cronheart');

        $this->addToAssertionCount(4);
    }

    public function test_render_aborts_with_wp_die_when_user_lacks_capability(): void
    {
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
        Functions\when('esc_html__')->returnArg();
        Functions\expect('wp_die')
            ->once()
            ->andReturnUsing(static function (string $msg): void {
                throw new \RuntimeException($msg);
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission');

        $this->buildPage()->render();
    }

    private function buildPage(?Resolver $resolver = null, ?\Closure $managementClientFactory = null, string $pluginFile = ''): SettingsPage
    {
        $resolver ??= new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );

        return new SettingsPage(new EventList($resolver), $resolver, $managementClientFactory, $pluginFile);
    }

    /**
     * A factory whose {@see ManagementClient} lists exactly the given
     * monitors and, on the account endpoint, returns the given account
     * snapshot (a default Starter snapshot when null). The client drives a
     * real SDK {@see MonitorApiClient} over a fake PSR-18 transport, so the
     * resolver → factory → listMonitors / account paths are exercised end to
     * end rather than short-circuited with a plain return. The connection
     * status renders the monitor listing first and the account card second,
     * so the queue order is [monitors page, account snapshot].
     *
     * @param list<Monitor>             $monitors
     * @param array<string, mixed>|null $account  default Starter snapshot when null
     *
     * @return \Closure(string): ManagementClient
     */
    private function factoryReturning(array $monitors, ?array $account = null): \Closure
    {
        $page = (string) json_encode([
            'data' => array_map([$this, 'monitorWire'], $monitors),
            'total' => \count($monitors),
            'limit' => 100,
            'offset' => 0,
        ]);
        $accountJson = (string) json_encode($account ?? $this->accountWire('Starter', 50, 10));

        return static function (string $token) use ($page, $accountJson): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token');
            $http = new FakeHttpClient([
                new Response(200, ['Content-Type' => 'application/json'], $page),
                new Response(200, ['Content-Type' => 'application/json'], $accountJson),
            ]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * A factory whose monitor listing succeeds but whose account call fails
     * (HTTP 500). With `retries: 0` the failed account GET is a single
     * request — the SDK otherwise retries 5xx on a retryable GET.
     *
     * @param list<Monitor> $monitors
     *
     * @return \Closure(string): ManagementClient
     */
    private function factoryMonitorsOkAccountFails(array $monitors): \Closure
    {
        $page = (string) json_encode([
            'data' => array_map([$this, 'monitorWire'], $monitors),
            'total' => \count($monitors),
            'limit' => 100,
            'offset' => 0,
        ]);

        return static function (string $token) use ($page): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: 0);
            $http = new FakeHttpClient([
                new Response(200, ['Content-Type' => 'application/json'], $page),
                new Response(500, ['Content-Type' => 'application/problem+json'], '{"title":"Server error","status":500}'),
            ]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function accountWire(string $planLabel, int $limit, int $used): array
    {
        return [
            'plan' => ['key' => strtolower($planLabel), 'label' => $planLabel, 'monitor_limit' => $limit],
            'monitor_budget' => ['used' => $used, 'limit' => $limit, 'remaining' => $limit - $used],
            'api_rate_limit' => ['limit' => 120, 'remaining' => 119],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorWire(Monitor $monitor): array
    {
        return [
            'uuid' => $monitor->uuid,
            'name' => $monitor->name,
            'schedule_kind' => $monitor->scheduleKind->value,
            'schedule_expr' => $monitor->scheduleExpr,
            'tz' => $monitor->tz,
            'grace_seconds' => $monitor->graceSeconds,
            'status' => $monitor->status->value,
            'next_expected_at' => $monitor->nextExpectedAt?->format(\DATE_ATOM),
            'last_ping_at' => $monitor->lastPingAt?->format(\DATE_ATOM),
            'created_at' => $monitor->createdAt->format(\DATE_ATOM),
            'ping_url' => $monitor->pingUrl,
            'badge_url' => $monitor->badgeUrl,
            'snoozed_until' => $monitor->snoozedUntil?->format(\DATE_ATOM),
        ];
    }

    /**
     * A resolver whose API token comes from the option layer, so
     * `apiToken()` is non-null and the picker fetch path engages.
     */
    private function resolverWithApiToken(string $token): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => Resolver::API_TOKEN_OPTION === $name ? $token : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }

    private function makeMonitor(string $uuid, string $name): Monitor
    {
        return new Monitor(
            $uuid,
            $name,
            ScheduleKind::Interval,
            '300',
            'UTC',
            60,
            MonitorStatus::Up,
            null,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'https://cronheart.com/ping/'.$uuid,
            'https://cronheart.com/badge/'.$uuid.'.svg',
        );
    }

    /**
     * @param array<string, mixed> $constants
     */
    private function resolverWithConstants(array $constants): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name) => \array_key_exists($name, $constants) ? $constants[$name] : null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}
