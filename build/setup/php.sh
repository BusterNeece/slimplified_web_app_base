#!/bin/bash
set -e
source /bd_build/buildconfig
set -x

PHP_VERSION=8.0

add-apt-repository -y ppa:ondrej/php
apt-get update

$minimal_apt_get_install php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-gd \
  php${PHP_VERSION}-curl php${PHP_VERSION}-xml php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-mbstring php${PHP_VERSION}-intl php${PHP_VERSION}-mysqlnd php${PHP_VERSION}-redis

echo "PHP_VERSION=${PHP_VERSION}" >>/etc/php/.version

# Copy PHP configuration
mkdir -p /run/php
touch /run/php/php${PHP_VERSION}-fpm.pid

cp /bd_build/php/php.ini /etc/php/${PHP_VERSION}/fpm/05-app.ini
cp /bd_build/php/phpfpmpool.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf

# Install Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
