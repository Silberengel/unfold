# Unfold

Unfold is a customizable framework for your Nostr-based magazine.

(This is the **Imwald** edition of Unfold.)

## Setup

### Clone the repository

```bash
git clone https://github.com/decent-newsroom/unfold.git
cd unfold
```

### Create the .env file

Copy the example file `.env.dist` and replace placeholders with your actual configuration.

If you have your own MySQL database, comment out the database service in `compose.yaml` and skip root password in `.env`. 
There are additional comments to that effect in the files.

### Configure `config/unfold.yaml`

Before running the application, review and update `config/unfold.yaml` to match your desired magazine settings, theme, and external links. This file controls:
- Magazine name, short name, and description
- Theme and color settings
- Community articles feature
- External footer links
- Other project-specific configuration

Edit the values in `config/unfold.yaml` as needed for your deployment.

### Customizing Theme and Icons

You can override the default theme and icons by adding your own files to `/assets/theme/local/`. To do this:
- Copy the structure and file names from `/assets/theme/default/`.
- Place your custom `theme.css` and icon files in your theme folder.
- Update your configuration in `config/unfold.yaml` to reference your custom theme if needed.

This allows you to easily switch or update the look and feel of your magazine without modifying the default assets.


### Build the Docker containers

For development:
```bash
docker compose build
```

For production (using production overrides), set `APP_ENV=prod` in your `.env` file, set a strong **`APP_SECRET`**, and run:

```bash
docker compose -f compose.yaml -f compose.prod.yaml build
```

`compose.override.yaml` is meant for local development. For production, always pass **both** compose files for `up` as well, otherwise Docker Compose still merges the dev override (FrankenPHP dev image, port `9080`, etc.):

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

The production compose file publishes the app on **host port `80`** → container `80` (FrankenPHP / Caddy). Put **TLS and your public hostname** (e.g. `https://blog.imwald.eu`) in front with Apache or nginx as a reverse proxy to `http://127.0.0.1:80` on that host (or another port if you change `compose.prod.yaml`).

Set **`TRUSTED_PROXIES`** to the CIDR of your reverse proxy (defaults in `compose.prod.yaml` cover Docker and private nets; include the proxy’s address if it is elsewhere). In **`APP_ENV=prod`**, `config/packages/framework.yaml` enables **`trusted_proxies`** from that env var so Symfony trusts `X-Forwarded-Proto` / `X-Forwarded-For` from the proxy; adjust the value if generated URLs or secure cookies are wrong behind HTTPS.

### Docker Hub (pre-built image)

To build the production FrankenPHP image and push it (example registry: [`silberengel/unfold`](https://hub.docker.com/r/silberengel/unfold)):

```bash
docker login
# If the server is linux/amd64 and your builder is ARM, set --platform (omit if arch matches).
docker build --platform linux/amd64 --target frankenphp_prod -t silberengel/unfold:latest .
docker push silberengel/unfold:latest
```

Tag a release when you want a pinned version:

```bash
docker tag silberengel/unfold:latest silberengel/unfold:1.0.0
docker push silberengel/unfold:1.0.0
```

**On the remote server** you only need `compose.hub.yaml`, a `.env` with at least **`APP_SECRET`** (and `MYSQL_*` / `MYSQL_ROOT_PASSWORD` if you use the bundled MySQL), and Docker Compose. Copy `compose.hub.yaml` from the repo (or clone once and take that file). The stack publishes the app on **host port `9080`** → container `80` by default (so **`:80` stays free** for Apache/nginx). Point your reverse proxy at `http://127.0.0.1:9080`. To bind only loopback, set **`HTTP_PUBLISH=127.0.0.1:9080`**; to use host port **80** instead, set **`HTTP_PUBLISH=80`**. Override the image with **`UNFOLD_DOCKER_IMAGE=myuser/unfold:1.0.0`** if you use another name or tag.

```bash
docker compose -f compose.hub.yaml pull
docker compose -f compose.hub.yaml up -d
docker compose -f compose.hub.yaml exec php php bin/console doctrine:migrations:migrate --no-interaction
```

The production image must include **compiled asset mapper files** under `public/assets/` (the Docker build runs `asset-map:compile`). If you ever see JS modules blocked because the MIME type is `text/html`, the static files are missing: rebuild and push the image, or run once on the server:

`docker compose -f compose.hub.yaml exec php php bin/console asset-map:compile --no-debug`

The default `compose.hub.yaml` stack includes the **MySQL** service like the main compose file. If you use an external database, remove the `database` service and the `depends_on` block from `compose.hub.yaml`, and set **`DATABASE_URL`** in the `php` service `environment` to your connection string.

**MySQL `1045 Access denied` for `unfold_user`:** The official MySQL image only applies **`MYSQL_USER` / `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` on the first start** of an empty data volume. If you change passwords in `.env` later, the files inside the **`database_data` volume** still hold the old users. Either set **`.env`** back to the **original** passwords, or stop the stack and remove the named volume (e.g. `docker compose -f compose.hub.yaml down` then `docker volume rm unfold_database_data` — **this deletes all DB data**), then **`up -d`** again with the passwords you want and run **migrations** again.

The repo’s **`cron`** service still expects a local build and bind-mounted source; for Hub deploys, run **`articles:get`** (and any other jobs) from a host **cron** or **systemd timer** calling `docker compose -f compose.hub.yaml exec -T php php bin/console …`.

### Start the Docker containers (development)

```bash
docker compose up -d
```


### Run Database Migrations

Before fetching or displaying articles, make sure your database schema is up to date. Run:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

If you use **`compose.hub.yaml`**, prefix commands with `docker compose -f compose.hub.yaml` (for example `docker compose -f compose.hub.yaml exec php php bin/console …`).

### Fetching Articles

To fetch articles from the default relay for the last two months, run:

```bash
docker compose exec php php bin/console articles:get -- '-2 month' 'now'
```

You can adjust the date range as needed. This command will import articles into the local database.
