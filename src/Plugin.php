<?php

declare(strict_types=1);

namespace Cronheart\WP;

/**
 * Main plugin class. Wired up from the top-level `cronheart.php`
 * bootstrap.
 *
 * `boot()` is intentionally a no-op in this scaffold commit — the
 * heartbeat and per-event monitoring layers land in subsequent commits
 * and register themselves through this class. Keeping the constructor
 * side-effect free means the class is unit-testable without a booted
 * WordPress runtime.
 */
final class Plugin
{
    public function boot(): void
    {
        // Layers attached in later commits:
        // - HeartbeatScheduler / HeartbeatHandler
        // - PerEventInstrumentation
        // - Admin\SettingsPage
    }
}
