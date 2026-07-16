#!/bin/sh
# First-run bootstrap + dev server for the compose `backend` service.
# Runs on every `docker compose up` and is idempotent on purpose: steps that are
# already done are skipped, so a warm boot is a no-op composer install + migrate
# + serve. A fresh clone needs no local PHP/Composer/Postgres at all.
set -e

# .env — copied from the example so a fresh clone boots unattended. The DB_*
# connection is pinned to the compose postgres service by environment overrides in
# docker-compose.yml (real env beats .env in Laravel), so the copied DB defaults
# are irrelevant here and the same file keeps working against a host Postgres.
if [ ! -f .env ]; then
    echo "[bootstrap] no backend/.env — creating it from .env.example"
    cp .env.example .env
    # Hand it to whoever owns the checkout so it stays editable from the host without
    # sudo. Everything here runs as root — that is what lets the same compose file
    # work against a checkout owned by you, by another uid, or by root (/var/www) —
    # so files it creates would otherwise all be root's.
    chown "$(stat -c %u .)":"$(stat -c %g .)" .env 2>/dev/null || true
fi

composer install

# key:generate unconditionally overwrites, so only run it when APP_KEY is empty.
if ! grep -q '^APP_KEY=.' .env; then
    php artisan key:generate --force
fi

# Serve uploaded images. The symlink artisan creates is absolute, and the repo is
# mounted at the same path as on the host, so it resolves on both sides.
if [ ! -e public/storage ]; then
    php artisan storage:link
fi

# Wait out Postgres's first-boot initialization. The compose healthcheck already gates
# `up`, but a plain `docker compose run backend` has no such gate. This probes the
# connection rather than retrying `migrate`, so a failing migration reports itself
# instead of being mistaken for 30 attempts' worth of "Postgres isn't up yet".
tries=0
until php artisan db:show >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "[bootstrap] database still unreachable after $tries attempts — giving up" >&2
        echo "[bootstrap] a .env from before the Postgres switch is the usual cause:" >&2
        echo "[bootstrap] compose pins DB_CONNECTION=pgsql, so check nothing else overrides it" >&2
        exit 1
    fi
    echo "[bootstrap] waiting for Postgres (attempt $tries/30)..."
    sleep 2
done

php artisan migrate --force

# Demo seed, exactly once: only when no user exists yet (fresh volume or fresh
# clone). `decant:fresh-start` keeps the admin user, so it won't re-trigger this.
db_state=$(php artisan tinker --execute='echo \App\Models\User::count() === 0 ? "DB_EMPTY" : "DB_SEEDED";' | tail -n 1) || true

# The seeder refuses to create the admin user without ADMIN_PASSWORD — generate one
# rather than fail, persisted in .env so it survives restarts. This runs whatever the
# database state is: `db:seed` and `decant:fresh-start` need it too, and a carried-over
# database with a freshly copied .env would otherwise leave it blank and break them.
if ! grep -q '^ADMIN_PASSWORD=.' .env; then
    admin_password=$(php -r 'echo bin2hex(random_bytes(8));')
    if grep -q '^ADMIN_PASSWORD=' .env; then
        sed -i "s/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=${admin_password}/" .env
    else
        printf '\nADMIN_PASSWORD=%s\n' "${admin_password}" >>.env
    fi
    echo "[bootstrap] ADMIN_PASSWORD was blank — generated one and saved it to backend/.env"
    if [ "$db_state" = "DB_SEEDED" ]; then
        echo "[bootstrap] your existing admin user keeps the password it already has —"
        echo "[bootstrap] this new one only takes effect the next time you seed"
    fi
fi

case "$db_state" in
DB_EMPTY)
    php artisan db:seed --force
    echo ""
    echo "=================================================================="
    echo "  Demo data seeded."
    echo "  Admin panel:  http://localhost:8010/admin"
    echo "  Login:        admin@decantplease.local"
    echo "  Password:     the ADMIN_PASSWORD line in backend/.env"
    echo "=================================================================="
    echo ""
    ;;
DB_SEEDED) ;;
*)
    echo "[bootstrap] could not determine database state ('$db_state') — skipping demo seed" >&2
    ;;
esac

# php -S directly instead of `php artisan serve`: serve re-spawns the real server
# with its env stripped to a whitelist, so the compose DB_* overrides would never
# reach web requests. Same router file + cwd=public/, exactly as artisan serve
# runs it.
cd public
exec php -S 0.0.0.0:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
