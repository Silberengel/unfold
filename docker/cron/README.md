# `cron` service (Docker)

The `cron` image runs a single job: **`php bin/console app:prewarm` every 10 minutes**, against the app tree bind-mounted at `/var/www/html`. Magazine **30040** indices, **MySQL backfill** for category `a` long-form rows, profile metadata, and comment cache are updated here (or by running `app:prewarm` manually)—not from a browser request.

When the site has **`community_publications: true`**, the same `app:prewarm` run also executes the **community publication** phase: a time-window mass ingest of kind **30040** (excluding this tenant’s site magazine `type: magazine` tree) into `PublicationIndexStore`, plus bounded BFS for nested **30040** children. Control it with `PREWARM_FLAGS` (e.g. `--no-publication-indices`, `--publication-index-budget=120`, `--publication-since='-2 month'`). Section bodies (**30041**, **30818**, etc.) are still loaded on demand via relay when a user opens the reader or an naddr card.

- **Flags:** set **`PREWARM_FLAGS`** in the project `.env` (Compose injects it). Example: `PREWARM_FLAGS="--metadata-limit=30 --no-magazine"`. After editing, run `docker compose up -d --force-recreate cron` (or `docker compose up -d cron`) so the container gets the new value. If unset, `app:prewarm` uses its **built-in defaults** (same idea as running the console with no args).

- **How env reaches cron:** the entrypoint writes `PREWARM_FLAGS` to `/run/cron-prewarm.env` at boot, because the system `crond` does not pass the container environment into crontab jobs.

- **Logs inside the container:** `tail -f /var/log/cron.log` (e.g. `docker compose exec cron tail -f /var/log/cron.log`).

- **PHP 8.3** extensions in the image are limited to what `app:prewarm` needs; the host **vendor** tree is what you mount from the repo.

- **Not included in** `compose.hub.yaml` (no app source mount). For production images, use host **cron** / **systemd** to `exec` the same command.

Change the schedule: edit `docker/cron/crontab`, then `docker compose build cron && docker compose up -d cron`.
