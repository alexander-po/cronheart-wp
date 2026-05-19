<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for cronheart-wp.
 *
 * `vendor/autoload.php` is enough for the scaffold — Brain Monkey,
 * which mocks WordPress core functions, is set up per-test (see
 * later commits' tests under `tests/Unit/Hooks/`) rather than
 * globally, because each test wants its own clean mock surface.
 */

require __DIR__.'/../vendor/autoload.php';
