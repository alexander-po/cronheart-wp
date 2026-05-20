<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Config\Resolver;

/**
 * Collects the per-event monitor registrations for the admin
 * settings page's read-only "Monitored events" table.
 *
 * Separated from `SettingsPage` so the data-gathering is unit
 * testable without standing up the Settings API or any WP rendering
 * surface — the render side is a tight HTML template (escapes via
 * `esc_html`) that consumes this DTO.
 *
 * v0.1.0 scope: hook name + resolved UUID per entry. Source
 * attribution (constant vs option vs filter) is deferred to v0.1.1
 * along with admin-side validation polish and an "edit per-event
 * map" form — for now operators add entries via
 * `cronheart_monitor()` in PHP or `CRONHEART_EVENT_<HOOK>_UUID` in
 * wp-config.php, and this table reflects whatever they configured.
 */
final class EventList
{
    public function __construct(
        private readonly Resolver $resolver,
    ) {
    }

    /**
     * @return list<array{hook: string, uuid: ?string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->resolver->eventHookNames() as $hook) {
            $entries[] = [
                'hook' => $hook,
                'uuid' => $this->resolver->eventUuid($hook),
            ];
        }

        return $entries;
    }
}
