#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd -P)"
PROFILE="2k"
ENGINE="mysql-5.7"
OUTPUT=""
SOURCE_REF="HEAD"
BASELINE_COMMIT="36a26f4ad1aaef9758922f24677069045c5291ab"
BASELINE_REF="${BASELINE_COMMIT}"
JIEBA_GITLINK="67fa2e36e72f69d9134b8a1037b83fbb070b9775"
JIEBA_URL="https://github.com/fxsjy/jieba"
JIEBA_DICTIONARY_SHA256="7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8"
JIEBA_DICTIONARY_BYTES=5071852
JIEBA_LICENSE_SHA256="18ba0984839f85853b29fadaf992f7dba8fd0ca0fbeae34de2b8735222dc7a37"
JIEBA_LICENSE_BYTES=1075
ALLOW_DIRTY=0
KEEP=0
CONCURRENCY_SECONDS=60
MIGRATION_DISK_MONITOR_PID=""
WPCLI_PROBE_CONTAINER=""
WATCHDOG_PID=""
WATCHDOG_ESCALATING=0
WHOLE_RUN_TIMEOUT_SECONDS=19800
WATCHDOG_CLEANUP_GRACE_SECONDS=300
DB_PRE_CORPUS_PEAK_LIMIT_BYTES=805306368
MIGRATION_FAILPOINT_DEADLINE_SECONDS=7200
MIGRATION_FAILPOINT_BATCH_BUDGET_SECONDS=300
MIGRATION_FAILPOINT_READY_TIMEOUT_SECONDS=$((MIGRATION_FAILPOINT_DEADLINE_SECONDS + MIGRATION_FAILPOINT_BATCH_BUDGET_SECONDS + 60))
MIGRATION_FAILPOINT_OUTER_TIMEOUT_SECONDS=$((MIGRATION_FAILPOINT_READY_TIMEOUT_SECONDS + 60))
MARIADB_IMAGE="mariadb@sha256:5a5c675881ef3fd1c1da9b0a3bfd6ee82edbe39cd9e32e06be18034c37235e0e"
MYSQL57_IMAGE="mysql@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb"
MYSQL_IMAGE="mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b"
WORDPRESS_IMAGE="wordpress@sha256:bfc320ed4f02dd3939186b8020de64203a48a939d6dedcf44cb92cf2368923f5"
WPCLI_IMAGE="wordpress@sha256:7f492e43c962ee85b1a9d5f88a97111559d92c2fb785f5d20650670bfaaa1763"

usage() {
    cat <<'EOF'
Usage: indexer/tools/run-relational-fts-worst-case.sh [options]

  --profile=2k|50k|100k
  --engine=mariadb-10.11|mysql-5.7|mysql-8.0
  --output=PATH                 Required machine-readable evidence destination.
  --source-ref=REF              Clean Git ref/worktree to package (default HEAD).
  --concurrency-seconds=N       Default 60; values below 60 are non-acceptance.
  --allow-dirty                 Local development only; evidence records dirty=true.
  --keep                        Keep disposable containers and temporary files.
  --help

The command is destructive only to containers and volumes it creates. It never
returns a successful SKIP. Docker/resource/schema/backend failures are fatal.
EOF
}

for argument in "$@"; do
    case "${argument}" in
        --profile=*) PROFILE="${argument#*=}" ;;
        --engine=*) ENGINE="${argument#*=}" ;;
        --output=*) OUTPUT="${argument#*=}" ;;
        --source-ref=*) SOURCE_REF="${argument#*=}" ;;
        --concurrency-seconds=*) CONCURRENCY_SECONDS="${argument#*=}" ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        --keep) KEEP=1 ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Unknown option: ${argument}" >&2; usage >&2; exit 2 ;;
    esac
done

case "${PROFILE}" in
    2k) DOCUMENTS=2000 ;;
    50k) DOCUMENTS=50000 ;;
    100k) DOCUMENTS=100000 ;;
    *) echo "Invalid profile: ${PROFILE}" >&2; exit 2 ;;
esac
case "${ENGINE}" in
    mariadb-10.11) EXPECTED_DB_IMAGE="${MARIADB_IMAGE}"; DB_IMAGE="${WP_FTS_MARIADB_IMAGE:-${MARIADB_IMAGE}}"; DB_KIND=mariadb ;;
    mysql-5.7) EXPECTED_DB_IMAGE="${MYSQL57_IMAGE}"; DB_IMAGE="${WP_FTS_MYSQL57_IMAGE:-${MYSQL57_IMAGE}}"; DB_KIND=mysql ;;
    mysql-8.0) EXPECTED_DB_IMAGE="${MYSQL_IMAGE}"; DB_IMAGE="${WP_FTS_MYSQL_IMAGE:-${MYSQL_IMAGE}}"; DB_KIND=mysql ;;
    *) echo "Invalid engine: ${ENGINE}" >&2; exit 2 ;;
esac
case "${PROFILE}/${ENGINE}" in
    2k/mysql-5.7) LANE_ID="mysql57-2k" ;;
    50k/mariadb-10.11) LANE_ID="mariadb1011-50k" ;;
    50k/mysql-8.0) LANE_ID="mysql80-50k" ;;
    100k/mariadb-10.11) LANE_ID="mariadb1011-100k" ;;
    100k/mysql-8.0) LANE_ID="mysql80-100k" ;;
    *)
        if (( ALLOW_DIRTY == 0 )); then
            echo "BLOCKED: ${PROFILE}/${ENGINE} is not one of the five clean acceptance lanes; use --allow-dirty for diagnostics." >&2
            exit 2
        fi
        LANE_ID="diagnostic-${ENGINE}-${PROFILE}"
        ;;
esac
WP_IMAGE="${WP_FTS_WORDPRESS_IMAGE:-${WORDPRESS_IMAGE}}"
WPCLI_RUN_IMAGE="${WP_FTS_WPCLI_IMAGE:-${WPCLI_IMAGE}}"
if (( ALLOW_DIRTY == 0 )); then
    for override_name in WP_FTS_MARIADB_IMAGE WP_FTS_MYSQL57_IMAGE WP_FTS_MYSQL_IMAGE WP_FTS_WORDPRESS_IMAGE WP_FTS_WPCLI_IMAGE; do
        if [[ -n "${!override_name-}" ]]; then
            echo "BLOCKED: image overrides are forbidden in clean acceptance lanes: ${override_name}. Use --allow-dirty for non-acceptance diagnostics." >&2
            exit 1
        fi
    done
fi
if [[ -z "${OUTPUT}" ]]; then
    echo "--output is required; acceptance evidence may not be discarded." >&2
    exit 2
fi
if [[ ! "${CONCURRENCY_SECONDS}" =~ ^[1-9][0-9]*$ ]]; then
    echo "--concurrency-seconds must be a positive integer." >&2
    exit 2
fi
if (( CONCURRENCY_SECONDS < 60 && ALLOW_DIRTY == 0 )); then
    echo "Acceptance concurrency must run for at least 60 seconds." >&2
    exit 2
fi

if ! command -v php >/dev/null 2>&1; then
    echo "BLOCKED: required command is unavailable: php" >&2
    exit 1
fi

PROOF_ROOT="$(mktemp -d /tmp/wp-fts-relational-worst-case.XXXXXX)"
COMPOSE_FILE="${PROOF_ROOT}/compose.yaml"
EVIDENCE_DIR="${PROOF_ROOT}/evidence"
DB_MEMORY_CHECKPOINTS="${EVIDENCE_DIR}/database-memory-cgroup.tsv"
DB_LAST_MEMORY_CHECKPOINT=""
WP_MEMORY_CHECKPOINTS="${EVIDENCE_DIR}/wordpress-memory-cgroup.tsv"
WP_LAST_MEMORY_CHECKPOINT=""
BUILD_DIR="${PROOF_ROOT}/build"
REPRO_BUILD_DIR="${PROOF_ROOT}/repro-build"
ZIP_PATH="${PROOF_ROOT}/wp-fts-indexer.zip"
REPRO_ZIP_PATH="${PROOF_ROOT}/wp-fts-indexer-repro.zip"
BASELINE_ZIP_PATH="${PROOF_ROOT}/wp-fts-indexer-baseline.zip"
PHP_INI="${PROOF_ROOT}/worst-case.ini"
SOURCE_ROOT="${REPO_ROOT}"
SOURCE_SHA=""
BASELINE_ROOT="${PROOF_ROOT}/baseline-source"
WORKTREE_CREATED=0
BASELINE_WORKTREE_CREATED=0
SOURCE_DIRTY=0
RUN_COMPLETED=0
RUN_PUBLISHED=0
COMPOSE_TORN_DOWN=0
RUN_STAGE="source-and-package"
RUN_PHASE="initialization"
RUN_ID="$(php -r 'echo bin2hex(random_bytes(16));')"
mkdir -p "${EVIDENCE_DIR}" "${BUILD_DIR}"
chmod 0777 "${EVIDENCE_DIR}"

repo_context_has_preexisting_entry() {
    local context="${REPO_ROOT}/.context"
    local first_entry

    if [[ ! -e "${context}" && ! -L "${context}" ]]; then
        return 1
    fi
    # The directory itself may be a pre-created empty evidence parent. A link,
    # a non-directory, an unreadable directory, or anything below it is input
    # that existed before this run and therefore invalidates a clean lane.
    if [[ -L "${context}" || ! -d "${context}" || ! -r "${context}" ]]; then
        return 0
    fi
    first_entry="$(find "${context}" -mindepth 1 -print -quit 2>/dev/null)" || return 0
    [[ -n "${first_entry}" ]]
}

# Snapshot the caller's tree before publishing the required RUNNING envelope.
# The output may intentionally live below the repository (as it does in CI),
# but that harness-owned file is evidence, not an uncommitted source input.
if [[ "${SOURCE_REF}" == "HEAD" ]] \
    && [[ -n "$(git -C "${REPO_ROOT}" status --porcelain --untracked-files=all 2>/dev/null)" ]]; then
    SOURCE_DIRTY=1
fi
if repo_context_has_preexisting_entry; then
    SOURCE_DIRTY=1
fi

publish_run_envelope() {
    local status="$1"
    local completed="$2"
    local runner_exit="$3"
    local failure_class="$4"
    local output_temporary="${OUTPUT}.tmp.$$"
    mkdir -p "$(dirname "${OUTPUT}")"
    php -r '
$status=$argv[1];
$completed=$argv[2]==="1";
$exit=$argv[3]===""?null:(int)$argv[3];
$preliminary=$argv[12]!==""&&is_file($argv[12]);
$data=[
 "schema"=>"relational-fts-run-state-v2","status"=>$status,"completed"=>$completed,
 "run_id"=>$argv[5],"lane_id"=>$argv[6],"profile"=>$argv[7],"documents"=>(int)$argv[8],
 "engine"=>$argv[9],"source_sha"=>$argv[10],"stage"=>$argv[11],"phase"=>$argv[4],
 "runner_exit"=>$exit,"failure_class"=>$argv[13]!==""?$argv[13]:null,
 "whole_run_timeout_seconds"=>(int)$argv[14],"watchdog_cleanup_grace_seconds"=>(int)$argv[15],
 "preliminary_evidence_present"=>$preliminary,
 "preliminary_evidence_sha256"=>$preliminary?hash_file("sha256",$argv[12]):null,
 "published_at_utc"=>gmdate("Y-m-d\\TH:i:s\\Z"),
];
$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(file_put_contents($argv[16],$json,LOCK_EX)!==strlen($json)||!rename($argv[16],$argv[17])){@unlink($argv[16]);fwrite(STDERR,"Could not atomically publish run state.\n");exit(1);}
' "${status}" "${completed}" "${runner_exit}" "${RUN_PHASE}" "${RUN_ID}" "${LANE_ID}" \
      "${PROFILE}" "${DOCUMENTS}" "${ENGINE}" "${SOURCE_SHA:-}" "${RUN_STAGE}" \
      "${EVIDENCE_DIR}/relational-fts-evidence.json" "${failure_class}" \
      "${WHOLE_RUN_TIMEOUT_SECONDS}" "${WATCHDOG_CLEANUP_GRACE_SECONDS}" "${output_temporary}" "${OUTPUT}"
}

publish_running_envelope() {
    publish_run_envelope RUNNING 0 "" ""
}

publish_failure_envelope() {
    local status="$1"
    local class
    case "${status}" in
        124) class="Timeout" ;;
        137) class="KilledOrOOM" ;;
        130|143) class="Cancelled" ;;
        *) class="ProcessFailure" ;;
    esac
    publish_run_envelope FAIL 0 "${status}" "${class}"
}

set_run_stage() {
    RUN_STAGE="$1"
    RUN_PHASE="${2:-$1}"
    publish_running_envelope
}

timed_host() {
    local label="$1"
    local seconds="$2"
    shift 2
    RUN_PHASE="${label}"
    timeout --signal=TERM --kill-after=30s "${seconds}s" "$@"
}

