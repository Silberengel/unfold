# Unfold: Imwald

<p align="center">
  <img src="assets/laeserin_logo.png" alt="Imwald" width="150">
</p>

A Symfony + FrankenPHP site that **reads Nostr long-form articles (kind 30023)** and related data from relays, stores articles in **MySQL**, and serves pages with Twig. **Comments and profile metadata** are **cache-backed** (not the full source of truth in the DB).

---

## Requirements

| Requirement | Version / notes |
|------------|-----------------|
| PHP        | **≥ 8.3.13** (see `composer.json`) |
| Docker     | Optional; recommended for local dev and production images |
| Database   | MySQL **8.0** (configurable) |

---

## Local development (Docker)

1. **Env:** copy `.env.dist` to `.env` and adjust if needed (especially `APP_SECRET` outside dev).
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

## Backfill articles + warm caches (recommended)

To **migrate**, **import articles from Nostr** for a time window, then **prewarm** magazine indices, author metadata, and comment caches:

```bash
make prewarm
```

| Step (script order) | Command / effect |
|---------------------|------------------|
| 1 | `docker compose up -d --wait` — starts **php**, **database**, and **cron** (the `cron` image runs a full `app:prewarm` on a 10 min schedule) |
| 2 | `doctrine:migrations:migrate` |
| 3 | `articles:get -- '-2 month' 'now'` — sync long-form into MySQL for that window |
| 4 | `app:prewarm` — magazine **30040**, **kind-0** profiles, **comment** cache (default **`--comments-max=10`**, newest by `createdAt`) |

`make prewarm` brings the stack (including `cron`) up so scheduled prewarm is active. **Optional** extra arguments for the **cron**-scheduled `app:prewarm` go in **`.env`** as **`PREWARM_FLAGS`** (same as you might pass to `php bin/console app:prewarm …`); Compose passes them into the `cron` container. Example: `PREWARM_FLAGS="--metadata-limit=50 --no-magazine"`. **Restart** the `cron` service after changing `PREWARM_FLAGS` so the container reloads the env. On the **hub** stack, the `prewarm` service reads the same `PREWARM_FLAGS`; use `docker compose -f compose.hub.yaml up -d --force-recreate prewarm` after changing it.

---

## Console commands (overview)

| Command | Purpose |
|---------|---------|
| `articles:get <from> <to>` | Pull long-form articles from Nostr for the time range, persist to DB |
| `app:prewarm` | Magazine relay refresh + metadata cache + comment cache warm |
| `doctrine:migrations:migrate` | Apply SQL migrations |
| `user:elevate` | (If used) user elevation helper |

`php bin/console list` and `… -h` for full options.

### `app:prewarm` (notable options)

| Option | Default | Meaning |
|--------|---------|--------|
| `--no-magazine` | off | Skip magazine 30040 index |
| `--no-metadata` | off | Skip Nostr kind-0 / profile cache |
| `--no-comments` | off | Skip comment thread cache |
| `--metadata-limit` | `0` (all authors) | Cap distinct author pubkeys |
| `--metadata-batch` | `50` | Pubkeys per batched Nostr `REQ` |
| `--comments-max` | `10` | Newest **N** articles (by `createdAt` **DESC**); `0` = all (still bounded by budget) |
| `--comments-budget` | `600` | Max wall seconds for the whole comments phase (Nostr is slow; raise e.g. `1200` if you need more articles in one run) |
| `--magazine-budget` | `30` | Max wall seconds for magazine refresh |

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
| Site title, `npub`, `d_tag`, **relays** (`default_relay`, `article_relays`, `profile_relays`), theme | `config/unfold.yaml` (imported as Symfony parameters) |
| `DATABASE_URL`, `APP_SECRET`, `HTTP_PORT`, `MYSQL_*`, optional **`PREWARM_FLAGS`** (for the Docker `cron` service) | `.env` / `.env.local` (see `.env.dist`) |
| Service wiring (e.g. cache, `NostrClient` args) | `config/services.yaml` |

