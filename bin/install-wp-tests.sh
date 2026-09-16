#!/usr/bin/env bash
#
# Install the WordPress test suite + (optionally) a WordPress core checkout for
# the Cast integration suite.
#
# Heavily trimmed from the canonical WP-CLI scaffold script:
#   https://raw.githubusercontent.com/wp-cli/scaffold-command/master/templates/install-wp-tests.sh
#
# The upstream script defaults WP_VERSION to `latest`. This copy deliberately
# PINS the WordPress version to **7.1** (the same baseline already pinned in
# composer.json via roots/wordpress-no-content 7.1) so the integration harness
# never silently drifts to trunk/latest.
#
# The downloaded `wp-tests-config.php` is generated but NOT used: the integration
# bootstrap (tests/Integration/bootstrap.php) overrides it with
# tests/Integration/wp-tests-config.php, which consumes WP_DB_* env vars. This
# script only needs to fetch the test-suite `includes`/`data` directories (and a
# core checkout when WP_CORE_DIR is unset or empty).
#
# Usage:
#   WP_TESTS_DIR=/tmp/wordpress-tests-lib \
#   WP_CORE_DIR=/tmp/wordpress \
#   bin/install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Database creation is skipped by design: MariaDB is provisioned by
# docker-compose.integration.yml (which creates the cast_test DB + user). Pass a
# sixth arg `false` to this script to manage DB creation yourself.

set -euo pipefail

DB_NAME=${1:-cast_test}
DB_USER=${2:-cast_test}
DB_PASS=${3:-cast_test}
DB_HOST=${4:-127.0.0.1:3307}
# PINNED baseline — do not change to `latest`/`trunk` without updating the
# integration doc + workflow and composer.json together.
WP_VERSION=${5:-7.1}
SKIP_DB_CREATE=${6:-true}

TMPDIR=${TMPDIR:-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_TESTS_FILE="$WP_TESTS_DIR"/includes/functions.php
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}
WP_CORE_FILE="$WP_CORE_DIR"/wp-settings.php

# Map the requested version to a wordpress-develop git ref for the test suite.
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    # e.g. "7.1" -> the 7.1 branch
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
    if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.0$ ]]; then
        # "x.y.0" = first release of "x.y" -> use the x.y branch
        WP_TESTS_TAG="branches/${WP_VERSION%.0}"
    else
        WP_TESTS_TAG="tags/$WP_VERSION"
    fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    echo "Unsupported WP_VERSION '$WP_VERSION'; use 'x.y' (branch), 'x.y.z' (tag), 'nightly' or 'trunk'." >&2
    exit 1
fi

download() {
    if command -v curl > /dev/null 2>&1; then
        curl -L -s "$1" > "$2"
    elif command -v wget > /dev/null 2>&1; then
        wget -nv -O "$2" "$1"
    else
        echo "Error: Neither curl nor wget is installed." >&2
        exit 1
    fi
}

install_wp() {
    if [ -f "$WP_CORE_FILE" ]; then
        echo "WordPress already installed at $WP_CORE_DIR"
        return
    fi
    rm -rf "$WP_CORE_DIR"
    mkdir -p "$WP_CORE_DIR"

    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        download https://github.com/WordPress/wordpress/archive/refs/heads/master.tar.gz "$TMPDIR/wordpress.tar.gz"
        ARCHIVE_NAME='wordpress'
    else
        if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
            ARCHIVE_NAME="wordpress-$WP_VERSION.0"
        else
            ARCHIVE_NAME="wordpress-$WP_VERSION"
        fi
    fi
    download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"
    mkdir -p "$WP_CORE_DIR"
    tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
    rm -f "$TMPDIR/wordpress.tar.gz"
    echo "WordPress $WP_VERSION installed at $WP_CORE_DIR"
}

install_test_suite() {
    if [ ! -f "$WP_TESTS_FILE" ]; then
        rm -rf "$WP_TESTS_DIR"
        mkdir -p "$WP_TESTS_DIR"

        if [[ $WP_TESTS_TAG == 'trunk' ]]; then
            ref=trunk
            archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${ref}.tar.gz"
        elif [[ $WP_TESTS_TAG == branches/* ]]; then
            ref=${WP_TESTS_TAG#branches/}
            archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${ref}.tar.gz"
        else
            ref=${WP_TESTS_TAG#tags/}
            archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/tags/${ref}.tar.gz"
        fi

        download "$archive_url" "$TMPDIR/wordpress-develop.tar.gz"
        [ -s "$TMPDIR/wordpress-develop.tar.gz" ] || { echo "Downloaded test suite archive is empty." >&2; exit 1; }

        mkdir -p "$TMPDIR/wp-dev-extract"
        tar -zxmf "$TMPDIR/wordpress-develop.tar.gz" -C "$TMPDIR/wp-dev-extract"
        mv "$TMPDIR/wp-dev-extract/wordpress-develop-${ref}/tests/phpunit/includes" "$WP_TESTS_DIR"/
        mv "$TMPDIR/wp-dev-extract/wordpress-develop-${ref}/tests/phpunit/data" "$WP_TESTS_DIR"/
        rm -rf "$TMPDIR/wp-dev-extract" "$TMPDIR/wordpress-develop.tar.gz"
        echo "Test suite installed at $WP_TESTS_DIR"
    else
        echo "Test suite already installed at $WP_TESTS_DIR"
    fi
}

create_db() {
    local EXTRA=""
    local PARTS
    IFS=':' read -ra PARTS <<< "$DB_HOST"
    local DB_HOSTNAME="${PARTS[0]}"
    local DB_PORT="${PARTS[1]:-}"
    if [[ -n "$DB_PORT" && "$DB_PORT" =~ ^[0-9]+$ ]]; then
        EXTRA=" --host=$DB_HOSTNAME --port=$DB_PORT --protocol=tcp"
    elif [[ -n "$DB_HOSTNAME" ]]; then
        EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
    fi
    if command -v mariadb-admin > /dev/null 2>&1; then
        mariadb-admin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
    else
        mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
    fi
}

install_db() {
    if [ "$SKIP_DB_CREATE" = "true" ]; then
        echo "Skipping DB creation (provisioned by docker-compose.integration.yml)."
        return 0
    fi
    create_db
    echo "Database $DB_NAME ensured."
}

install_wp
install_test_suite
install_db
echo "Done."
