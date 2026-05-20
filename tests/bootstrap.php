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
 */

require __DIR__.'/../vendor/autoload.php';
