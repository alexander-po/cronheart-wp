<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit;

use Cronheart\WP\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function test_plugin_class_can_be_instantiated_and_booted(): void
    {
        // Smoke test for the scaffold: prove the PSR-4 autoloader
        // resolves our namespace and the bootstrap class is loadable
        // without a WordPress runtime. Real behaviour tests land in
        // the heartbeat / per-event / admin commits.
        $plugin = new Plugin();
        $plugin->boot();

        $this->expectNotToPerformAssertions();
    }
}
