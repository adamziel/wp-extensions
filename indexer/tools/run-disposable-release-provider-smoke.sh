#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
COMPONENT_DIR="${REPO_ROOT}/components/full-text-search"
SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD 2>/dev/null || printf 'unknown')"
REQUESTED_PORT="${WP_FTS_HTTP_PORT:-8088}"
PROOF_ROOT=""
COMPOSE_FILE=""
PORT=""

skip() {
    printf 'SKIP: %s\n' "$1"
    exit 0
}

require_command() {
    local command_name="$1"
    command -v "${command_name}" >/dev/null 2>&1 || skip "${command_name} is unavailable."
}

port_is_free() {
    php -r '
        $server = @stream_socket_server("tcp://127.0.0.1:" . $argv[1], $errno, $errstr);
        if (!is_resource($server)) {
            exit(1);
        }
        fclose($server);
    ' "$1" >/dev/null 2>&1
}

find_free_port() {
    php -r '
        $server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        if (!is_resource($server)) {
            exit(1);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        $pos = strrpos((string) $name, ":");
        if ($pos === false) {
            exit(1);
        }
        echo substr((string) $name, $pos + 1);
    '
}

cleanup() {
    local status=$?
    if [[ -n "${COMPOSE_FILE:-}" && -f "${COMPOSE_FILE}" ]]; then
        docker compose -f "${COMPOSE_FILE}" down -v >/dev/null 2>&1 || true
    fi
    if [[ "${PROOF_ROOT:-}" == /tmp/wp-fts-release-provider-smoke.* ]]; then
        rm -rf "${PROOF_ROOT}"
    fi
    if [[ "${status}" -ne 0 ]]; then
        printf 'FAIL: Docker disposable release/provider smoke failed with exit %s.\n' "${status}" >&2
    fi
    exit "${status}"
}

run_step() {
    local label="$1"
    shift
    printf 'INFO: %s\n' "${label}"
    "$@"
    printf 'PASS: %s\n' "${label}"
}

require_command php
require_command tar
require_command composer
require_command docker

docker compose version >/dev/null 2>&1 || skip "Docker Compose plugin is unavailable."
docker info >/dev/null 2>&1 || skip "Docker daemon is unavailable or not reachable."

if [[ ! -d "${COMPONENT_DIR}" ]]; then
    printf 'FAIL: Missing Composer path repository at %s.\n' "${COMPONENT_DIR}" >&2
    exit 1
fi

PORT="${REQUESTED_PORT}"
if ! port_is_free "${PORT}"; then
    PORT="$(find_free_port)" || {
        printf 'FAIL: HTTP port %s is occupied and no fallback port could be selected.\n' "${REQUESTED_PORT}" >&2
        exit 1
    }
    printf 'INFO: 127.0.0.1:%s is occupied; using disposable port %s.\n' "${REQUESTED_PORT}" "${PORT}"
fi

PROOF_ROOT="$(mktemp -d /tmp/wp-fts-release-provider-smoke.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"
trap cleanup EXIT INT TERM

mkdir -p "${PROOF_ROOT}/plugin" "${PROOF_ROOT}/components/full-text-search" "${PROOF_ROOT}/release" "${PROOF_ROOT}/release-build"

(
    cd "${PLUGIN_DIR}"
    tar \
        --exclude='./vendor' \
        --exclude='./node_modules' \
        --exclude='./dist' \
        --exclude='.git' \
        --exclude='./.env' \
        --exclude='*/.env' \
        --exclude='*.pem' \
        -cf - .
) | (
    cd "${PROOF_ROOT}/plugin"
    tar -xf -
)

(
    cd "${COMPONENT_DIR}"
    tar \
        --exclude='./vendor' \
        --exclude='./node_modules' \
        --exclude='./dist' \
        --exclude='.git' \
        --exclude='./.env' \
        --exclude='*/.env' \
        --exclude='*.pem' \
        -cf - .
) | (
    cd "${PROOF_ROOT}/components/full-text-search"
    tar -xf -
)

run_step "Installing source-copy Composer production dependencies" \
    composer install --working-dir="${PROOF_ROOT}/plugin" --no-interaction --no-dev --optimize-autoloader

run_step "Building direct-install release ZIP in disposable temp storage" \
    php "${PLUGIN_DIR}/tools/build-release-zip.php" \
        --plugin-src="${PLUGIN_DIR}" \
        --monorepo-root="${REPO_ROOT}" \
        --build-dir="${PROOF_ROOT}/release-build" \
        --output="${PROOF_ROOT}/release/wp-fts-indexer.zip"

cat > "${COMPOSE_FILE}" <<YAML
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: wpfts_release_smoke
      MARIADB_USER: wpfts_release_smoke
      MARIADB_PASSWORD: wpfts_release_smoke_dev_only
      MARIADB_ROOT_PASSWORD: wpfts_release_smoke_root_dev_only
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-uwpfts_release_smoke", "-pwpfts_release_smoke_dev_only"]
      interval: 5s
      timeout: 3s
      retries: 30
  wordpress:
    image: wordpress:php8.2-apache
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "127.0.0.1:${PORT}:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wpfts_release_smoke
      WORDPRESS_DB_USER: wpfts_release_smoke
      WORDPRESS_DB_PASSWORD: wpfts_release_smoke_dev_only
    volumes:
      - wp_data:/var/www/html
  wpcli:
    image: wordpress:cli-php8.2
    depends_on:
      db:
        condition: service_healthy
      wordpress:
        condition: service_started
    user: "33:33"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wpfts_release_smoke
      WORDPRESS_DB_USER: wpfts_release_smoke
      WORDPRESS_DB_PASSWORD: wpfts_release_smoke_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${PROOF_ROOT}/plugin:/smoke-src:ro
      - ${PROOF_ROOT}/release:/release:ro
    entrypoint: ["wp"]
volumes:
  wp_data:
YAML

run_step "Starting disposable WordPress and MariaDB containers" \
    docker compose -f "${COMPOSE_FILE}" up -d db wordpress

wp_config_ready=0
for _ in $(seq 1 60); do
    if docker compose -f "${COMPOSE_FILE}" exec -T wordpress test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
        wp_config_ready=1
        break
    fi
    sleep 2
done
if [[ "${wp_config_ready}" != "1" ]]; then
    printf 'FAIL: WordPress container did not create wp-config.php in time.\n' >&2
    exit 1
fi

run_step "Installing disposable WordPress site" \
    docker compose -f "${COMPOSE_FILE}" run --rm wpcli core install \
        --path=/var/www/html \
        --url="http://wordpress:80" \
        --title="WP FTS Release Provider Smoke" \
        --admin_user=admin \
        --admin_password="wpfts_release_smoke_admin_only" \
        --admin_email=admin@example.test \
        --skip-email

run_step "Marking disposable WordPress root for guarded smokes" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint sh wpcli -lc \
        'touch /var/www/html/.wp-fts-disposable-smoke /var/www/html/.wp-fts-provider-compatibility-smoke'

run_step "Running direct-install release ZIP smoke" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
        -e WP_FTS_WP_PATH=/var/www/html \
        -e WP_FTS_WP_CLI=wp \
        -e WP_FTS_WP_URL=http://wordpress:80 \
        -e WP_FTS_RELEASE_ZIP=/release/wp-fts-indexer.zip \
        -e WP_FTS_DISPOSABLE_SMOKE_ALLOW=1 \
        wpcli /smoke-src/tools/smoke-disposable-wordpress-release.php --zip=/release/wp-fts-indexer.zip

run_step "Deactivating installed release before source provider compatibility smoke" \
    docker compose -f "${COMPOSE_FILE}" run --rm wpcli plugin deactivate indexer --path=/var/www/html

run_step "Running provider compatibility smoke against disposable WordPress" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
        -e WP_FTS_WP_PATH=/var/www/html \
        -e WP_FTS_WP_CLI=wp \
        -e WP_FTS_PROVIDER_COMPATIBILITY_ALLOW=1 \
        -e "WP_FTS_SOURCE_SHA=${SOURCE_SHA}" \
        wpcli /smoke-src/tools/smoke-search-provider-compatibility.php

printf 'PASS: Docker disposable release/provider smoke completed.\n'
