<?php

declare(strict_types=1);

namespace Cronheart\WP\Hooks;

use Cronheart\WP\Config\Resolver;

\defined('ABSPATH') || exit;

/**
 * Queues one heartbeat tick when a heartbeat UUID is first saved or
 * changed, so the first ping does not wait for the next five-minute tick.
 * It only schedules a WP-Cron event: no request is sent while the settings
 * are being saved, and a failure here never reaches the save.
 */
final class FirstHeartbeat
{
    public const TICK_ARG = 'first-heartbeat';

    public function register(): void
    {
        add_action('add_option_'.Resolver::HEARTBEAT_OPTION, [$this, 'on_added'], 10, 2);
        add_action('update_option_'.Resolver::HEARTBEAT_OPTION, [$this, 'on_updated'], 10, 2);
    }

    public function on_added(mixed $option, mixed $value): void
    {
        $this->queue('', $value);
    }

    public function on_updated(mixed $old, mixed $new): void
    {
        $this->queue($old, $new);
    }

    private function queue(mixed $old, mixed $new): void
    {
        try {
            if (!\is_string($new) || '' === $new || $new === $old) {
                return;
            }

            if (false !== wp_next_scheduled(HeartbeatScheduler::TICK_HOOK, [self::TICK_ARG])) {
                return;
            }

            wp_schedule_single_event(time(), HeartbeatScheduler::TICK_HOOK, [self::TICK_ARG]);
        } catch (\Throwable) {
            return;
        }
    }
}
