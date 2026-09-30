#!/usr/bin/env bash
#
# End-to-end smoke test: installs cronheart-wp into the devstack WP,
# wires it to a cronheart backend, triggers WP-Cron, and asserts the
# pings arrived.
#
# # Inputs
#
#     HEARTBEAT_UUID, EVENT_UUID   two monitors created for this run
#                                  (heartbeat + a test per-event hook).
#     CRONHEART_ENDPOINT           backend URL as seen from the WP
#                                  containers; defaults to
#                                  https://cronheart.com. Anything but
#                                  https:// turns on
#                                  CRONHEART_ALLOW_INSECURE_ENDPOINT.
#     CRONHEART_API_TOKEN          optional. When set, the script reads
#                                  both monitors' ping history through
#                                  the public REST API and fails unless
#                                  this run's pings arrived. Without it,
#                                  verify on the dashboard by hand.
#     CRONHEART_ALLOW_INSECURE_TOKEN=1
#                                  lets the token travel to a non-https
#                                  endpoint; use it only with a
#                                  throwaway token on a local backend.
#
# # Prerequisites
#
#     1. Plugin zip built:   ./bin/build-release.sh
#     2. WP + MySQL up:      docker compose -f devstack/docker-compose.yml up -d
#        (for a backend on a Docker network, layer
#        devstack/docker-compose.local.yml, see README.md)
#
# # Example
#
#     HEARTBEAT_UUID=<uuid> EVENT_UUID=<uuid> CRONHEART_API_TOKEN=<token> \
#         ./devstack/smoke.sh

set -euo pipefail

CRONHEART_ENDPOINT="${CRONHEART_ENDPOINT:-https://cronheart.com}"
case "$CRONHEART_ENDPOINT" in
    https://*) CRONHEART_ALLOW_INSECURE="false" ;;
    *) CRONHEART_ALLOW_INSECURE="true" ;;
esac

if [ -z "${HEARTBEAT_UUID:-}" ] || [ -z "${EVENT_UUID:-}" ]; then
    echo "HEARTBEAT_UUID and EVENT_UUID are required." >&2
    echo "Create two monitors on the backend and re-run:" >&2
    echo "  HEARTBEAT_UUID=<uuid> EVENT_UUID=<uuid> [CRONHEART_API_TOKEN=<token>] ./devstack/smoke.sh" >&2
    exit 2
fi

if [ -n "${CRONHEART_API_TOKEN:-}" ] && [ "$CRONHEART_ALLOW_INSECURE" = "true" ] \
    && [ "${CRONHEART_ALLOW_INSECURE_TOKEN:-}" != "1" ]; then
    echo "Refusing to send CRONHEART_API_TOKEN to $CRONHEART_ENDPOINT without TLS." >&2
    echo "For a throwaway token on a local backend, set CRONHEART_ALLOW_INSECURE_TOKEN=1." >&2
    exit 2
fi

# ── Fixed inputs ──────────────────────────────────────────────────────
SITE_URL="http://localhost:8082"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin"
ADMIN_EMAIL="admin@example.test"
EVENT_HOOK="cronheart_smoke_event"

# ── Helpers ──────────────────────────────────────────────────────────
log() { printf "\n\033[1;34m▸ %s\033[0m\n" "$*"; }
warn() { printf "\033[1;33m! %s\033[0m\n" "$*"; }
fail() { printf "\033[1;31m✗ %s\033[0m\n" "$*"; exit 1; }
ok() { printf "\033[1;32m✓ %s\033[0m\n" "$*"; }

WPCLI="docker compose -f devstack/docker-compose.yml exec -T wp-cli wp"