initialize_and_attest_jieba_source() {
    local label="$1"
    local root="$2"
    local relative_path="$3"
    local evidence_path="$4"
    local indexed_entry indexed_mode indexed_gitlink indexed_stage indexed_path configured_path configured_url
    local actual_gitlink dictionary license temporary

    temporary="${evidence_path}.tmp.$$"
    php -r '
$data=["schema"=>"jieba-source-attestation-v1","status"=>"RUNNING","source_root_commit"=>$argv[1],"path"=>$argv[2]];
file_put_contents($argv[3],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
' "$(git -C "${root}" rev-parse HEAD)" "${relative_path}" "${temporary}"
    mv "${temporary}" "${evidence_path}"

    indexed_entry="$(git -C "${root}" ls-files --stage -- "${relative_path}")"
    if [[ -z "${indexed_entry}" || "${indexed_entry}" == *$'\n'* ]]; then
        echo "BLOCKED: ${label} Jieba source must have one exact index entry: ${relative_path}." >&2
        return 1
    fi
    read -r indexed_mode indexed_gitlink indexed_stage indexed_path <<< "${indexed_entry}"
    configured_path="$(git -C "${root}" config -f .gitmodules --get "submodule.${relative_path}.path")"
    configured_url="$(git -C "${root}" config -f .gitmodules --get "submodule.${relative_path}.url")"
    if [[ "${indexed_mode}" != 160000 \
        || "${indexed_gitlink}" != "${JIEBA_GITLINK}" \
        || "${indexed_stage}" != 0 \
        || "${indexed_path}" != "${relative_path}" \
        || "${configured_path}" != "${relative_path}" \
        || "${configured_url}" != "${JIEBA_URL}" ]]; then
        echo "BLOCKED: ${label} Jieba gitlink/path/URL attestation failed for ${relative_path}." >&2
        return 1
    fi

    timed_host "${label}-jieba-submodule-sync" 120 git -C "${root}" submodule sync -- "${relative_path}"
    timed_host "${label}-jieba-submodule-update" 900 git -C "${root}" -c protocol.version=2 \
        submodule update --init --depth 1 -- "${relative_path}"
    actual_gitlink="$(git -C "${root}/${relative_path}" rev-parse HEAD)"
    if [[ "${actual_gitlink}" != "${JIEBA_GITLINK}" \
        || -n "$(git -C "${root}/${relative_path}" status --porcelain --untracked-files=all)" ]]; then
        echo "BLOCKED: ${label} Jieba checkout is not the clean pinned gitlink ${JIEBA_GITLINK}." >&2
        return 1
    fi

    dictionary="${root}/${relative_path}/jieba/dict.txt"
    license="${root}/${relative_path}/LICENSE"
    php -r '
$dictionary=$argv[1];$license=$argv[2];
$expected=[
 "dictionary"=>["bytes"=>(int)$argv[3],"sha256"=>$argv[4]],
 "license"=>["bytes"=>(int)$argv[5],"sha256"=>$argv[6]],
];
foreach([$dictionary,$license] as $path){
 if(!is_file($path)||is_link($path)){fwrite(STDERR,"Pinned Jieba runtime source is missing or linked: {$path}\n");exit(1);}
}
$actual=[
 "dictionary"=>["bytes"=>filesize($dictionary),"sha256"=>hash_file("sha256",$dictionary)],
 "license"=>["bytes"=>filesize($license),"sha256"=>hash_file("sha256",$license)],
];
if($actual!==$expected){fwrite(STDERR,"Pinned Jieba runtime source bytes do not match the attested source.\n");exit(1);}
$data=[
 "schema"=>"jieba-source-attestation-v1","status"=>"PASS",
 "source_root_commit"=>$argv[7],"path"=>$argv[8],"url"=>$argv[9],"gitlink"=>$argv[10],
 "dictionary"=>$actual["dictionary"],"license"=>$actual["license"],
];
file_put_contents($argv[11],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
' "${dictionary}" "${license}" "${JIEBA_DICTIONARY_BYTES}" "${JIEBA_DICTIONARY_SHA256}" \
      "${JIEBA_LICENSE_BYTES}" "${JIEBA_LICENSE_SHA256}" "$(git -C "${root}" rev-parse HEAD)" \
      "${relative_path}" "${configured_url}" "${actual_gitlink}" "${temporary}"
    mv "${temporary}" "${evidence_path}"
}

capture_host() {
    local variable="$1"
    local label="$2"
    local seconds="$3"
    local captured
    shift 3
    RUN_PHASE="${label}"
    captured="$(timeout --signal=TERM --kill-after=30s "${seconds}s" "$@")" || return
    printf -v "${variable}" '%s' "${captured}"
}

capture_compose() {
    local variable="$1"
    local label="$2"
    local seconds="$3"
    shift 3
    capture_host "${variable}" "${label}" "${seconds}" docker compose -f "${COMPOSE_FILE}" "$@"
}

capture_database_memory_checkpoint() {
    local checkpoint="$1"
    local raw
    if [[ "${checkpoint}" == *$'\t'* || "${checkpoint}" == *$'\n'* ]]; then
        echo "BLOCKED: database memory checkpoint labels must be one TSV-safe line." >&2
        return 1
    fi
    capture_compose raw "database-memory-${checkpoint}" 30 exec -T db sh -c "${CGROUP_MEMORY_PROBE}"
    printf '%s\t%s\n' "${checkpoint}" "${raw}" >> "${DB_MEMORY_CHECKPOINTS}"
    DB_LAST_MEMORY_CHECKPOINT="${raw}"
}

capture_wordpress_memory_checkpoint() {
    local checkpoint="$1"
    local raw container_id lifecycle started_at host_pid restart_count extra
    if [[ "${checkpoint}" == *$'\t'* || "${checkpoint}" == *$'\n'* ]]; then
        echo "BLOCKED: WordPress memory checkpoint labels must be one TSV-safe line." >&2
        return 1
    fi
    capture_compose container_id "wordpress-memory-container-${checkpoint}" 30 ps -q wordpress
    if [[ -z "${container_id}" || "${container_id}" != "${WP_CONTAINER}" ]]; then
        echo "BLOCKED: WordPress container identity changed before memory checkpoint ${checkpoint}." >&2
        return 1
    fi
    capture_compose raw "wordpress-memory-${checkpoint}" 30 exec -T wordpress sh -c "${CGROUP_MEMORY_PROBE}"
    capture_host lifecycle "wordpress-lifecycle-${checkpoint}" 30 docker inspect \
      --format '{{.State.StartedAt}}|{{.State.Pid}}|{{.RestartCount}}' "${container_id}"
    IFS='|' read -r started_at host_pid restart_count extra <<< "${lifecycle}"
    if [[ -z "${started_at}" || -n "${extra}" || -z "${host_pid}" || "${host_pid}" == *[!0-9]* || "${host_pid}" == "0" || -z "${restart_count}" || "${restart_count}" == *[!0-9]* ]]; then
        echo "BLOCKED: WordPress container lifecycle is malformed at memory checkpoint ${checkpoint}." >&2
        return 1
    fi
    raw="${raw}"$'\t'"${container_id}"$'\t'"${started_at}"$'\t'"${host_pid}"$'\t'"${restart_count}"
    printf '%s\t%s\n' "${checkpoint}" "${raw}" >> "${WP_MEMORY_CHECKPOINTS}"
    WP_LAST_MEMORY_CHECKPOINT="${raw}"
}

finalize_cgroup_memory_evidence() {
    local resources="${EVIDENCE_DIR}/resources.json"
    local temporary="${resources}.tmp.$$"
    php -r '
$resources=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$lines=file($argv[2],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($lines)){fwrite(STDERR,"Could not read database cgroup memory checkpoints.\n");exit(1);}
$unsigned=static fn(string $value):?int=>$value!==""&&strspn($value,"0123456789")===strlen($value)?(int)$value:null;
$parse=static function(string $label,string $raw)use($unsigned):array{
    $parts=explode("\t",$raw);
    $version=$parts[0]??"unavailable";
    $sources=$version==="v2"
        ? ["usage"=>"memory.current","peak"=>"memory.peak","limit_events"=>"memory.events:max","oom_events"=>"memory.events:oom","oom_kill_events"=>"memory.events:oom_kill"]
        : ["usage"=>"memory.usage_in_bytes","peak"=>"memory.max_usage_in_bytes","limit_events"=>"memory.failcnt","oom_events"=>"memory.failcnt (conservative)","oom_kill_events"=>"memory.oom_control:oom_kill"];
    $checkpoint=[
        "checkpoint"=>$label,
        "cgroup_version"=>$version,
        "usage_bytes"=>$unsigned($parts[1]??""),
        "peak_bytes"=>$unsigned($parts[2]??""),
        "limit_events"=>$unsigned($parts[3]??""),
        "oom_events"=>$unsigned($parts[4]??""),
        "oom_kill_events"=>$unsigned($parts[5]??""),
        "sources"=>$sources,
        "raw_sha256"=>hash("sha256",$raw),
    ];
    if(isset($parts[6])){$checkpoint["container_id"]=$parts[6];}
    if(isset($parts[7])){$checkpoint["container_started_at"]=$parts[7];}
    if(isset($parts[8])){$checkpoint["container_host_pid"]=$unsigned($parts[8]);}
    if(isset($parts[9])){$checkpoint["container_restart_count"]=$unsigned($parts[9]);}
    return $checkpoint;
};
$checkpoints=[];$labels=[];$malformed=false;
foreach($lines as $line){
    $separator=strpos($line,"\t");
    if($separator===false){$malformed=true;continue;}
    $label=substr($line,0,$separator);$raw=substr($line,$separator+1);
    if($label===""||isset($labels[$label])){$malformed=true;continue;}
    $labels[$label]=true;$checkpoint=$parse($label,$raw);
    $valid=in_array($checkpoint["cgroup_version"],["v1","v2"],true)
        && is_int($checkpoint["usage_bytes"])&&$checkpoint["usage_bytes"]>=0
        && is_int($checkpoint["peak_bytes"])&&$checkpoint["peak_bytes"]>0
        && $checkpoint["peak_bytes"]>=$checkpoint["usage_bytes"]
        && is_int($checkpoint["limit_events"])&&$checkpoint["limit_events"]>=0
        && is_int($checkpoint["oom_events"])&&$checkpoint["oom_events"]>=0
        && is_int($checkpoint["oom_kill_events"])&&$checkpoint["oom_kill_events"]>=0;
    if(!$valid){$malformed=true;}
    $checkpoints[]=$checkpoint;
}
$expectedLabels=["pre-corpus"];
foreach(["common_or","max_valid_or_prefix","rare_anchor_and","prefix_fanout"] as $case){
    for($sample=0;$sample<10;$sample++){$expectedLabels[]="pre-cold-restart-{$case}-{$sample}";}
}
$expectedLabels[]="final-workload";
$actualLabels=array_column($checkpoints,"checkpoint");
$database=is_array($resources["database"]??null)?$resources["database"]:[];
$effectiveCgroup=is_array($database["effective_cgroup"]??null)?$database["effective_cgroup"]:[];
$memory=is_array($database["memory"]??null)?$database["memory"]:[];
$pre=is_array($memory["pre_corpus"]??null)?$memory["pre_corpus"]:[];
$limit=(int)($memory["limit_bytes"]??0);$preLimit=(int)($memory["pre_corpus_peak_limit_bytes"]??0);$expectedPreLimit=(int)$argv[4];
$first=$checkpoints[0]??[];$final=$checkpoints[array_key_last($checkpoints)]??[];
$versions=array_values(array_unique(array_column($checkpoints,"cgroup_version")));
$peaks=array_values(array_filter(array_column($checkpoints,"peak_bytes"),"is_int"));
$limitEvents=array_values(array_filter(array_column($checkpoints,"limit_events"),"is_int"));
$oomEvents=array_values(array_filter(array_column($checkpoints,"oom_events"),"is_int"));
$oomKills=array_values(array_filter(array_column($checkpoints,"oom_kill_events"),"is_int"));
$wholePeak=$peaks===[]?null:max($peaks);$maxLimitEvents=$limitEvents===[]?null:max($limitEvents);
$maxOom=$oomEvents===[]?null:max($oomEvents);$maxOomKills=$oomKills===[]?null:max($oomKills);
$failures=is_array($resources["verification"]["gate_failures"]??null)?array_values($resources["verification"]["gate_failures"]):[];
if($malformed||$actualLabels!==$expectedLabels||($memory["expected_checkpoint_labels"]??null)!==$expectedLabels){$failures[]="database cgroup memory checkpoints do not match the exact ordered 42-checkpoint inventory";}
if(count($versions)!==1||($versions[0]??null)!==($effectiveCgroup["version"]??null)){$failures[]="database cgroup memory checkpoint versions do not match the effective cgroup";}
if($first!==$pre){$failures[]="database pre-corpus cgroup memory checkpoint changed before finalization";}
if(!is_int($wholePeak)||$wholePeak<1||$limit!==1073741824||$wholePeak>$limit){$failures[]="database whole-run cgroup peak is outside the hard 1 GiB limit";}
if(!is_int($pre["peak_bytes"]??null)||$preLimit!==$expectedPreLimit||$pre["peak_bytes"]>$preLimit){$failures[]="database pre-corpus cgroup peak exceeds 768 MiB";}
if($maxOom!==0||$maxOomKills!==0){$failures[]="database cgroup recorded an OOM or OOM kill";}
$memory["checkpoints"]=$checkpoints;
$memory["expected_checkpoint_labels"]=$expectedLabels;
$memory["checkpoint_count"]=count($checkpoints);
$memory["final_checkpoint"]=$final;
$memory["whole_run_peak_bytes"]=$wholePeak;
$memory["whole_run_headroom_bytes"]=is_int($wholePeak)?$limit-$wholePeak:null;
$memory["max_limit_events"]=$maxLimitEvents;
$memory["oom_events"]=$maxOom;
$memory["oom_kill_events"]=$maxOomKills;
$memory["counter_aggregation"]="maximum across restart-delimited cumulative counters";
$memory["complete"]=true;
$resources["database"]["memory"]=$memory;

$wordpressLines=file($argv[5],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($wordpressLines)){fwrite(STDERR,"Could not read WordPress cgroup memory checkpoints.\n");exit(1);}
$wordpressCheckpoints=[];$wordpressLabels=[];$wordpressMalformed=false;
foreach($wordpressLines as $line){
    $separator=strpos($line,"\t");
    if($separator===false){$wordpressMalformed=true;continue;}
    $label=substr($line,0,$separator);$raw=substr($line,$separator+1);
    if($label===""||isset($wordpressLabels[$label])){$wordpressMalformed=true;continue;}
    $wordpressLabels[$label]=true;$checkpoint=$parse($label,$raw);
    $valid=in_array($checkpoint["cgroup_version"],["v1","v2"],true)
        && is_int($checkpoint["usage_bytes"])&&$checkpoint["usage_bytes"]>=0
        && is_int($checkpoint["peak_bytes"])&&$checkpoint["peak_bytes"]>0
        && $checkpoint["peak_bytes"]>=$checkpoint["usage_bytes"]
        && is_int($checkpoint["limit_events"])&&$checkpoint["limit_events"]>=0
        && is_int($checkpoint["oom_events"])&&$checkpoint["oom_events"]>=0
        && is_int($checkpoint["oom_kill_events"])&&$checkpoint["oom_kill_events"]>=0
        && is_string($checkpoint["container_id"]??null)
        && preg_match("/\\A[0-9a-f]{64}\\z/D",$checkpoint["container_id"])===1
        && is_string($checkpoint["container_started_at"]??null)&&$checkpoint["container_started_at"]!==""
        && is_int($checkpoint["container_host_pid"]??null)&&$checkpoint["container_host_pid"]>0
        && is_int($checkpoint["container_restart_count"]??null)&&$checkpoint["container_restart_count"]>=0;
    if(!$valid){$wordpressMalformed=true;}
    $wordpressCheckpoints[]=$checkpoint;
}
$wordpressExpectedLabels=["pre-corpus","final-workload"];
$wordpressActualLabels=array_column($wordpressCheckpoints,"checkpoint");
$wordpressEvidence=is_array($resources["wordpress"]??null)?$resources["wordpress"]:[];
$wordpressCgroup=is_array($wordpressEvidence["effective_cgroup"]??null)?$wordpressEvidence["effective_cgroup"]:[];
$wordpressMemory=is_array($wordpressEvidence["memory"]??null)?$wordpressEvidence["memory"]:[];
$wordpressContainerId=$wordpressEvidence["container_id"]??null;
$wordpressLifecycle=is_array($wordpressEvidence["container_lifecycle"]??null)?$wordpressEvidence["container_lifecycle"]:[];
$wordpressPre=is_array($wordpressMemory["pre_corpus"]??null)?$wordpressMemory["pre_corpus"]:[];
$wordpressLimit=(int)($wordpressMemory["limit_bytes"]??0);
$wordpressFirst=$wordpressCheckpoints[0]??[];$wordpressFinal=$wordpressCheckpoints[array_key_last($wordpressCheckpoints)]??[];
$wordpressVersions=array_values(array_unique(array_column($wordpressCheckpoints,"cgroup_version")));
$wordpressPeaks=array_values(array_filter(array_column($wordpressCheckpoints,"peak_bytes"),"is_int"));
$wordpressLimitEvents=array_values(array_filter(array_column($wordpressCheckpoints,"limit_events"),"is_int"));
$wordpressOomEvents=array_values(array_filter(array_column($wordpressCheckpoints,"oom_events"),"is_int"));
$wordpressOomKills=array_values(array_filter(array_column($wordpressCheckpoints,"oom_kill_events"),"is_int"));
$wordpressPeak=$wordpressPeaks===[]?null:max($wordpressPeaks);
$wordpressMaxLimitEvents=$wordpressLimitEvents===[]?null:max($wordpressLimitEvents);
$wordpressMaxOom=$wordpressOomEvents===[]?null:max($wordpressOomEvents);
$wordpressMaxOomKills=$wordpressOomKills===[]?null:max($wordpressOomKills);
if($wordpressMalformed||$wordpressActualLabels!==$wordpressExpectedLabels||($wordpressMemory["expected_checkpoint_labels"]??null)!==$wordpressExpectedLabels){$failures[]="WordPress cgroup memory checkpoints do not match the exact ordered two-checkpoint inventory";}
if(count($wordpressVersions)!==1||($wordpressVersions[0]??null)!==($wordpressCgroup["version"]??null)){$failures[]="WordPress cgroup memory checkpoint versions do not match the effective cgroup";}
if(!is_string($wordpressContainerId)||preg_match("/\\A[0-9a-f]{64}\\z/D",$wordpressContainerId)!==1||count(array_filter($wordpressCheckpoints,static fn(array $checkpoint):bool=>($checkpoint["container_id"]??null)!==$wordpressContainerId))!==0){$failures[]="WordPress cgroup memory checkpoints do not retain one persistent container identity";}
if(array_keys($wordpressLifecycle)!==["started_at","host_pid","restart_count"]||!is_string($wordpressLifecycle["started_at"]??null)||$wordpressLifecycle["started_at"]===""||!is_int($wordpressLifecycle["host_pid"]??null)||$wordpressLifecycle["host_pid"]<1||!is_int($wordpressLifecycle["restart_count"]??null)||$wordpressLifecycle["restart_count"]<0||count(array_filter($wordpressCheckpoints,static fn(array $checkpoint):bool=>["started_at"=>$checkpoint["container_started_at"]??null,"host_pid"=>$checkpoint["container_host_pid"]??null,"restart_count"=>$checkpoint["container_restart_count"]??null]!==$wordpressLifecycle))!==0){$failures[]="WordPress cgroup memory checkpoints do not retain one unrestarted container lifecycle";}
if($wordpressFirst!==$wordpressPre){$failures[]="WordPress pre-corpus cgroup memory checkpoint changed before finalization";}
if(!is_int($wordpressPeak)||$wordpressPeak<1||$wordpressLimit!==536870912||$wordpressPeak>$wordpressLimit||($wordpressFinal["peak_bytes"]??null)!==$wordpressPeak||($wordpressFinal["peak_bytes"]??0)<($wordpressFirst["peak_bytes"]??PHP_INT_MAX)){$failures[]="WordPress whole-run cgroup peak is outside the hard 512 MiB persistent-container contract";}
if($wordpressMaxLimitEvents!==0||$wordpressMaxOom!==0||$wordpressMaxOomKills!==0){$failures[]="WordPress cgroup recorded a memory-limit, OOM, or OOM-kill event";}
$wordpressMemory["checkpoints"]=$wordpressCheckpoints;
$wordpressMemory["expected_checkpoint_labels"]=$wordpressExpectedLabels;
$wordpressMemory["checkpoint_count"]=count($wordpressCheckpoints);
$wordpressMemory["final_checkpoint"]=$wordpressFinal;
$wordpressMemory["whole_run_peak_bytes"]=$wordpressPeak;
$wordpressMemory["whole_run_headroom_bytes"]=is_int($wordpressPeak)?$wordpressLimit-$wordpressPeak:null;
$wordpressMemory["max_limit_events"]=$wordpressMaxLimitEvents;
$wordpressMemory["oom_events"]=$wordpressMaxOom;
$wordpressMemory["oom_kill_events"]=$wordpressMaxOomKills;
$wordpressMemory["counter_aggregation"]="maximum across cumulative checkpoints in one unrestarted container";
$wordpressMemory["complete"]=true;
$resources["wordpress"]["memory"]=$wordpressMemory;
$resources["verification"]["gate_failures"]=$failures;
$resources["status"]=$failures===[]?"PASS":"FAIL";
$json=json_encode($resources,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(file_put_contents($argv[3],$json,LOCK_EX)!==strlen($json)||!rename($argv[3],$argv[1])){@unlink($argv[3]);fwrite(STDERR,"Could not atomically update database cgroup memory evidence.\n");exit(1);}
if($failures!==[]){foreach($failures as $failure){fwrite(STDERR,"BLOCKED: {$failure}.\n");}exit(1);}
' "${resources}" "${DB_MEMORY_CHECKPOINTS}" "${temporary}" "${DB_PRE_CORPUS_PEAK_LIMIT_BYTES}" "${WP_MEMORY_CHECKPOINTS}"
}

arm_cleanup_escalation() {
    if [[ -n "${WATCHDOG_PID:-}" ]]; then
        kill "${WATCHDOG_PID}" >/dev/null 2>&1 || true
        wait "${WATCHDOG_PID}" 2>/dev/null || true
    fi
    start_watchdog "${WATCHDOG_CLEANUP_GRACE_SECONDS}" 9 0
    WATCHDOG_ESCALATING=1
}

start_watchdog() {
    local delay_seconds="$1"
    local first_signal="$2"
    local escalation_seconds="$3"
    # Keep the watchdog in one directly tracked process. A background shell
    # around `sleep` leaves the sleep child orphaned with the CI pipes open when
    # cleanup kills only the shell.
    php -r '
$parent=(int)$argv[1];$delay=(int)$argv[2];$first=(int)$argv[3];$escalation=(int)$argv[4];
sleep($delay);
if(posix_getppid()!==$parent||!@posix_kill($parent,0)||!@posix_kill($parent,$first)){exit(0);}
if($escalation>0){sleep($escalation);if(posix_getppid()===$parent&&@posix_kill($parent,0)){@posix_kill($parent,9);}}
' "$$" "${delay_seconds}" "${first_signal}" "${escalation_seconds}" "wp-fts-watchdog-${RUN_ID}" \
      </dev/null >/dev/null 2>&1 &
    WATCHDOG_PID=$!
}

publish_evidence() {
    local status="$1"
    local complete_success=0
    local archive_path="${OUTPUT}.artifacts.tar.gz"
    local archive_temporary="${archive_path}.tmp.$$"
    local output_temporary="${OUTPUT}.tmp.$$"
    mkdir -p "$(dirname "${OUTPUT}")"
    if (( status == 0 && RUN_COMPLETED == 1 )); then
        complete_success=1
    else
        # Publish the small terminal object before compression or Docker cleanup.
        # A later TERM-to-KILL escalation therefore cannot leave a stale PASS.
        publish_failure_envelope "${status}" || true
    fi

    rm -f \
        "${archive_temporary}" \
        "${output_temporary}" \
        "${OUTPUT}.partial-evidence.json" \
        "${OUTPUT}.partial-evidence.json.tmp.$$" \
        "${OUTPUT}.partial-corpus.json" \
        "${OUTPUT}.partial-corpus.json.tmp.$$"
    if [[ -d "${EVIDENCE_DIR}" ]]; then
        if timeout --signal=TERM --kill-after=30s 240s tar -czf "${archive_temporary}" -C "${EVIDENCE_DIR}" . \
            && mv "${archive_temporary}" "${archive_path}"; then
            :
        elif (( complete_success == 1 )); then
            rm -f "${archive_temporary}" "${archive_path}"
            echo "FAIL: could not atomically publish the complete raw artifact bundle." >&2
            return 1
        else
            rm -f "${archive_temporary}" "${archive_path}"
        fi
    fi

    if (( complete_success == 1 )); then
        if ! cp "${EVIDENCE_DIR}/relational-fts-evidence.json" "${output_temporary}" \
            || ! mv "${output_temporary}" "${OUTPUT}"; then
            rm -f "${output_temporary}"
            echo "FAIL: could not atomically publish the completed evidence report." >&2
            return 1
        fi
        RUN_PUBLISHED=1
        return
    fi

    if [[ -f "${EVIDENCE_DIR}/relational-fts-evidence.json" ]]; then
        cp "${EVIDENCE_DIR}/relational-fts-evidence.json" "${OUTPUT}.partial-evidence.json.tmp.$$"
        mv "${OUTPUT}.partial-evidence.json.tmp.$$" "${OUTPUT}.partial-evidence.json"
    elif [[ -f "${EVIDENCE_DIR}/corpus-manifest.json" ]]; then
        cp "${EVIDENCE_DIR}/corpus-manifest.json" "${OUTPUT}.partial-corpus.json.tmp.$$"
        mv "${OUTPUT}.partial-corpus.json.tmp.$$" "${OUTPUT}.partial-corpus.json"
    fi

}

# Preserve the database failure itself, not only the runner's coarse stage.
# These commands run before both the evidence archive and `compose down`; an
# initialization OOM or unsupported server flag would otherwise destroy the
# only logs and container state that explain why the real-database proof died.
capture_failure_environment_artifacts() {
    if [[ ! -f "${COMPOSE_FILE}" || ! -d "${EVIDENCE_DIR}" ]]; then
        return
    fi

    local manifest="${EVIDENCE_DIR}/failure-environment-capture.txt"
    local temporary="${manifest}.tmp.$$"
    local artifact command_status db_container
    : > "${temporary}"

    artifact="${EVIDENCE_DIR}/failure-compose-ps.json"
    if timeout --signal=TERM --kill-after=5s 30s docker compose -f "${COMPOSE_FILE}" \
        ps --all --format json > "${artifact}.tmp.$$" 2>&1; then
        command_status=0
    else
        command_status=$?
    fi
    mv "${artifact}.tmp.$$" "${artifact}"
    printf 'compose_ps_exit=%d\n' "${command_status}" >> "${temporary}"

    artifact="${EVIDENCE_DIR}/failure-compose.log"
    if timeout --signal=TERM --kill-after=5s 30s docker compose -f "${COMPOSE_FILE}" \
        logs --no-color --timestamps > "${artifact}.tmp.$$" 2>&1; then
        command_status=0
    else
        command_status=$?
    fi
    mv "${artifact}.tmp.$$" "${artifact}"
    printf 'compose_logs_exit=%d\n' "${command_status}" >> "${temporary}"

    db_container="$(timeout --signal=TERM --kill-after=5s 15s docker compose -f "${COMPOSE_FILE}" \
        ps --all -q db 2>/dev/null | head -n 1 || true)"
    printf 'db_container=%s\n' "${db_container}" >> "${temporary}"
    if [[ -n "${db_container}" ]]; then
        artifact="${EVIDENCE_DIR}/failure-db-inspect.json"
        if timeout --signal=TERM --kill-after=5s 15s docker inspect "${db_container}" \
            > "${artifact}.tmp.$$" 2>&1; then
            command_status=0
        else
            command_status=$?
        fi
        mv "${artifact}.tmp.$$" "${artifact}"
        printf 'db_inspect_exit=%d\n' "${command_status}" >> "${temporary}"

        artifact="${EVIDENCE_DIR}/failure-db.log"
        if timeout --signal=TERM --kill-after=5s 30s docker logs --timestamps "${db_container}" \
            > "${artifact}.tmp.$$" 2>&1; then
            command_status=0
        else
            command_status=$?
        fi
        mv "${artifact}.tmp.$$" "${artifact}"
        printf 'db_logs_exit=%d\n' "${command_status}" >> "${temporary}"
    fi

    mv "${temporary}" "${manifest}"
}

quiesce_failed_workloads() {
    if [[ ! -f "${COMPOSE_FILE}" ]]; then
        return 0
    fi
    # Stop the SQL server before any diagnostic capture or compression. A PHP
    # client killed at its deadline may have left its statement running on the
    # server, and cleanup evidence must never prolong that work.
    if timeout --signal=KILL 30s docker compose -f "${COMPOSE_FILE}" kill wordpress db >/dev/null 2>&1; then
        return 0
    fi
    echo "WARN: compose kill failed; tearing down the failed workload before diagnostic capture." >&2
    if timeout --signal=TERM --kill-after=15s 60s docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1; then
        COMPOSE_TORN_DOWN=1
        return 0
    fi
    # Compose may fail while the Docker daemon can still address already-known
    # containers. This last bounded fallback is enough to stop residual SQL even
    # when project-level teardown is unavailable.
    local container_id direct_kill_failed=0
    for container_id in "${WP_CONTAINER:-}" "${DB_CONTAINER:-}"; do
        if [[ -n "${container_id}" ]] && ! timeout --signal=KILL 15s docker kill "${container_id}" >/dev/null 2>&1; then
            direct_kill_failed=1
        fi
    done
    if (( direct_kill_failed == 0 )) && [[ -n "${WP_CONTAINER:-}${DB_CONTAINER:-}" ]]; then
        return 0
    fi
    echo "WARN: failed workloads could not be proven quiescent; skipping diagnostic capture and compression." >&2
    return 1
}

cleanup() {
    local status=$?
    local failure_workloads_quiesced=1
    trap - EXIT INT TERM USR1
    if (( status == 0 && RUN_COMPLETED == 0 )); then
        status=1
    fi
    if (( status != 0 )); then
        # Make the terminal object durable before any compression or Docker
        # cleanup, then retain a TERM-to-KILL ceiling around cleanup itself.
        publish_failure_envelope "${status}" || true
        if (( WATCHDOG_ESCALATING == 0 )); then
            arm_cleanup_escalation
        fi
    elif [[ -n "${WATCHDOG_PID:-}" ]]; then
        kill "${WATCHDOG_PID}" >/dev/null 2>&1 || true
        wait "${WATCHDOG_PID}" 2>/dev/null || true
        WATCHDOG_PID=""
    fi
    if (( status != 0 )); then
        if ! quiesce_failed_workloads; then
            failure_workloads_quiesced=0
        fi
    fi
    if [[ -n "${MIGRATION_DISK_MONITOR_PID:-}" ]]; then
        touch "${EVIDENCE_DIR}/migration-disk-monitor.stop" 2>/dev/null || true
        for _ in $(seq 1 40); do
            if ! kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
                break
            fi
            sleep 0.25
        done
        if kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
            kill "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null || true
            sleep 1
        fi
        if kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
            kill -9 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null || true
        fi
        wait "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null || true
        MIGRATION_DISK_MONITOR_PID=""
    fi
    if (( status != 0 && failure_workloads_quiesced == 1 )); then
        if (( COMPOSE_TORN_DOWN == 0 )); then
            capture_failure_environment_artifacts || true
        fi
        if (( KEEP == 0 )); then
            if timeout --signal=TERM --kill-after=15s 60s docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1; then
                COMPOSE_TORN_DOWN=1
            fi
        fi
    fi
    if [[ -n "${WPCLI_PROBE_CONTAINER:-}" ]]; then
        docker rm -f "${WPCLI_PROBE_CONTAINER}" >/dev/null 2>&1 || true
        WPCLI_PROBE_CONTAINER=""
    fi
    if (( status != 0 || RUN_PUBLISHED == 0 )); then
        if (( status == 0 || failure_workloads_quiesced == 1 )); then
            publish_evidence "${status}" || true
        fi
    fi
    if (( KEEP == 0 || failure_workloads_quiesced == 0 )); then
        if (( COMPOSE_TORN_DOWN == 0 )); then
            timeout --signal=TERM --kill-after=30s 180s docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1 || true
        fi
    fi
    if (( KEEP == 0 )); then
        if (( WORKTREE_CREATED == 1 )); then
            timeout --signal=TERM --kill-after=30s 60s git -C "${REPO_ROOT}" worktree remove --force "${SOURCE_ROOT}" >/dev/null 2>&1 || true
        fi
        if (( BASELINE_WORKTREE_CREATED == 1 )); then
            timeout --signal=TERM --kill-after=30s 60s git -C "${REPO_ROOT}" worktree remove --force "${BASELINE_ROOT}" >/dev/null 2>&1 || true
        fi
        rm -rf "${PROOF_ROOT}"
    else
        echo "Kept proof directory: ${PROOF_ROOT}" >&2
    fi
    if [[ -n "${WATCHDOG_PID:-}" ]]; then
        kill "${WATCHDOG_PID}" >/dev/null 2>&1 || true
        wait "${WATCHDOG_PID}" 2>/dev/null || true
        WATCHDOG_PID=""
    fi
    exit "${status}"
}
trap cleanup EXIT
trap 'publish_failure_envelope 130 || true; exit 130' INT
trap 'publish_failure_envelope 143 || true; exit 143' TERM
trap 'WATCHDOG_ESCALATING=1; publish_failure_envelope 124 || true; exit 124' USR1

# Apart from the entry cleanliness snapshot above, a non-PASS object exists
# before Docker, Git, package, or database work starts.
# The internal watchdog leaves five minutes for the signal trap, raw bundle, and
# container teardown before escalating to SIGKILL.
publish_running_envelope
if ! php -r 'exit(function_exists("posix_kill") && function_exists("posix_getppid") ? 0 : 1);'; then
    echo "BLOCKED: host PHP must provide posix_kill() and posix_getppid() for the whole-run watchdog." >&2
    exit 1
fi
USR1_SIGNAL="$(kill -l USR1)"
if [[ ! "${USR1_SIGNAL}" =~ ^[0-9]+$ ]]; then
    echo "BLOCKED: could not resolve the host SIGUSR1 number." >&2
    exit 1
fi
start_watchdog "${WHOLE_RUN_TIMEOUT_SECONDS}" "${USR1_SIGNAL}" "${WATCHDOG_CLEANUP_GRACE_SECONDS}"

# Install failure publication before every preflight that can fail in CI. PHP
# is the sole exception because it serializes the machine-readable envelope.
for command in docker git composer unzip rsync tar timeout; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "BLOCKED: required command is unavailable: ${command}" >&2
        exit 1
    fi
done
if ! timed_host docker-info 30 docker info >/dev/null 2>&1; then
    echo "BLOCKED: Docker daemon is unavailable." >&2
    exit 1
fi
if ! timed_host docker-compose-version 30 docker compose version >/dev/null 2>&1; then
    echo "BLOCKED: Docker Compose is unavailable." >&2
    exit 1
fi

if ! capture_host SOURCE_SHA source-ref-resolve 60 git -C "${REPO_ROOT}" rev-parse "${SOURCE_REF}" 2>/dev/null; then
    echo "BLOCKED: source ref does not resolve: ${SOURCE_REF}" >&2
    exit 1
fi

if (( SOURCE_DIRTY == 1 && ALLOW_DIRTY == 0 )); then
    echo "BLOCKED: source tree is dirty; commit/stash it or use --allow-dirty for non-acceptance smoke evidence." >&2
    exit 1
fi

# A clean acceptance lane packages an immutable detached worktree, not the live
# checkout that was inspected above. This closes the status-check/build race and
# makes SOURCE_SHA identify every packaged byte even if another process edits the
# caller's checkout while the multi-hour proof is running. Dirty HEAD smokes are
# intentionally the sole exception because their purpose is to exercise those
# uncommitted bytes and their evidence is permanently marked non-acceptance.
if [[ "${SOURCE_REF}" != "HEAD" || "${ALLOW_DIRTY}" == "0" ]]; then
    SOURCE_ROOT="${PROOF_ROOT}/source"
    capture_host SOURCE_COMMIT source-commit-resolve 60 git -C "${REPO_ROOT}" rev-parse "${SOURCE_REF}"
    timed_host source-worktree-create 300 git -C "${REPO_ROOT}" worktree add --detach "${SOURCE_ROOT}" "${SOURCE_COMMIT}"
    WORKTREE_CREATED=1
fi
timed_host baseline-worktree-create 300 git -C "${REPO_ROOT}" worktree add --detach "${BASELINE_ROOT}" "${BASELINE_REF}"
BASELINE_WORKTREE_CREATED=1
TEST_SCRIPT="${SOURCE_ROOT}/indexer/tests/integration/relational-fts-worst-case.php"
MUTATION_PROOF_SCRIPT="${SOURCE_ROOT}/indexer/tests/integration/mutation-fence-concurrency.php"
ISOLATED_BOUNDARIES_SCRIPT="${SOURCE_ROOT}/indexer/tests/integration/relational-fts-isolated-boundaries.php"
OLD_POSTING_FRONTIER_SCRIPT="${SOURCE_ROOT}/indexer/tests/integration/old-posting-frontier.php"
SOURCE_SHA="$(git -C "${SOURCE_ROOT}" rev-parse HEAD)"
BASELINE_SHA="$(git -C "${BASELINE_ROOT}" rev-parse HEAD)"
if [[ "${BASELINE_SHA}" != "${BASELINE_COMMIT}" ]]; then
    echo "BLOCKED: legacy comparison must resolve to immutable v3 ${BASELINE_COMMIT}, got ${BASELINE_SHA}." >&2
    exit 1
fi
if (( WORKTREE_CREATED == 1 )) && [[ -n "$(git -C "${SOURCE_ROOT}" status --porcelain --untracked-files=all)" ]]; then
    SOURCE_DIRTY=1
fi
if (( SOURCE_DIRTY == 1 && ALLOW_DIRTY == 0 )); then
    echo "BLOCKED: source tree is dirty; commit/stash it or use --allow-dirty for non-acceptance smoke evidence." >&2
    exit 1
fi
if [[ ! -f "${TEST_SCRIPT}" ]]; then
    echo "BLOCKED: missing integration proof script: ${TEST_SCRIPT}" >&2
    exit 1
fi
if [[ ! -f "${MUTATION_PROOF_SCRIPT}" ]]; then
    echo "BLOCKED: missing mutation-fence concurrency proof: ${MUTATION_PROOF_SCRIPT}" >&2
    exit 1
fi
if [[ ! -f "${ISOLATED_BOUNDARIES_SCRIPT}" ]]; then
    echo "BLOCKED: missing isolated boundary proof: ${ISOLATED_BOUNDARIES_SCRIPT}" >&2
    exit 1
fi
if [[ ! -f "${OLD_POSTING_FRONTIER_SCRIPT}" ]]; then
    echo "BLOCKED: missing old-posting frontier proof: ${OLD_POSTING_FRONTIER_SCRIPT}" >&2
    exit 1
fi
initialize_and_attest_jieba_source \
    current "${SOURCE_ROOT}" "components/full-text-search/resources/sources/jieba" \
    "${EVIDENCE_DIR}/jieba-source-current.json"
initialize_and_attest_jieba_source \
    baseline "${BASELINE_ROOT}" "indexer/resources/sources/jieba" \
    "${EVIDENCE_DIR}/jieba-source-baseline.json"
TEST_SCRIPT_SHA256="$(php -r 'echo hash_file("sha256", $argv[1]);' "${TEST_SCRIPT}")"
MUTATION_PROOF_SHA256="$(php -r 'echo hash_file("sha256", $argv[1]);' "${MUTATION_PROOF_SCRIPT}")"
ISOLATED_BOUNDARIES_SHA256="$(php -r 'echo hash_file("sha256", $argv[1]);' "${ISOLATED_BOUNDARIES_SCRIPT}")"
OLD_POSTING_FRONTIER_SHA256="$(php -r 'echo hash_file("sha256", $argv[1]);' "${OLD_POSTING_FRONTIER_SCRIPT}")"

timed_host package-primary-build 1800 php "${SOURCE_ROOT}/indexer/tools/build-release-zip.php" \
    --plugin-src="${SOURCE_ROOT}/indexer" \
    --monorepo-root="${SOURCE_ROOT}" \
    --build-dir="${BUILD_DIR}" \
    --output="${ZIP_PATH}" > "${EVIDENCE_DIR}/package-build.json"
timed_host package-repro-build 1800 php "${SOURCE_ROOT}/indexer/tools/build-release-zip.php" \
    --plugin-src="${SOURCE_ROOT}/indexer" \
    --monorepo-root="${SOURCE_ROOT}" \
    --build-dir="${REPRO_BUILD_DIR}" \
    --output="${REPRO_ZIP_PATH}" > "${EVIDENCE_DIR}/package-repro-build.json"
timed_host package-primary-zip-test 300 unzip -t "${ZIP_PATH}" > "${EVIDENCE_DIR}/zip-test.log"
timed_host package-repro-zip-test 300 unzip -t "${REPRO_ZIP_PATH}" > "${EVIDENCE_DIR}/zip-repro-test.log"
COMPOSER_PATH="$(command -v composer)"
PHP_PATH="$(command -v php)"
capture_host COMPOSER_VERSION composer-version 60 composer --version --no-ansi 2>&1
COMPOSER_SHA256="$(php -r '$hash=hash_file("sha256",$argv[1]);if(!is_string($hash)){exit(1);}echo $hash;' "${COMPOSER_PATH}")"
PHP_BINARY_SHA256="$(php -r '$hash=hash_file("sha256",$argv[1]);if(!is_string($hash)){exit(1);}echo $hash;' "${PHP_PATH}")"
timed_host package-reproducibility-evidence 600 php -r '
$canonicalize=function(mixed $value)use(&$canonicalize):mixed{
    if(!is_array($value)){return $value;}
    if(array_is_list($value)){return array_map($canonicalize,$value);}
    ksort($value,SORT_STRING);
    foreach($value as $key=>$child){$value[$key]=$canonicalize($child);}
    return $value;
};
$manifest=static function(string $path):array{
    $zip=new ZipArchive();
    $opened=$zip->open($path);
    if($opened!==true){throw new RuntimeException("Could not open release ZIP {$path}: {$opened}");}
    try{
        $entries=[];
        for($index=0;$index<$zip->numFiles;$index++){
            $name=$zip->getNameIndex($index);
            $stat=$zip->statIndex($index);
            if(!is_string($name)||$name===""||!is_array($stat)||str_ends_with($name,"/")){
                throw new RuntimeException("Release ZIP contains a malformed entry at index {$index}.");
            }
            $stream=$zip->getStream($name);
            if(!is_resource($stream)){throw new RuntimeException("Could not stream release ZIP entry {$name}.");}
            $hash=hash_init("sha256");
            $bytes=0;
            while(!feof($stream)){
                $chunk=fread($stream,1048576);
                if($chunk===false){fclose($stream);throw new RuntimeException("Could not read release ZIP entry {$name}.");}
                if($chunk===""){continue;}
                $bytes+=strlen($chunk);
                hash_update($hash,$chunk);
            }
            fclose($stream);
            $opsys=0;$attributes=0;
            if(!$zip->getExternalAttributesIndex($index,$opsys,$attributes)){
                throw new RuntimeException("Could not inspect release ZIP attributes for {$name}.");
            }
            $entries[]=[
                "name"=>$name,
                "bytes"=>$bytes,
                "content_sha256"=>hash_final($hash),
                "crc32"=>sprintf("%u",(int)($stat["crc"]??0)),
                "compressed_bytes"=>(int)($stat["comp_size"]??-1),
                "compression_method"=>(int)($stat["comp_method"]??-1),
                "mtime"=>(int)($stat["mtime"]??-1),
                "opsys"=>$opsys,
                "external_attributes"=>$attributes,
            ];
        }
    }finally{$zip->close();}
    $names=array_column($entries,"name");
    $sorted=$names;sort($sorted,SORT_STRING);
    return [
        "entry_count"=>count($entries),
        "ordered_unique_names"=>$names===$sorted&&count(array_unique($names))===count($names),
        "entries"=>$entries,
    ];
};
$primary=$manifest($argv[1]);
$secondary=$manifest($argv[2]);
$primaryZip=hash_file("sha256",$argv[1]);
$secondaryZip=hash_file("sha256",$argv[2]);
$primaryBuild=json_decode((string)file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
$secondaryBuild=json_decode((string)file_get_contents($argv[4]),true,512,JSON_THROW_ON_ERROR);
$primaryManifestHash=hash("sha256",json_encode($canonicalize($primary),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$secondaryManifestHash=hash("sha256",json_encode($canonicalize($secondary),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$toolchain=[
    "php_version"=>PHP_VERSION,
    "php_sapi"=>PHP_SAPI,
    "php_binary_sha256"=>$argv[8],
    "zip_extension_version"=>phpversion("zip")?:null,
    "libzip_version"=>defined("ZipArchive::LIBZIP_VERSION")?ZipArchive::LIBZIP_VERSION:null,
    "zlib_version"=>defined("ZLIB_VERSION")?ZLIB_VERSION:null,
    "composer_version"=>$argv[7],
    "composer_binary_sha256"=>$argv[6],
];
$isolationValid=($primaryBuild["build_dir"]??null)===$argv[10]
    &&($secondaryBuild["build_dir"]??null)===$argv[11]
    &&$argv[10]!==$argv[11]
    &&($primaryBuild["composer_home"]??null)===$argv[10]."/composer-home"
    &&($secondaryBuild["composer_home"]??null)===$argv[11]."/composer-home"
    &&($primaryBuild["composer_cache_dir"]??null)===$argv[10]."/composer-cache"
    &&($secondaryBuild["composer_cache_dir"]??null)===$argv[11]."/composer-cache"
    &&($primaryBuild["composer_plugins"]??null)===false
    &&($secondaryBuild["composer_plugins"]??null)===false
    &&($primaryBuild["composer_scripts"]??null)===false
    &&($secondaryBuild["composer_scripts"]??null)===false;
$gates=[
    ["id"=>"independent_build_zip_bytes_identical","expected"=>$primaryZip,"actual"=>$secondaryZip,"passed"=>is_string($primaryZip)&&hash_equals($primaryZip,(string)$secondaryZip)],
    ["id"=>"independent_build_entry_manifests_identical","expected"=>$primaryManifestHash,"actual"=>$secondaryManifestHash,"passed"=>hash_equals($primaryManifestHash,$secondaryManifestHash)&&$primary===$secondary],
    ["id"=>"independent_build_entries_ordered_unique","expected"=>true,"actual"=>[$primary["ordered_unique_names"],$secondary["ordered_unique_names"]],"passed"=>$primary["entry_count"]>0&&$primary["ordered_unique_names"]&&$secondary["ordered_unique_names"]],
    ["id"=>"independent_build_reports_match_archives","expected"=>[$primaryZip,$secondaryZip],"actual"=>[$primaryBuild["sha256"]??null,$secondaryBuild["sha256"]??null],"passed"=>($primaryBuild["status"]??null)==="ok"&&($secondaryBuild["status"]??null)==="ok"&&($primaryBuild["sha256"]??null)===$primaryZip&&($secondaryBuild["sha256"]??null)===$secondaryZip],
    ["id"=>"package_toolchain_recorded","expected"=>"complete version and binary identities","actual"=>$toolchain,"passed"=>is_string($toolchain["php_version"])&&$toolchain["php_version"]!==""&&is_string($toolchain["composer_version"])&&$toolchain["composer_version"]!==""&&is_string($toolchain["php_binary_sha256"])&&strlen($toolchain["php_binary_sha256"])===64&&is_string($toolchain["composer_binary_sha256"])&&strlen($toolchain["composer_binary_sha256"])===64],
    ["id"=>"package_builds_use_fresh_composer_state","expected"=>"independent homes and caches; plugins/scripts disabled","actual"=>["primary_home"=>$primaryBuild["composer_home"]??null,"secondary_home"=>$secondaryBuild["composer_home"]??null,"primary_cache"=>$primaryBuild["composer_cache_dir"]??null,"secondary_cache"=>$secondaryBuild["composer_cache_dir"]??null,"plugins"=>[$primaryBuild["composer_plugins"]??null,$secondaryBuild["composer_plugins"]??null],"scripts"=>[$primaryBuild["composer_scripts"]??null,$secondaryBuild["composer_scripts"]??null]],"passed"=>$isolationValid],
];
$passed=count(array_filter($gates,static fn(array $gate):bool=>$gate["passed"]!==true))===0;
$evidence=[
    "schema"=>"relational-fts-package-reproducibility-v1",
    "status"=>$passed?"PASS":"FAIL",
    "source_sha"=>$argv[5],
    "build_isolation"=>[
        "build_directories"=>"independent",
        "composer_homes"=>"fresh-per-build",
        "composer_caches"=>"fresh-per-build",
        "composer_plugins"=>"disabled",
        "composer_scripts"=>"disabled",
    ],
    "primary"=>["zip_sha256"=>$primaryZip,"entry_manifest_sha256"=>$primaryManifestHash,"manifest"=>$primary],
    "secondary"=>["zip_sha256"=>$secondaryZip,"entry_manifest_sha256"=>$secondaryManifestHash,"manifest"=>$secondary],
    "toolchain"=>$toolchain,
    "gates"=>$gates,
];
$hashInput=$evidence;
$evidence["evidence_sha256"]=hash("sha256",json_encode($canonicalize($hashInput),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
file_put_contents($argv[9],json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
if(!$passed){fwrite(STDERR,"FAIL: independent release package builds are not reproducible.\n");exit(1);}
' "${ZIP_PATH}" "${REPRO_ZIP_PATH}" "${EVIDENCE_DIR}/package-build.json" "${EVIDENCE_DIR}/package-repro-build.json" \
  "${SOURCE_SHA}" "${COMPOSER_SHA256}" "${COMPOSER_VERSION}" "${PHP_BINARY_SHA256}" "${EVIDENCE_DIR}/package-reproducibility.json" \
  "${BUILD_DIR}" "${REPRO_BUILD_DIR}"
ZIP_SHA256="$(php -r '$e=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(($e["status"]??null)!=="PASS"){exit(1);}echo $e["primary"]["zip_sha256"]??"";' "${EVIDENCE_DIR}/package-reproducibility.json")"
# Package immutable legacy runtime source with the current hardened packager.
# Executing historical packaging code would re-enable whatever Composer plugin,
# script, auth, and global-config behavior happened to exist at that old ref.
timed_host baseline-package-build 1800 php "${SOURCE_ROOT}/indexer/tools/build-release-zip.php" \
    --plugin-src="${BASELINE_ROOT}/indexer" \
    --monorepo-root="${BASELINE_ROOT}" \
    --build-dir="${PROOF_ROOT}/baseline-build" \
    --output="${BASELINE_ZIP_PATH}" > "${EVIDENCE_DIR}/baseline-package-build.json"
timed_host baseline-package-zip-test 300 unzip -t "${BASELINE_ZIP_PATH}" > "${EVIDENCE_DIR}/baseline-zip-test.log"
BASELINE_ZIP_SHA256="$(php -r '
$build=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$zipHash=hash_file("sha256",$argv[2]);
if(($build["status"]??null)!=="ok"||($build["sha256"]??null)!==$zipHash||($build["composer_plugins"]??null)!==false||($build["composer_scripts"]??null)!==false){fwrite(STDERR,"FAIL: immutable baseline package did not use the hardened source-bound Composer policy.\n");exit(1);}
echo $zipHash;
' "${EVIDENCE_DIR}/baseline-package-build.json" "${BASELINE_ZIP_PATH}")"

cat > "${PHP_INI}" <<'INI'
memory_limit=128M
max_execution_time=0
display_errors=1
log_errors=1
INI

if [[ "${DB_KIND}" == "mariadb" ]]; then
    DB_ENV=$(cat <<'YAML'
      MARIADB_DATABASE: wpfts
      MARIADB_USER: wpfts
      MARIADB_PASSWORD: wpfts_dev_only
      MARIADB_ROOT_PASSWORD: wpfts_root_dev_only
YAML
)
    DB_HEALTH='["CMD", "mariadb-admin", "ping", "-h", "localhost", "-uroot", "-pwpfts_root_dev_only"]'
else
    DB_ENV=$(cat <<'YAML'
      MYSQL_DATABASE: wpfts
      MYSQL_USER: wpfts
      MYSQL_PASSWORD: wpfts_dev_only
      MYSQL_ROOT_PASSWORD: wpfts_root_dev_only
YAML
)
    DB_HEALTH='["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-pwpfts_root_dev_only"]'
fi

cat > "${COMPOSE_FILE}" <<YAML
services:
  db:
    image: ${DB_IMAGE}
    cpus: "1.0"
    mem_limit: 1073741824
    memswap_limit: 1073741824
    environment:
${DB_ENV}
    volumes:
      - db_data:/var/lib/mysql
      - ${EVIDENCE_DIR}:/evidence
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
      - --performance-schema=ON
      # The search SQL contract is <=32 KiB. Retaining exactly that many bytes
      # preserves complete statement identity without the per-event reserves
      # that made 65,536-byte retention exceed this lane's 1 GiB cgroup.
      - --performance-schema-max-sql-text-length=32768
      # 2,048 global events cover every measured request/worker interval with
      # enough room for the exact 2,003-event targeted-scope attribution while
      # avoiding the default 10,000-row SQL_TEXT allocation.
      - --performance-schema-events-statements-history-long-size=2048
      # The proof reads only history_long. Do not reserve unused per-thread
      # history or digest summaries for the 32-KiB statement payload.
      - --performance-schema-events-statements-history-size=0
      - --performance-schema-digests-size=0
      # Twenty-four client connections plus the pinned engines' internal
      # threads fit well below this reserve. Runtime gates fail if any thread
      # cannot be instrumented, so this cannot silently weaken attribution.
      - --performance-schema-max-thread-instances=128
      - --innodb-buffer-pool-size=268435456
      - --tmp-table-size=33554432
      - --max-heap-table-size=33554432
      - --max-connections=24
      - --innodb-flush-log-at-trx-commit=1
      - --innodb-buffer-pool-dump-at-shutdown=OFF
      - --innodb-buffer-pool-load-at-startup=OFF
    healthcheck:
      test: ${DB_HEALTH}
      interval: 3s
      timeout: 3s
      retries: 60
  wordpress:
    image: ${WP_IMAGE}
    cpus: "1.0"
    mem_limit: 536870912
    memswap_limit: 536870912
    depends_on:
      db:
        condition: service_healthy
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wpfts
      WORDPRESS_DB_USER: wpfts
      WORDPRESS_DB_PASSWORD: wpfts_dev_only
    volumes:
      - wp_data:/var/www/html
      - ${ZIP_PATH}:/proof/wp-fts-indexer.zip:ro
      - ${BASELINE_ZIP_PATH}:/proof/wp-fts-indexer-baseline.zip:ro
      - ${TEST_SCRIPT}:/proof/relational-fts-worst-case.php:ro
      - ${MUTATION_PROOF_SCRIPT}:/proof/mutation-fence-concurrency.php:ro
      - ${ISOLATED_BOUNDARIES_SCRIPT}:/proof/relational-fts-isolated-boundaries.php:ro
      - ${OLD_POSTING_FRONTIER_SCRIPT}:/proof/old-posting-frontier.php:ro
      - ${EVIDENCE_DIR}:/evidence
      - ${PHP_INI}:/usr/local/etc/php/conf.d/zzz-wp-fts-worst-case.ini:ro
  wpcli:
    image: ${WPCLI_RUN_IMAGE}
    cpus: "1.0"
    mem_limit: 536870912
    memswap_limit: 536870912
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
      - ${ZIP_PATH}:/proof/wp-fts-indexer.zip:ro
      - ${BASELINE_ZIP_PATH}:/proof/wp-fts-indexer-baseline.zip:ro
      - ${TEST_SCRIPT}:/proof/relational-fts-worst-case.php:ro
      - ${MUTATION_PROOF_SCRIPT}:/proof/mutation-fence-concurrency.php:ro
      - ${ISOLATED_BOUNDARIES_SCRIPT}:/proof/relational-fts-isolated-boundaries.php:ro
      - ${OLD_POSTING_FRONTIER_SCRIPT}:/proof/old-posting-frontier.php:ro
      - ${EVIDENCE_DIR}:/evidence
      - ${PHP_INI}:/usr/local/etc/php/conf.d/zzz-wp-fts-worst-case.ini:ro
    entrypoint: ["wp"]
volumes:
  db_data:
  wp_data:
YAML

phase_timeout_seconds() {
    case "$1" in
        setup|indexing-prepare|reindex-drain|migration-finalize|migration-rerun|multisite-migration|drain) printf '7200\n' ;;
        cold-prepare|dependency-lob|max-valid-setup|max-valid-search|search-memory-sample|validate|writer-aggregate|old-posting-frontier|scope-ddl-writer|scope-proof) printf '1800\n' ;;
        concurrent-reader|concurrent-writer) printf '%s\n' "$((CONCURRENCY_SECONDS + 180))" ;;
        *) printf '600\n' ;;
    esac
}

timed_compose() {
    local label="$1"
    local seconds="$2"
    shift 2
    timed_host "${label}" "${seconds}" docker compose -f "${COMPOSE_FILE}" "$@"
}

configure_performance_schema_consumers() {
    local label="$1"
    if [[ "${DB_KIND}" == "mariadb" ]]; then
        timed_compose "${label}" 60 exec -T db mariadb -uroot -pwpfts_root_dev_only -e "UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','events_statements_history_long'); UPDATE performance_schema.setup_consumers SET ENABLED='NO' WHERE NAME='events_statements_history'; GRANT SELECT ON performance_schema.* TO 'wpfts'@'%'; FLUSH PRIVILEGES;"
    else
        timed_compose "${label}" 60 exec -T db mysql -uroot -pwpfts_root_dev_only -e "UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','events_statements_history_long'); UPDATE performance_schema.setup_consumers SET ENABLED='NO' WHERE NAME='events_statements_history'; GRANT SELECT ON performance_schema.* TO 'wpfts'@'%'; FLUSH PRIVILEGES;"
    fi
}

set_run_stage "environment-startup"
timed_compose environment-up 600 up -d db wordpress
for _ in $(seq 1 90); do
    if timed_compose wordpress-config-probe 30 exec -T wordpress test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
if ! timed_compose wordpress-config-final-probe 30 exec -T wordpress test -f /var/www/html/wp-config.php; then
    echo "BLOCKED: WordPress did not create wp-config.php." >&2
    exit 1
fi
if ! timed_compose wordpress-timeout-probe 30 exec -T wordpress sh -c 'command -v timeout >/dev/null 2>&1'; then
    echo "BLOCKED: the pinned WordPress image does not provide the external timeout required by failure and infinite-tokenizer proofs." >&2
    exit 1
fi

timed_compose wordpress-core-install 600 run --rm wpcli core install \
    --url=http://wordpress \
    --title='Relational FTS Worst Case' \
    --admin_user=admin \
    --admin_password=wpfts_dev_admin_only \
    --admin_email=admin@example.test \
    --skip-email
timed_compose wordpress-multisite-convert 600 run --rm wpcli core multisite-convert \
    --title='Relational FTS Worst Case Network' \
    --base=/
timed_compose wordpress-site2-create 600 run --rm wpcli --url=http://wordpress site create --slug=site2 --title='FTS migration site two' --email=admin@example.test
timed_compose wordpress-site3-create 600 run --rm wpcli --url=http://wordpress site create --slug=site3 --title='FTS migration site three' --email=admin@example.test
timed_compose wordpress-disposable-marker 30 exec -T wordpress touch /var/www/html/.wp-fts-relational-worst-case
timed_compose baseline-plugin-install 600 run --rm wpcli --url=http://wordpress plugin install /proof/wp-fts-indexer-baseline.zip --force --activate-network
timed_compose wordpress-config-lock 300 run --rm wpcli --url=http://wordpress config set WP_FTS_INDEX_LOCK_TTL 30 --raw
timed_compose wordpress-config-cron 300 run --rm wpcli --url=http://wordpress config set DISABLE_WP_CRON true --raw

configure_performance_schema_consumers performance-schema-enable

capture_compose DB_CONTAINER database-container-id 30 ps -q db
capture_compose WP_CONTAINER wordpress-container-id 30 ps -q wordpress
capture_host DB_LIMITS database-resource-inspect 30 docker inspect --format '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}} {{.HostConfig.MemorySwap}}' "${DB_CONTAINER}"
capture_host WP_LIMITS wordpress-resource-inspect 30 docker inspect --format '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}} {{.HostConfig.MemorySwap}}' "${WP_CONTAINER}"
capture_host DB_MOUNTS database-mount-inspect 30 docker inspect --format '{{json .Mounts}}' "${DB_CONTAINER}"
if [[ "${DB_LIMITS}" != "1000000000 1073741824 1073741824" ]]; then
    echo "BLOCKED: database cgroup limits are not 1 CPU / 1 GiB hard memory+swap: ${DB_LIMITS}" >&2
    exit 1
fi
if [[ "${WP_LIMITS}" != "1000000000 536870912 536870912" ]]; then
    echo "BLOCKED: WordPress cgroup limits are not 1 CPU / 512 MiB hard memory+swap: ${WP_LIMITS}" >&2
    exit 1
fi
php -r '
$mounts=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$valid=false;
foreach($mounts as $mount){
    if(($mount["Destination"]??null)==="/var/lib/mysql"&&($mount["Type"]??null)==="volume"&&!empty($mount["Name"])){$valid=true;break;}
}
if(!$valid){fwrite(STDERR,"BLOCKED: database data directory is not a persistent named volume.\n");exit(1);}
' "${DB_MOUNTS}"
CGROUP_PROBE='set -eu
read_first() {
    for path in "$@"; do
        if [ -r "${path}" ]; then
            cat "${path}"
            return
        fi
    done
    printf unavailable
}
if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
    printf "v2\t%s\t%s\t%s\n" \
        "$(read_first /sys/fs/cgroup/cpu.max)" \
        "$(read_first /sys/fs/cgroup/memory.max)" \
        "$(read_first /sys/fs/cgroup/memory.swap.max)"
else
    quota="$(read_first /sys/fs/cgroup/cpu/cpu.cfs_quota_us /sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us)"
    period="$(read_first /sys/fs/cgroup/cpu/cpu.cfs_period_us /sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us)"
    memory="$(read_first /sys/fs/cgroup/memory/memory.limit_in_bytes)"
    memory_swap="$(read_first /sys/fs/cgroup/memory/memory.memsw.limit_in_bytes)"
    printf "v1\t%s %s\t%s\t%s\n" "${quota}" "${period}" "${memory}" "${memory_swap}"
fi'
CGROUP_MEMORY_PROBE='set -eu
event_value() {
    file="$1"
    wanted="$2"
    while IFS=" " read -r name value remainder; do
        if [ "${name}" = "${wanted}" ]; then
            printf "%s" "${value}"
            return
        fi
    done < "${file}"
    printf unavailable
}
if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
    current=$(cat /sys/fs/cgroup/memory.current 2>/dev/null || printf unavailable)
    peak=$(cat /sys/fs/cgroup/memory.peak 2>/dev/null || printf unavailable)
    limit_events=$(event_value /sys/fs/cgroup/memory.events max)
    oom_events=$(event_value /sys/fs/cgroup/memory.events oom)
    oom_kill_events=$(event_value /sys/fs/cgroup/memory.events oom_kill)
    printf "v2\t%s\t%s\t%s\t%s\t%s\n" "${current}" "${peak}" "${limit_events}" "${oom_events}" "${oom_kill_events}"
else
    current=$(cat /sys/fs/cgroup/memory/memory.usage_in_bytes 2>/dev/null || printf unavailable)
    peak=$(cat /sys/fs/cgroup/memory/memory.max_usage_in_bytes 2>/dev/null || printf unavailable)
    limit_events=$(cat /sys/fs/cgroup/memory/memory.failcnt 2>/dev/null || printf unavailable)
    oom_kill_events=$(event_value /sys/fs/cgroup/memory/memory.oom_control oom_kill)
    # cgroup v1 has no separate cumulative OOM-attempt counter. A zero
    # memory.failcnt is the stricter portable proof that it never hit the limit.
    printf "v1\t%s\t%s\t%s\t%s\t%s\n" "${current}" "${peak}" "${limit_events}" "${limit_events}" "${oom_kill_events}"
fi'
capture_compose DB_EFFECTIVE_CGROUP database-cgroup-probe 30 exec -T db sh -c "${CGROUP_PROBE}"
capture_compose WP_EFFECTIVE_CGROUP wordpress-cgroup-probe 30 exec -T wordpress sh -c "${CGROUP_PROBE}"
: > "${DB_MEMORY_CHECKPOINTS}"
capture_database_memory_checkpoint pre-corpus
DB_PRE_CORPUS_MEMORY="${DB_LAST_MEMORY_CHECKPOINT}"
: > "${WP_MEMORY_CHECKPOINTS}"
capture_wordpress_memory_checkpoint pre-corpus
WP_PRE_CORPUS_MEMORY="${WP_LAST_MEMORY_CHECKPOINT}"
capture_compose WPCLI_PROBE_CONTAINER wpcli-probe-start 60 run --no-deps -d --entrypoint sh wpcli -c 'sleep 300'
if [[ -z "${WPCLI_PROBE_CONTAINER}" ]]; then
    echo "BLOCKED: could not create the persistent WP-CLI resource probe container." >&2
    exit 1
fi
capture_host WPCLI_EFFECTIVE_CGROUP wpcli-cgroup-probe 30 docker exec "${WPCLI_PROBE_CONTAINER}" sh -c "${CGROUP_PROBE}"
capture_host DB_DIGEST database-image-digest 30 docker image inspect "${DB_IMAGE}" --format '{{json .RepoDigests}}'
capture_host DB_IMAGE_ID database-image-id 30 docker image inspect "${DB_IMAGE}" --format '{{.Id}}'
capture_host DB_RUNNING_IMAGE_ID database-running-image-id 30 docker inspect --format '{{.Image}}' "${DB_CONTAINER}"
capture_host WP_DIGEST wordpress-image-digest 30 docker image inspect "${WP_IMAGE}" --format '{{json .RepoDigests}}'
capture_host WP_IMAGE_ID wordpress-image-id 30 docker image inspect "${WP_IMAGE}" --format '{{.Id}}'
capture_host WP_RUNNING_IMAGE_ID wordpress-running-image-id 30 docker inspect --format '{{.Image}}' "${WP_CONTAINER}"
capture_host WPCLI_DIGEST wpcli-image-digest 30 docker image inspect "${WPCLI_RUN_IMAGE}" --format '{{json .RepoDigests}}'
capture_host WPCLI_IMAGE_ID wpcli-image-id 30 docker image inspect "${WPCLI_RUN_IMAGE}" --format '{{.Id}}'
capture_host WPCLI_RUNNING_IMAGE_ID wpcli-running-image-id 30 docker inspect --format '{{.Image}}' "${WPCLI_PROBE_CONTAINER}"
timed_host wpcli-probe-remove 30 docker rm -f "${WPCLI_PROBE_CONTAINER}" >/dev/null
WPCLI_PROBE_CONTAINER=""
php -r '
$gates = [];
$allowDirty = $argv[25] === "1";

$imageEvidence = static function (
    string $label,
    string $expectedReference,
    string $selectedReference,
    string $repoDigestsJson,
    string $imageId,
    string $runningImageId
) use (&$gates, $allowDirty): array {
    $repoDigests = json_decode($repoDigestsJson, true, 512, JSON_THROW_ON_ERROR);
    $repoDigests = is_array($repoDigests) ? array_values($repoDigests) : [];
    $separator = strpos($expectedReference, "@");
    $expectedDigest = $separator === false ? "" : substr($expectedReference, $separator + 1);
    $actualDigests = [];
    foreach ($repoDigests as $repoDigest) {
        if (!is_string($repoDigest)) {
            continue;
        }
        $separator = strpos($repoDigest, "@");
        if ($separator !== false) {
            $actualDigests[] = substr($repoDigest, $separator + 1);
        }
    }
    $actualDigests = array_values(array_unique($actualDigests));
    $containerMatches = $imageId !== "" && hash_equals($imageId, $runningImageId);
    $matchesExpected = $containerMatches
        && $selectedReference === $expectedReference
        && $expectedDigest !== ""
        && in_array($expectedDigest, $actualDigests, true);
    if (!$containerMatches) {
        $gates[] = "{$label} running container image ID differs from the inspected reference";
    }
    if (!$allowDirty && !$matchesExpected) {
        $gates[] = "{$label} image does not resolve to its pinned acceptance digest";
    }

    return [
        "expected_reference" => $expectedReference,
        "selected_reference" => $selectedReference,
        "expected_manifest_digest" => $expectedDigest,
        "actual_manifest_digests" => $actualDigests,
        "actual_repo_digests" => $repoDigests,
        "actual_image_id" => $imageId,
        "running_container_image_id" => $runningImageId,
        "container_matches_inspected_image" => $containerMatches,
        "matches_expected" => $matchesExpected,
    ];
};

$effectiveCgroup = static function (string $label, string $raw, int $expectedMemoryBytes) use (&$gates): array {
    $parts = explode("\t", trim($raw));
    $version = $parts[0] ?? "unavailable";
    $cpuParts = array_values(array_filter(
        explode(" ", trim($parts[1] ?? "")),
        static fn(string $value): bool => $value !== ""
    ));
    $integer = static fn(?string $value): ?int => is_string($value)
        && $value !== ""
        && strspn($value, "0123456789") === strlen($value)
            ? (int) $value
            : null;
    $cpuQuota = $integer($cpuParts[0] ?? null);
    $cpuPeriod = $integer($cpuParts[1] ?? null);
    $memoryMax = $integer($parts[2] ?? null);
    $rawSwapMax = $integer($parts[3] ?? null);
    $swapMax = $version === "v1" && $rawSwapMax !== null && $memoryMax !== null
        ? $rawSwapMax - $memoryMax
        : $rawSwapMax;
    $cpuMatches = $cpuQuota !== null && $cpuQuota > 0 && $cpuPeriod !== null && $cpuQuota === $cpuPeriod;
    $memoryMatches = $memoryMax === $expectedMemoryBytes;
    $swapMatches = $swapMax === 0;
    if (!$cpuMatches || !$memoryMatches || !$swapMatches || !in_array($version, ["v1", "v2"], true)) {
        $gates[] = "{$label} effective cgroup is not 1 CPU / {$expectedMemoryBytes} bytes / zero swap";
    }

    return [
        "version" => $version,
        "cpu" => [
            "quota_us" => $cpuQuota,
            "period_us" => $cpuPeriod,
            "effective_cpus" => $cpuQuota !== null && $cpuPeriod !== null && $cpuPeriod > 0
                ? $cpuQuota / $cpuPeriod
                : null,
            "matches_expected" => $cpuMatches,
        ],
        "memory" => [
            "max_bytes" => $memoryMax,
            "matches_expected" => $memoryMatches,
        ],
        "swap" => [
            "raw_max_bytes" => $rawSwapMax,
            "effective_max_bytes" => $swapMax,
            "matches_expected" => $swapMatches,
        ],
        "raw_sha256" => hash("sha256", $raw),
        "matches_expected" => $cpuMatches && $memoryMatches && $swapMatches,
    ];
};

$memoryCheckpoint = static function (string $label, string $raw): array {
    $parts = explode("\t", trim($raw));
    $version = $parts[0] ?? "unavailable";
    $unsigned = static fn(?string $value): ?int => is_string($value)
        && $value !== ""
        && strspn($value, "0123456789") === strlen($value)
            ? (int) $value
            : null;
    $sources = $version === "v2"
        ? ["usage" => "memory.current", "peak" => "memory.peak", "limit_events" => "memory.events:max", "oom_events" => "memory.events:oom", "oom_kill_events" => "memory.events:oom_kill"]
        : ["usage" => "memory.usage_in_bytes", "peak" => "memory.max_usage_in_bytes", "limit_events" => "memory.failcnt", "oom_events" => "memory.failcnt (conservative)", "oom_kill_events" => "memory.oom_control:oom_kill"];

    $checkpoint = [
        "checkpoint" => $label,
        "cgroup_version" => $version,
        "usage_bytes" => $unsigned($parts[1] ?? null),
        "peak_bytes" => $unsigned($parts[2] ?? null),
        "limit_events" => $unsigned($parts[3] ?? null),
        "oom_events" => $unsigned($parts[4] ?? null),
        "oom_kill_events" => $unsigned($parts[5] ?? null),
        "sources" => $sources,
        "raw_sha256" => hash("sha256", $raw),
    ];
    if (isset($parts[6])) {
        $checkpoint["container_id"] = $parts[6];
    }
    if (isset($parts[7])) {
        $checkpoint["container_started_at"] = $parts[7];
    }
    if (isset($parts[8])) {
        $checkpoint["container_host_pid"] = $unsigned($parts[8]);
    }
    if (isset($parts[9])) {
        $checkpoint["container_restart_count"] = $unsigned($parts[9]);
    }
    return $checkpoint;
};

$databaseImage = $imageEvidence("database", $argv[5], $argv[6], $argv[3], $argv[4], $argv[28]);
$wordpressImage = $imageEvidence("WordPress", $argv[11], $argv[12], $argv[9], $argv[10], $argv[29]);
$wpcliImage = $imageEvidence("WP-CLI", $argv[16], $argv[17], $argv[14], $argv[15], $argv[30]);
$databaseCgroup = $effectiveCgroup("database", $argv[7], 1073741824);
$wordpressCgroup = $effectiveCgroup("WordPress", $argv[13], 536870912);
$wpcliCgroup = $effectiveCgroup("WP-CLI", $argv[18], 536870912);
$databasePreCorpusMemory = $memoryCheckpoint("pre-corpus", $argv[36]);
$databasePreCorpusPeakLimit = (int) $argv[37];
$databasePreCorpusValid = $databasePreCorpusMemory["cgroup_version"] === ($databaseCgroup["version"] ?? null)
    && is_int($databasePreCorpusMemory["usage_bytes"])
    && $databasePreCorpusMemory["usage_bytes"] >= 0
    && is_int($databasePreCorpusMemory["peak_bytes"])
    && $databasePreCorpusMemory["peak_bytes"] > 0
    && $databasePreCorpusMemory["peak_bytes"] >= $databasePreCorpusMemory["usage_bytes"]
    && $databasePreCorpusPeakLimit === 805306368
    && $databasePreCorpusMemory["peak_bytes"] <= $databasePreCorpusPeakLimit
    && is_int($databasePreCorpusMemory["limit_events"])
    && $databasePreCorpusMemory["limit_events"] >= 0
    && $databasePreCorpusMemory["oom_events"] === 0
    && $databasePreCorpusMemory["oom_kill_events"] === 0;
if (!$databasePreCorpusValid) {
    $gates[] = "database pre-corpus cgroup memory must retain at least 256 MiB headroom without OOM";
}
$wordpressPreCorpusMemory = $memoryCheckpoint("pre-corpus", $argv[38]);
$wordpressContainerLifecycle = [
    "started_at" => $wordpressPreCorpusMemory["container_started_at"] ?? null,
    "host_pid" => $wordpressPreCorpusMemory["container_host_pid"] ?? null,
    "restart_count" => $wordpressPreCorpusMemory["container_restart_count"] ?? null,
];
$wordpressPreCorpusValid = $wordpressPreCorpusMemory["cgroup_version"] === ($wordpressCgroup["version"] ?? null)
    && is_int($wordpressPreCorpusMemory["usage_bytes"])
    && $wordpressPreCorpusMemory["usage_bytes"] >= 0
    && is_int($wordpressPreCorpusMemory["peak_bytes"])
    && $wordpressPreCorpusMemory["peak_bytes"] > 0
    && $wordpressPreCorpusMemory["peak_bytes"] >= $wordpressPreCorpusMemory["usage_bytes"]
    && $wordpressPreCorpusMemory["peak_bytes"] <= 536870912
    && ($wordpressPreCorpusMemory["container_id"] ?? null) === $argv[39]
    && is_string($wordpressContainerLifecycle["started_at"])
    && $wordpressContainerLifecycle["started_at"] !== ""
    && is_int($wordpressContainerLifecycle["host_pid"])
    && $wordpressContainerLifecycle["host_pid"] > 0
    && is_int($wordpressContainerLifecycle["restart_count"])
    && $wordpressContainerLifecycle["restart_count"] >= 0
    && $wordpressPreCorpusMemory["limit_events"] === 0
    && $wordpressPreCorpusMemory["oom_events"] === 0
    && $wordpressPreCorpusMemory["oom_kill_events"] === 0;
if (!$wordpressPreCorpusValid) {
    $gates[] = "WordPress pre-corpus cgroup memory must stay within 512 MiB without a limit or OOM event";
}
$databaseMemoryCheckpointLabels = ["pre-corpus"];
foreach (["common_or", "max_valid_or_prefix", "rare_anchor_and", "prefix_fanout"] as $case) {
    for ($sample = 0; $sample < 10; $sample++) {
        $databaseMemoryCheckpointLabels[] = "pre-cold-restart-{$case}-{$sample}";
    }
}
$databaseMemoryCheckpointLabels[] = "final-workload";
$packageReproducibility = json_decode((string) file_get_contents($argv[31]), true, 512, JSON_THROW_ON_ERROR);
$data = [
 "schema" => "relational-fts-resources-v1",
 "status" => $gates === [] ? "PASS" : "FAIL",
 "verification" => [
     "schema" => "relational-fts-resource-verification-v1",
     "acceptance_lane" => !$allowDirty,
     "gate_failures" => $gates,
 ],
 "database" => [
     "limits" => $argv[1],
     "mounts" => json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR),
     "image_digests" => $databaseImage["actual_repo_digests"],
     "image" => $databaseImage,
     "effective_cgroup" => $databaseCgroup,
     "memory" => [
         "schema" => "relational-fts-database-cgroup-memory-v1",
         "limit_bytes" => 1073741824,
         "pre_corpus_peak_limit_bytes" => $databasePreCorpusPeakLimit,
         "pre_corpus" => $databasePreCorpusMemory,
         "expected_checkpoint_labels" => $databaseMemoryCheckpointLabels,
         "checkpoints" => [$databasePreCorpusMemory],
         "checkpoint_count" => 1,
         "final_checkpoint" => null,
         "whole_run_peak_bytes" => $databasePreCorpusMemory["peak_bytes"],
         "whole_run_headroom_bytes" => is_int($databasePreCorpusMemory["peak_bytes"])
             ? 1073741824 - $databasePreCorpusMemory["peak_bytes"]
             : null,
         "max_limit_events" => $databasePreCorpusMemory["limit_events"],
         "oom_events" => $databasePreCorpusMemory["oom_events"],
         "oom_kill_events" => $databasePreCorpusMemory["oom_kill_events"],
         "counter_aggregation" => "maximum across restart-delimited cumulative counters",
         "complete" => false,
     ],
 ],
 "wordpress" => [
     "limits" => $argv[8],
     "image_digests" => $wordpressImage["actual_repo_digests"],
     "image" => $wordpressImage,
     "container_id" => $argv[39],
     "container_lifecycle" => $wordpressContainerLifecycle,
     "effective_cgroup" => $wordpressCgroup,
     "memory" => [
         "schema" => "relational-fts-wordpress-cgroup-memory-v2",
         "limit_bytes" => 536870912,
         "pre_corpus" => $wordpressPreCorpusMemory,
         "expected_checkpoint_labels" => ["pre-corpus", "final-workload"],
         "checkpoints" => [$wordpressPreCorpusMemory],
         "checkpoint_count" => 1,
         "final_checkpoint" => null,
         "whole_run_peak_bytes" => $wordpressPreCorpusMemory["peak_bytes"],
         "whole_run_headroom_bytes" => is_int($wordpressPreCorpusMemory["peak_bytes"])
             ? 536870912 - $wordpressPreCorpusMemory["peak_bytes"]
             : null,
         "max_limit_events" => $wordpressPreCorpusMemory["limit_events"],
         "oom_events" => $wordpressPreCorpusMemory["oom_events"],
         "oom_kill_events" => $wordpressPreCorpusMemory["oom_kill_events"],
         "counter_aggregation" => "maximum across cumulative checkpoints in one unrestarted container",
         "complete" => false,
     ],
 ],
 "wpcli" => [
     "image_digests" => $wpcliImage["actual_repo_digests"],
     "image" => $wpcliImage,
     "effective_cgroup" => $wpcliCgroup,
 ],
 "profile" => $argv[19], "documents" => (int) $argv[20], "engine" => $argv[21],
 "harness_sha256" => $argv[22],
 "mutation_proof_sha256" => $argv[23],
 "isolated_boundaries_sha256" => $argv[24],
 "old_posting_frontier_sha256" => $argv[27],
 "package_reproducibility" => $packageReproducibility,
 "io_profile" => [
     "mode" => "host-provided-unthrottled",
     "latency_scope" => "this recorded CI/Docker runner only",
     "portable_gates" => ["statement_count", "statement_bytes", "rows_examined", "rows_sent", "memory"],
 ],
 "host_environment" => [
     "runner_os" => $argv[32],
     "runner_arch" => $argv[33],
     "image_os" => $argv[34],
     "image_version" => $argv[35],
 ]
];
file_put_contents($argv[26], json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
if ($gates !== []) {
    foreach ($gates as $gate) {
        fwrite(STDERR, "BLOCKED: {$gate}.\n");
    }
    exit(1);
}
' "${DB_LIMITS}" "${DB_MOUNTS}" "${DB_DIGEST}" "${DB_IMAGE_ID}" "${EXPECTED_DB_IMAGE}" "${DB_IMAGE}" "${DB_EFFECTIVE_CGROUP}" \
  "${WP_LIMITS}" "${WP_DIGEST}" "${WP_IMAGE_ID}" "${WORDPRESS_IMAGE}" "${WP_IMAGE}" "${WP_EFFECTIVE_CGROUP}" \
  "${WPCLI_DIGEST}" "${WPCLI_IMAGE_ID}" "${WPCLI_IMAGE}" "${WPCLI_RUN_IMAGE}" "${WPCLI_EFFECTIVE_CGROUP}" \
  "${PROFILE}" "${DOCUMENTS}" "${ENGINE}" "${TEST_SCRIPT_SHA256}" "${MUTATION_PROOF_SHA256}" "${ISOLATED_BOUNDARIES_SHA256}" "${ALLOW_DIRTY}" "${EVIDENCE_DIR}/resources.json" \
  "${OLD_POSTING_FRONTIER_SHA256}" "${DB_RUNNING_IMAGE_ID}" "${WP_RUNNING_IMAGE_ID}" "${WPCLI_RUNNING_IMAGE_ID}" \
  "${EVIDENCE_DIR}/package-reproducibility.json" "${RUNNER_OS:-local}" "${RUNNER_ARCH:-unknown}" "${ImageOS:-unknown}" "${ImageVersion:-unknown}" \
  "${DB_PRE_CORPUS_MEMORY}" "${DB_PRE_CORPUS_PEAK_LIMIT_BYTES}" "${WP_PRE_CORPUS_MEMORY}" "${WP_CONTAINER}"

ACTIVE_SOURCE_SHA="${BASELINE_SHA}"
ACTIVE_ZIP_SHA256="${BASELINE_ZIP_SHA256}"
ACTIVE_SOURCE_DIRTY=0
ACTIVE_ALLOW_DIRTY=0

env_options() {
    local phase="$1"
    printf '%s\n' \
      -e "WP_FTS_WC_PHASE=${phase}" \
      -e WP_FTS_WP_PATH=/var/www/html \
      -e WP_FTS_WC_ALLOW_DISPOSABLE=1 \
      -e "WP_FTS_SOURCE_SHA=${ACTIVE_SOURCE_SHA}" \
      -e "WP_FTS_ZIP_SHA256=${ACTIVE_ZIP_SHA256}" \
      -e "WP_FTS_HARNESS_SHA256=${TEST_SCRIPT_SHA256}" \
      -e "WP_FTS_SOURCE_DIRTY=${ACTIVE_SOURCE_DIRTY}" \
      -e "WP_FTS_WC_ALLOW_DIRTY=${ACTIVE_ALLOW_DIRTY}" \
      -e "WP_FTS_WC_PROFILE=${PROFILE}" \
      -e "WP_FTS_WC_ENGINE=${ENGINE}" \
      -e "WP_FTS_WC_LANE_ID=${LANE_ID}" \
      -e WP_FTS_WC_EVIDENCE_DIR=/evidence \
      -e "WP_FTS_WC_CONCURRENCY_SECONDS=${CONCURRENCY_SECONDS}"
}

run_php_phase() {
    local phase="$1"
    shift
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options "${phase}")
    timed_compose "php-${phase}" "$(phase_timeout_seconds "${phase}")" exec -T "${options[@]}" "$@" wordpress php /proof/relational-fts-worst-case.php
}

run_wpcli_php_phase() {
    local phase="$1"
    shift
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options "${phase}")
    timed_compose "wpcli-php-${phase}" "$(phase_timeout_seconds "${phase}")" run --rm "${options[@]}" "$@" wpcli --url=http://wordpress --user=admin eval-file /proof/relational-fts-worst-case.php
}

record_installed_tree_binding() {
    local checkpoint="$1"
    local artifact="/evidence/installed-tree-${checkpoint}.json"
    timed_compose "installed-tree-${checkpoint}" 600 exec -T \
      -e "WP_FTS_WC_TREE_CHECKPOINT=${checkpoint}" \
      -e "WP_FTS_WC_TREE_SOURCE_SHA=${SOURCE_SHA}" \
      -e "WP_FTS_WC_TREE_ZIP_SHA256=${ZIP_SHA256}" \
      wordpress php -r '
$zipPath="/proof/wp-fts-indexer.zip";
$root="/var/www/html/wp-content/plugins/indexer";
$checkpoint=getenv("WP_FTS_WC_TREE_CHECKPOINT");
$zip=new ZipArchive();
$opened=$zip->open($zipPath);
if($opened!==true){throw new RuntimeException("Could not open mounted release ZIP: ".$opened);}
$expected=[];
try{
 for($index=0;$index<$zip->numFiles;$index++){
  $name=$zip->getNameIndex($index);
  if(!is_string($name)||!str_starts_with($name,"indexer/")||str_ends_with($name,"/")){continue;}
  $relative=substr($name,strlen("indexer/"));
  if($relative===""||str_contains($relative,"\\")||in_array("..",explode("/",$relative),true)){throw new RuntimeException("Unsafe ZIP path: ".$name);}
  $stream=$zip->getStream($name);
  if(!is_resource($stream)){throw new RuntimeException("Could not stream ZIP entry: ".$name);}
  $hash=hash_init("sha256");$bytes=0;
  while(!feof($stream)){$chunk=fread($stream,1048576);if($chunk===false){fclose($stream);throw new RuntimeException("Could not read ZIP entry: ".$name);}if($chunk!==""){$bytes+=strlen($chunk);hash_update($hash,$chunk);}}
  fclose($stream);
  $expected[]=["path"=>$relative,"bytes"=>$bytes,"sha256"=>hash_final($hash)];
 }
}finally{$zip->close();}
usort($expected,static fn(array $a,array $b):int=>strcmp($a["path"],$b["path"]));
$actual=[];$symlinks=[];
if(!is_dir($root)){throw new RuntimeException("Installed plugin tree is absent.");}
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
 $path=$file->getPathname();$relative=str_replace("\\","/",substr($path,strlen($root)+1));
 if(is_link($path)){$symlinks[]=$relative;continue;}
 if(!$file->isFile()){continue;}
 $hash=hash_file("sha256",$path);$bytes=filesize($path);
 if(!is_string($hash)||!is_int($bytes)){throw new RuntimeException("Could not hash installed file: ".$relative);}
 $actual[]=["path"=>$relative,"bytes"=>$bytes,"sha256"=>$hash];
}
usort($actual,static fn(array $a,array $b):int=>strcmp($a["path"],$b["path"]));
$expectedNames=array_column($expected,"path");$actualNames=array_column($actual,"path");
$gates=[
 ["id"=>"installed_tree_exact_names","expected"=>$expectedNames,"actual"=>$actualNames,"passed"=>$expectedNames===$actualNames],
 ["id"=>"installed_tree_exact_content","expected"=>hash("sha256",json_encode($expected,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),"actual"=>hash("sha256",json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),"passed"=>$expected===$actual],
 ["id"=>"installed_tree_no_symlinks","expected"=>[],"actual"=>$symlinks,"passed"=>$symlinks===[]],
 ["id"=>"installed_tree_zip_digest","expected"=>getenv("WP_FTS_WC_TREE_ZIP_SHA256"),"actual"=>hash_file("sha256",$zipPath),"passed"=>hash_equals((string)getenv("WP_FTS_WC_TREE_ZIP_SHA256"),(string)hash_file("sha256",$zipPath))],
];
$passed=count(array_filter($gates,static fn(array $gate):bool=>$gate["passed"]!==true))===0;
$data=["schema"=>"relational-fts-installed-tree-v1","status"=>$passed?"PASS":"FAIL","checkpoint"=>$checkpoint,"source_sha"=>getenv("WP_FTS_WC_TREE_SOURCE_SHA"),"zip_sha256"=>getenv("WP_FTS_WC_TREE_ZIP_SHA256"),"expected_manifest"=>$expected,"actual_manifest"=>$actual,"expected_manifest_sha256"=>hash("sha256",json_encode($expected,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),"actual_manifest_sha256"=>hash("sha256",json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),"gates"=>$gates];
$target=$argv[1];$temporary=$target.".tmp.".getmypid();$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$target)){@unlink($temporary);throw new RuntimeException("Could not atomically write installed-tree evidence.");}
if(!$passed){fwrite(STDERR,"Installed plugin tree differs from the mounted release ZIP.\n");exit(1);}
' "${artifact}"
}

open_concurrency_window() {
    local expected_ready=10
    local attempts=0
    while (( attempts < 600 )); do
        local ready_count
        ready_count="$(find "${EVIDENCE_DIR}" -maxdepth 1 -type f -name 'concurrency-ready-*.json' | wc -l | tr -d ' ')"
        if [[ "${ready_count}" == "${expected_ready}" ]]; then
            break
        fi
        sleep 0.2
        attempts=$((attempts + 1))
    done
    local ready_count
    ready_count="$(find "${EVIDENCE_DIR}" -maxdepth 1 -type f -name 'concurrency-ready-*.json' | wc -l | tr -d ' ')"
    if [[ "${ready_count}" != "${expected_ready}" ]]; then
        echo "FAIL: only ${ready_count}/${expected_ready} concurrency workers reached the shared barrier." >&2
        return 1
    fi
    timed_compose concurrency-window-open 60 exec -T \
      -e "WP_FTS_WC_CONCURRENCY_SECONDS=${CONCURRENCY_SECONDS}" \
      wordpress php -r '
$baseline=json_decode((string)file_get_contents("/evidence/concurrency-baseline.json"),true,512,JSON_THROW_ON_ERROR);
$runId=$baseline["concurrency_run_id"]??null;
if(!is_string($runId)||strlen($runId)!==32||strspn($runId,"0123456789abcdefABCDEF")!==32){fwrite(STDERR,"Invalid concurrency run identity.\n");exit(1);}
$minimum=(int)getenv("WP_FTS_WC_CONCURRENCY_SECONDS");
$windowSeconds=$minimum+2;
$start=hrtime(true)+2000000000;
$data=["schema"=>"relational-fts-concurrency-window-v1","run_id"=>$runId,"start_monotonic_ns"=>$start,"deadline_monotonic_ns"=>$start+$windowSeconds*1000000000,"minimum_overlap_seconds"=>$minimum,"window_seconds"=>$windowSeconds];
$target="/evidence/concurrency-window.json";$temporary=$target.".tmp.".getmypid();$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$target)){@unlink($temporary);fwrite(STDERR,"Could not open concurrency window.\n");exit(1);}
'
}

run_isolated_boundaries() {
    local artifact="${EVIDENCE_DIR}/relational-fts-isolated-boundaries.json"
    local log="${EVIDENCE_DIR}/relational-fts-isolated-boundaries.log"
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options isolated-boundaries)

    if ! timed_compose isolated-timeout-probe 30 exec -T wordpress sh -c 'command -v timeout >/dev/null 2>&1'; then
        echo "BLOCKED: the WordPress image does not provide the external timeout required by the infinite-tokenizer proof." >&2
        return 1
    fi

    rm -f "${artifact}" "${log}"
    timed_compose isolated-boundaries 240 exec -T "${options[@]}" \
      -e "WP_FTS_HARNESS_SHA256=${ISOLATED_BOUNDARIES_SHA256}" \
      wordpress timeout -s KILL 180 php -d memory_limit=128M \
      /proof/relational-fts-isolated-boundaries.php > "${log}"

    php -r '
$artifact=file_get_contents($argv[1]);
$stdout=file_get_contents($argv[2]);
if($artifact===false||$stdout===false||!hash_equals(hash("sha256",$artifact),hash("sha256",$stdout))){fwrite(STDERR,"FAIL: isolated-boundary stdout and artifact differ.\n");exit(1);}
$e=json_decode($artifact,true,512,JSON_THROW_ON_ERROR);
$gates=is_array($e["gates"]??null)?array_values($e["gates"]):[];
$ids=[];
foreach($gates as $gate){
    if(!is_array($gate)||!is_string($gate["id"]??null)||($gate["id"]??"")===""||($gate["passed"]??null)!==true){fwrite(STDERR,"FAIL: isolated-boundary evidence has a malformed or failed gate.\n");exit(1);}
    $ids[]=$gate["id"];
}

if(($e["schema"]??null)!=="relational-fts-isolated-boundaries-v1"||($e["status"]??null)!=="PASS"||count($gates)!==62||count(array_unique($ids))!==62){fwrite(STDERR,"FAIL: isolated-boundary evidence is incomplete.\n");exit(1);}
' "${artifact}" "${log}"
}

run_old_posting_frontier() {
    local artifact="${EVIDENCE_DIR}/old-posting-frontier.json"
    rm -f "${artifact}"
    timed_compose old-posting-frontier 360 exec -T \
      -e WP_FTS_FRONTIER_HOST=db \
      -e WP_FTS_FRONTIER_PORT=3306 \
      -e WP_FTS_FRONTIER_USER=wpfts \
      -e WP_FTS_FRONTIER_PASSWORD=wpfts_dev_only \
      -e WP_FTS_FRONTIER_DATABASE=wpfts \
      -e WP_FTS_FRONTIER_PLUGIN_PATH=/var/www/html/wp-content/plugins/indexer \
      -e "WP_FTS_FRONTIER_HARNESS_SHA256=${OLD_POSTING_FRONTIER_SHA256}" \
      -e "WP_FTS_FRONTIER_SOURCE_SHA=${SOURCE_SHA}" \
      -e "WP_FTS_FRONTIER_ZIP_SHA256=${ZIP_SHA256}" \
      -e "WP_FTS_FRONTIER_ENGINE=${ENGINE}" \
      wordpress timeout -s KILL 300 php -d memory_limit=128M \
      /proof/old-posting-frontier.php > "${artifact}"
}

wait_for_database() {
    local attempt
    for attempt in $(seq 1 120); do
        if [[ "${DB_KIND}" == "mariadb" ]]; then
            if timed_compose database-ready-probe 15 exec -T db mariadb-admin ping -h localhost -uroot -pwpfts_root_dev_only >/dev/null 2>&1; then
                return 0
            fi
        elif timed_compose database-ready-probe 15 exec -T db mysqladmin ping -h localhost -uroot -pwpfts_root_dev_only >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "FAIL: database did not recover after a required cold restart." >&2
    return 1
}

start_migration_disk_monitor() {
    rm -f "${EVIDENCE_DIR}/migration-disk-monitor.stop" "${EVIDENCE_DIR}/migration-disk-samples.tsv" "${EVIDENCE_DIR}/migration-disk-window.tsv"
    timed_compose migration-disk-monitor 15000 exec -T db sh -c '
monotonic() { cut -d " " -f 1 /proc/uptime; }
sample() {
    sample_started=$(monotonic)
    total_kib=$(du -sk /var/lib/mysql | awk "{print \$1}")
    fts_kib=0
    for path in /var/lib/mysql/wpfts/wp_fts_*; do
        [ -e "$path" ] || continue
        path_kib=$(du -sk "$path" | awk "{print \$1}")
        fts_kib=$((fts_kib + path_kib))
    done
    sample_finished=$(monotonic)
    printf "%s\t%s\t%s\t%s\n" "$sample_started" "$sample_finished" "$((total_kib * 1024))" "$((fts_kib * 1024))" >> /evidence/migration-disk-samples.tsv
}
printf "start\t%s\n" "$(monotonic)" > /evidence/migration-disk-window.tsv
while [ ! -f /evidence/migration-disk-monitor.stop ]; do
    sample
    sleep 0.25
done
sample
printf "end\t%s\n" "$(monotonic)" >> /evidence/migration-disk-window.tsv
' >/dev/null 2>&1 &
    MIGRATION_DISK_MONITOR_PID=$!
    for _ in $(seq 1 40); do
        if [[ -s "${EVIDENCE_DIR}/migration-disk-samples.tsv" ]]; then
            return 0
        fi
        if ! kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
            echo "FAIL: physical migration disk monitor exited before its first sample." >&2
            return 1
        fi
        sleep 0.25
    done
    echo "FAIL: physical migration disk monitor produced no sample." >&2
    return 1
}

stop_migration_disk_monitor() {
    touch "${EVIDENCE_DIR}/migration-disk-monitor.stop"
    for _ in $(seq 1 40); do
        if ! kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
            break
        fi
        sleep 0.25
    done
    if kill -0 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null; then
        kill -9 "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null || true
        wait "${MIGRATION_DISK_MONITOR_PID}" 2>/dev/null || true
        MIGRATION_DISK_MONITOR_PID=""
        echo "FAIL: physical migration disk monitor did not stop within 10 seconds." >&2
        return 1
    fi
    if ! wait "${MIGRATION_DISK_MONITOR_PID}"; then
        MIGRATION_DISK_MONITOR_PID=""
        echo "FAIL: physical migration disk monitor exited unsuccessfully." >&2
        return 1
    fi
    MIGRATION_DISK_MONITOR_PID=""
    php -r '
$lines=file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$samples=[];
foreach($lines as $line){
    $parts=explode("\t",$line);
    if(count($parts)!==4||!is_numeric($parts[0])||!is_numeric($parts[1])||$parts[2]===""||strspn($parts[2],"0123456789")!==strlen($parts[2])||$parts[3]===""||strspn($parts[3],"0123456789")!==strlen($parts[3])){fwrite(STDERR,"Malformed migration disk sample.\n");exit(1);}
    $samples[]=["started_monotonic_seconds"=>(float)$parts[0],"finished_monotonic_seconds"=>(float)$parts[1],"volume_bytes"=>(int)$parts[2],"fts_bytes"=>(int)$parts[3]];
}
if($samples===[]){fwrite(STDERR,"No migration disk samples.\n");exit(1);}
$windowLines=file($argv[2],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$window=[];
foreach($windowLines as $line){$parts=explode("\t",$line);if(count($parts)!==2||!in_array($parts[0],["start","end"],true)||!is_numeric($parts[1])){fwrite(STDERR,"Malformed migration disk window.\n");exit(1);}$window[$parts[0]]=(float)$parts[1];}
if(!isset($window["start"],$window["end"])||$window["end"]<$window["start"]){fwrite(STDERR,"Incomplete migration disk window.\n");exit(1);}
$volume=array_column($samples,"volume_bytes");$fts=array_column($samples,"fts_bytes");
$first=$samples[0];$final=$samples[array_key_last($samples)];$peakVolume=max($volume);$peakFts=max($fts);
$volumeDenominator=max(1,$first["volume_bytes"],$final["volume_bytes"]);
$ftsDenominator=max(1,$first["fts_bytes"],$final["fts_bytes"]);
$volumeRatio=$peakVolume/$volumeDenominator;$ftsRatio=$peakFts/$ftsDenominator;
$maxGap=0.0;$sampleDurations=[];
foreach($samples as $index=>$sample){
 if($sample["finished_monotonic_seconds"]<$sample["started_monotonic_seconds"]){fwrite(STDERR,"Migration disk sample clock moved backwards.\n");exit(1);}
 $sampleDurations[]=$sample["finished_monotonic_seconds"]-$sample["started_monotonic_seconds"];
 if($index>0){$maxGap=max($maxGap,$sample["finished_monotonic_seconds"]-$samples[$index-1]["finished_monotonic_seconds"]);}
}
$maxSampleDuration=max($sampleDurations);
$leadingGap=max(0.0,$first["finished_monotonic_seconds"]-$window["start"]);
$trailingGap=max(0.0,$window["end"]-$final["finished_monotonic_seconds"]);
$coverage=max(0.0,$final["finished_monotonic_seconds"]-$first["finished_monotonic_seconds"]);
$windowSeconds=$window["end"]-$window["start"];
$gates=[
 ["id"=>"migration_physical_disk_sample_count","expected"=>">= 20","actual"=>count($samples),"passed"=>count($samples)>=20],
 ["id"=>"migration_physical_disk_baseline_fts_bytes","expected"=>"> 0","actual"=>$first["fts_bytes"],"passed"=>$first["fts_bytes"]>0],
 ["id"=>"migration_physical_disk_final_fts_bytes","expected"=>"> 0","actual"=>$final["fts_bytes"],"passed"=>$final["fts_bytes"]>0],
 ["id"=>"migration_physical_volume_peak_ratio","expected"=>"<= 2.2","actual"=>$volumeRatio,"passed"=>$volumeRatio<=2.2],
 ["id"=>"migration_physical_fts_peak_ratio","expected"=>"<= 2.2","actual"=>$ftsRatio,"passed"=>$ftsRatio<=2.2],
 ["id"=>"migration_physical_monotonic_coverage","expected"=>"entire marked window","actual"=>["window_seconds"=>$windowSeconds,"coverage_seconds"=>$coverage,"leading_gap_seconds"=>$leadingGap,"trailing_gap_seconds"=>$trailingGap],"passed"=>$windowSeconds>0.0&&$leadingGap<=0.75&&$trailingGap<=0.75&&$coverage+1.5>=$windowSeconds],
 ["id"=>"migration_physical_max_observed_gap","expected"=>"<= 0.75","actual"=>$maxGap,"passed"=>$maxGap<=0.75],
 ["id"=>"migration_physical_max_sample_duration","expected"=>"<= 0.75","actual"=>$maxSampleDuration,"passed"=>$maxSampleDuration<=0.75],
];
$passed=count(array_filter($gates,static fn(array $gate):bool=>!$gate["passed"]))===0;
$data=[
 "schema"=>"relational-fts-migration-physical-disk-v2","status"=>$passed?"PASS":"FAIL","source_sha"=>$argv[3],"profile"=>$argv[4],
 "target_interval_seconds"=>0.25,"sample_count"=>count($samples),"samples_sha256"=>hash_file("sha256",$argv[1]),"window_sha256"=>hash_file("sha256",$argv[2]),
 "monotonic_window"=>$window,"window_seconds"=>$windowSeconds,"coverage_seconds"=>$coverage,"leading_gap_seconds"=>$leadingGap,"trailing_gap_seconds"=>$trailingGap,"max_observed_gap_seconds"=>$maxGap,"max_sample_duration_seconds"=>$maxSampleDuration,
 "first"=>$first,"final"=>$final,"peak_volume_bytes"=>$peakVolume,"peak_fts_bytes"=>$peakFts,
 "peak_volume_ratio"=>$volumeRatio,"peak_fts_ratio"=>$ftsRatio,"gates"=>$gates,
];
file_put_contents($argv[5],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
if(!$passed){fwrite(STDERR,"Physical migration disk evidence failed.\n");exit(1);}
' "${EVIDENCE_DIR}/migration-disk-samples.tsv" "${EVIDENCE_DIR}/migration-disk-window.tsv" "${SOURCE_SHA}" "${PROFILE}" "${EVIDENCE_DIR}/migration-disk.json"
}

kill_uncommitted_transaction() {
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options transaction-crash)
    rm -f "${EVIDENCE_DIR}/transaction-crash-ready.json"
    timed_compose transaction-crash 180 exec -T "${options[@]}" wordpress sh -c '
        php /proof/relational-fts-worst-case.php >/evidence/transaction-crash-process.log 2>&1 &
        child=$!
        ready=0
        i=0
        while [ "$i" -lt 120 ]; do
            if [ -f /evidence/transaction-crash-ready.json ]; then ready=1; break; fi
            if ! kill -0 "$child" 2>/dev/null; then wait "$child"; exit $?; fi
            i=$((i+1)); sleep 1
        done
        if [ "$ready" -ne 1 ]; then kill -9 "$child" 2>/dev/null || true; wait "$child" 2>/dev/null || true; exit 1; fi
        kill -9 "$child"
        wait "$child" 2>/dev/null
        status=$?
        [ "$status" -eq 137 ]
    '
}

kill_migration_phase() {
    local target="$1"
    local ready="${EVIDENCE_DIR}/migration-phase-${target}.json"
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options migration-failpoint)
    rm -f "${ready}" "${EVIDENCE_DIR}/migration-worker-${target}.ndjson"
    timed_compose "migration-failpoint-${target}" "${MIGRATION_FAILPOINT_OUTER_TIMEOUT_SECONDS}" exec -T "${options[@]}" \
      -e "WP_FTS_WC_MIGRATION_TARGET=${target}" \
      -e "WP_FTS_WC_MIGRATION_DEADLINE_SECONDS=${MIGRATION_FAILPOINT_DEADLINE_SECONDS}" \
      -e "WP_FTS_WC_MIGRATION_BATCH_BUDGET_SECONDS=${MIGRATION_FAILPOINT_BATCH_BUDGET_SECONDS}" \
      wordpress sh -c '
        target="$1"
        ready_timeout="$2"
        php /proof/relational-fts-worst-case.php >"/evidence/migration-${target}-process.log" 2>&1 &
        child=$!
        ready=0
        i=0
        while [ "$i" -lt "$ready_timeout" ]; do
            if [ -f "/evidence/migration-phase-${target}.json" ]; then ready=1; break; fi
            if ! kill -0 "$child" 2>/dev/null; then wait "$child"; exit $?; fi
            i=$((i+1)); sleep 1
        done
        if [ "$ready" -ne 1 ]; then kill -9 "$child" 2>/dev/null || true; wait "$child" 2>/dev/null || true; exit 1; fi
        kill -9 "$child"
        wait "$child" 2>/dev/null
        status=$?
        [ "$status" -eq 137 ]
    ' sh "${target}" "${MIGRATION_FAILPOINT_READY_TIMEOUT_SECONDS}"
}

run_migration_post_kill_probe() {
    local target="$1"
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options migration-post-kill-probe)
    rm -f "${EVIDENCE_DIR}/migration-post-kill-${target}.json"
    timed_compose "migration-post-kill-${target}" 180 exec -T "${options[@]}" \
        -e "WP_FTS_WC_MIGRATION_TARGET=${target}" \
        wordpress timeout -s KILL 120 php /proof/relational-fts-worst-case.php
}

