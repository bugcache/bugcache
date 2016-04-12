FROM php:7.0

EXPOSE 8000

# Install PCNTL for graceful shutdown of Aerys
RUN docker-php-ext-install -j$(nproc) pcntl

# RUN apt-get update -q -y
# RUN apt-get install curl -q -y
# RUN curl -sS https://getcomposer.org/installer | php
# RUN mv composer.phar /usr/local/bin/composer

ADD . /app

WORKDIR "/app"
# RUN composer install

USER nobody

ENTRYPOINT ["/app/vendor/bin/aerys", "-c", "/app/res/config/config.dev.php", "-d"]
