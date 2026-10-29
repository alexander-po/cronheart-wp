#!/usr/bin/env bash
#
# End-to-end smoke test: brings up the devstack, installs cronheart-wp
# into its WordPress, wires it to a cronheart backend, triggers WP-Cron,
# and asserts the pings arrived.
#
# # Inputs
#
#     HEARTBEAT_UUID, EVENT_UUID, FAIL_EVENT_UUID
#                                  three monitors created for this run:
#                                  the heartbeat, a test per-event hook
#                                  that succeeds, and one that throws.
#     CRONHEART_ENDPOINT           backend URL as seen from the WP
#                                  containers; defaults to
#                                  https://cronheart.com. Anything but
#                                  https:// turns on
#                                  CRONHEART_ALLOW_INSECURE_ENDPOINT.
#     CRONHEART_BACKEND_NETWORK    optional. The backend's Docker
#                                  network; when set, the stack also
#                                  loads devstack/docker-compose.local.yml
#                                  and joins it. Pass it per run rather
#                                  than exporting it.
#     CRONHEART_API_TOKEN          optional. When set, the script reads
#                                  each monitor's ping history through
#                                  the public REST API before the stack
#                                  starts, so a bad token or UUID fails
#                                  fast, and fails unless this run's
#                                  pings arrived: a heartbeat, a start
#                                  and a success, and a start and exactly
#                                  one fail for the throwing hook.
#                                  Without it, verify on the dashboard by
#                                  hand.
#     CRONHEART_ALLOW_INSECURE_TOKEN=1
#                                  lets the token travel to a non-https
#                                  endpoint; use it only with a
#                                  throwaway token on a local backend.
#
# # Prerequisites
#
#     Plugin zip built:   ./bin/build-release.sh
#
# # Example
#
#     HEARTBEAT_UUID=<uuid> EVENT_UUID=<uuid> FAIL_EVENT_UUID=<uuid> \
#         CRONHEART_API_TOKEN=<token> ./devstack/smoke.sh

set -euo pipefail

CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

export CRONHEART_ENDPOINT="${CRONHEART_ENDPOINT:-https://cronheart.com}"
case "$CRONHEART_ENDPOINT" in
    https://*) CRONHEART_ALLOW_INSECURE="false" ;;
    *) CRONHEART_ALLOW_INSECURE="true" ;;
esac

if [ -z "${HEARTBEAT_UUID:-}" ] || [ -z "${EVENT_UUID:-}" ] || [ -z "${FAIL_EVENT_UUID:-}" ]; then
    echo "HEARTBEAT_UUID, EVENT_UUID and FAIL_EVENT_UUID are required." >&2
    echo "Create three monitors on the backend and re-run:" >&2
    echo "  HEARTBEAT_UUID=<uuid> EVENT_UUID=<uuid> FAIL_EVENT_UUID=<uuid> [CRONHEART_API_TOKEN=<token>] ./devstack/smoke.sh" >&2
    exit 2
fi

if [ -n "${CRONHEART_API_TOKEN:+x}" ] && [ "$CRONHEART_ALLOW_INSECURE" = "true" ] \
    && [ "${CRONHEART_ALLOW_INSECURE_TOKEN:-}" != "1" ]; then
    echo "Refusing to send CRONHEART_API_TOKEN to $CRONHEART_ENDPOINT without TLS." >&2
    echo "For a throwaway token on a local backend, set CRONHEART_ALLOW_INSECURE_TOKEN=1." >&2
    exit 2
fi

if [ ! -f build/cronheart.zip ]; then
    echo "build/cronheart.zip not found; run ./bin/build-release.sh first." >&2
    exit 2
fi

# ── Fixed inputs ──────────────────────────────────────────────────────
SITE_URL="http://localhost:8082"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin"
ADMIN_EMAIL="admin@example.test"
EVENT_HOOK="cronheart_smoke_event"
FAIL_EVENT_HOOK="cronheart_smoke_fail_event"
POLL_ATTEMPTS=5
POLL_INTERVAL_SECONDS=3
WORDPRESS_WAIT_SECONDS=60