LIST_PINGS_PHP=$(cat <<'PHP'
$response = wp_remote_get(
    untrailingslashit( CRONHEART_ENDPOINT ) . '/api/v1/monitors/' . getenv( 'SMOKE_MONITOR_UUID' ) . '/pings?limit=100',
    array(
        'timeout'     => 15,
        'redirection' => 0,
        'headers' => array( 'Authorization' => 'Bearer ' . getenv( 'CRONHEART_API_TOKEN' ) ),
    )
);
if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
    WP_CLI::error( 'ping history request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
}
$body = json_decode( wp_remote_retrieve_body( $response ), true );
if ( ! is_array( $body['data'] ?? null ) ) {
    WP_CLI::error( 'ping history response has no data list' );
}
foreach ( $body['data'] as $ping ) {
    echo $ping['id'], ' ', $ping['kind'], "\n";
}
PHP
)

# The token reaches the container through the environment, never through argv.
list_pings() {
    SMOKE_MONITOR_UUID="$1" docker compose -f devstack/docker-compose.yml exec -T \
        -e CRONHEART_API_TOKEN -e SMOKE_MONITOR_UUID wp-cli \
        wp eval "$LIST_PINGS_PHP" --allow-root
}

new_pings() {
    awk 'NR == FNR { seen[$1] = 1; next } NF && !seen[$1]' <(printf '%s\n' "$1") <(printf '%s\n' "$2")
}

count_kind() {
    printf '%s\n' "$1" | awk -v kind="$2" '$2 == kind { n++ } END { print n + 0 }'
}

log "Smoke run against $CRONHEART_ENDPOINT"

# ── 1. WP install ────────────────────────────────────────────────────
log "Installing WordPress (idempotent — skips if already installed)"
$WPCLI core is-installed --allow-root >/dev/null 2>&1 \
    || $WPCLI core install \
        --url="$SITE_URL" \
        --title="Cronheart Smoke" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email \
        --allow-root

# ── 2. Configure endpoint constants in wp-config.php ─────────────────
# Each `wp config set ... --type=constant` is idempotent — adds the
# constant if missing, updates if present.
log "Setting cronheart constants in wp-config.php"
$WPCLI config set CRONHEART_HEARTBEAT_UUID "$HEARTBEAT_UUID" --type=constant --allow-root
$WPCLI config set "CRONHEART_EVENT_$(echo "$EVENT_HOOK" | tr '[:lower:]' '[:upper:]')_UUID" "$EVENT_UUID" --type=constant --allow-root
$WPCLI config set CRONHEART_ENDPOINT "$CRONHEART_ENDPOINT" --type=constant --allow-root
$WPCLI config set CRONHEART_ALLOW_INSECURE_ENDPOINT "$CRONHEART_ALLOW_INSECURE" --type=constant --raw --allow-root

# ── 3. Install + activate plugin ────────────────────────────────────
log "Installing plugin from zip"
$WPCLI plugin install /tmp/cronheart.zip --force --activate --allow-root

# ── 4. Drop a mu-plugin that registers the per-event monitor and
#       schedules a test event for the smoke run.
log "Dropping mu-plugin that registers a per-event monitor + schedules a test event"
docker compose -f devstack/docker-compose.yml exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose -f devstack/docker-compose.yml exec -T wordpress sh -c "cat > /var/www/html/wp-content/mu-plugins/cronheart-smoke.php <<'PHP'
<?php
// Test mu-plugin. Loaded by WP before regular plugins so the
// \`cronheart_monitor()\` registration is visible to PerEventInstrumentation's
// \`plugins_loaded(PHP_INT_MAX)\` enumeration pass.
add_action( 'plugins_loaded', static function (): void {
    if ( function_exists( 'cronheart_monitor' ) ) {
        cronheart_monitor( '${EVENT_HOOK}' ); // UUID comes from CRONHEART_EVENT_… constant
    }
}, 1 );

// Register the test event so WP-CLI can fire it.
add_action( '${EVENT_HOOK}', static function (): void {
    error_log( 'cronheart-smoke: ${EVENT_HOOK} fired successfully' );
}, 10, 0 );

