#
# Base image
#
FROM ubuntu:bionic

# Set time zone
ENV TZ="UTC"

# Run base build process
COPY ./build/ /bd_build

RUN chmod a+x /bd_build/*.sh \
    && /bd_build/prepare.sh \
    && /bd_build/add_user.sh \
    && /bd_build/setup.sh \
    && /bd_build/cleanup.sh \
    && rm -rf /bd_build

# Install Dockerize
ENV DOCKERIZE_VERSION v0.6.1
RUN wget https://github.com/jwilder/dockerize/releases/download/$DOCKERIZE_VERSION/dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz \
    && tar -C /usr/local/bin -xzvf dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz \
    && rm dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz

#
# START Operations as `app` user
#
USER app

# Clone repo and set up repo
WORKDIR /var/app/www
VOLUME ["/var/app/www_tmp", "/etc/letsencrypt"]

COPY --chown=app:app ./www/composer.json ./www/composer.lock ./
RUN composer install  \
    --ignore-platform-reqs \
    --no-ansi \
    --no-autoloader \
    --no-interaction \
    --no-scripts

# We need to copy our whole application so that we can generate the autoload file inside the vendor folder.
COPY --chown=app:app ./www/ .

RUN composer dump-autoload --optimize --classmap-authoritative

#
# END Operations as `app` user
#

USER root

EXPOSE 80 443

ENTRYPOINT ["dockerize",\
    "-wait","tcp://mariadb:3306",\
    "-timeout","40s"]
CMD ["/usr/local/bin/my_init"]
