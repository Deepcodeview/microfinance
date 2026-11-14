FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql bcmath \
    && a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT=/var/www/api/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo "date.timezone=UTC" > /usr/local/etc/php/conf.d/timezone.ini \
    && echo "expose_php=Off" > /usr/local/etc/php/conf.d/security.ini

WORKDIR /var/www/api
