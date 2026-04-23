#!/usr/bin/env sh
# After `docker compose up`, run migrations, backfill long-form articles, and app:prewarm.
# Usage: from repository root: ./scripts/docker-prewarm.sh
# Or:    make prewarm
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

echo "==> docker compose up -d --wait (php, database, cron: full app:prewarm every 10 min; set PREWARM_FLAGS in .env to match your CLI)"
docker compose up -d --wait

echo "==> doctrine:migrations:migrate"
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction

echo "==> articles:get (last 2 months → now)"
docker compose exec -T php php bin/console articles:get -- '-2 month' 'now'

echo "==> app:prewarm"
docker compose exec -T php php bin/console app:prewarm

echo "Done."
