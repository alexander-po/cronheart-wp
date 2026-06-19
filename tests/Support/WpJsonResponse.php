<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Support;

/**
 * Test sentinel standing in for the request termination that
 * {@see \wp_send_json_success()} / {@see \wp_send_json_error()} perform via
 * `wp_die()`. The AJAX tests alias those functions to throw this so the
 * handler stops exactly where production would, and the test can inspect the
 * success flag, payload, and HTTP status it was about to emit.
 */
final class WpJsonResponse extends \RuntimeException
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct('wp_send_json terminated the request');
    }
}
