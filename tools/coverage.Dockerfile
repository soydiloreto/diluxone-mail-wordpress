# The image used to measure coverage locally.
#
# Neither composer:2 nor php:8.3-cli ships a coverage driver, and without one
# PHPUnit measures nothing. pcov is the lightest and the one CI uses. It is
# built once and stays in Docker's cache.
FROM php:8.3-cli
RUN pecl install pcov && docker-php-ext-enable pcov