# ── Helpers ──────────────────────────────────────────────────────────
log() { printf "\n\033[1;34m▸ %s\033[0m\n" "$*"; }
warn() { printf "\033[1;33m! %s\033[0m\n" "$*"; }
fail() { printf "\033[1;31m✗ %s\033[0m\n" "$*"; exit 1; }
ok() { printf "\033[1;32m✓ %s\033[0m\n" "$*"; }

COMPOSE=(docker compose -f devstack/docker-compose.yml)
if [ -n "${CRONHEART_BACKEND_NETWORK:-}" ]; then
    COMPOSE+=(-f devstack/docker-compose.local.yml)
fi
WPCLI=("${COMPOSE[@]}" exec -T wp-cli wp)

LIST_PINGS_PHP=$(cat <<'PHP'
$url = rtrim( getenv( 'CRONHEART_ENDPOINT' ), '/' ) . '/api/v1/monitors/' . rawurlencode( getenv( 'SMOKE_MONITOR_UUID' ) ) . '/pings?limit=100';
$context = stream_context_create( array( 'http' => array(
    'header'          => 'Authorization: Bearer ' . trim( (string) fgets( STDIN ) ),
    'timeout'         => 15,
    'follow_location' => 0,
    'ignore_errors'   => true,
) ) );
$body = @file_get_contents( $url, false, $context );
$status = $http_response_header[0] ?? ( error_get_last()['message'] ?? 'no response' );
if ( false === $body || ! preg_match( '#^HTTP/\S+ 200\b#', $status ) ) {
    fwrite( STDERR, "ping history request failed: $status\n" );
    exit( 1 );
}
$data = json_decode( $body, true )['data'] ?? null;
if ( ! is_array( $data ) ) {
    fwrite( STDERR, "ping history response has no data list\n" );
    exit( 1 );
}
foreach ( $data as $ping ) {
    echo $ping['id'], ' ', $ping['kind'], "\n";
}
PHP
)

# A one-off wp-cli container, so the read works before the stack is up and
# on the same networks. The token reaches it on stdin, never through argv or
# the container's environment.
list_pings() {
    printenv CRONHEART_API_TOKEN | SMOKE_MONITOR_UUID="$1" "${COMPOSE[@]}" run --rm --no-deps -T \
        -e CRONHEART_ENDPOINT -e SMOKE_MONITOR_UUID \
        --entrypoint php wp-cli -r "$LIST_PINGS_PHP"
}

new_pings() {
    awk 'NR == FNR { seen[$1] = 1; next } NF && !seen[$1]' <(printf '%s\n' "$1") <(printf '%s\n' "$2")
}

count_kind() {
    printf '%s\n' "$1" | awk -v kind="$2" '$2 == kind { n++ } END { print n + 0 }'
}

log "Smoke run against $CRONHEART_ENDPOINT${CRONHEART_BACKEND_NETWORK:+, joined to Docker network $CRONHEART_BACKEND_NETWORK}"

if [ -n "${CRONHEART_API_TOKEN:+x}" ]; then
    log "Reading the three monitors' ping history before the stack starts"
    HEARTBEAT_BEFORE=$(list_pings "$HEARTBEAT_UUID") || fail "Could not read the heartbeat monitor's pings. Check the endpoint, the token and the UUID."
    EVENT_BEFORE=$(list_pings "$EVENT_UUID") || fail "Could not read the per-event monitor's pings. Check the endpoint, the token and the UUID."
    FAIL_EVENT_BEFORE=$(list_pings "$FAIL_EVENT_UUID") || fail "Could not read the failing event monitor's pings. Check the endpoint, the token and the UUID."
fi

log "Starting WordPress + MySQL + WP-CLI"
"${COMPOSE[@]}" up -d

deadline=$((SECONDS + WORDPRESS_WAIT_SECONDS))
until "${COMPOSE[@]}" exec -T wp-cli test -s wp-config.php; do
    [ "$SECONDS" -lt "$deadline" ] || fail "WordPress did not finish setting up its files within ${WORDPRESS_WAIT_SECONDS}s."
    sleep 1
done

