#!/bin/bash
set -e
source /bd_build/buildconfig
set -x

$minimal_apt_get_install sudo

adduser --home /var/app --disabled-password --gecos "" app

usermod -aG docker_env app
usermod -aG www-data app

mkdir -p /var/app/www /var/app/www_tmp

chown -R app:app /var/app
chmod -R 777 /var/app/www_tmp

echo 'app ALL=(ALL) NOPASSWD: ALL' >> /etc/sudoers
