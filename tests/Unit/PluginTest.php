<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit;

use Cronheart\WP\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function test_plugin_class_is_constructible_without_wp_runtime(): void
    {
        // Bootstrap class itself is side-effect free at construction —
        // hook registration happens in `boot()`, which the runtime
        // call in `cronheart.php` invokes after WordPress functions
        // are available. The integration smoke test that exercises
        // the full `boot()` chain lives in the v0.1.0 release
        // verification (commit 5) against a real WP install.
        $plugin = new Plugin();

        self::assertInstanceOf(Plugin::class, $plugin);
    }
}
