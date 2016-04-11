FROM php:7.0

EXPOSE 8000

RUN apt-get update -q -y
RUN apt-get install curl -q -y
RUN curl -sS https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer

RUN apt-get install mysql-client -q -y

ADD . /app

WORKDIR "/app"
RUN composer install

ENTRYPOINT ["/app/vendor/bin/aerys", "-c", "/app/res/config/config.sample.php", "-d"]
