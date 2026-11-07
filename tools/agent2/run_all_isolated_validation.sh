#!/usr/bin/env bash
# Agent 2 — isolated EV backend, OCPP and Next Admin validation.
# Never writes deployed source, production Mongo, payment providers or chargers.
set -Eeuo pipefail
umask 077
root="$(cd "$(dirname "$0")/../.." && pwd)"
stage_root="/home/hasio/agent2-work"
backend="/home/hasio/ev-deployment/admin/UV Charging"
gateway="/home/hasio/ev-deployment/ocpp-gateway-next"
admin="/home/hasio/ev-deployment/admin-password-rotation"
if [[ ! -d "$stage_root" || ! -f "$backend/src/app.js" ||
      ! -f "$gateway/ocpp-handlers.mjs" || ! -f "$admin/package.json" ]]; then
  echo "Authorized source missing; no test or deployment performed" >&2
  exit 2
fi
work="$(mktemp -d "$stage_root/isolated-validation-XXXXXXXX")"
logdir="$stage_root/validation-logs"
mkdir -p "$logdir"
log="$logdir/agent2-$(date -u +%Y%m%dT%H%M%SZ).log"
server_pid=""
cleanup(){
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT
trap 'echo "ISOLATED_VALIDATION_FAILED; see restricted on-host log" >&2' ERR
exec > >(tee -a "$log") 2>&1
echo "Building isolated source copies. Live deployment untouched."
mkdir -p "$work/express" "$work/ocpp" "$work/next"
cp -a "$backend/src" "$work/express/src"
cp "$backend/package.json" "$work/express/package.json"
ln -s "$backend/node_modules" "$work/express/node_modules"
cp "$gateway/ocpp-handlers.mjs" "$work/ocpp/ocpp-handlers.mjs"

for patch in \
  stage_payment_safety.py stage_booking_slot_uniqueness.py \
  stage_charging_source_safety.py stage_ocpp_customer_status.py \
  stage_ocpp_customer_summary.py stage_ocpp_charging_station_screen.py \
  stage_ocpp_qr_safety.py stage_admin_ocpp_binding.py \
  stage_google_maps_config.py
do
  python3 "$root/tools/agent2/$patch" "$work/express"
done
python3 "$root/tools/agent2/stage_ocpp201_token_binding.py" \
  "$work/ocpp/ocpp-handlers.mjs"

for target in \
  src/app.js src/routes/paymentRoutes.js src/routes/adminRoutes.js \
  src/services/userService.js src/models/BookingModel.js \
  src/models/ChargingSessionModel.js src/controllers/adminOcppBooking.js \
  src/controllers/adminMultiCharger.js src/utils/ocppSourceEvidence.mjs \
  src/utils/ocppReissuePolicy.mjs src/utils/ocppSourceBindingIndexAudit.mjs
do
  node --check "$work/express/$target"
done
node --check "$work/ocpp/ocpp-handlers.mjs"
node --check "$work/ocpp/ocppStartAuthorization.mjs"
python3 -m py_compile "$root/tools/agent2"/stage_*.py
echo "Source patch and syntax checks passed."

node --test "$root"/integrations/hasone-express/tests/*.test.mjs
node "$root/tools/agent2/test_staged_payment_routes.mjs" "$work/express" "$backend"
node "$root/tools/agent2/run_isolated_backend_demo.mjs" \
  "$work/express" "$backend" "$work/ocpp/ocpp-handlers.mjs"
echo "Source-backed isolated backend tests passed."

for dir in app components lib public; do
  cp -a "$admin/$dir" "$work/next/$dir"
done
for file in package.json tsconfig.json next-env.d.ts next.config.ts \
  postcss.config.mjs eslint.config.mjs
do
  cp "$admin/$file" "$work/next/$file"
done
ln -s "$admin/node_modules" "$work/next/node_modules"
python3 "$root/tools/agent2/stage_next_ocpp_report_page.py" "$work/next"
python3 "$root/tools/agent2/stage_next_ocpp_booking_workflow.py" "$work/next"
(
  cd "$work/next"
  node ./node_modules/typescript/bin/tsc --noEmit --pretty false
  NEXT_TELEMETRY_DISABLED=1 NODE_OPTIONS='--max-old-space-size=3072' \
    node ./node_modules/next/dist/bin/next build --webpack
)
echo "Next.js TypeScript and Webpack production build passed on copied source."

browser_flag="$(printenv RUN_AGENT2_BROWSER || echo 0)"
if [[ "$browser_flag" == "1" ]]; then
  test -n "$(printenv PUPPETEER_PATH)" &&
    test -n "$(printenv CHROME_PATH)" ||
    { echo "Headless Chrome/Puppeteer env paths required" >&2; exit 3; }
  port="$(printenv AGENT2_TEST_PORT || echo 3428)"
  [[ "$port" =~ ^[0-9]{4,5}$ ]] ||
    { echo "Invalid staging browser port" >&2; exit 3; }
  if ss -lnt | grep -q ":$port "; then
    echo "Port already occupied; will not touch listener" >&2
    exit 3
  fi
  (cd "$work/next" && node ./node_modules/next/dist/bin/next start \
    --hostname 127.0.0.1 --port "$port") &
  server_pid=$!
  ready=0
  for i in $(seq 1 30); do
    if curl -s --max-time 2 -o /dev/null "http://127.0.0.1:$port/bookings"; then
      ready=1; break
    fi
    sleep 0.4
  done
  test "$ready" -eq 1 ||
    { echo "Isolated Next Admin did not start" >&2; exit 3; }
  node "$root/tools/agent2/test_next_admin_multicharger_browser.mjs" \
    "http://127.0.0.1:$port"
  echo "Mocked-API Chrome acceptance passed."
fi
echo "ISOLATED_AGENT2_VALIDATION_PASS; production and hardware unchanged."
