#!/bin/sh
# Dev server for the compose `frontend` service. Runs on every `docker compose up`;
# a warm boot is a no-op npm install + next dev. A fresh clone needs no local Node.
set -e

# .env.local is optional (the code falls back to these same localhost URLs) — the
# copy just keeps parity with the documented manual setup.
if [ ! -f .env.local ]; then
    echo "[bootstrap] no frontend/.env.local — creating it from .env.local.example"
    cp .env.local.example .env.local
    # Hand it to the checkout's owner so it stays editable from the host without
    # sudo — see the backend entrypoint for why this runs as root.
    chown "$(stat -c %u .)":"$(stat -c %g .)" .env.local 2>/dev/null || true
fi

npm install

exec npm run dev -- -p 3001
