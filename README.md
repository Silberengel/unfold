# Unfold

<p align="center">
  <img src="assets/laeserin_logo.png" alt="Site logo" width="150">
</p>

A Symfony + FrankenPHP site that **reads Nostr long-form articles (kinds 30023/30024/30817)** and related data from relays, and serves pages with Twig.

**One repository, multiple sites.** Imwald and GitCitadel (and future magazines) share this codebase and can share one MySQL database. Each site has a profile under `config/sites/` and `assets/theme/sites/`; Docker images are built with **`UNFOLD_SITE`** (see below).

### Where data lives

| Data | Storage |
|------|---------|
| Published articles (30023/24) | **MySQL** `article` table (global rows) + `article_magazine` (which magazine tenant ingested/references each row) |
| Magazine index (30040), kind-0 **profiles**, NIP-65 **relay lists** (10002) | **MySQL** `event` table with stable `core_row_key` — magazine indices are prefixed with `magazine_slug`; profiles/relay lists are shared |
| Comment / reply / thread **UI** (fetched thread HTML, etc.) | **Filesystem cache** pool `cache.replies` (not the DB) |
| Unpublished **editor preview** payloads | **Filesystem cache** pool `cache.drafts` |
| Generic Symfony `cache.app` | Other app caches; **not** used for long-term profile or magazine index storage |

NIP-09 kind-5 deletions that target stored kinds are applied to **MySQL** (articles + `event` rows). Relays are expected to handle ephemeral thread data.

---

## Requirements

| Requirement | Version / notes |
|------------|-----------------|
| PHP        | **≥ 8.3.13** (see `composer.json`) |
| Docker     | Optional; recommended for local dev and production images |
| Database   | MySQL **8.0** (configurable) |

---

## Local development (Docker)

1. **Env:** copy `.env.dist` to `.env` and adjust if needed (especially `APP_SECRET` outside dev). Default site is **`UNFOLD_SITE=imwald`**; switch with `make use-site UNFOLD_SITE=gitcitadel` (set **`HTTP_PORT=9085`** in `.env` if running both stacks locally).
2. **Start stack**

   ```bash
   docker compose up -d
   ```