# ── 1. WP install ────────────────────────────────────────────────────
log "Installing WordPress (idempotent — skips if already installed)"
"${WPCLI[@]}" core is-installed --allow-root >/dev/null 2>&1 \
    || "${WPCLI[@]}" core install \
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
"${WPCLI[@]}" config set CRONHEART_HEARTBEAT_UUID "$HEARTBEAT_UUID" --type=constant --allow-root
"${WPCLI[@]}" config set "CRONHEART_EVENT_$(echo "$EVENT_HOOK" | tr '[:lower:]' '[:upper:]')_UUID" "$EVENT_UUID" --type=constant --allow-root
"${WPCLI[@]}" config set "CRONHEART_EVENT_$(echo "$FAIL_EVENT_HOOK" | tr '[:lower:]' '[:upper:]')_UUID" "$FAIL_EVENT_UUID" --type=constant --allow-root
"${WPCLI[@]}" config set CRONHEART_ENDPOINT "$CRONHEART_ENDPOINT" --type=constant --allow-root
"${WPCLI[@]}" config set CRONHEART_ALLOW_INSECURE_ENDPOINT "$CRONHEART_ALLOW_INSECURE" --type=constant --raw --allow-root

# ── 3. Install + activate plugin ────────────────────────────────────
log "Installing plugin from zip"
"${WPCLI[@]}" plugin install /tmp/cronheart.zip --force --activate --allow-root

# ── 4. Drop a mu-plugin that registers the per-event monitors and
#       schedules the two test events for the smoke run.
log "Dropping mu-plugin that registers two per-event monitors + schedules their test events"
"${COMPOSE[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
"${COMPOSE[@]}" exec -T wordpress sh -c "cat > /var/www/html/wp-content/mu-plugins/cronheart-smoke.php <<'PHP'
<?php
// Test mu-plugin. Loaded by WP before regular plugins so the
// \`cronheart_monitor()\` registration is visible to PerEventInstrumentation's
// \`plugins_loaded(PHP_INT_MAX)\` enumeration pass.
add_action( 'plugins_loaded', static function (): void {
    if ( function_exists( 'cronheart_monitor' ) ) {
        cronheart_monitor( '${EVENT_HOOK}' ); // UUID comes from CRONHEART_EVENT_… constant
        cronheart_monitor( '${FAIL_EVENT_HOOK}' );
    }
}, 1 );

// Register the test event so WP-CLI can fire it.
add_action( '${EVENT_HOOK}', static function (): void {
    error_log( 'cronheart-smoke: ${EVENT_HOOK} fired successfully' );
}, 10, 0 );

add_action( '${FAIL_EVENT_HOOK}', static function (): void {
    throw new RuntimeException( 'cronheart-smoke: ${FAIL_EVENT_HOOK} failed on purpose' );
}, 10, 0 );

foreach ( array( '${EVENT_HOOK}', '${FAIL_EVENT_HOOK}' ) as \$cronheart_smoke_hook ) {
    if ( ! wp_next_scheduled( \$cronheart_smoke_hook ) ) {
        wp_schedule_event( time(), 'hourly', \$cronheart_smoke_hook );
    }
}
PHP" || fail "Could not write the smoke mu-plugin."

# Re-trigger plugin bootstrap so the mu-plugin's add_action lands.
"${WPCLI[@]}" cache flush --allow-root >/dev/null 2>&1 || true

# ── 5. Fire WP-Cron events ───────────────────────────────────────────
log "Firing heartbeat tick"
"${WPCLI[@]}" cron event run cronheart_heartbeat_tick --allow-root || warn "heartbeat tick run reported failure"

log "Firing test event (${EVENT_HOOK})"
"${WPCLI[@]}" cron event run "$EVENT_HOOK" --allow-root || warn "test event run reported failure"

log "Firing the failing test event (${FAIL_EVENT_HOOK}); WP-CLI must report the failure"
[ "$("${WPCLI[@]}" cron event list --hook="$FAIL_EVENT_HOOK" --format=count --allow-root)" -ge 1 ] \
    || fail "The failing test event is not scheduled."
if "${WPCLI[@]}" cron event run "$FAIL_EVENT_HOOK" --allow-root; then
    fail "The failing test event exited 0; its callback did not throw."
fi

