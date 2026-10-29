<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit;

use Cronheart\WP\Log\DebugLogLogger;
use Cronheart\WP\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PluginDebugLogTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, bool>, 1: bool}>
     */
    public static function debugConstants(): array
    {
        return [
            'both on' => [['WP_DEBUG' => true, 'WP_DEBUG_LOG' => true], true],
            'debug off, log on' => [['WP_DEBUG' => false, 'WP_DEBUG_LOG' => true], false],
            'debug on, log undefined' => [['WP_DEBUG' => true], false],
            'debug on, log off' => [['WP_DEBUG' => true, 'WP_DEBUG_LOG' => false], false],
        ];
    }

    /**
     * @param array<string, bool> $constants
     */
    #[DataProvider('debugConstants')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_logger_is_on_only_while_wp_debug_and_wp_debug_log_are_on(array $constants, bool $expected): void
    {
        foreach ($constants as $name => $value) {
            \define($name, $value);
        }

        self::assertSame($expected, self::closure('enabled')());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_writer_appends_the_line_to_the_php_error_log(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cronheart-log');
        self::assertIsString($log);
        ini_set('error_log', $log);

        self::closure('write')('cronheart: test line');

        self::assertStringContainsString('cronheart: test line', (string) file_get_contents($log));
    }

    private static function closure(string $property): \Closure
    {
        $logger = (new \ReflectionMethod(Plugin::class, 'buildDebugLogLogger'))->invoke(null);
        self::assertInstanceOf(DebugLogLogger::class, $logger);
        $closure = (new \ReflectionProperty(DebugLogLogger::class, $property))->getValue($logger);
        self::assertInstanceOf(\Closure::class, $closure);

        return $closure;
    }
}
