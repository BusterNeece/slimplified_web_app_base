FROM gitpod/workspace-full:latest

USER root

RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -yq \
        php-redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/*

USER gitpod
