FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql mysqli

ENV APACHE_DOCUMENT_ROOT=/var/www/html
ENV OMQR_SESSION_DRIVER=database
ENV OMQR_DB_AUTO_CREATE=false
ENV OMQR_ASSET_BASE=/assets
ENV OMQR_APP_ENTRY=/

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["sh", "-c", "PORT=${PORT:-10000}; sed -i \"s|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|\" /etc/apache2/sites-available/000-default.conf; sed -i \"s|<Directory /var/www/>|<Directory /var/www/html/public/>|\" /etc/apache2/apache2.conf; sed -i \"s/^Listen .*/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -i \"s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; apache2-foreground"]
