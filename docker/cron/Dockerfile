FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    bash \
    cron \
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql intl opcache zip gmp

WORKDIR /var/www/html

COPY crontab /etc/cron.d/unfold-prewarm
COPY prewarm_cron.sh /prewarm_cron.sh
COPY entry-cron.sh /entry-cron.sh

RUN chmod 0644 /etc/cron.d/unfold-prewarm \
    && chmod +x /prewarm_cron.sh /entry-cron.sh

CMD ["/entry-cron.sh"]
