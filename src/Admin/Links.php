<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

\defined('ABSPATH') || exit;

/**
 * Links to cronheart.com from wp-admin; they carry the `ref=wp-plugin` label.
 */
final class Links
{
    public const GUIDE = 'https://cronheart.com/guides/monitor-wordpress-cron?ref=wp-plugin';
    public const REGISTER = 'https://cronheart.com/register?ref=wp-plugin';
}