run_migration_snapshot_case() {
    local case_id="$1"
    local path="${EVIDENCE_DIR}/migration-snapshot-case-${case_id}.json"
    local pre_failure_path="${path}.pre-failure.json"
    local log_path="${EVIDENCE_DIR}/migration-snapshot-case-${case_id}.log"
    local options=()
    while IFS= read -r option; do options+=("${option}"); done < <(env_options migration-snapshot-case)
    rm -f "${path}" "${pre_failure_path}" "${log_path}"
    local started finished elapsed_ms
    started="$(php -r 'echo hrtime(true);')"
    RUN_PHASE="migration-snapshot-case-${case_id}"
    set +e
    timed_compose "migration-snapshot-case-${case_id}" 150 exec -T "${options[@]}" -e "WP_FTS_WC_CASE=${case_id}" wordpress \
      timeout -s KILL 120 php -d memory_limit=128M /proof/relational-fts-worst-case.php \
      2>&1 | php -r '
$path=$argv[1];$limit=1048576;$tailLimit=65536;$marker="\n[output truncated; final 65536 bytes follow]\n";
$output=fopen($path,"wb");
if($output===false){fwrite(STDERR,"Could not open bounded migration snapshot log.\n");exit(1);}
$written=0;$truncated=false;$tail="";
while(!feof(STDIN)){
    $chunk=fread(STDIN,8192);
    if($chunk===false){fclose($output);fwrite(STDERR,"Could not read migration snapshot output.\n");exit(1);}
    if($chunk===""){continue;}
    $tail=substr($tail.$chunk,-$tailLimit);
    $remaining=$limit-$written;
    if($remaining>0){
        $slice=substr($chunk,0,$remaining);$offset=0;$length=strlen($slice);
        while($offset<$length){
            $count=fwrite($output,substr($slice,$offset));
            if($count===false||$count===0){fclose($output);fwrite(STDERR,"Could not write bounded migration snapshot log.\n");exit(1);}
            $offset+=$count;
        }
        $written+=$length;
    }
    if(strlen($chunk)>$remaining){$truncated=true;}
}
if($truncated){
    $payloadLimit=$limit-strlen($marker)-$tailLimit;
    if(!ftruncate($output,$payloadLimit)||fseek($output,$payloadLimit)!==0){
        fclose($output);fwrite(STDERR,"Could not mark truncated migration snapshot log.\n");exit(1);
    }
    foreach([$marker,$tail] as $suffix){
        $offset=0;$length=strlen($suffix);
        while($offset<$length){
            $count=fwrite($output,substr($suffix,$offset));
            if($count===false||$count===0){fclose($output);fwrite(STDERR,"Could not write migration snapshot log tail.\n");exit(1);}
            $offset+=$count;
        }
    }
}
if(!fclose($output)){fwrite(STDERR,"Could not close bounded migration snapshot log.\n");exit(1);}
' "${log_path}"
    local -a statuses=("${PIPESTATUS[@]}")
    local status="${statuses[0]:-1}"
    local sink_status="${statuses[1]:-1}"
    set -e
    if (( sink_status != 0 && status == 0 )); then
        status=1
    fi
    finished="$(php -r 'echo hrtime(true);')"
    elapsed_ms="$(php -r 'printf("%.3f", ((int)$argv[2]-(int)$argv[1])/1000000);' "${started}" "${finished}")"
    if (( status != 0 )) && [[ -f "${path}" ]]; then
        mv "${path}" "${pre_failure_path}"
    fi
    if (( status != 0 )) || [[ ! -f "${path}" ]]; then
        local failure_status=0
        timeout --signal=TERM --kill-after=2s 10s php -r '
$exit=(int)$argv[2];
$elapsed=(float)$argv[6];
$timedOut=$exit===124||($exit===137&&$elapsed>=119000.0);
$logHash=is_file($argv[9])?hash_file("sha256",$argv[9]):hash("sha256","");
$memoryFatal=false;
if(is_file($argv[9])){
    $handle=fopen($argv[9],"rb");
    if($handle===false){fwrite(STDERR,"Could not scan migration snapshot log.\n");exit(1);}
    while(($line=fgets($handle))!==false){
        if(str_contains($line,"Allowed memory size of 134217728 bytes exhausted")){$memoryFatal=true;break;}
    }
    if(!feof($handle)&&!$memoryFatal){fclose($handle);fwrite(STDERR,"Could not scan migration snapshot log.\n");exit(1);}
    fclose($handle);
}
$class=$timedOut?"Timeout":($exit===137?"KilledOrOOM":($memoryFatal?"MemoryLimitFatal":"ProcessFailure"));
$message=$timedOut?"Legacy snapshot case exceeded the 120-second process limit":($exit===137?"Legacy snapshot case was SIGKILLed or OOM-killed before its timeout":($memoryFatal?"Legacy snapshot case exhausted its 128 MiB PHP memory limit":"Legacy snapshot case exited before writing PASS evidence"));
$data=[
 "schema"=>"relational-fts-migration-snapshot-case-v1","status"=>"FAIL","phase"=>"migration-snapshot-case","case"=>$argv[1],
 "source_sha"=>$argv[3],"zip_sha256"=>$argv[4],"manifest_sha256"=>null,"profile"=>$argv[5],
 "process_timeout_seconds"=>120,"memory_limit_bytes"=>134217728,"process_identity"=>null,
 "duration_ms"=>$elapsed,"query_count"=>null,"max_sql_bytes"=>null,"php_memory_delta_bytes"=>null,"rss_delta_bytes"=>null,
 "php_lifetime_peak_before_reset_bytes"=>null,
 "php_phase_peak_bytes"=>null,"php_peak_bytes"=>null,"rss_peak_bytes"=>null,
 "query"=>null,"options"=>null,"legacy_execution"=>null,
 "error"=>["class"=>$class,"message"=>$message,"exit"=>$exit,"log_sha256"=>$logHash],
 "discarded_pre_failure_artifact"=>is_file($argv[8])?[
     "sha256"=>hash_file("sha256",$argv[8]),
     "bytes"=>filesize($argv[8]),
 ]:null
];
$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$temporary=$argv[7].".tmp.".getmypid();
if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$argv[7])){@unlink($temporary);fwrite(STDERR,"Could not atomically publish migration snapshot failure evidence.\n");exit(1);}
' "${case_id}" "${status}" "${ACTIVE_SOURCE_SHA}" "${ACTIVE_ZIP_SHA256}" "${PROFILE}" "${elapsed_ms}" "${path}" "${pre_failure_path}" "${log_path}" || failure_status=$?
        if (( failure_status != 0 )); then
            echo "FAIL: could not publish bounded failure evidence for migration snapshot case ${case_id}." >&2
            return "${failure_status}"
        fi
        # A killed PHP client can leave its server-side statement running. Stop
        # this lane immediately so the EXIT cleanup removes the database instead
        # of contaminating a later case with residual legacy work.
        if (( status == 0 )); then
            return 1
        fi
        return "${status}"
    fi
}

