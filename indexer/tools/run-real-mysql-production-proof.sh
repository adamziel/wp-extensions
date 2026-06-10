#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
PORT="${WP_FTS_HTTP_PORT:-8088}"
PROOF_ROOT="$(mktemp -d /tmp/wp-fts-mysql-proof.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"

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

mkdir -p "${PROOF_ROOT}/plugin"
(
    cd "${PLUGIN_DIR}"
    tar \
        --exclude='./vendor' \
        --exclude='./node_modules' \
        --exclude='./dist' \
        --exclude='.git' \
        --exclude='.env' \
        --exclude='*.pem' \
        -cf - .
) | (
    cd "${PROOF_ROOT}/plugin"
    tar -xf -
)

(
    cd "${PROOF_ROOT}/plugin"
    composer install --no-interaction --no-dev --optimize-autoloader
)

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
