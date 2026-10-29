<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\PingsDisabledReason;
use Cronheart\WP\Config\Resolver;

\defined('ABSPATH') || exit;

/**
 * Site-wide wp-admin error notice shown while the plugin sends no pings,
 * so an operator learns why their monitors went quiet. It is shown on every
 * admin screen to users who can manage options, and cannot be dismissed:
 * it disappears once the cause is fixed.
 *
 * The endpoint value itself is never printed, since a URL can carry
 * credentials in its userinfo.
 */
final class PingsDisabledNotice
{
    public function __construct(
        private readonly PingsDisabledReason $reason,
        private readonly Resolver $resolver,
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'.esc_html($this->message()).'</p></div>';
    }

    private function message(): string
    {
        return match ($this->reason) {
            PingsDisabledReason::CurlMissing => __(
                'Cronheart is not sending pings: the PHP cURL extension (curl) is not loaded on this server. Ask your host to enable it. Until then, your Cronheart monitors will alert that these jobs stopped checking in.',
                'cronheart'
            ),
            PingsDisabledReason::ClientUnavailable => __(
                'Cronheart is not sending pings: its ping client could not start on this server. Until then, your Cronheart monitors will alert that these jobs stopped checking in.',
                'cronheart'
            ),
            PingsDisabledReason::EndpointRejected => $this->resolver->endpointIsConstant()
                ? __(
                    'Cronheart is not sending pings: the endpoint set by CRONHEART_ENDPOINT in wp-config.php is not accepted. Use an https:// URL (plain http:// also needs CRONHEART_ALLOW_INSECURE_ENDPOINT), or remove the setting to use cronheart.com. Until then, your Cronheart monitors will alert that these jobs stopped checking in.',
                    'cronheart'
                )
                : __(
                    'Cronheart is not sending pings: the endpoint stored in the cronheart_endpoint option is not accepted. Use an https:// URL (plain http:// also needs CRONHEART_ALLOW_INSECURE_ENDPOINT), or delete the option to use cronheart.com. Until then, your Cronheart monitors will alert that these jobs stopped checking in.',
                    'cronheart'
                ),
        };
    }
}
