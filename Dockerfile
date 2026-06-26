FROM php:8.2-apache
RUN sed -i 's/^LoadModule mpm_event/#LoadModule mpm_event/' /etc/apache2/mods-enabled/mpm_event.conf 2>/dev/null || true
RUN docker-php-ext-install pdo pdo_mysql
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html