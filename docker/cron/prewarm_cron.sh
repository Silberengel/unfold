#!/bin/bash
set -euo pipefail
# shellcheck source=/dev/null
if [[ -f /run/cron-prewarm.env ]]; then
    source /run/cron-prewarm.env
fi
cd /var/www/html
# shellcheck disable=SC2086
exec php bin/console app:prewarm ${PREWARM_FLAGS:-}
