FROM php:8.1-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip curl libcurl4-openssl-dev \
    libonig-dev libxml2-dev libpq-dev \
    && docker-php-ext-install pdo pdo_mysql

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash && \
    mv /root/.symfony*/bin/symfony /usr/local/bin/symfony

# install xdebug
RUN pecl install xdebug-3.1.5 && docker-php-ext-enable xdebug
