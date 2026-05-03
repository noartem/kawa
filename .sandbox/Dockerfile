ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli-bookworm

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv
COPY --from=ghcr.io/astral-sh/uv:latest /uvx /usr/local/bin/uvx

ARG USER
ARG PWD

ARG DEBIAN_FRONTEND=noninteractive
ARG NODE_MAJOR=24
ARG COMPOSER_VERSION=2.8.7
ARG RTK_VERSION
ARG APP_UID
ARG APP_GID

ARG NEXUS_HOST=host.docker.internal
ARG NEXUS_PORT=801
ARG NEXUS_APT_REPOSITORY=apt-debian-bookworm
ARG NEXUS_APT_UPDATES_REPOSITORY=apt-debian-bookworm-updates
ARG NEXUS_APT_SECURITY_REPOSITORY=apt-debian-bookworm-security

ENV NEXUS_URL=http://${NEXUS_HOST}:${NEXUS_PORT}
ENV NPM_REGISTRY=${NEXUS_URL}/repository/npm/
ENV COMPOSER_REGISTRY=${NEXUS_URL}/repository/composer/
ENV PYPI_INDEX=${NEXUS_URL}/repository/pypi/simple

RUN set -eux; \
    rm -f /etc/apt/sources.list.d/debian.sources; \
    cat > /etc/apt/sources.list.d/nexus.list <<EOF
deb [trusted=yes] ${NEXUS_URL}/repository/${NEXUS_APT_REPOSITORY}/ bookworm main
deb [trusted=yes] ${NEXUS_URL}/repository/${NEXUS_APT_UPDATES_REPOSITORY}/ bookworm-updates main
deb [trusted=yes] ${NEXUS_URL}/repository/${NEXUS_APT_SECURITY_REPOSITORY}/ bookworm-security main
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
    ripgrep \
    fzf \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install -j$(nproc) \
    bcmath \
    exif \
    gd \
    intl \
    ldap \
    pdo_pgsql \
    sockets \
    xsl \
    zip \
    && pecl install redis xdebug \
    && docker-php-ext-enable redis xdebug \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && curl -fsSL https://getcomposer.org/installer | php -- --version=${COMPOSER_VERSION} --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    if ! grep -q '^ru_RU.UTF-8 UTF-8' /etc/locale.gen; then \
        printf 'ru_RU.UTF-8 UTF-8\n' >> /etc/locale.gen; \
    fi; \
    locale-gen ru_RU.UTF-8

RUN set -eux; \
    curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/refs/heads/master/install.sh | RTK_INSTALL_DIR=/usr/local/bin RTK_VERSION="${RTK_VERSION:-}" sh; \
    rtk --version

RUN groupadd -g ${APP_GID} ${USER} \
    && useradd -m -d /home/${USER} -u ${APP_UID} -g ${APP_GID} -s /bin/zsh ${USER}

COPY .sandbox/php.ini /usr/local/etc/php/conf.d/99-sandbox.ini
COPY .sandbox/zshrc /home/${USER}/.zshrc
COPY .sandbox/vimrc /home/${USER}/.vimrc
COPY .sandbox/start.sh /usr/local/bin/sandbox-start

RUN chmod +x /usr/local/bin/sandbox-start

RUN mkdir -p \
    ${PWD} \
    /home/${USER}/.config/opencode \
    /home/${USER}/.config/nnt \
    /home/${USER}/.config/pip \
    /home/${USER}/.local/share/uv \
    /home/${USER}/.local/share/opencode \
    /home/${USER}/.local/state/opencode \
    /home/${USER}/.cache/opencode \
    /home/${USER}/.cache/uv \
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
    mkdir -p /etc/pip; \
    cat > /etc/pip.conf <<EOF
[global]
index-url = ${PYPI_INDEX}
trusted-host = ${NEXUS_HOST}
disable-pip-version-check = true
EOF
RUN set -eux; \
    mkdir -p /home/${USER}/.config/pip; \
    cat > /home/${USER}/.config/pip/pip.conf <<EOF
[global]
index-url = ${PYPI_INDEX}
trusted-host = ${NEXUS_HOST}
disable-pip-version-check = true
EOF
ENV UV_DEFAULT_INDEX=${PYPI_INDEX}
ENV UV_INDEX=''
ENV UV_NATIVE_TLS=true

RUN npm install -g opencode-ai@1.14.33 nonotify@0.2.0

RUN su -s /bin/zsh ${USER} -c "git config --global --add safe.directory '*'"

WORKDIR ${PWD}

ENV HOME=/home/${USER}
ENV COMPOSER_HOME=/home/${USER}/.composer
ENV PATH="/home/${USER}/.composer/vendor/bin:${PATH}"
ENV SHELL=/bin/zsh
ENV LANG=ru_RU.UTF-8
ENV LC_ALL=ru_RU.UTF-8

ENV npm_config_cache=/home/${USER}/.npm
ENV COMPOSER_CACHE_DIR=/home/${USER}/.composer/cache

CMD ["sandbox-start"]
