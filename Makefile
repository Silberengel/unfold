# Default compose file from repo root; override with `make prewarm COMPOSE=...` if needed.
COMPOSE ?= docker compose
UNFOLD_SITE ?= imwald

.PHONY: prewarm use-site
prewarm:
	@chmod +x scripts/docker-prewarm.sh 2>/dev/null || true
	@./scripts/docker-prewarm.sh

# Switch local dev site profile (config/unfold.yaml + theme). Restart php after switching.
use-site:
	@chmod +x scripts/select-unfold-site.sh 2>/dev/null || true
	@./scripts/select-unfold-site.sh $(UNFOLD_SITE)
