<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Cronheart\WP\Admin\EventList;
use Cronheart\WP\Config\Resolver;
use PHPUnit\Framework\TestCase;

final class EventListTest extends TestCase
{
    public function test_entries_returns_empty_list_when_no_hooks_registered(): void
    {
        $list = new EventList($this->resolverWith([], []));

        self::assertSame([], $list->entries());
    }

    public function test_entries_returns_one_row_per_registered_hook_with_resolved_uuid(): void
    {
        $resolver = $this->resolverWith(
            optionMap: ['app:reports:nightly' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            filterMap: ['app:cleanup:weekly' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
        );

        $entries = (new EventList($resolver))->entries();

        // Sort for deterministic comparison (hook-name order is not
        // a guaranteed contract of the resolver).
        usort($entries, static fn (array $a, array $b): int => $a['hook'] <=> $b['hook']);

        self::assertSame([
            ['hook' => 'app:cleanup:weekly', 'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
            ['hook' => 'app:reports:nightly', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
        ], $entries);
    }

    public function test_entries_renders_suppressed_uuid_as_null(): void
    {
        // An option entry with empty-string UUID is the deliberate
        // "do not monitor in this environment" signal; the resolver
        // returns null. The admin table renders that as `(suppressed)`
        // — verified by checking the DTO carries null cleanly.
        $list = new EventList($this->resolverWith(
            optionMap: ['app:reports:nightly' => ''],
            filterMap: [],
        ));

        self::assertSame(
            [['hook' => 'app:reports:nightly', 'uuid' => null]],
            $list->entries(),
        );
    }

    /**
     * @param array<string, string> $optionMap
     * @param array<string, string> $filterMap
     */
    private function resolverWith(array $optionMap, array $filterMap = []): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => Resolver::EVENT_MAP_OPTION === $name ? $optionMap : null,
            filterApplier: static fn (string $name, array $value) => Resolver::EVENT_MAP_FILTER === $name ? $filterMap : $value,
        );
    }
}
