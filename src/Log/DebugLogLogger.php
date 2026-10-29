<?php

declare(strict_types=1);

namespace Cronheart\WP\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Writes failed-ping records to the PHP error log while both
 * `WP_DEBUG` and `WP_DEBUG_LOG` are on, as WordPress's own debug log
 * needs, so a site owner can see why a ping did not arrive.
 *
 * The log outlives the job and is often readable by the host or shipped
 * elsewhere, so a line never carries a monitor UUID, an API token or a full
 * URL: only allow-listed context keys with scalar values are written, every
 * URL is cut to its scheme and host, UUID- and token-shaped text is
 * replaced, and control characters become spaces so a value cannot forge a
 * second line.
 *
 * `log()` leaves `$message` untyped so the class still loads when another
 * plugin's autoloader supplies psr/log 1.x, whose interface declares it
 * untyped; a typed parameter there is a fatal error on every request.
 */
final class DebugLogLogger extends AbstractLogger
{
    private const MAX_LINE_BYTES = 500;

    private const WRITTEN_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
    ];

    private const CONTEXT_KEYS = ['monitor_uuid_hash', 'action', 'attempts', 'last_status', 'last_error', 'error'];

    /**
     * @param \Closure(): bool       $enabled
     * @param \Closure(string): void $write
     */
    public function __construct(
        private readonly \Closure $enabled,
        private readonly \Closure $write,
    ) {
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (!\in_array($level, self::WRITTEN_LEVELS, true) || !($this->enabled)()) {
            return;
        }

        $fields = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if (!\array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (null === $value || \is_scalar($value)) {
                $fields[] = $key.'='.self::redact(self::format($value));
            }
        }

        $line = 'cronheart: '.self::redact((string) $message).([] === $fields ? '' : ' ('.implode(', ', $fields).')');
        ($this->write)(self::cap($line));
    }

    private static function cap(string $line): string
    {
        $cut = substr($line, 0, self::MAX_LINE_BYTES);
        for ($dropped = 0; $dropped < 3 && $cut !== $line && 1 !== preg_match('//u', $cut); ++$dropped) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static function format(bool|float|int|string|null $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => "'".$value."'",
            default => (string) $value,
        };
    }

    private static function redact(string $text): string
    {
        // A quoted URL runs to its closing quote, so a credential holding a
        // space or a # cannot leave a fragment past the cut.
        $text = preg_replace_callback(
            '#([\'"])([a-z][a-z0-9+.-]*://)([^\'"]*)\1#i',
            static function (array $match): string {
                $afterUserinfo = substr($match[3], (int) strrpos('@'.$match[3], '@'));

                return $match[1].$match[2].(preg_match('#^(\[[^\]]*\]|[^/?\#:\s]*)#', $afterUserinfo, $host) ? $host[1] : '').$match[1];
            },
            $text,
        ) ?? '{unreadable}';
        $text = preg_replace(
            '#\b([a-z][a-z0-9+.-]*://)(?:[^\s"\'<>/?\#]*@)?(\[[^\]\s]*\]|[^\s"\'<>/?\#:@]*)[^\s"\'<>]*#i',
            '$1$2',
            $text,
        ) ?? '{unreadable}';
        $text = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $text) ?? '{unreadable}';
        $text = preg_replace('/cmk_[A-Za-z0-9_-]+/', '{api_key}', $text) ?? '{unreadable}';

        return preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text) ?? '{unreadable}';
    }
}