3. **App URL (default):** [http://127.0.0.1:9080](http://127.0.0.1:9080)  
   Port comes from `HTTP_PORT` in `.env` and `compose.override.yaml` (loopback only).

4. **First-time DB:** migrations run on **php** container start when `migrations/` contains PHP files (see `frankenphp/docker-entrypoint.sh`).

| Service | Role |
|--------|------|
| `php`  | FrankenPHP + Caddy, Symfony app, console |
| `database` | MySQL; dev exposes `127.0.0.1:3307 → 3306` for local clients |
| `cron` | Runs full **`app:prewarm` every 10 minutes**; repo bind-mounted at `/var/www/html` (see `docker/cron/`) |

---

## Backfill articles + prewarm (recommended)

To **migrate**, **import articles from Nostr** for a time window, then run **prewarm** (magazine + profiles + deletions + comment cache):

```bash
make prewarm
```

| Step (script order) | Command / effect |
|---------------------|------------------|
| 1 | `docker compose up -d --wait` — starts **php**, **database**, and **cron** (the `cron` image runs a full `app:prewarm` on a 10 min schedule) |
| 2 | `doctrine:migrations:migrate` — applies schema (including `event` columns for core Nostr rows) |
| 3 | `articles:get -- '-2 month' 'now'` — sync long-form into the `article` table |
| 4 | `app:prewarm` — NIP-09 kind-5 sync (for stored kinds), magazine **30040** → `event`, kind-0 **profiles** (and relay lists on demand) → `event`, **comment** thread cache → `cache.replies` (default **`--comments-max=10`**, newest by `createdAt`) |

`make prewarm` brings the stack (including `cron`) up so scheduled prewarm is active. **Optional** extra arguments for the **cron**-scheduled `app:prewarm` go in **`.env`** as **`PREWARM_FLAGS`** (same as you might pass to `php bin/console app:prewarm …`); Compose passes them into the `cron` container. Example: `PREWARM_FLAGS="--metadata-limit=50 --no-magazine"`. **Restart** the `cron` service after changing `PREWARM_FLAGS` so the container reloads the env. On the **hub** stack, the `prewarm` service reads the same `PREWARM_FLAGS`; use `docker compose -f compose.hub.yaml up -d --force-recreate prewarm` after changing it.

**Fresh database or major upgrade:** after schema changes, run **`articles:get`** + **`app:prewarm`** (or `make prewarm`) so `article` and `event` are repopulated from relays. There is no automatic migration of old PSR **profile** cache into MySQL.

---

## Console commands (overview)

| Command | Purpose |
|---------|---------|
| `articles:get <from> <to>` | Pull long-form articles from Nostr for the time range, persist to `article` |
| `app:prewarm` | Magazine 30040 + kind-0 profile prewarm (→ `event`), NIP-09 deletions, comment thread warm (→ `cache.replies`) |
| `doctrine:migrations:migrate` | Apply SQL migrations |
| `user:elevate` | (If used) user elevation helper |

`php bin/console list` and `… -h` for full options.

### `app:prewarm` (notable options)

| Option | Default | Meaning |
|--------|---------|--------|
| `--no-magazine` | off | Skip magazine 30040 index fetch / `event` update |
| `--no-metadata` | off | Skip batched kind-0 profile prewarm (writes to `event`) |
| `--no-deletions` | off | Skip NIP-09 kind-5 fetch and application (articles + `event` index/profile rows) |
| `--deletion-since` | `-2 month` | `strtotime()` lower bound for kind-5 author-scoped fetch |
| `--no-comments` | off | Skip comment thread prewarm (`cache.replies`) |
| `--metadata-limit` | `0` (all authors) | Max distinct author pubkeys for the metadata phase |
| `--metadata-batch` | `50` | Pubkeys per batched kind-0 Nostr `REQ` |
| `--comments-max` | `10` | Newest **N** articles (by `createdAt` **DESC**); `0` = all (still bounded by budget) |
| `--comments-budget` | `600` | Max wall seconds for the whole comments phase (Nostr is slow; raise e.g. `1200` if you need more articles in one run) |
| `--magazine-budget` | `90` | Max wall seconds for magazine **per-category** 30040 fetches (root is separate; cap 600s in code). If you have many categories, a **low** budget can stop before the last slug is refreshed. Set `MAGAZINE_PREWARM_PREFER_SLUGS` (comma-separated category `#d` slugs) to fetch those first after the root. |

Prewarm clears the PHP **CLI** execution time limit for that run; relay work can be slow.

### `PREWARM_ON_START` (optional)

| Variable | Set where | Effect |
|----------|------------|--------|
| `PREWARM_ON_START=1` | **Compose `environment` on the `php` service** (not only Symfony `.env` inside the container) | After DB is up and migrations run, executes **`app:prewarm` once** on start. **Does not** run `articles:get`. |

For a full **Nostr backfill** + one-shot prewarm, use **`make prewarm`** (or a host **cron** / **systemd** timer) instead of relying on **`PREWARM_ON_START` alone**.

---

## Configuration

| What | File |
|------|------|
| **Site profiles** (edit these) | `config/sites/imwald.yaml`, `config/sites/gitcitadel.yaml` |
| Active Symfony config (generated) | `config/unfold.yaml` — from `scripts/select-unfold-site.sh` |
| Site theme assets (source) | `assets/theme/sites/{imwald,gitcitadel}/` → copied to `assets/theme/local/` |
| Site logo | `assets/sites/{imwald,gitcitadel}/laeserin_logo.png` |
| `UNFOLD_SITE` (dev Docker) | `.env` — `imwald` (default) or `gitcitadel`; entrypoint runs select script |
| `MAGAZINE_PREWARM_PREFER_SLUGS` | `.env` / `.env.local` — optional comma-separated category slugs to prioritize in `app:prewarm` magazine phase (after the root). Use when the relay time budget would otherwise skip your updated category. |
| `DATABASE_URL`, `APP_SECRET`, `HTTP_PORT`, `MYSQL_*`, optional **`PREWARM_FLAGS`** (for the Docker `cron` service) | `.env` / `.env.local` (see `.env.dist`) |
| Cache pool definitions (`cache.replies`, `cache.drafts`, `cache.app`) | `config/packages/cache.yaml` |
| Service wiring (e.g. which pool comment loaders use) | `config/services.yaml` |

### Multiple sites (one branch)

| Site | `magazine_slug` | Hub compose | Default image tag |
|------|-----------------|-------------|-------------------|
| **Imwald** | `imwald` | `deploy/imwald/compose.hub.yaml` (includes MySQL) | `silberengel/unfold:imwald` |
| **GitCitadel** | `gitcitadel` | `deploy/gitcitadel/compose.hub.yaml` (shared DB) | `silberengel/unfold:gitcitadel` |

**Local dev — switch site:**

```bash
make use-site UNFOLD_SITE=gitcitadel   # or imwald (default)
docker compose up -d --force-recreate php cron
```

**Production — build per site** (same Dockerfile, different build arg):

```bash
./scripts/build-hub-image.sh imwald
./scripts/build-hub-image.sh gitcitadel
docker push silberengel/unfold:imwald
docker push silberengel/unfold:gitcitadel
```

Root `compose.hub.yaml` is a copy of **`deploy/imwald/compose.hub.yaml`** for backward compatibility.

**Relays (short):** `default_relay` and `article_relays` drive article sync and many queries; `profile_relays` are used **first** for kind-0 / profile fetches, then the merged default + article set (see `NostrClient`).

### Shared MySQL (multiple magazines on one host)

Two deployments (e.g. Imwald + GitCitadel) can use **one MySQL** instead of separate `database_data` volumes:

1. Set a unique **`magazine_slug`** in each site profile (`config/sites/imwald.yaml`, `config/sites/gitcitadel.yaml`).
2. Run **one** MySQL container (imwald hub **`deploy/imwald/`** owns `unfold-mysql`). Point GitCitadel at **`DATABASE_HOST=unfold-mysql`** on network `unfold_default`.
3. **Nuke old volumes** and run migrations once on the imwald stack.
4. Backfill **each** hub stack separately (`articles:get`, `app:prewarm`) — each image tags rows with its own `magazine_slug`.

Articles and kind-0 profiles are stored once and shared; magazine indices, featured authors, admin users, and list/search/sitemap views are scoped per `magazine_slug`.

---

## Production / Hub (remote server)

Each site uses a **pre-built** image (site baked in at build time via **`UNFOLD_SITE`**). On the server: copy **`deploy/imwald/`** or **`deploy/gitcitadel/`** (`compose.hub.yaml` + `.env` from `.env.dist`), plus **`Makefile.hub`** from the repo root.

| Site | Hub directory | Default image | HTTP (default) |
|------|---------------|---------------|----------------|
| Imwald (DB owner) | `deploy/imwald/` | `silberengel/unfold:imwald` | `9080` |
| GitCitadel | `deploy/gitcitadel/` | `silberengel/unfold:gitcitadel` | `127.0.0.1:9085` |

| Topic | Notes |
|-------|--------|
| Imwald `compose.hub.yaml` | **`php`** + **`database`** (`unfold-mysql`) + **`prewarm`**. Start this stack first. |
| GitCitadel `compose.hub.yaml` | **`php`** + **`prewarm`** only; joins external network **`unfold_default`**. |
| HTTP | **`HTTP_PUBLISH`** in `.env`. Reverse proxy (Apache/nginx) in front; set **`TRUSTED_PROXIES`**. |
| Secrets | Real **`APP_SECRET`** and **`MYSQL_*`** (same credentials on both hubs when sharing DB). |
| `PREWARM_FLAGS` | Optional CLI args for **`prewarm`**. After editing `.env`: `docker compose … up -d --force-recreate prewarm`. |

### Build, tag, and push (on your machine or CI)

From the **repository root**:

```bash
./scripts/build-hub-image.sh imwald
./scripts/build-hub-image.sh gitcitadel
docker push silberengel/unfold:imwald
docker push silberengel/unfold:gitcitadel
```

Equivalent manual build:

```bash
docker build --platform linux/amd64 --target frankenphp_prod \
  --build-arg UNFOLD_SITE=imwald -t silberengel/unfold:imwald .
```

- Use **`linux/amd64`** on amd64 servers; **`arm64`** on arm servers if needed.
- Override on the server with **`UNFOLD_DOCKER_IMAGE`** in `.env` if you use a private registry.

### Deploy on the server (pull, up, migrate)

**Imwald (first — creates MySQL):**

```bash
cd /path/to/imwald-deploy   # deploy/imwald/ contents + .env
docker compose -f compose.hub.yaml pull
docker compose -f compose.hub.yaml up -d
docker compose -f compose.hub.yaml exec php php bin/console doctrine:migrations:migrate --no-interaction
```

**GitCitadel (after imwald hub is up):**

```bash
cd /path/to/gitcitadel-deploy   # deploy/gitcitadel/ contents + .env
docker compose -f compose.hub.yaml pull
docker compose -f compose.hub.yaml up -d
make -f Makefile.hub HUB_COMPOSE=compose.hub.yaml backfill   # optional first-time Nostr import
```

After code changes: **`pull` → `up -d`** on each stack; run **migrations** once on imwald when new migration files ship.

### `Makefile.hub` (on the server)

Copy **`Makefile.hub`** next to each hub’s **`compose.hub.yaml`**. For GitCitadel, set **`HUB_COMPOSE=compose.hub.yaml`** (file in that directory):

```bash
make -f Makefile.hub help
make -f Makefile.hub pull
make -f Makefile.hub up
make -f Makefile.hub migrate          # imwald only (runs migrations)
make -f Makefile.hub backfill         # articles:get + prewarm-once
```

**Imwald from repo root** (default `HUB_COMPOSE=deploy/imwald/compose.hub.yaml`):

```bash
make -f Makefile.hub -C . pull
```

### One-time Nostr backfill (equivalent to `make prewarm` on dev)

Use **`make -f Makefile.hub backfill`**, or run the same **inside the `php` container**:

```bash
docker compose -f compose.hub.yaml exec -T php php bin/console articles:get -- '-2 month' 'now'
docker compose -f compose.hub.yaml exec -T php php bin/console app:prewarm
```

Adjust the **articles:get** window as needed (see **`Makefile.hub`** / `ARTICLES_FROM` / `ARTICLES_TO`).

### Scheduled `app:prewarm` on hub

The **`prewarm`** service uses the **same** image as `php` and runs **`app:prewarm` every 10 minutes** (same cadence as dev’s `docker/cron`). It waits for **MySQL** and the **`doctrine_migration_versions`** table (so the `php` entrypoint has run migrations) — it does **not** use `curl` to the `php` service, which can fail if HTTP is only bound for loopback inside that container. **Optional** `PREWARM_FLAGS` in `.env` is passed into that container; after changing it, run:

```bash
docker compose -f compose.hub.yaml up -d --force-recreate prewarm
```

**If you do not** want a Compose sidecar (e.g. to save RAM), stop and disable the `prewarm` service and use **host** `cron` or **systemd** instead:

```text
*/10 * * * * cd /path/to/deploy && docker compose -f compose.hub.yaml exec -T php php bin/console app:prewarm
```

**`PREWARM_ON_START=1`** on the `php` service only warms **once** at container start, not on a schedule.

The file `compose.hub.yaml` in the repo repeats minimal pull/migrate/build one-liners in its header for quick copy-paste.

---

## License

**MIT** — see [`LICENSE`](LICENSE).

---

## Project links (example)

Configurable under `parameters.external_links` in `config/unfold.yaml` (e.g. Unfold on GitHub, Decent Newsroom). Adjust for your deployment.
