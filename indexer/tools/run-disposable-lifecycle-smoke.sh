#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
COMPONENT_DIR="${REPO_ROOT}/components/full-text-search"
SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD 2>/dev/null || printf 'unknown')"
PROOF_ROOT=""
COMPOSE_FILE=""
LIFECYCLE_REPORT_FILE=""
LIFECYCLE_REPORT_CONTAINER_FILE="/smoke-reports/lifecycle-report.json"
LIFECYCLE_OUTPUT_FILE=""

skip() {
    printf 'SKIP: %s\n' "$1"
    exit 0
}

require_command() {
    local command_name="$1"
    command -v "${command_name}" >/dev/null 2>&1 || skip "${command_name} is unavailable."
}

cleanup() {
    local status=$?
    if [[ -n "${COMPOSE_FILE:-}" && -f "${COMPOSE_FILE}" ]]; then
        docker compose -f "${COMPOSE_FILE}" down -v >/dev/null 2>&1 || true
    fi
    if [[ "${PROOF_ROOT:-}" == /tmp/wp-fts-lifecycle-smoke.* ]]; then
        rm -rf "${PROOF_ROOT}"
    fi
    if [[ "${status}" -ne 0 ]]; then
        printf 'FAIL: Docker disposable lifecycle smoke failed with exit %s.\n' "${status}" >&2
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

run_lifecycle_step() {
    local label="$1"
    shift
    printf 'INFO: %s\n' "${label}"
    set +e
    "$@" >"${LIFECYCLE_OUTPUT_FILE}" 2>&1
    local command_status=$?
    set -e
    if [[ "${command_status}" -ne 0 ]]; then
        cat "${LIFECYCLE_OUTPUT_FILE}" >&2
        return "${command_status}"
    fi

    local report_status
    if ! report_status="$(php -r '
$report = json_decode((string) @file_get_contents($argv[1] ?? ""), true);
if (!is_array($report)) {
    exit(2);
}
if (($report["schema"] ?? "") !== "wp-fts-disposable-lifecycle-smoke-v1") {
    exit(4);
}
$status = $report["status"] ?? "";
if (!is_string($status) || $status === "") {
    exit(3);
}
echo $status;
' "${LIFECYCLE_REPORT_FILE}")"; then
        cat "${LIFECYCLE_OUTPUT_FILE}" >&2
        printf 'FAIL: Inner lifecycle smoke did not write a parseable lifecycle report.\n' >&2
        return 1
    fi

    printf '{"schema":"wp-fts-disposable-lifecycle-wrapper-proof-v1","inner_report_schema":"wp-fts-disposable-lifecycle-smoke-v1","inner_report_status":"%s"}\n' "${report_status}"

    if [[ "${report_status}" != "passed" ]]; then
        cat "${LIFECYCLE_OUTPUT_FILE}" >&2
        printf 'FAIL: Inner lifecycle smoke reported status "%s"; expected "passed".\n' "${report_status}" >&2
        return 1
    fi

    printf 'INFO: Inner lifecycle smoke output captured %s bytes.\n' "$(wc -c <"${LIFECYCLE_OUTPUT_FILE}" | tr -d ' ')"
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

PROOF_ROOT="$(mktemp -d /tmp/wp-fts-lifecycle-smoke.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"
LIFECYCLE_REPORT_FILE="${PROOF_ROOT}/reports/lifecycle-report.json"
LIFECYCLE_OUTPUT_FILE="${PROOF_ROOT}/lifecycle-output.txt"
trap cleanup EXIT INT TERM

mkdir -p "${PROOF_ROOT}/plugin" "${PROOF_ROOT}/components/full-text-search" "${PROOF_ROOT}/reports"
chmod 0777 "${PROOF_ROOT}/reports"

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

cat > "${COMPOSE_FILE}" <<YAML
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: wpfts_lifecycle_smoke
      MARIADB_USER: wpfts_lifecycle_smoke
      MARIADB_PASSWORD: wpfts_lifecycle_smoke_dev_only
      MARIADB_ROOT_PASSWORD: wpfts_lifecycle_smoke_root_dev_only
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-uwpfts_lifecycle_smoke", "-pwpfts_lifecycle_smoke_dev_only"]
      interval: 5s
      timeout: 3s
      retries: 30
  wordpress:
    image: wordpress:php8.2-apache
    depends_on:
      db:
        condition: service_healthy
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wpfts_lifecycle_smoke
      WORDPRESS_DB_USER: wpfts_lifecycle_smoke
      WORDPRESS_DB_PASSWORD: wpfts_lifecycle_smoke_dev_only
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
      WORDPRESS_DB_NAME: wpfts_lifecycle_smoke
      WORDPRESS_DB_USER: wpfts_lifecycle_smoke
      WORDPRESS_DB_PASSWORD: wpfts_lifecycle_smoke_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${PROOF_ROOT}/plugin:/smoke-src:ro
      - ${PROOF_ROOT}/reports:/smoke-reports
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
        --title="WP FTS Lifecycle Smoke" \
        --admin_user=admin \
        --admin_password="wpfts_lifecycle_smoke_admin_only" \
        --admin_email=admin@example.test \
        --skip-email

run_step "Installing source-copy plugin into disposable WordPress" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint sh wpcli -lc \
        'rm -rf /var/www/html/wp-content/plugins/indexer && mkdir -p /var/www/html/wp-content/plugins && cp -R /smoke-src /var/www/html/wp-content/plugins/indexer'

run_step "Marking disposable WordPress root for guarded lifecycle smoke" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint sh wpcli -lc \
        'touch /var/www/html/.wp-fts-lifecycle-smoke'

printf 'INFO: Multisite lifecycle sub-scenario not run; this Docker wrapper records a single-site disposable lifecycle boundary.\n'

run_lifecycle_step "Running disposable lifecycle smoke against source-copy plugin" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
        -e WP_FTS_WP_PATH=/var/www/html \
        -e WP_FTS_WP_CLI=wp \
        -e WP_FTS_WP_URL=http://wordpress:80 \
        -e WP_FTS_LIFECYCLE_SMOKE_ALLOW=1 \
        -e "WP_FTS_SOURCE_SHA=${SOURCE_SHA}" \
        wpcli /smoke-src/tools/smoke-disposable-wordpress-lifecycle.php \
        --report-file="${LIFECYCLE_REPORT_CONTAINER_FILE}"

printf 'PASS: Docker disposable lifecycle smoke completed.\n'
