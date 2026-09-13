# La imagen para medir cobertura en local.
#
# Ni composer:2 ni php:8.3-cli traen un driver de cobertura, y sin uno
# PHPUnit no mide nada. pcov es el más liviano y el que usa el CI. Se
# construye una vez y queda en el caché de Docker.
FROM php:8.3-cli
RUN pecl install pcov && docker-php-ext-enable pcov
