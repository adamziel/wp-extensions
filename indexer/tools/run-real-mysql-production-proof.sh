#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
COMPONENT_DIR="${REPO_ROOT}/components/full-text-search"
SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
PORT="${WP_FTS_HTTP_PORT:-8088}"
PROOF_ROOT="$(mktemp -d /tmp/wp-fts-mysql-proof.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"
PROOF_HOME="${PROOF_ROOT}/home"
PROOF_TMPDIR="${PROOF_ROOT}/tmp"
PROOF_COMPOSER_HOME="${PROOF_ROOT}/composer/home"
PROOF_COMPOSER_CACHE_DIR="${PROOF_ROOT}/composer/cache"
PROOF_SAFE_PATH="${PATH:-/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin}"

PROOF_TAR_EXCLUDES=(
    --exclude='./vendor'
    --exclude='./node_modules'
    --exclude='./dist'
    --exclude='./.git'
    --exclude='./.env'
    --exclude='*/.env'
    --exclude='*.pem'
    --exclude='./auth.json'
    --exclude='*/auth.json'
    --exclude='./.composer'
    --exclude='./.composer/**'
    --exclude='*/.composer'
    --exclude='*/.composer/**'
)

cleanup() {
    local status=$?
    if [[ "${PROOF_ROOT:-}" == /tmp/wp-fts-mysql-proof.* ]]; then
        if [[ -f "${COMPOSE_FILE:-}" ]]; then
            docker compose -f "${COMPOSE_FILE}" down -v >/dev/null 2>&1 || true
        fi
        rm -rf "${PROOF_ROOT}"
    fi
    exit "${status}"
}
trap cleanup EXIT INT TERM

copy_proof_tree() {
    local source_dir="$1"
    local destination_dir="$2"

    mkdir -p "${destination_dir}"
    (
        cd "${source_dir}"
        tar "${PROOF_TAR_EXCLUDES[@]}" -cf - .
    ) | (
        cd "${destination_dir}"
        tar -xf -
    )
}

install_proof_composer_dependencies() {
    mkdir -p "${PROOF_HOME}" "${PROOF_TMPDIR}" "${PROOF_COMPOSER_HOME}" "${PROOF_COMPOSER_CACHE_DIR}"

    # Keep host credential helpers and Composer auth out of the copied-source proof install.
    (
        cd "${PROOF_ROOT}/plugin"
        env -i \
            PATH="${PROOF_SAFE_PATH}" \
            HOME="${PROOF_HOME}" \
            TMPDIR="${PROOF_TMPDIR}" \
            COMPOSER_HOME="${PROOF_COMPOSER_HOME}" \
            COMPOSER_CACHE_DIR="${PROOF_COMPOSER_CACHE_DIR}" \
            composer install --no-interaction --no-dev --optimize-autoloader
    )
}

copy_proof_tree "${PLUGIN_DIR}" "${PROOF_ROOT}/plugin"

if [[ ! -d "${COMPONENT_DIR}" ]]; then
    echo "BLOCKED: Missing Composer path repository at ${COMPONENT_DIR}." >&2
    exit 1
fi

copy_proof_tree "${COMPONENT_DIR}" "${PROOF_ROOT}/components/full-text-search"
install_proof_composer_dependencies

cat > "${COMPOSE_FILE}" <<YAML
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: wpfts
      MARIADB_USER: wpfts
      MARIADB_PASSWORD: wpfts_dev_only
      MARIADB_ROOT_PASSWORD: wpfts_root_dev_only
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-uwpfts", "-pwpfts_dev_only"]
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
      WORDPRESS_DB_NAME: wpfts
      WORDPRESS_DB_USER: wpfts
      WORDPRESS_DB_PASSWORD: wpfts_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${PROOF_ROOT}/plugin:/var/www/html/wp-content/plugins/indexer:ro
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
      WORDPRESS_DB_NAME: wpfts
      WORDPRESS_DB_USER: wpfts
      WORDPRESS_DB_PASSWORD: wpfts_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${PROOF_ROOT}/plugin:/var/www/html/wp-content/plugins/indexer:ro
    entrypoint: ["wp"]
volumes:
  wp_data:
YAML

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
    echo "BLOCKED: WordPress container did not create wp-config.php in time." >&2
    exit 1
fi

docker compose -f "${COMPOSE_FILE}" run --rm wpcli core install \
    --url="http://wordpress:80" \
    --title="WP FTS MySQL Proof" \
    --admin_user=admin \
    --admin_password="wpfts_dev_admin_only" \
    --admin_email=admin@example.test \
    --skip-email

docker compose -f "${COMPOSE_FILE}" run --rm wpcli plugin activate indexer

docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
    -e WP_FTS_WP_PATH=/var/www/html \
    -e WP_FTS_WP_CLI=wp \
    -e WP_FTS_WP_URL=http://wordpress:80 \
    wpcli /var/www/html/wp-content/plugins/indexer/tests/integration/real-wordpress-mysql.php

docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
    -e WP_FTS_WP_PATH=/var/www/html \
    -e WP_FTS_WP_CLI=wp \
    -e WP_FTS_WP_URL=http://wordpress:80 \
    -e WP_FTS_PROOF_HTTP_BASE=http://wordpress:80 \
    -e WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1 \
    -e "WP_FTS_SOURCE_SHA=${SOURCE_SHA}" \
    wpcli /var/www/html/wp-content/plugins/indexer/tests/integration/real-mysql-production-proof.php
