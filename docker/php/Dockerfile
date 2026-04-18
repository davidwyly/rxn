FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mysqli zip

# Xdebug is opt-in via the INSTALL_XDEBUG build arg so production images
# don't ship with a debugger loaded.
ARG INSTALL_XDEBUG=0
RUN if [ "$INSTALL_XDEBUG" = "1" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && { \
            echo "[XDebug]"; \
            echo "xdebug.mode=develop,debug"; \
            echo "xdebug.start_with_request=yes"; \
            echo "xdebug.client_host=host.docker.internal"; \
            echo "xdebug.client_port=9003"; \
            echo "xdebug.log=/tmp/xdebug.log"; \
        } > /usr/local/etc/php/conf.d/xdebug.ini; \
    fi

# Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/rxn
