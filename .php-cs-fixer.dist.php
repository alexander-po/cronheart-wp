<?php

declare(strict_types=1);

/*
 * Internal-code style. Scoped to `src/` and `tests/` — the SDK-style
 * core of this plugin lives under PSR-4 / strict_types, the same as
 * cron-monitor-php.
 *
 * The plugin's WordPress-facing entry point (`cronheart.php`) and the
 * future Admin classes are NOT covered here — they follow WordPress
 * Coding Standards instead and are enforced via phpcs against
 * `.phpcs.xml.dist`. Trying to dual-enforce both rule sets on the same
 * file produces unfixable noise (snake_case vs camelCase, hook names
 * vs PSR symbols).
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP81Migration' => true,
        '@PHP80Migration:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_to_comment' => false,
        'phpdoc_separation' => false,
        // Match cron-monitor-php's snake_case test method casing.
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
