#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
COMPONENT_DIR="${REPO_ROOT}/components/full-text-search"
SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD 2>/dev/null || printf 'unknown')"
PROOF_ROOT=""
COMPOSE_FILE=""
UPGRADE_REPORT_FILE=""
UPGRADE_REPORT_CONTAINER_FILE="/smoke-reports/upgrade-report.json"
UPGRADE_OUTPUT_FILE=""
PREVIOUS_PACKAGE="${WP_FTS_PREVIOUS_DIRECT_PACKAGE:-}"
SOURCE_COPY_TAR_EXCLUDES=(
    --exclude='./vendor'
    --exclude='./node_modules'
    --exclude='./dist'
    --exclude='.git'
    --exclude='./.env'
    --exclude='*/.env'
    --exclude='*.pem'
    --exclude='*.key'
    --exclude='./auth.json'
    --exclude='*/auth.json'
    --exclude='./.composer'
    --exclude='./.composer/**'
    --exclude='*/.composer'
    --exclude='*/.composer/**'
)

skip() {
    printf 'SKIP: %s\n' "$1"
    exit 0
}

require_command() {
    local command_name="$1"
    command -v "${command_name}" >/dev/null 2>&1 || skip "${command_name} is unavailable."
}

usage() {
    cat <<'TXT'
Usage: tools/run-disposable-upgrade-multisite-smoke.sh --previous-package=/path/to/previous.zip

Builds the current direct-install ZIP in temporary storage, installs a supplied
previous direct-install ZIP into a disposable Docker WordPress/MariaDB stack,
upgrades it to the current ZIP, and records explicit multisite boundary evidence.
TXT
}

while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --previous-package=*)
            PREVIOUS_PACKAGE="${1#--previous-package=}"
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            printf 'FAIL: Unknown option: %s\n' "$1" >&2
            exit 2
            ;;
    esac
done

