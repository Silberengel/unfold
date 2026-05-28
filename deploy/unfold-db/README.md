# Hub shared MySQL (`unfold-db`)

Runs **`unfold-mysql`** on Docker network **`unfold_default`**. Imwald and GitCitadel app stacks connect here; restarting one site’s `php` / `prewarm` does not stop MySQL.

## Layout

| Directory | Services |
|-----------|----------|
| **`deploy/unfold-db/`** | `database` → `unfold-mysql` |
| **`deploy/imwald/`** | `php`, `prewarm` |
| **`deploy/gitcitadel/`** | `php`, `prewarm` |

## First-time setup

```bash
cd /path/to/unfold-db-deploy   # this directory + .env
make -f Makefile.hub up

cd /path/to/imwald-deploy
make -f Makefile.hub up
make -f Makefile.hub migrate

cd /path/to/gitcitadel-deploy
make -f Makefile.hub up
```

Use the **same** `MYSQL_USER`, `MYSQL_PASSWORD`, and `MYSQL_DATABASE` in all three `.env` files (only `deploy/unfold-db/.env` needs `MYSQL_ROOT_PASSWORD`).

## Migrate from imwald-owned MySQL (existing server)

Your data lives in volume **`unfold_database_data`** — the new DB stack uses that same volume name.

1. **Stop app workloads** (leave MySQL running for now):
   ```bash
   cd /path/to/imwald-deploy
   docker compose -f compose.hub.yaml stop php prewarm
   cd /path/to/gitcitadel-deploy
   docker compose -f compose.hub.yaml stop php prewarm
   ```

2. **Deploy `unfold-db`** (copy `deploy/unfold-db/` + `.env` with current `MYSQL_*` secrets):
   ```bash
   cd /path/to/unfold-db-deploy
   make -f Makefile.hub up
   ```
   If **`unfold-mysql` already exists** from the old imwald compose, stop/remove only the old DB container first:
   ```bash
   docker stop unfold-mysql
   docker rm unfold-mysql
   make -f Makefile.hub up
   ```

3. **Replace imwald `compose.hub.yaml`** with the new app-only file (no `database` service), then:
   ```bash
   cd /path/to/imwald-deploy
   make -f Makefile.hub up
   make -f Makefile.hub migrate   # if needed
   ```

4. **Update gitcitadel** compose if needed, then `make -f Makefile.hub up`.

5. **Remove orphaned DB service** from the old imwald project (optional cleanup):
   ```bash
   docker compose -f compose.hub.yaml rm -f database 2>/dev/null || true
   ```

## Operations

| Goal | Command |
|------|---------|
| Restart **one site** only | `make -f Makefile.hub restart` in imwald **or** gitcitadel dir |
| Stop **one site** only | `make -f Makefile.hub down` in that app dir |
| Restart **MySQL** (both sites down briefly) | `make -f Makefile.hub restart` in **unfold-db** dir |
| MySQL logs | `make -f Makefile.hub logs-db` in unfold-db dir |