# ── 6. Verify pings ──────────────────────────────────────────────────
if [ -n "${CRONHEART_API_TOKEN:+x}" ]; then
    log "Reading the three monitors' ping history (up to $POLL_ATTEMPTS reads, ${POLL_INTERVAL_SECONDS}s apart)"
    attempt=1
    settled=0
    while :; do
        HEARTBEAT_AFTER=$(list_pings "$HEARTBEAT_UUID") || fail "Could not re-read the heartbeat monitor's pings."
        EVENT_AFTER=$(list_pings "$EVENT_UUID") || fail "Could not re-read the per-event monitor's pings."
        FAIL_EVENT_AFTER=$(list_pings "$FAIL_EVENT_UUID") || fail "Could not re-read the failing event monitor's pings."
        HEARTBEAT_NEW=$(new_pings "$HEARTBEAT_BEFORE" "$HEARTBEAT_AFTER")
        EVENT_NEW=$(new_pings "$EVENT_BEFORE" "$EVENT_AFTER")
        FAIL_EVENT_NEW=$(new_pings "$FAIL_EVENT_BEFORE" "$FAIL_EVENT_AFTER")
        HEARTBEAT_FOUND=$(count_kind "$HEARTBEAT_NEW" heartbeat)
        START_FOUND=$(count_kind "$EVENT_NEW" start)
        SUCCESS_FOUND=$(count_kind "$EVENT_NEW" success)
        FAIL_START_FOUND=$(count_kind "$FAIL_EVENT_NEW" start)
        FAIL_FOUND=$(count_kind "$FAIL_EVENT_NEW" fail)
        FAIL_SUCCESS_FOUND=$(count_kind "$FAIL_EVENT_NEW" success)
        # Once every expected ping is in, read once more an interval later,
        # so a duplicate fail or a late success still lands before the
        # exact counts below are asserted.
        if [ "$HEARTBEAT_FOUND" -ge 1 ] && [ "$START_FOUND" -ge 1 ] && [ "$SUCCESS_FOUND" -ge 1 ] \
            && [ "$FAIL_START_FOUND" -ge 1 ] && [ "$FAIL_FOUND" -ge 1 ]; then
            [ "$settled" -eq 1 ] && break
            settled=1
        elif [ "$attempt" -ge "$POLL_ATTEMPTS" ]; then
            break
        fi
        attempt=$((attempt + 1))
        sleep "$POLL_INTERVAL_SECONDS"
    done

    log "New pings on the heartbeat monitor ($HEARTBEAT_UUID):"
    echo "${HEARTBEAT_NEW:-(none)}"
    log "New pings on the per-event monitor ($EVENT_UUID):"
    echo "${EVENT_NEW:-(none)}"
    log "New pings on the failing event monitor ($FAIL_EVENT_UUID):"
    echo "${FAIL_EVENT_NEW:-(none)}"

    echo
    echo "Heartbeat pings: $HEARTBEAT_FOUND (expected ≥1)"
    echo "Per-event start pings: $START_FOUND (expected ≥1)"
    echo "Per-event success pings: $SUCCESS_FOUND (expected ≥1)"
    echo "Failing event start pings: $FAIL_START_FOUND (expected ≥1)"
    echo "Failing event fail pings: $FAIL_FOUND (expected exactly 1)"
    echo "Failing event success pings: $FAIL_SUCCESS_FOUND (expected 0)"

    if [ "$HEARTBEAT_FOUND" -lt 1 ] || [ "$START_FOUND" -lt 1 ] || [ "$SUCCESS_FOUND" -lt 1 ] \
        || [ "$FAIL_START_FOUND" -lt 1 ] || [ "$FAIL_FOUND" -ne 1 ] || [ "$FAIL_SUCCESS_FOUND" -ne 0 ]; then
        fail "Smoke verification failed — expected pings missing. See output above."
    fi

    echo
    ok "All expected pings observed. Smoke run complete."
else
    cat <<EOF

The plugin has driven a heartbeat tick + two per-event runs against
$CRONHEART_ENDPOINT. To verify the pings arrived, open the
cronheart dashboard and inspect the three monitors corresponding to:

  - Heartbeat UUID: $HEARTBEAT_UUID
                    → expect 1 ping of kind 'heartbeat' within the
                      last few seconds.
  - Per-event UUID: $EVENT_UUID
                    → expect 2 pings, kind 'start' then 'success',
                      within the last few seconds.
  - Failing event UUID: $FAIL_EVENT_UUID
                    → expect 2 pings, kind 'start' then exactly one
                      'fail', within the last few seconds.

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
