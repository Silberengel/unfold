#!/bin/bash
set -euo pipefail
# crond does not pass Compose env to job children; write once at boot for prewarm_cron.sh.
export PREWARM_FLAGS="${PREWARM_FLAGS:-}"
declare -p PREWARM_FLAGS >/run/cron-prewarm.env
exec cron -f