set_run_stage "baseline-corpus-and-index"
run_php_phase setup > "${EVIDENCE_DIR}/baseline-setup.log"
BASELINE_INDEX_STARTED="$(php -r 'echo hrtime(true);')"
set +e
timed_compose baseline-reindex 7200 run --rm wpcli --url=http://wordpress fts reindex \
    --post_type=post \
    --post_status=publish,draft,pending,future,private \
    > "${EVIDENCE_DIR}/baseline-reindex.log" 2>&1
BASELINE_INDEX_EXIT=$?
set -e
BASELINE_INDEX_FINISHED="$(php -r 'echo hrtime(true);')"
BASELINE_INDEX_ELAPSED="$(php -r 'printf("%.6f", ((int)$argv[2]-(int)$argv[1])/1000000000);' "${BASELINE_INDEX_STARTED}" "${BASELINE_INDEX_FINISHED}")"
php -r '
$out=file_get_contents($argv[5]);
$data=["schema"=>"relational-fts-indexing-v1","status"=>(int)$argv[7]===0?"PASS":"FAIL","source_sha"=>$argv[1],"zip_sha256"=>$argv[2],"profile"=>$argv[3],"documents"=>(int)$argv[4],"elapsed_seconds"=>(float)$argv[6],"exit"=>(int)$argv[7],"output_sha256"=>hash("sha256",$out),"output_tail"=>substr($out,-2000)];
file_put_contents($argv[8],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
' "${BASELINE_SHA}" "${BASELINE_ZIP_SHA256}" "${PROFILE}" "${DOCUMENTS}" "${EVIDENCE_DIR}/baseline-reindex.log" "${BASELINE_INDEX_ELAPSED}" "${BASELINE_INDEX_EXIT}" "${EVIDENCE_DIR}/baseline-indexing.json"
if (( BASELINE_INDEX_EXIT != 0 )); then
    echo "FAIL: populated baseline WP-CLI reindex failed; see ${EVIDENCE_DIR}/baseline-reindex.log" >&2
    exit 1
fi
run_php_phase multisite-baseline-setup > "${EVIDENCE_DIR}/multisite-baseline-setup.log"
for migration_case in common_or max_valid_or_prefix rare_anchor_and prefix_fanout ambiguous_morphology_or ambiguous_morphology_and; do
    run_migration_snapshot_case "${migration_case}"
done
snapshot_finalize_options=()
while IFS= read -r option; do snapshot_finalize_options+=("${option}"); done < <(env_options migration-snapshot-finalize)
timed_compose migration-snapshot-finalize 150 exec -T "${snapshot_finalize_options[@]}" wordpress \
    timeout -s KILL 120 php -d memory_limit=128M /proof/relational-fts-worst-case.php \
    > "${EVIDENCE_DIR}/migration-snapshot-finalize.log"
# Replace the active plugin files without an activation request. The next fresh
# PHP process installs each failpoint before it explicitly starts the migration.
timed_compose current-plugin-install 600 run --rm wpcli --url=http://wordpress plugin install /proof/wp-fts-indexer.zip --force
record_installed_tree_binding post-install
ACTIVE_SOURCE_SHA="${SOURCE_SHA}"
ACTIVE_ZIP_SHA256="${ZIP_SHA256}"
ACTIVE_SOURCE_DIRTY="${SOURCE_DIRTY}"
ACTIVE_ALLOW_DIRTY="${ALLOW_DIRTY}"
set_run_stage "migration-failpoints"
run_php_phase migration-oracle > "${EVIDENCE_DIR}/migration-oracle.log"
start_migration_disk_monitor
for migration_phase in \
    legacy_renamed_fts_terms \
    legacy_renamed_fts_postings \
    legacy_renamed_fts_docs \
    legacy_renamed_fts_doc_lengths \
    legacy_renamed_fts_docmeta \
    legacy_renamed_fts_meta \
    legacy_renamed_fts_queue \
    v4_created \
    reconciliation_enqueued \
    ready_verified \
    legacy_cleaned; do
    kill_migration_phase "${migration_phase}"
    # The very next fresh WordPress process must prove that the durable state
    # left by SIGKILL still serves ordinary options, posts, and saves while the
    # enabled public search replacement fails closed without core LIKE or FTS.
    run_migration_post_kill_probe "${migration_phase}" \
        > "${EVIDENCE_DIR}/migration-post-kill-${migration_phase}.log"
done
run_php_phase migration-finalize > "${EVIDENCE_DIR}/migration-finalize.log"
stop_migration_disk_monitor
run_php_phase migration-rerun > "${EVIDENCE_DIR}/migration-rerun.log"
run_php_phase multisite-migration > "${EVIDENCE_DIR}/multisite-migration.log"
run_php_phase migration-rebind > "${EVIDENCE_DIR}/migration-rebind.log"

# The queue fake tests cannot prove session-lock behavior. Exercise the exact
# source-bound queue implementation through three independent connections to
# this lane's real MySQL/MariaDB server, including contention and connection-
# death recovery, and retain the machine-readable result.
set_run_stage "mutation-fence-concurrency"
timed_compose mutation-fence-concurrency 240 exec -T \
    -e WP_FTS_MYSQL_HOST=db \
    -e WP_FTS_MYSQL_PORT=3306 \
    -e WP_FTS_MYSQL_USER=wpfts \
    -e WP_FTS_MYSQL_PASSWORD=wpfts_dev_only \
    -e WP_FTS_MYSQL_DATABASE=wpfts \
    -e "WP_FTS_WC_ENGINE=${ENGINE}" \
    -e "WP_FTS_SOURCE_SHA=${SOURCE_SHA}" \
    -e WP_FTS_WP_PATH=/var/www/html \
    -e WP_FTS_MUTATION_QUEUE_PATH=/var/www/html/wp-content/plugins/indexer/src/IndexQueue.php \
    wordpress timeout -s KILL 180 php /proof/mutation-fence-concurrency.php \
    > "${EVIDENCE_DIR}/mutation-fence-concurrency.json"

# A manual WP-CLI index is only usable by visitor traffic when both runtimes
# derive the same complete index behavior profile. Record the full inputs from
# each real bootstrap and fail before reindexing if they differ.
set_run_stage "current-runtime-and-reindex"
run_php_phase runtime-profile -e WP_FTS_WC_RUNTIME_ROLE=web > "${EVIDENCE_DIR}/runtime-profile-web.log"
run_wpcli_php_phase runtime-profile -e WP_FTS_WC_RUNTIME_ROLE=wpcli > "${EVIDENCE_DIR}/runtime-profile-wpcli.log"
php -r '
$web=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$cli=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$normalize=function(mixed $value)use(&$normalize):mixed{
    if(!is_array($value)){return $value;}
    if(array_is_list($value)){return array_map($normalize,$value);}
    ksort($value,SORT_STRING);
    foreach($value as $key=>$item){$value[$key]=$normalize($item);}
    return $value;
};
$webProfile=$normalize($web["profile"]??null);
$cliProfile=$normalize($cli["profile"]??null);
$gates=[
 ["id"=>"runtime_profile_full_parity","expected"=>$webProfile,"actual"=>$cliProfile,"passed"=>$webProfile===$cliProfile],
 ["id"=>"runtime_profile_hash_parity","expected"=>$web["profile_hash"]??null,"actual"=>$cli["profile_hash"]??null,"passed"=>is_string($web["profile_hash"]??null)&&($web["profile_hash"]??null)===($cli["profile_hash"]??null)],
 ["id"=>"runtime_analyzer_signature_parity","expected"=>$web["analyzer_signature"]??null,"actual"=>$cli["analyzer_signature"]??null,"passed"=>is_string($web["analyzer_signature"]??null)&&($web["analyzer_signature"]??null)===($cli["analyzer_signature"]??null)],
 ["id"=>"runtime_unicode_normalizer_signature_parity","expected"=>$web["unicode_normalizer_signature"]??null,"actual"=>$cli["unicode_normalizer_signature"]??null,"passed"=>is_string($web["unicode_normalizer_signature"]??null)&&($web["unicode_normalizer_signature"]??null)===($cli["unicode_normalizer_signature"]??null)],
];
$passed=count(array_filter($gates,static fn(array $gate):bool=>!$gate["passed"]))===0;
$evidence=["schema"=>"relational-fts-runtime-parity-v1","status"=>$passed?"PASS":"FAIL","web"=>$web,"wpcli"=>$cli,"gates"=>$gates];
file_put_contents($argv[3],json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
if(!$passed){
    fwrite(STDERR,"FAIL: web/WP-CLI index profile mismatch: web=".(string)($web["profile_hash"]??"")." wpcli=".(string)($cli["profile_hash"]??"")."\n");
    exit(1);
}
' "${EVIDENCE_DIR}/runtime-profile-web.json" "${EVIDENCE_DIR}/runtime-profile-wpcli.json" "${EVIDENCE_DIR}/runtime-profile-parity.json"

# The migration has already populated v4. Deliberately invalidate every derived
# content hash before timing so this measures a full extraction/analyzer/writer
# rebuild rather than a fast unchanged reconciliation.
run_php_phase indexing-prepare > "${EVIDENCE_DIR}/indexing-prepare.log"
INDEX_REBUILD_DOCUMENTS="$(php -r '$e=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(($e["schema"]??null)!=="relational-fts-indexing-prepare-v2"||($e["status"]??null)!=="PASS"||(int)($e["documents"]??0)<1){exit(1);} echo (int)$e["documents"];' "${EVIDENCE_DIR}/indexing-prepare.json")"

INDEX_STARTED="$(php -r 'echo hrtime(true);')"
set +e
timed_compose reindex-enqueue 1800 run --rm wpcli --url=http://wordpress fts reindex \
    --post_type=post \
    --post_status=publish,draft,pending,future,private \
    --format=json > "${EVIDENCE_DIR}/reindex-enqueue.json" 2> "${EVIDENCE_DIR}/reindex-enqueue.stderr"
INDEX_ENQUEUE_EXIT=$?
INDEX_ENQUEUE_PROOF_EXIT=1
INDEX_DRAIN_EXIT=1
if (( INDEX_ENQUEUE_EXIT == 0 )); then
    php -r '
$e=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$statuses=is_array($e["post_status"]??null)?array_values($e["post_status"]):[];sort($statuses,SORT_STRING);
$types=is_array($e["post_type"]??null)?array_values($e["post_type"]):[];sort($types,SORT_STRING);
if(($e["status"]??null)!=="queued"||($e["has_more"]??null)!==true||($e["requested_limit"]??null)!==0||$statuses!==["draft","future","pending","private","publish"]||$types!==["post"]){fwrite(STDERR,"FAIL: asynchronous WP-CLI reindex enqueue output is incomplete.\n");exit(1);}
' "${EVIDENCE_DIR}/reindex-enqueue.json"
    INDEX_ENQUEUE_PROOF_EXIT=$?
    if (( INDEX_ENQUEUE_PROOF_EXIT == 0 )); then
        run_php_phase reindex-drain > "${EVIDENCE_DIR}/reindex-drain.log" 2>&1
        INDEX_DRAIN_EXIT=$?
    fi
fi
cat "${EVIDENCE_DIR}/reindex-enqueue.json" "${EVIDENCE_DIR}/reindex-enqueue.stderr" > "${EVIDENCE_DIR}/reindex.log"
if [[ -f "${EVIDENCE_DIR}/reindex-drain.log" ]]; then
    cat "${EVIDENCE_DIR}/reindex-drain.log" >> "${EVIDENCE_DIR}/reindex.log"
fi
if (( INDEX_ENQUEUE_EXIT != 0 )); then
    INDEX_EXIT=${INDEX_ENQUEUE_EXIT}
elif (( INDEX_ENQUEUE_PROOF_EXIT != 0 )); then
    INDEX_EXIT=${INDEX_ENQUEUE_PROOF_EXIT}
else
    INDEX_EXIT=${INDEX_DRAIN_EXIT}
fi
set -e
INDEX_FINISHED="$(php -r 'echo hrtime(true);')"
INDEX_ELAPSED="$(php -r 'printf("%.6f", ((int)$argv[2]-(int)$argv[1])/1000000000);' "${INDEX_STARTED}" "${INDEX_FINISHED}")"
php -r '
$out=file_get_contents($argv[5]);
$data=["schema"=>"relational-fts-indexing-v2","status"=>(int)$argv[7]===0?"PASS":"FAIL","mode"=>"forced_full_rebuild","source_sha"=>$argv[1],"zip_sha256"=>$argv[2],"profile"=>$argv[3],"documents"=>(int)$argv[4],"rebuilt_documents"=>(int)$argv[9],"elapsed_seconds"=>(float)$argv[6],"exit"=>(int)$argv[7],"enqueue_exit"=>(int)$argv[10],"enqueue_proof_exit"=>(int)$argv[11],"drain_exit"=>(int)$argv[12],"drain_artifact_sha256"=>is_file($argv[13])?hash_file("sha256",$argv[13]):null,"output_sha256"=>hash("sha256",$out),"output_tail"=>substr($out,-2000)];
file_put_contents($argv[8],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
' "${SOURCE_SHA}" "${ZIP_SHA256}" "${PROFILE}" "${DOCUMENTS}" "${EVIDENCE_DIR}/reindex.log" "${INDEX_ELAPSED}" "${INDEX_EXIT}" "${EVIDENCE_DIR}/indexing.json" "${INDEX_REBUILD_DOCUMENTS}" "${INDEX_ENQUEUE_EXIT}" "${INDEX_ENQUEUE_PROOF_EXIT}" "${INDEX_DRAIN_EXIT}" "${EVIDENCE_DIR}/reindex-drain.json"
if (( INDEX_EXIT != 0 )); then
    echo "FAIL: production WP-CLI reindex enqueue or bounded worker drain failed; see ${EVIDENCE_DIR}/reindex.log" >&2
    exit 1
fi

set_run_stage "validation-and-boundaries"
run_wpcli_php_phase wpcli-adapter > "${EVIDENCE_DIR}/wpcli-adapter.log"
run_php_phase cold-ready-request > "${EVIDENCE_DIR}/cold-ready-request.log"
run_php_phase dependency-lob > "${EVIDENCE_DIR}/dependency-lob.log"
run_php_phase validate > "${EVIDENCE_DIR}/validate.log"
# Every required production search shape gets one dedicated PHP lifetime. The
# finalizer requires the exact source/case inventory and thirteen distinct
# /proc process identities before accepting any peak-memory gate.
SEARCH_MEMORY_CASES=(
    common_or
    max_valid_or_prefix
    rare_anchor_and
    prefix_fanout
    surface_rarest_exact_anchor_and
    surface_dense_candidate_prefix_and
    selective_prefix_anchor_and
    hidden_dirty_head
    impossible_and
    all_packs
    ambiguous_morphology_or
    ambiguous_morphology_and
    field_impact
)
for case_id in "${SEARCH_MEMORY_CASES[@]}"; do
    run_php_phase search-memory-sample \
      -e "WP_FTS_WC_CASE=${case_id}" \
      > "${EVIDENCE_DIR}/search-memory-${case_id}.log"
done
run_php_phase writer-aggregate > "${EVIDENCE_DIR}/writer-aggregate.log"
run_old_posting_frontier
run_php_phase max-valid-setup > "${EVIDENCE_DIR}/max-valid-setup.log"
# This must be a separate PHP process: its resettable PHP peak and Linux RSS
# high-water evidence would be meaningless after indexing forty MiB of sources.
run_php_phase max-valid-search > "${EVIDENCE_DIR}/max-valid-search.log"

# Exercise the exact accepted/rejected analyzer, query-plan, document-term, and
# bulk-enqueue boundaries in one fresh 128 MiB process. Its infinite tokenizer
# makes the external 180-second process kill part of the acceptance contract.
# It cleans every temporary row before later cold/concurrency measurements.
run_isolated_boundaries

kill_uncommitted_transaction
run_php_phase verify-transaction-crash > "${EVIDENCE_DIR}/transaction-crash-verify.log"

COLD_SAMPLES=10
set_run_stage "cold-cache"
run_php_phase cold-prepare > "${EVIDENCE_DIR}/cold-prepare.log"
for case_id in common_or max_valid_or_prefix rare_anchor_and prefix_fanout; do
    for sample in $(seq 0 $((COLD_SAMPLES - 1))); do
        # Docker may recreate/reset a container cgroup across restart. Retain
        # this segment's cumulative peak and OOM counters before that boundary.
        capture_database_memory_checkpoint "pre-cold-restart-${case_id}-${sample}"
        timed_compose cold-database-restart 300 restart db >/dev/null
        wait_for_database
        # Both supported servers reset at least one statement consumer on
        # restart, so restore the exact attribution state before every sample.
        configure_performance_schema_consumers "cold-performance-schema-enable-${case_id}-${sample}"
        run_php_phase cold-sample \
          -e "WP_FTS_WC_CASE=${case_id}" \
          -e "WP_FTS_WC_SAMPLE=${sample}" \
          > "${EVIDENCE_DIR}/cold-${case_id}-${sample}.log"
    done
done
run_php_phase cold-cleanup > "${EVIDENCE_DIR}/cold-cleanup.log"

set_run_stage "concurrency-and-drain"
run_php_phase concurrency-setup > "${EVIDENCE_DIR}/concurrency-setup.log"
run_php_phase idle-http > "${EVIDENCE_DIR}/idle-http.log"

timed_compose wpcli-cursor-page1 300 run --rm wpcli --url=http://wordpress --user=admin fts search 'commonalpha commonbeta commongamma' \
  --mode=OR --limit=20 --lang=en --format=json --explain \
  > "${EVIDENCE_DIR}/wpcli-page-1.json"
WPCLI_NEXT_CURSOR="$(php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(!is_string($p["next_cursor"]??null)||$p["next_cursor"]===""){exit(1);} echo $p["next_cursor"];' "${EVIDENCE_DIR}/wpcli-page-1.json")"
timed_compose wpcli-cursor-page2 300 run --rm wpcli --url=http://wordpress --user=admin fts search 'commonalpha commonbeta commongamma' \
  --mode=OR --limit=20 --lang=en --format=json --explain \
  --after_cursor="${WPCLI_NEXT_CURSOR}" \
  > "${EVIDENCE_DIR}/wpcli-page-2.json"
WPCLI_PREVIOUS_CURSOR="$(php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(!is_string($p["previous_cursor"]??null)||$p["previous_cursor"]===""){exit(1);} echo $p["previous_cursor"];' "${EVIDENCE_DIR}/wpcli-page-2.json")"
timed_compose wpcli-cursor-reverse 300 run --rm wpcli --url=http://wordpress --user=admin fts search 'commonalpha commonbeta commongamma' \
  --mode=OR --limit=20 --lang=en --format=json --explain \
  --before_cursor="${WPCLI_PREVIOUS_CURSOR}" \
  > "${EVIDENCE_DIR}/wpcli-page-reverse.json"
php -r '
$first=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$second=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$reverse=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
$oracle=json_decode(file_get_contents($argv[5]),true,512,JSON_THROW_ON_ERROR);
$ids=static fn(array $p):array=>array_map("intval",array_column(is_array($p["results"]??null)?$p["results"]:[],"doc_id"));
$scores=static function(array $p):array{$out=[];foreach(is_array($p["results"]??null)?$p["results"]:[] as $row){if(!is_array($row)||!is_numeric($row["doc_id"]??null)||!is_numeric($row["score"]??null)){return [];}$out[]=["doc_id"=>(int)$row["doc_id"],"score"=>number_format((float)$row["score"],8,".","")];}return $out;};
$a=$ids($first);$b=$ids($second);$c=$ids($reverse);
$oracleHash=$oracle["evidence_sha256"]??null;$oracleInput=$oracle;unset($oracleInput["evidence_sha256"]);
$canonicalize=function(mixed $value)use(&$canonicalize):mixed{if(!is_array($value)){return $value;}if(array_is_list($value)){return array_map($canonicalize,$value);}ksort($value,SORT_STRING);foreach($value as $key=>$child){$value[$key]=$canonicalize($child);}return $value;};
$calculatedOracleHash=hash("sha256",json_encode($canonicalize($oracleInput),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
$statements=[];foreach([$first,$second,$reverse] as $p){$statements[]=(int)($p["explain"]["query_statements"]??PHP_INT_MAX);}
$gates=[
 ["id"=>"wpcli_first_next_reverse_parity","expected"=>$a,"actual"=>$c,"passed"=>$a===$c],
 ["id"=>"wpcli_cursor_no_duplicates","expected"=>40,"actual"=>count(array_unique([...$a,...$b])),"passed"=>count($a)===20&&count($b)===20&&count(array_unique([...$a,...$b]))===40],
 ["id"=>"wpcli_cursor_query_statements","expected"=>"<= 3","actual"=>max($statements),"passed"=>max($statements)<=3],
 ["id"=>"wpcli_oracle_artifact_binding","expected"=>["relational-fts-adapter-oracle-v1","direct-set-oriented-searcher",$argv[6]],"actual"=>[$oracle["schema"]??null,$oracle["source"]??null,$oracle["source_sha"]??null],"passed"=>($oracle["schema"]??null)==="relational-fts-adapter-oracle-v1"&&($oracle["source"]??null)==="direct-set-oriented-searcher"&&($oracle["source_sha"]??null)===$argv[6]&&is_string($oracleHash)&&hash_equals($calculatedOracleHash,$oracleHash)],
 ["id"=>"wpcli_first_page_oracle_order","expected"=>$oracle["result_ids"]??null,"actual"=>$a,"passed"=>($oracle["result_ids"]??null)===$a],
 ["id"=>"wpcli_first_page_oracle_scores","expected"=>$oracle["result_score_signature"]??null,"actual"=>$scores($first),"passed"=>($oracle["result_score_signature"]??null)===$scores($first)],
];
$out=["schema"=>"relational-fts-wpcli-cursor-v2","status"=>count(array_filter($gates,static fn(array $gate):bool=>!$gate["passed"]))===0?"PASS":"FAIL","oracle"=>$oracle,"first"=>$first,"second"=>$second,"reverse"=>$reverse,"gates"=>$gates];
file_put_contents($argv[4],json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
foreach($gates as $gate){if(!$gate["passed"]){fwrite(STDERR,"WP-CLI cursor gate failed: ".$gate["id"]."\n");exit(1);}}
' "${EVIDENCE_DIR}/wpcli-page-1.json" "${EVIDENCE_DIR}/wpcli-page-2.json" "${EVIDENCE_DIR}/wpcli-page-reverse.json" "${EVIDENCE_DIR}/wpcli-cursor.json" "${EVIDENCE_DIR}/adapter-oracle.json" "${SOURCE_SHA}"

rm -f "${EVIDENCE_DIR}"/concurrency-ready-*.json "${EVIDENCE_DIR}/concurrency-window.json"
pids=()
for worker in $(seq 0 7); do
    run_php_phase concurrent-reader -e "WP_FTS_WC_WORKER=${worker}" > "${EVIDENCE_DIR}/concurrent-reader-${worker}.log" 2>&1 &
    pids+=("$!")
done
for worker in 0 1; do
    run_php_phase concurrent-writer -e "WP_FTS_WC_WORKER=${worker}" > "${EVIDENCE_DIR}/concurrent-writer-${worker}.log" 2>&1 &
    pids+=("$!")
done
open_concurrency_window
CONCURRENCY_FAILURE=0
for pid in "${pids[@]}"; do
    if ! wait "${pid}"; then
        CONCURRENCY_FAILURE=1
    fi
done
if (( CONCURRENCY_FAILURE != 0 )); then
    echo "FAIL: at least one concurrent reader/writer process failed." >&2
    exit 1
fi

rm -f "${EVIDENCE_DIR}"/scope-ddl-{start,release}-*.json \
      "${EVIDENCE_DIR}"/scope-ddl-{ready,writer}-*.json
scope_ddl_writer_pids=()
for ordinal in 1 2; do
    for operation in insert update; do
        run_php_phase scope-ddl-writer \
          -e "WP_FTS_WC_DDL_ORDINAL=${ordinal}" \
          -e "WP_FTS_WC_DDL_OPERATION=${operation}" \
          > "${EVIDENCE_DIR}/scope-ddl-writer-${ordinal}-${operation}.log" 2>&1 &
        scope_ddl_writer_pids+=("$!")
    done
done
if ! run_php_phase scope-proof > "${EVIDENCE_DIR}/scope-proof.log"; then
    for pid in "${scope_ddl_writer_pids[@]}"; do
        kill "${pid}" >/dev/null 2>&1 || true
    done
    wait "${scope_ddl_writer_pids[@]}" 2>/dev/null || true
    echo "FAIL: populated scope-index proof failed while concurrent core-table writers were active." >&2
    exit 1
fi
SCOPE_DDL_WRITER_FAILURE=0
for pid in "${scope_ddl_writer_pids[@]}"; do
    if ! wait "${pid}"; then
        SCOPE_DDL_WRITER_FAILURE=1
    fi
done
if (( SCOPE_DDL_WRITER_FAILURE != 0 )); then
    echo "FAIL: at least one concurrent scope-index DDL writer failed." >&2
    exit 1
fi
run_php_phase drain > "${EVIDENCE_DIR}/drain.log"
set_run_stage "finalization"
record_installed_tree_binding pre-finalize
capture_database_memory_checkpoint final-workload
capture_wordpress_memory_checkpoint final-workload
finalize_cgroup_memory_evidence
run_php_phase finalize > "${EVIDENCE_DIR}/finalize.log"

if grep -R -E '(^|\[)(SKIP|PENDING)(:|\])' "${EVIDENCE_DIR}" --exclude='relational-fts-evidence.json' >/dev/null 2>&1; then
    echo "FAIL: evidence logs contain a skipped or pending lane." >&2
    exit 1
fi
php -r '
$e=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$canonicalize=function(mixed $value)use(&$canonicalize):mixed{
 if(!is_array($value)){return $value;}
 if(array_is_list($value)){return array_map($canonicalize,$value);}
 ksort($value,SORT_STRING);foreach($value as $key=>$child){$value[$key]=$canonicalize($child);}return $value;
};
$recorded=$e["evidence_sha256"]??null;$hashInput=$e;unset($hashInput["evidence_sha256"]);
$calculated=hash("sha256",json_encode($canonicalize($hashInput),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
$manifest=$e["manifest"]["profile"]??[];
$bound=($e["source_sha"]??null)===$argv[2]
 &&($e["engine"]??null)===$argv[3]
 &&($manifest["name"]??null)===$argv[4]
 &&($manifest["documents"]??null)===(int)$argv[5]
 &&($e["acceptance_lane"]??null)===($argv[6]==="0")
 &&($e["lane_id"]??null)===$argv[7];
if(($e["schema"]??null)!=="relational-fts-evidence-v3"||($e["status"]??null)!=="PASS"||($e["completed"]??null)!==true||!$bound||!is_string($recorded)||!hash_equals($calculated,$recorded)){fwrite(STDERR,"Final evidence is incomplete, unbound, failed, or has an invalid self-hash.\n");exit(1);}
' "${EVIDENCE_DIR}/relational-fts-evidence.json" "${SOURCE_SHA}" "${ENGINE}" "${PROFILE}" "${DOCUMENTS}" "${ALLOW_DIRTY}" "${LANE_ID}"
RUN_COMPLETED=1
publish_evidence 0
echo "PASS: ${DOCUMENTS} real WordPress documents; evidence ${OUTPUT}"
