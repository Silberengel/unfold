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
| 4 | `app:prewarm` — magazine **30040**, **kind-0** profiles, **comment** cache (default **`--comments-max=20`**, newest by `createdAt`) |

`make prewarm` brings the stack (including `cron`) up so scheduled prewarm is active. **Optional** extra arguments for the **cron**-scheduled `app:prewarm` go in **`.env`** as **`PREWARM_FLAGS`** (same as you might pass to `php bin/console app:prewarm …`); Compose passes them into the `cron` container. Example: `PREWARM_FLAGS="--metadata-limit=50 --no-magazine"`. **Restart** the `cron` service after changing `PREWARM_FLAGS` so the container reloads the env. Hub / `compose.hub.yaml` has no `cron` service; use a host timer or `exec` if you need the same there.

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
| `--comments-max` | `20` | Newest **N** articles (by `createdAt` **DESC**); `0` = all (still bounded by budget) |
| `--comments-budget` | `120` | Max wall seconds for the comments phase |
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

## Production / Hub image

| Topic | Notes |
|-------|--------|
| `compose.hub.yaml` | Runs a **pulled** image (default `silberengel/unfold:latest`), no local PHP app build. Override with `UNFOLD_DOCKER_IMAGE`. |
| HTTP publish | `HTTP_PUBLISH` in `.env` (default **9080** → container **80**). Set `TRUSTED_PROXIES` behind a reverse proxy. |
| Secrets | Set `APP_SECRET` and DB credentials in **real** env; do not commit production secrets. |

File header in `compose.hub.yaml` lists pull, migrate, and optional build/push one-liners.

---

## License

**MIT** — see [`LICENSE`](LICENSE).

---

## Project links (example)

Configurable under `parameters.external_links` in `config/unfold.yaml` (e.g. Unfold on GitHub, Decent Newsroom). Adjust for your deployment.
