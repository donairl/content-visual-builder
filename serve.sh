#!/usr/bin/env bash
# Development mode in one command: Yii backend (API, login, public pages) + Next.js editor with
# hot reload. Open http://127.0.0.1:3000/editor/ — the Next dev server proxies every non-editor
# path to the backend, so both share one origin (session cookie + CSRF just work).
#
#   ./serve.sh
#   BACKEND_PORT=8081 EDITOR_PORT=3001 ADMIN_PASSWORD=secret123 ./serve.sh
#
# Ctrl+C stops both servers.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_PORT="${BACKEND_PORT:-8080}"
EDITOR_PORT="${EDITOR_PORT:-3000}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin12345}"

say() { printf '\033[1;34m▸\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

for tool in php composer node npm; do
  command -v "$tool" >/dev/null || die "'$tool' is not installed."
done
port_busy "$BACKEND_PORT" && die "Port $BACKEND_PORT is already in use (set BACKEND_PORT)."
port_busy "$EDITOR_PORT" && die "Port $EDITOR_PORT is already in use (set EDITOR_PORT)."

# ---------------------------------------------------------------- backend
cd "$ROOT/backend"
if [[ ! -f .env ]]; then
  cp .env.example .env
  say "Created backend/.env (APP_ENV=dev, SQLite)."
fi
driver="$(grep -E '^DB_DRIVER=' .env | tail -n1 | cut -d= -f2 | tr -d '[:space:]')"
if [[ "${driver:-sqlite}" == sqlite ]] && ! php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
  die "PHP extension pdo_sqlite is missing. Install it (Arch/CachyOS: sudo pacman -S php-sqlite) or set DB_DRIVER=pgsql|mysql in backend/.env."
fi
[[ -d vendor ]] || { say "Installing backend dependencies…"; composer install --no-interaction; }

say "Applying database migrations…"
php yii migrate:up --no-interaction >/dev/null

if out="$(php yii user:create "$ADMIN_EMAIL" "Admin" --role=admin --password="$ADMIN_PASSWORD" --no-interaction 2>&1)"; then
  say "Created admin user $ADMIN_EMAIL."
elif grep -q 'already exists' <<<"$out"; then
  say "Admin user $ADMIN_EMAIL already exists (password unchanged)."
else
  printf '%s\n' "$out" >&2
  die "Could not create the admin user."
fi

# ---------------------------------------------------------------- editor
cd "$ROOT/editor"
[[ -d node_modules ]] || { say "Installing editor dependencies…"; npm install --no-fund --no-audit; }

# ---------------------------------------------------------------- run
# Both servers share this script's process group, so one `kill 0` stops everything.
trap 'trap - INT TERM EXIT; kill 0 2>/dev/null' INT TERM EXIT

(cd "$ROOT/backend" && php -d upload_max_filesize=10M -d post_max_size=12M -S "127.0.0.1:$BACKEND_PORT" -t public public/index.php 2>&1 \
  | sed -u 's/^/\x1b[2m[api]\x1b[0m /') &
(cd "$ROOT/editor" && CVB_BACKEND_URL="http://127.0.0.1:$BACKEND_PORT" NEXT_TELEMETRY_DISABLED=1 npx next dev --hostname 127.0.0.1 --port "$EDITOR_PORT" 2>&1 \
  | sed -u 's/^/\x1b[2m[editor]\x1b[0m /') &

cat <<EOF

  Editor       http://127.0.0.1:$EDITOR_PORT/editor/
  Login        $ADMIN_EMAIL / $ADMIN_PASSWORD
  Public page  http://127.0.0.1:$EDITOR_PORT/<slug>   (after publishing)
  API          http://127.0.0.1:$BACKEND_PORT/api/…

  Ctrl+C to stop.

EOF
wait
