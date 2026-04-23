# Default compose file from repo root; override with `make prewarm COMPOSE=...` if needed.
COMPOSE ?= docker compose

.PHONY: prewarm
prewarm:
	@chmod +x scripts/docker-prewarm.sh 2>/dev/null || true
	@./scripts/docker-prewarm.sh
