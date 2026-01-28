FROM ubuntu:22.04

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv
COPY --from=ghcr.io/astral-sh/uv:latest /uvx /usr/local/bin/uvx

ARG USER
ARG PROJECT_PATH

ARG DEBIAN_FRONTEND=noninteractive
ARG NODE_MAJOR=24
ARG COMPOSER_VERSION=2.8.7
ARG PHP_VERSION=8.5
ARG APP_UID
ARG APP_GID

ARG NEXUS_HOST=host.docker.internal
ARG NEXUS_PORT=801

ENV NEXUS_URL=http://${NEXUS_HOST}:${NEXUS_PORT}
ENV NPM_REGISTRY=${NEXUS_URL}/repository/npm/
ENV COMPOSER_REGISTRY=${NEXUS_URL}/repository/composer/
ENV PYPI_REGISTRY=${NEXUS_URL}/repository/pypi/

RUN set -eux; \
    cat > /etc/apt/sources.list.d/nexus.list <<EOF
 deb [trusted=yes] ${NEXUS_URL}/repository/apt-ubuntu-jammy/ jammy main restricted universe multiverse
 deb [trusted=yes] ${NEXUS_URL}/repository/apt-ubuntu-jammy-updates/ jammy-updates main restricted universe multiverse
 deb [trusted=yes] ${NEXUS_URL}/repository/apt-ubuntu-jammy-security/ jammy-security main restricted universe multiverse
EOF

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    gnupg \
    lsb-release \
    locales \
    unzip \
    zip \
    bash \
    zsh \
    vim \
    less \
    fzf \
    libpng-dev \
    libzip-dev \
    libpq-dev \
    libldap2-dev \
    libicu-dev \
    libxml2-dev \
    libxslt1-dev \
    libonig-dev \
    libssl-dev \
    pkg-config \
    software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
    php${PHP_VERSION} \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-dev \
    php${PHP_VERSION}-exif \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-ldap \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-opcache \
    php${PHP_VERSION}-pgsql \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-sockets \
    php${PHP_VERSION}-xdebug \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-xsl \
    php${PHP_VERSION}-zip \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && curl -fsSL https://getcomposer.org/installer | php -- --version=${COMPOSER_VERSION} --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

RUN locale-gen ru_RU.UTF-8

RUN groupadd -g ${APP_GID} ${USER} \
    && useradd -m -d /home/${USER} -u ${APP_UID} -g ${APP_GID} -s /bin/zsh ${USER}

COPY .sandbox/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-sandbox.ini
COPY .sandbox/zshrc /home/${USER}/.zshrc
COPY .sandbox/vimrc /home/${USER}/.vimrc

RUN mkdir -p \
    ${PROJECT_PATH} \
    ${PROJECT_PATH}/vendor \
    ${PROJECT_PATH}/node_modules \
    /home/${USER}/.config/opencode \
    /home/${USER}/.local/share/opencode \
    /home/${USER}/.local/state/opencode \
    /home/${USER}/.cache/opencode \
    /home/${USER}/.composer \
    /home/${USER}/.composer/cache \
    /home/${USER}/.npm \
    /home/${USER}/.zsh_history.d \
    && chown -R ${APP_UID}:${APP_GID} /home/${USER} \
    && chmod -R u+rwX /home/${USER}

RUN set -eux; \
    npm config set registry "${NPM_REGISTRY}"; \
    npm config set strict-ssl false; \
    su -s /bin/zsh ${USER} -c "npm config set registry '${NPM_REGISTRY}'"; \
    su -s /bin/zsh ${USER} -c "npm config set strict-ssl false"

RUN set -eux; \
    composer config -g repo.packagist composer "${COMPOSER_REGISTRY}"; \
    composer config -g --no-plugins --no-interaction secure-http false; \
    su -s /bin/zsh ${USER} -c "composer config -g repo.packagist composer '${COMPOSER_REGISTRY}'"; \
    su -s /bin/zsh ${USER} -c "composer config -g --no-plugins --no-interaction secure-http false"

RUN set -eux; \
    mkdir -p /etc/uv; \
    printf 'index-url = %s\n' "${PYPI_REGISTRY}" > /etc/uv/uv.toml

RUN set -eux; \
    su -s /bin/zsh ${USER} -c "mkdir -p ~/.config/uv"; \
    printf 'index-url = %s\n' "${PYPI_REGISTRY}" | su -s /bin/zsh ${USER} -c "cat > ~/.config/uv/uv.toml"

RUN set -eux; \
    mkdir -p /etc/pip; \
    printf '[global]\nindex-url = %s\n' "${PYPI_REGISTRY}" > /etc/pip/pip.conf

RUN set -eux; \
    su -s /bin/zsh ${USER} -c "mkdir -p ~/.pip"; \
    printf '[global]\nindex-url = %s\n' "${PYPI_REGISTRY}" | su -s /bin/zsh ${USER} -c "cat > ~/.pip/pip.conf"

RUN npm install -g opencode-ai

RUN su -s /bin/zsh ${USER} -c "git config --global --add safe.directory '*'"

WORKDIR ${PROJECT_PATH}

#COPY --chown=${APP_UID}:${APP_GID} scripts ./scripts
#RUN chmod +x ./scripts/start ./scripts/stop

ENV HOME=/home/${USER}
ENV COMPOSER_HOME=/home/${USER}/.composer
ENV PATH="/home/${USER}/.composer/vendor/bin:${PATH}"
ENV SHELL=/bin/zsh
ENV LANG=ru_RU.UTF-8
ENV LC_ALL=ru_RU.UTF-8

ENV npm_config_cache=/home/${USER}/.npm
ENV COMPOSER_CACHE_DIR=/home/${USER}/.composer/cache

CMD ["sleep", "infinity"]
