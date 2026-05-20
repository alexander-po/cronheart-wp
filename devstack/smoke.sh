#!/usr/bin/env bash
#
# End-to-end smoke test: installs cronheart-wp into the devstack WP,
# wires it to a cronheart backend, triggers WP-Cron, and asserts the
# pings arrived.
#
# # Two modes
#
# **A. Production mode (default — public contributors)**
#     Pings cronheart.com. You must:
#       - Sign up at https://cronheart.com and create two monitors
#         (heartbeat + a test per-event hook).
#       - Pass the UUIDs through `HEARTBEAT_UUID` / `EVENT_UUID`
#         env vars.
#     Verification: visit the cronheart.com dashboard manually
#     (this script cannot read the prod DB).
#
# **B. Local-backend mode (maintainers only)**
#     Requires access to the closed-source `cron-monitor` backend
#     repository at `../cron-monitor`. With that backend up via
#     `make up`, set `CRONHEART_LOCAL_BACKEND=1` when invoking this
#     script. UUIDs default to the two literals pre-registered by
#     the README's flow-B instructions. Verification is automated
#     against the `pings` table.
#
# # Prerequisites (both modes)
#
#     1. Plugin zip built:   ./bin/build-release.sh
#     2. WP + MySQL up:      docker compose -f devstack/docker-compose.yml up -d
#
# # Examples
#
#     # Mode A (against cronheart.com):
#     HEARTBEAT_UUID=xxxxxxxx-… EVENT_UUID=yyyyyyyy-… ./devstack/smoke.sh
#
#     # Mode B (against local backend):
#     CRONHEART_LOCAL_BACKEND=1 ./devstack/smoke.sh

set -euo pipefail

# ── Mode detection ────────────────────────────────────────────────────
LOCAL_MODE="${CRONHEART_LOCAL_BACKEND:-}"

if [ -n "$LOCAL_MODE" ]; then
    MODE="local"
    CRONHEART_INTERNAL_ENDPOINT="http://app"
    CRONHEART_ALLOW_INSECURE="true"
    # These literals match the rows pre-registered by the README's
    # flow-B SQL. Smoke.sh will fail the DB assertion if the rows
    # are missing.
    HEARTBEAT_UUID="${HEARTBEAT_UUID:-11111111-1111-4111-8111-111111111111}"
    EVENT_UUID="${EVENT_UUID:-22222222-2222-4222-8222-222222222222}"
else
    MODE="prod"
    CRONHEART_INTERNAL_ENDPOINT="https://cronheart.com"
    CRONHEART_ALLOW_INSECURE="false"
    if [ -z "${HEARTBEAT_UUID:-}" ] || [ -z "${EVENT_UUID:-}" ]; then
        echo "Production mode requires HEARTBEAT_UUID and EVENT_UUID env vars." >&2
        echo "Sign up at https://cronheart.com, create two monitors, and re-run:" >&2
        echo "  HEARTBEAT_UUID=<uuid> EVENT_UUID=<uuid> ./devstack/smoke.sh" >&2
        echo "" >&2
        echo "Or run in local-backend mode if you have the cron-monitor source:" >&2
        echo "  CRONHEART_LOCAL_BACKEND=1 ./devstack/smoke.sh" >&2
        exit 2
    fi
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

log "Smoke mode: $MODE (endpoint = $CRONHEART_INTERNAL_ENDPOINT)"

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
$WPCLI config set CRONHEART_ENDPOINT "$CRONHEART_INTERNAL_ENDPOINT" --type=constant --allow-root
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
log "Firing heartbeat tick"
$WPCLI cron event run cronheart_heartbeat_tick --allow-root || warn "heartbeat tick run reported failure"

log "Firing test event (${EVENT_HOOK})"
$WPCLI cron event run "$EVENT_HOOK" --allow-root || warn "test event run reported failure"

# ── 6. Verify pings ──────────────────────────────────────────────────
if [ "$MODE" = "local" ]; then
    # Numbers we expect (per smoke run):
    #   - heartbeat UUID ($HEARTBEAT_UUID): 1 row, kind=heartbeat
    #   - event UUID     ($EVENT_UUID):    2 rows, kind=start + kind=success
    log "Pings observed in the cronheart DB (last 5 minutes):"
    PING_QUERY="
      SELECT id, monitor_id, kind, user_agent, received_at
        FROM pings
       WHERE received_at > NOW() - INTERVAL 5 MINUTE
    ORDER BY id DESC;
    "
    PING_OUTPUT=$(docker compose -f ../cron-monitor/docker-compose.yml exec -T db \
        mysql -uapp -papp cronmonitor -e "$PING_QUERY" 2>&1 | grep -v "Warning: Using a password" || echo "")

    if [ -z "$PING_OUTPUT" ]; then
        fail "Could not read pings from cronheart DB. Is the backend container up?"
    fi

    echo "$PING_OUTPUT"

    # Belt-and-suspenders: assert that the expected rows are present.
    # We accept any agent prefixed `cron-monitor-php-sdk/` so a future
    # SDK version bump (0.2 → 0.3) does not require touching this script.
    HEARTBEAT_FOUND=$(echo "$PING_OUTPUT" | awk '$3 == "heartbeat" && $4 ~ /^cron-monitor-php-sdk\// {n++} END {print n+0}')
    START_FOUND=$(echo "$PING_OUTPUT" | awk '$3 == "start" && $4 ~ /^cron-monitor-php-sdk\// {n++} END {print n+0}')
    SUCCESS_FOUND=$(echo "$PING_OUTPUT" | awk '$3 == "success" && $4 ~ /^cron-monitor-php-sdk\// {n++} END {print n+0}')

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
    # Production mode: we cannot read the cronheart.com DB. Print
    # what would be expected and ask the human to verify on the
    # dashboard.
    cat <<EOF

The plugin has driven a heartbeat tick + a per-event run against
$CRONHEART_INTERNAL_ENDPOINT. To verify the pings arrived, open the
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
    ok "Production smoke run complete — verify the pings manually on the dashboard."
fi
