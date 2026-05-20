<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for cronheart-wp.
 *
 * Loads `vendor/autoload.php` — Composer's PSR-4 autoloader covers
 * both the plugin's own `Cronheart\WP\…` namespace and the unmodified
 * `CronMonitor\…` SDK namespace from vendor/.
 *
 * Vendor namespace prefixing (Strauss / php-scoper) is deferred to
 * v0.1.1+ when we submit to wordpress.org and conflict-isolation
 * becomes meaningful. For the v0.1.0 GitHub-only release, vendor/
 * autoload is sufficient.
 *
 * Brain Monkey, which mocks WordPress core functions, is set up
 * per-test class (in `setUp()`/`tearDown()`) rather than globally —
 * each test wants its own clean mock surface.
 *
 * `ABSPATH` is defined to a sentinel value before any plugin source
 * file is touched. Every PHP file in `src/` carries the canonical
 * `defined('ABSPATH') || exit;` direct-access guard that
 * WordPress.org Plugin Check expects — without this define, the
 * first PSR-4 autoload of `Cronheart\WP\Plugin` from a test class
 * would silently terminate the test runner. The sentinel value
 * (the tests/ directory) is deliberately not a real WordPress
 * install path — it exists solely to make the guards no-op here.
 *
 * The `cronheart_monitor()` global helper used to be wired through
 * Composer's `autoload.files`, but Plugin Check's `ABSPATH || exit`
 * regex and Composer-autoload-triggers-before-bootstrap together
 * forced us to load it explicitly. Production wires it from
 * `cronheart.php`; tests require it directly so the helper test
 * (`tests/Unit/Helpers/MonitorHelperTest.php`) can call it.
 */

\defined('ABSPATH') || \define('ABSPATH', __DIR__.'/');

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Helpers/monitor.php';
