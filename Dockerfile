#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.3 AS frankenphp_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

VOLUME /app/var/

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
	acl \
	curl \
	ca-certificates \
	gnupg \
	file \
	gettext \
	git \
        bash \
    libnss3-tools \
    cron \
	&& rm -rf /var/lib/apt/lists/*

# Asciidoctor.js (kind 30041 / 30818 publication bodies) via bin/render-asciidoc.mjs
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
	&& apt-get install -y --no-install-recommends nodejs \
	&& rm -rf /var/lib/apt/lists/*

# Composer: copy from the official image instead of @composer on install-php-extensions, which
# curl's getcomposer.org and fails when build DNS is broken (e.g. curl: (6) Could not resolve host).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN set -eux; \
	install-php-extensions \
		apcu \
		intl \
		opcache \
		zip \
        gmp \
        gd \
	;

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

# App liveness: GET /health (no DB/Nostr; see HealthController)
HEALTHCHECK --interval=10s --timeout=5s --retries=6 --start-period=120s \
	CMD curl -fsS http://127.0.0.1/health -o /dev/null || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

# imwald | gitcitadel — selects config/sites/*.yaml and assets/theme/sites/* before cache compile.
ARG UNFOLD_SITE=imwald

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="import worker.Caddyfile"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/caddy/worker.Caddyfile

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
COPY --link patches ./patches
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
	npm ci --omit=dev

RUN set -eux; \
	chmod +x scripts/select-unfold-site.sh; \
	./scripts/select-unfold-site.sh "${UNFOLD_SITE}";

RUN set -eux; \
	mkdir -p var/cache var/log; \
	cp .env.dist .env; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	rm -f .env; \
	composer run-script --no-dev post-install-cmd; \
	php bin/console asset-map:compile --no-debug; \
	# Strip deployment secrets from the compiled .env.local.php so they cannot be read from the
	# image layers. The listed keys must be injected as real environment variables at runtime;
	# Symfony will raise a clear error rather than silently using the public .env.dist defaults.
	# Done LAST: cache:clear and asset-map:compile both boot the Symfony kernel and need the env
	# vars resolved; stripping before them causes "Environment variable not found" errors.
	# MAINTENANCE: if a new secret is added to .env.dist, add it here too so it is not
	# compiled into the image. Use array_diff_key so the strip is explicit and order-independent;
	# missing keys are safely ignored (they were never compiled in and therefore never a risk).
	php -r '$strip=array_flip(["APP_SECRET","DATABASE_URL","MYSQL_USER","MYSQL_PASSWORD","MYSQL_ROOT_PASSWORD"]); $e=array_diff_key(include(".env.local.php"),$strip); file_put_contents(".env.local.php","<?php return ".var_export($e,true).";".PHP_EOL);' ; \
	chmod +x bin/console; sync;
