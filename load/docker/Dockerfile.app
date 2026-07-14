# VLT-LOAD P2.2 — SUT: nginx + php-fpm in ONE image, real FPM-pool concurrency.
#
# Deliberately NOT `vendor/bin/testbench serve` (php artisan serve): that is a
# single-threaded dev server — pm.max_children (the whole slo.md §0.1 executor
# raccounting) cannot exist on it (plan.md D28, harness.md §2.2). The app code
# itself is bind-mounted from the repo at /app (see docker-compose.load.yml) —
# this image only carries the runtime, not a COPY of the source.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx gettext postgresql-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql mbstring

COPY load/docker/nginx.conf /etc/nginx/nginx.conf
COPY load/docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf.template
COPY load/docker/php.ini /usr/local/etc/php/conf.d/99-vaulter-load.ini
COPY load/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /app

ENTRYPOINT ["/entrypoint.sh"]