**Relays (short):** `default_relay` and `article_relays` drive article sync and many queries; `profile_relays` are used **first** for kind-0 / profile fetches, then the merged default + article set (see `NostrClient`).

---

## Production / Hub (remote server)

The app runs as a **pre-built** image (no app source on the server). The server only needs `compose.hub.yaml`, a `.env`, and Docker. Default image: `silberengel/unfold:latest`; override with **`UNFOLD_DOCKER_IMAGE`**.

| Topic | Notes |
|-------|--------|
| `compose.hub.yaml` | Defines **`php`** (FrankenPHP) + **`database`** (MySQL) + **`prewarm`** (same app image: **`app:prewarm` every 10 minutes**, like dev’s `docker/cron`). Optional: disable `prewarm` in Compose if you prefer a host `cron` only. |
| HTTP | **`HTTP_PUBLISH`** in `.env` maps **host** port → container **80** (default **9080**). Put a reverse proxy (e.g. Apache) in front; set **`TRUSTED_PROXIES`** to match your proxy (often include `127.0.0.0/8` and the Docker bridge CIDR, e.g. `172.16.0.0/12`). |
| Secrets | Real **`APP_SECRET`** and **`MYSQL_*`** (or external DB via `DATABASE_URL` if you change the file). Do not commit production `.env`. |
| `PREWARM_FLAGS` | Optional extra CLI args for the hub **`prewarm`** service (and dev **`cron`**). After editing `.env`, run `docker compose -f compose.hub.yaml up -d --force-recreate prewarm`. |

### Build, tag, and push (on your machine or CI)

From the **repository root** (same `Dockerfile` as local prod):

```bash
# Production image
docker build --platform linux/amd64 --target frankenphp_prod -t YOUR_REGISTRY/unfold:latest .

# Optional: immutable tag for rollbacks
docker build --platform linux/amd64 --target frankenphp_prod -t YOUR_REGISTRY/unfold:1.0.0 .

# Push what you use on the server
docker push YOUR_REGISTRY/unfold:latest
docker push YOUR_REGISTRY/unfold:1.0.0
```

- Use **`linux/amd64`** if the server is amd64; use **`arm64`** (or a matching `--platform`) for arm servers.
- The image name must match what the server will pull: either keep **`UNFOLD_DOCKER_IMAGE=YOUR_REGISTRY/unfold:TAG`** in server `.env`, or push to the default name **`silberengel/unfold:latest`**.

### Deploy on the server (pull, up, migrate)

In a directory that contains **only** `compose.hub.yaml` and your **`.env`** (e.g. `~/tmp/unfold`):

```bash
cd /path/to/deploy
docker compose -f compose.hub.yaml pull
docker compose -f compose.hub.yaml up -d
docker compose -f compose.hub.yaml exec php php bin/console doctrine:migrations:migrate --no-interaction
```

After code changes: **`pull` → `up -d`**; run **migrations** when the repo added new migration files.

**Optional image / tag** (in `.env` or one-shot):

```bash
export UNFOLD_DOCKER_IMAGE=YOUR_REGISTRY/unfold:1.0.0
docker compose -f compose.hub.yaml up -d
```

### One-time Nostr backfill (equivalent to `make prewarm` on dev)

`compose.hub` has no bind-mounted repo, so run the same commands **inside the `php` container** (after the stack is up and migrations have run):

```bash
docker compose -f compose.hub.yaml exec -T php php bin/console articles:get -- '-2 month' 'now'
docker compose -f compose.hub.yaml exec -T php php bin/console app:prewarm
```

Adjust the **articles:get** window as needed.

### Scheduled `app:prewarm` on hub

The **`prewarm`** service in `compose.hub.yaml` uses the **same** image as `php` and runs **`app:prewarm` every 10 minutes** (same cadence as dev’s `docker/cron`). It starts only after the **database** is healthy and the **`php`** service passes its healthcheck (so migrations from the `php` entrypoint have typically completed). **Optional** `PREWARM_FLAGS` in `.env` is passed into that container; after changing it, run:

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
