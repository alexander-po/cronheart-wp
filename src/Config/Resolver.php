<?php

declare(strict_types=1);

namespace Cronheart\WP\Config;

/**
 * Resolves UUIDs from the three configured sources for both the
 * heartbeat layer and the per-event layer (which lands in the next
 * commit and consumes `eventUuid()`).
 *
 * Source precedence, highest first:
 *
 *   1. **wp-config.php constant**
 *      - Site heartbeat: `CRONHEART_HEARTBEAT_UUID`
 *      - Per-event:      `CRONHEART_EVENT_<HOOK>_UUID`
 *        (hook name uppercased, `-`/`.` → `_`)
 *      Recommended for production — keeps the per-monitor UUID, a
 *      write capability secret, out of the database and out of any
 *      file an admin user with manage_options can read.
 *
 *   2. **WordPress option**
 *      - Site heartbeat: `cronheart_heartbeat_uuid` (string)
 *      - Per-event:      `cronheart_event_map`      (array<hook, uuid>)
 *      Set through the admin UI that lands in commit 4. Convenient
 *      for non-technical operators who do not control wp-config.php.
 *
 *   3. **`cronheart_monitor_map` filter** (per-event only)
 *      Programmatic registration through the `cronheart_monitor()`
 *      helper in commit 3 — the WP-idiomatic registration channel
 *      for plugin developers wiring their own hooks.
 *
 * **Empty strings** at any level are treated as an explicit "do not
 * monitor in this environment" signal — parallel to the SDK's
 * `#[Monitor(uuid: '')]` semantics. Mirrors how a missing env var
 * shadows an attribute on the SDK side.
 *
 * **Pure PHP**: the resolver never touches WordPress globals itself.
 * The three closures injected through the constructor own that
 * boundary, which keeps the class unit-testable without Brain Monkey
 * or any WP runtime. `Plugin::boot()` wires the closures to
 * `get_option`, `defined`+`constant`, and `apply_filters` against
 * the real WP runtime.
 */
final class Resolver
{
    public const HEARTBEAT_CONSTANT = 'CRONHEART_HEARTBEAT_UUID';
    public const HEARTBEAT_OPTION = 'cronheart_heartbeat_uuid';
    public const EVENT_MAP_OPTION = 'cronheart_event_map';
    public const EVENT_MAP_FILTER = 'cronheart_monitor_map';
    public const EVENT_CONSTANT_PREFIX = 'CRONHEART_EVENT_';
    public const EVENT_CONSTANT_SUFFIX = '_UUID';

    /**
     * @param \Closure(string): ?string                      $constantReader returns the
     *                                                                       string value
     *                                                                       of a defined
     *                                                                       constant, or
     *                                                                       null
     * @param \Closure(string): mixed                        $optionReader   returns the
     *                                                                       current
     *                                                                       value of a
     *                                                                       WP option,
     *                                                                       or null when
     *                                                                       unset
     * @param \Closure(string, array<string, string>): mixed $filterApplier  applies a
     *                                                                       WP filter
     *                                                                       with the
     *                                                                       given empty
     *                                                                       default
     */
    public function __construct(
        private readonly \Closure $constantReader,
        private readonly \Closure $optionReader,
        private readonly \Closure $filterApplier,
    ) {
    }

    public function heartbeatUuid(): ?string
    {
        $fromConstant = ($this->constantReader)(self::HEARTBEAT_CONSTANT);
        if (null !== $fromConstant) {
            return '' === $fromConstant ? null : $fromConstant;
        }

        $fromOption = ($this->optionReader)(self::HEARTBEAT_OPTION);
        if (\is_string($fromOption)) {
            return '' === $fromOption ? null : $fromOption;
        }

        return null;
    }

    public function eventUuid(string $hookName): ?string
    {
        $fromConstant = ($this->constantReader)(self::EVENT_CONSTANT_PREFIX
            .self::normaliseHookForConstant($hookName)
            .self::EVENT_CONSTANT_SUFFIX);
        if (null !== $fromConstant) {
            return '' === $fromConstant ? null : $fromConstant;
        }

        $optionMap = ($this->optionReader)(self::EVENT_MAP_OPTION);
        if (\is_array($optionMap) && \array_key_exists($hookName, $optionMap)) {
            $value = $optionMap[$hookName];
            if (\is_string($value)) {
                return '' === $value ? null : $value;
            }
        }

        $filterMap = ($this->filterApplier)(self::EVENT_MAP_FILTER, []);
        if (\is_array($filterMap) && \array_key_exists($hookName, $filterMap)) {
            $value = $filterMap[$hookName];
            if (\is_string($value)) {
                return '' === $value ? null : $value;
            }
        }

        return null;
    }

    /**
     * `app:reports:nightly` → `APP_REPORTS_NIGHTLY`. WP hook names by
     * convention use snake_case but can include `:`, `-`, `.`; we
     * normalise the lot to underscores so the constant name is a
     * legal PHP identifier.
     */
    private static function normaliseHookForConstant(string $hookName): string
    {
        return strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $hookName) ?? '');
    }
}