cleanup() {
    local status=$?
    if [[ -n "${COMPOSE_FILE:-}" && -f "${COMPOSE_FILE}" ]]; then
        docker compose -f "${COMPOSE_FILE}" down -v >/dev/null 2>&1 || true
    fi
    if [[ "${PROOF_ROOT:-}" == /tmp/wp-fts-upgrade-multisite-smoke.* ]]; then
        rm -rf "${PROOF_ROOT}"
    fi
    if [[ "${status}" -ne 0 ]]; then
        printf 'FAIL: Docker disposable upgrade/multisite smoke failed with exit %s.\n' "${status}" >&2
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

# Keep source-copy Composer isolated from ambient credential-capable env.
run_source_copy_composer_install() {
    local composer_home="${PROOF_ROOT}/composer-home"
    local composer_cache_dir="${PROOF_ROOT}/composer-cache"
    local composer_tmp_dir="${PROOF_ROOT}/composer-tmp"
    local composer_path="${PATH:-/usr/local/bin:/usr/bin:/bin}"
    local locale_key
    local -a composer_env=(
        -i
        "PATH=${composer_path}"
        "TMPDIR=${composer_tmp_dir}"
        "TMP=${composer_tmp_dir}"
        "TEMP=${composer_tmp_dir}"
        "COMPOSER_HOME=${composer_home}"
        "COMPOSER_CACHE_DIR=${composer_cache_dir}"
    )

    mkdir -p "${composer_home}" "${composer_cache_dir}" "${composer_tmp_dir}"

    for locale_key in LANG LC_ALL LC_CTYPE; do
        if [[ -n "${!locale_key:-}" ]]; then
            composer_env+=("${locale_key}=${!locale_key}")
        fi
    done

    env "${composer_env[@]}" composer install --working-dir="${PROOF_ROOT}/plugin" --no-interaction --no-dev --optimize-autoloader
}

run_upgrade_step() {
    local label="$1"
    shift
    printf 'INFO: %s\n' "${label}"
    set +e
    "$@" >"${UPGRADE_OUTPUT_FILE}" 2>&1
    local command_status=$?
    set -e
    if [[ "${command_status}" -ne 0 ]]; then
        cat "${UPGRADE_OUTPUT_FILE}" >&2
        return "${command_status}"
    fi

    local proof_json
    if ! proof_json="$(php -r '
$report = json_decode((string) @file_get_contents($argv[1] ?? ""), true);
if (!is_array($report)) {
    exit(2);
}
if (($report["schema"] ?? "") !== "wp-fts-disposable-upgrade-smoke-v1") {
    exit(4);
}
$status = $report["status"] ?? "";
if (!is_string($status) || $status === "") {
    exit(3);
}
$upgrade = $report["upgrade_evidence"]["status"] ?? $status;
$multisite = $report["multisite_evidence"]["status"] ?? "not_run";
echo json_encode([
    "schema" => "wp-fts-disposable-upgrade-multisite-wrapper-proof-v1",
    "inner_report_schema" => "wp-fts-disposable-upgrade-smoke-v1",
    "inner_report_status" => $status,
    "upgrade_evidence_status" => is_string($upgrade) ? $upgrade : $status,
    "multisite_evidence_status" => is_string($multisite) ? $multisite : "not_run",
], JSON_UNESCAPED_SLASHES);
' "${UPGRADE_REPORT_FILE}")"; then
        cat "${UPGRADE_OUTPUT_FILE}" >&2
        printf 'FAIL: Inner upgrade smoke did not write a parseable upgrade report.\n' >&2
        return 1
    fi

    printf '%s\n' "${proof_json}"

    local report_status
    report_status="$(php -r '
$report = json_decode((string) @file_get_contents($argv[1] ?? ""), true);
echo is_array($report) && is_string($report["status"] ?? null) ? $report["status"] : "";
' "${UPGRADE_REPORT_FILE}")"
    if [[ "${report_status}" != "passed" ]]; then
        cat "${UPGRADE_OUTPUT_FILE}" >&2
        printf 'FAIL: Inner upgrade smoke reported status "%s"; expected "passed".\n' "${report_status}" >&2
        return 1
    fi

    printf 'INFO: Inner upgrade smoke output captured %s bytes.\n' "$(wc -c <"${UPGRADE_OUTPUT_FILE}" | tr -d ' ')"
    printf 'PASS: %s\n' "${label}"
}

if [[ -z "${PREVIOUS_PACKAGE}" ]]; then
    skip "Previous direct-install package is required; pass --previous-package=/path/to/previous.zip."
fi

if [[ ! -f "${PREVIOUS_PACKAGE}" || ! -r "${PREVIOUS_PACKAGE}" || "${PREVIOUS_PACKAGE}" != *.zip ]]; then
    skip "Previous direct-install package is unavailable, unreadable, or is not a ZIP file."
fi

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

PROOF_ROOT="$(mktemp -d /tmp/wp-fts-upgrade-multisite-smoke.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"
UPGRADE_REPORT_FILE="${PROOF_ROOT}/reports/upgrade-report.json"
UPGRADE_OUTPUT_FILE="${PROOF_ROOT}/upgrade-output.txt"
trap cleanup EXIT INT TERM

mkdir -p "${PROOF_ROOT}/plugin" "${PROOF_ROOT}/components/full-text-search" "${PROOF_ROOT}/release" "${PROOF_ROOT}/release-build" "${PROOF_ROOT}/reports"
chmod 0777 "${PROOF_ROOT}/reports"

cp "${PREVIOUS_PACKAGE}" "${PROOF_ROOT}/release/previous-wp-fts-indexer.zip"

(
    cd "${PLUGIN_DIR}"
    tar "${SOURCE_COPY_TAR_EXCLUDES[@]}" -cf - .
) | (
    cd "${PROOF_ROOT}/plugin"
    tar -xf -
)

(
    cd "${COMPONENT_DIR}"
    tar "${SOURCE_COPY_TAR_EXCLUDES[@]}" -cf - .
) | (
    cd "${PROOF_ROOT}/components/full-text-search"
    tar -xf -
)

run_step "Installing source-copy Composer production dependencies" \
    run_source_copy_composer_install

run_step "Building current direct-install release ZIP in disposable temp storage" \
    php "${PLUGIN_DIR}/tools/build-release-zip.php" \
        --plugin-src="${PLUGIN_DIR}" \
        --monorepo-root="${REPO_ROOT}" \
        --build-dir="${PROOF_ROOT}/release-build" \
        --output="${PROOF_ROOT}/release/current-wp-fts-indexer.zip"

cat > "${COMPOSE_FILE}" <<YAML
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: wpfts_upgrade_smoke
      MARIADB_USER: wpfts_upgrade_smoke
      MARIADB_PASSWORD: wpfts_upgrade_smoke_dev_only
      MARIADB_ROOT_PASSWORD: wpfts_upgrade_smoke_root_dev_only
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-uwpfts_upgrade_smoke", "-pwpfts_upgrade_smoke_dev_only"]
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
      WORDPRESS_DB_NAME: wpfts_upgrade_smoke
      WORDPRESS_DB_USER: wpfts_upgrade_smoke
      WORDPRESS_DB_PASSWORD: wpfts_upgrade_smoke_dev_only
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
      WORDPRESS_DB_NAME: wpfts_upgrade_smoke
      WORDPRESS_DB_USER: wpfts_upgrade_smoke
      WORDPRESS_DB_PASSWORD: wpfts_upgrade_smoke_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${PROOF_ROOT}/plugin:/smoke-src:ro
      - ${PROOF_ROOT}/release:/release:ro
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
        --title="WP FTS Upgrade Smoke" \
        --admin_user=admin \
        --admin_password="wpfts_upgrade_smoke_admin_only" \
        --admin_email=admin@example.test \
        --skip-email

run_step "Marking disposable WordPress root for guarded upgrade smoke" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint sh wpcli -lc \
        'touch /var/www/html/.wp-fts-upgrade-smoke'

printf 'INFO: Multisite runtime sub-scenario not run; this Docker wrapper records an explicit multisite boundary.\n'

run_upgrade_step "Running disposable upgrade smoke from previous package to current package" \
    docker compose -f "${COMPOSE_FILE}" run --rm --entrypoint php \
        -e WP_FTS_WP_PATH=/var/www/html \
        -e WP_FTS_WP_CLI=wp \
        -e WP_FTS_WP_URL=http://wordpress:80 \
        -e WP_FTS_UPGRADE_SMOKE_ALLOW=1 \
        -e "WP_FTS_SOURCE_SHA=${SOURCE_SHA}" \
        -e WP_FTS_PREVIOUS_RELEASE_ZIP=/release/previous-wp-fts-indexer.zip \
        -e WP_FTS_CURRENT_RELEASE_ZIP=/release/current-wp-fts-indexer.zip \
        wpcli /smoke-src/tools/smoke-disposable-wordpress-upgrade.php \
        --report-file="${UPGRADE_REPORT_CONTAINER_FILE}"

printf 'PASS: Docker disposable upgrade/multisite smoke completed.\n'
