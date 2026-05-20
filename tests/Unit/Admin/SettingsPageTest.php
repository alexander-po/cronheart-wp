<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\EventList;
use Cronheart\WP\Admin\SettingsPage;
use Cronheart\WP\Config\Resolver;
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

    public function test_register_settings_wires_setting_section_and_field(): void
    {
        Functions\when('__')->returnArg();
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

        $this->buildPage()->register_settings();

        $this->addToAssertionCount(3);
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

    private function buildPage(): SettingsPage
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );

        return new SettingsPage(new EventList($resolver));
    }
}