if ( ! wp_next_scheduled( '${EVENT_HOOK}' ) ) {
    wp_schedule_event( time(), 'hourly', '${EVENT_HOOK}' );
}
PHP" || warn "mu-plugin write failed; per-event step will be skipped"

# Re-trigger plugin bootstrap so the mu-plugin's add_action lands.
$WPCLI cache flush --allow-root >/dev/null 2>&1 || true

# ── 5. Fire WP-Cron events ───────────────────────────────────────────
if [ -n "${CRONHEART_API_TOKEN:-}" ]; then
    log "Reading both monitors' ping history before the run"
    HEARTBEAT_BEFORE=$(list_pings "$HEARTBEAT_UUID") || fail "Could not read the heartbeat monitor's pings. Check the endpoint, the token and the UUID."
    EVENT_BEFORE=$(list_pings "$EVENT_UUID") || fail "Could not read the per-event monitor's pings. Check the endpoint, the token and the UUID."
fi

log "Firing heartbeat tick"
$WPCLI cron event run cronheart_heartbeat_tick --allow-root || warn "heartbeat tick run reported failure"

log "Firing test event (${EVENT_HOOK})"
$WPCLI cron event run "$EVENT_HOOK" --allow-root || warn "test event run reported failure"

# ── 6. Verify pings ──────────────────────────────────────────────────
if [ -n "${CRONHEART_API_TOKEN:-}" ]; then
    HEARTBEAT_AFTER=$(list_pings "$HEARTBEAT_UUID") || fail "Could not re-read the heartbeat monitor's pings."
    EVENT_AFTER=$(list_pings "$EVENT_UUID") || fail "Could not re-read the per-event monitor's pings."
    HEARTBEAT_NEW=$(new_pings "$HEARTBEAT_BEFORE" "$HEARTBEAT_AFTER")
    EVENT_NEW=$(new_pings "$EVENT_BEFORE" "$EVENT_AFTER")

    log "New pings on the heartbeat monitor ($HEARTBEAT_UUID):"
    echo "${HEARTBEAT_NEW:-(none)}"
    log "New pings on the per-event monitor ($EVENT_UUID):"
    echo "${EVENT_NEW:-(none)}"

    HEARTBEAT_FOUND=$(count_kind "$HEARTBEAT_NEW" heartbeat)
    START_FOUND=$(count_kind "$EVENT_NEW" start)
    SUCCESS_FOUND=$(count_kind "$EVENT_NEW" success)

    echo
    echo "Heartbeat pings: $HEARTBEAT_FOUND (expected ≥1)"
    echo "Per-event start pings: $START_FOUND (expected ≥1)"
    echo "Per-event success pings: $SUCCESS_FOUND (expected ≥1)"

    if [ "$HEARTBEAT_FOUND" -lt 1 ] || [ "$START_FOUND" -lt 1 ] || [ "$SUCCESS_FOUND" -lt 1 ]; then
        fail "Smoke verification failed — expected pings missing. See output above."
    fi

    echo
    ok "All expected pings observed. Smoke run complete."
else
    cat <<EOF

The plugin has driven a heartbeat tick + a per-event run against
$CRONHEART_ENDPOINT. To verify the pings arrived, open the
cronheart dashboard and inspect the two monitors corresponding to:

  - Heartbeat UUID: $HEARTBEAT_UUID
                    → expect 1 ping of kind 'heartbeat' within the
                      last few seconds.
  - Per-event UUID: $EVENT_UUID
                    → expect 2 pings, kind 'start' then 'success',
                      within the last few seconds.

If those pings did not arrive, check:
  - The WordPress debug log:
      docker compose -f devstack/docker-compose.yml exec wordpress \
          tail /var/www/html/wp-content/debug.log
  - The plugin's resolved UUIDs / endpoint:
      docker compose -f devstack/docker-compose.yml exec wp-cli \
          wp config get CRONHEART_ENDPOINT --allow-root

EOF
    ok "Smoke run complete — verify the pings manually on the dashboard, or re-run with CRONHEART_API_TOKEN."
fi
