# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Nextcloud 34 with the oci8 extension, which the official images leave out. Oracle's Instant
# Client is free to download but not to redistribute, so it is fetched at build time and this
# image is never pushed. x86_64 only: Instant Client and the libaio path are that machine's.
FROM nextcloud:34-apache

# Pinned and checked, as docs/security.md#supply-chain asks of anything CI runs.
ARG INSTANT_CLIENT=https://download.oracle.com/otn_software/linux/instantclient/2326200
ARG BASIC_ZIP=instantclient-basiclite-linux.x64-23.26.2.0.0.zip
ARG BASIC_SHA256=c5e97765e633ad02597b8274c35efe5c10bd249a3285e34ee58fa7b318243225
ARG SDK_ZIP=instantclient-sdk-linux.x64-23.26.2.0.0.zip
ARG SDK_SHA256=b49ce017cb1c8035b0506289f95cd6ee69173632e2795e4520fd2a38b51fbedb
ARG OCI8=3.4.1

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libaio1t64 unzip; \
    # Instant Client looks for the pre-t64 name.
    ln -s /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1; \
    curl -fsSL -o /tmp/basic.zip "$INSTANT_CLIENT/$BASIC_ZIP"; \
    curl -fsSL -o /tmp/sdk.zip "$INSTANT_CLIENT/$SDK_ZIP"; \
    echo "$BASIC_SHA256  /tmp/basic.zip" | sha256sum -c -; \
    echo "$SDK_SHA256  /tmp/sdk.zip" | sha256sum -c -; \
    mkdir -p /opt/oracle; \
    # Both archives carry the same META-INF.
    unzip -qo /tmp/basic.zip -d /opt/oracle; \
    unzip -qo /tmp/sdk.zip -d /opt/oracle; \
    mv /opt/oracle/instantclient_* /opt/oracle/instantclient; \
    echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf; \
    ldconfig; \
    # oci8 left php-src in PHP 8.4; PECL has it.
    echo "instantclient,/opt/oracle/instantclient" | pecl install "oci8-$OCI8"; \
    docker-php-ext-enable oci8; \
    rm -rf /tmp/*.zip /var/lib/apt/lists/*
