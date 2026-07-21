<?php
declare(strict_types=1);

/** @var int */
$wp_fts_sqlite_dbdelta_calls = 0;

/**
 * The real WordPress SQLite drop-in translates dbDelta's MySQL DDL. This
 * isolated regression starts from that translated schema, so dbDelta only
 * needs to model the idempotent "already current" result.
 *
 * @param string[] $statements
 */
function dbDelta(array $statements): void
{
    global $wpdb, $wp_fts_sqlite_dbdelta_calls;

    if (!isset($wpdb) || !$wpdb instanceof WP_FTS_Schema_Repair_SQLite_WPDB || count($statements) !== 4) {
        throw new RuntimeException('SQLite schema regression received an invalid dbDelta call.');
    }
    $wp_fts_sqlite_dbdelta_calls++;
    wp_fts_schema_repair_apply_schema($wpdb);
}

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

final class WP_FTS_Schema_Repair_SQLite_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $term_relationships = 'wp_term_relationships';
    public string $last_error = '';
    public PDO $dbh;
    /** @var string[] */
    public array $queries = [];

    /** Create the in-memory translated schema target with strict PDO failures. */
    public function __construct()
    {
        $this->dbh = new PDO('sqlite::memory:');
        $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
    }

    /** @return object[] */
    public function get_results(mixed $statement): array
    {
        $sql = (string) $statement;
        $this->queries[] = $sql;
        $result = $this->dbh->query($sql);

        return $result === false ? [] : $result->fetchAll(PDO::FETCH_OBJ);
    }

    /** Mirror wpdb's false-plus-last_error contract around SQLite execution. */
    public function query(mixed $statement): int|false
    {
        $sql = (string) $statement;
        $this->queries[] = $sql;
        try {
            return $this->dbh->exec($sql);
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return false;
        }
    }
}

/** @return string[] */
function wp_fts_schema_repair_schema(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS wp_fts_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, lang BLOB NOT NULL, kind INTEGER NOT NULL DEFAULT 0, term BLOB NOT NULL, doc_freq INTEGER NOT NULL DEFAULT 0)',
        'CREATE UNIQUE INDEX IF NOT EXISTS wp_fts_term_identity ON wp_fts_terms(lang,kind,term)',
        'CREATE INDEX IF NOT EXISTS wp_fts_empty_terms ON wp_fts_terms(doc_freq)',
        'CREATE TABLE IF NOT EXISTS wp_fts_postings (term_id INTEGER NOT NULL, post_id INTEGER NOT NULL, impact INTEGER NOT NULL, PRIMARY KEY(term_id,post_id))',
        'CREATE INDEX IF NOT EXISTS wp_fts_post_term_impact ON wp_fts_postings(post_id,term_id,impact)',
        'CREATE TABLE IF NOT EXISTS wp_fts_documents (post_id INTEGER PRIMARY KEY, primary_lang BLOB NOT NULL DEFAULT \'und\', content_hash BLOB, snippet_text TEXT, indexed_at INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE IF NOT EXISTS wp_fts_work (job_key BLOB PRIMARY KEY, kind TEXT NOT NULL, post_id INTEGER NOT NULL DEFAULT 0, generation INTEGER NOT NULL DEFAULT 1, state TEXT NOT NULL DEFAULT \'pending\', available_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, claim_token TEXT NOT NULL DEFAULT \'\', claimed_generation INTEGER NOT NULL DEFAULT 0, claim_expires_at INTEGER NOT NULL DEFAULT 0, cursor_post_id INTEGER NOT NULL DEFAULT 0, scope_coverage TEXT NOT NULL DEFAULT \'\', scope_incarnation BLOB NOT NULL DEFAULT \'\', scope_subject_type TEXT NOT NULL DEFAULT \'\', scope_subject_id INTEGER NOT NULL DEFAULT 0, payload TEXT, last_error_code TEXT NOT NULL DEFAULT \'\', last_error_at INTEGER NOT NULL DEFAULT 0)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_ready ON wp_fts_work(kind,state,available_at,post_id,job_key)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_claim_token ON wp_fts_work(claim_token,post_id)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_kind_job ON wp_fts_work(kind,job_key)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_scope_subject ON wp_fts_work(kind,scope_coverage,scope_subject_type,scope_subject_id)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_dirty ON wp_fts_work(post_id,kind)',
        'CREATE INDEX IF NOT EXISTS wp_fts_work_recoverable ON wp_fts_work(kind,state,claim_expires_at,available_at,post_id,job_key)',
    ];
}

/** Install the translated current schema without destructive replacement. */
function wp_fts_schema_repair_apply_schema(WP_FTS_Schema_Repair_SQLite_WPDB $wpdb): void
{
    foreach (wp_fts_schema_repair_schema() as $statement) {
        $wpdb->dbh->exec($statement);
    }
}

/** Seed rows whose survival distinguishes repair from table recreation. */
function wp_fts_schema_repair_seed(WP_FTS_Schema_Repair_SQLite_WPDB $wpdb): void
{
    $insert = $wpdb->dbh->prepare('INSERT INTO wp_fts_terms(term_id,lang,kind,term,doc_freq) VALUES(1,?,?,?,1)');
    $insert->execute(['en', 0, 'kept']);
    $wpdb->dbh->exec('INSERT INTO wp_fts_postings(term_id,post_id,impact) VALUES(1,42,4096)');
    $wpdb->dbh->exec("INSERT INTO wp_fts_documents(post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES(42,'en','hash','kept',1)");
    $wpdb->dbh->exec("INSERT INTO wp_fts_work(job_key,kind,post_id,generation,state,payload) VALUES('meta:search-epoch','meta',0,7,'meta','0123456789abcdef0123456789abcdef')");
    $wpdb->dbh->exec("INSERT INTO wp_fts_work(job_key,kind,post_id,generation,state,available_at) VALUES('post:42','post',42,3,'ready',1)");
}

/** @return array{wpdb:WP_FTS_Schema_Repair_SQLite_WPDB,storage:WP_FTS_Storage_Mysql} */
function wp_fts_schema_repair_fixture(bool $seed = true): array
{
    global $wpdb;

    $wpdb = new WP_FTS_Schema_Repair_SQLite_WPDB();
    wp_fts_schema_repair_apply_schema($wpdb);
    if ($seed) {
        wp_fts_schema_repair_seed($wpdb);
    }

    return ['wpdb' => $wpdb, 'storage' => new WP_FTS_Storage_Mysql($wpdb)];
}

/** @return array{valid:bool,drops:int,terms:int,postings:int,documents:int,work:int} */
function wp_fts_schema_repair_result(WP_FTS_Schema_Repair_SQLite_WPDB $wpdb, WP_FTS_Storage_Mysql $storage): array
{
    return [
        'valid' => !empty($storage->verify_schema()['valid']),
        'drops' => count(array_filter(
            $wpdb->queries,
            static fn(string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'DROP TABLE')
        )),
        'terms' => (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_terms')->fetchColumn(),
        'postings' => (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings')->fetchColumn(),
        'documents' => (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_documents')->fetchColumn(),
        'work' => (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE job_key='post:42' AND generation=3")->fetchColumn(),
    ];
}

try {
    ['wpdb' => $wpdb, 'storage' => $storage] = wp_fts_schema_repair_fixture();

    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $before = $storage->verify_schema();
    $wpdb->queries = [];
    $storage->create_tables();
    $storage->create_tables();
    $after = $storage->verify_schema();
    $idempotentWpdb = $wpdb;
    $idempotentDbDeltaCalls = $wp_fts_sqlite_dbdelta_calls;

    $fresh = wp_fts_schema_repair_fixture(false);
    foreach (['wp_fts_terms', 'wp_fts_postings', 'wp_fts_documents', 'wp_fts_work'] as $table) {
        $fresh['wpdb']->dbh->exec("DROP TABLE {$table}");
    }
    $fresh['wpdb']->queries = [];
    $fresh['storage']->create_tables();
    $freshResult = wp_fts_schema_repair_result($fresh['wpdb'], $fresh['storage']);

    $missingDocument = wp_fts_schema_repair_fixture();
    $missingDocument['wpdb']->dbh->exec('DROP TABLE wp_fts_documents');
    $missingDocument['wpdb']->queries = [];
    $missingDocument['storage']->create_tables();
    $missingDocumentResult = wp_fts_schema_repair_result($missingDocument['wpdb'], $missingDocument['storage']);

    $missingTerm = wp_fts_schema_repair_fixture();
    $missingTerm['wpdb']->dbh->exec('DROP TABLE wp_fts_terms');
    $missingTerm['wpdb']->queries = [];
    $missingTerm['storage']->create_tables();
    $missingTermResult = wp_fts_schema_repair_result($missingTerm['wpdb'], $missingTerm['storage']);

    $mismatchedDocument = wp_fts_schema_repair_fixture();
    $mismatchedDocument['wpdb']->dbh->exec('ALTER TABLE wp_fts_documents ADD COLUMN stale_generation TEXT');
    $mismatchedDocument['wpdb']->queries = [];
    $mismatchedDocument['storage']->create_tables();
    $mismatchedDocumentResult = wp_fts_schema_repair_result($mismatchedDocument['wpdb'], $mismatchedDocument['storage']);

    // Model the immediately preceding dictionary contract. Removing the
    // redundant hash is a search-generation change: terms, postings, and
    // documents must be rebuilt together while queue work and the cursor epoch
    // survive independently.
    $legacyTermHash = wp_fts_schema_repair_fixture();
    $legacyTermHash['wpdb']->dbh->exec('DROP TABLE wp_fts_terms');
    $legacyTermHash['wpdb']->dbh->exec(
        'CREATE TABLE wp_fts_terms ('
        . 'term_id INTEGER PRIMARY KEY AUTOINCREMENT, term_hash BLOB NOT NULL, '
        . 'lang BLOB NOT NULL, kind INTEGER NOT NULL DEFAULT 0, term BLOB NOT NULL, '
        . 'doc_freq INTEGER NOT NULL DEFAULT 0)'
    );
    $legacyTermHash['wpdb']->dbh->exec('CREATE UNIQUE INDEX wp_fts_term_identity ON wp_fts_terms(lang,kind,term)');
    $legacyTermHash['wpdb']->dbh->exec('CREATE INDEX wp_fts_term_hash ON wp_fts_terms(term_hash)');
    $legacyTermHash['wpdb']->dbh->exec('CREATE INDEX wp_fts_empty_terms ON wp_fts_terms(doc_freq)');
    $legacyTermHash['wpdb']->dbh->exec(
        "INSERT INTO wp_fts_terms(term_id,term_hash,lang,kind,term,doc_freq) "
        . "VALUES(1,X'00000000000000000000000000000000','en',0,'legacy',1)"
    );
    $legacyTermHash['wpdb']->queries = [];
    $legacyTermHash['storage']->create_tables();
    $legacyTermHashResult = wp_fts_schema_repair_result($legacyTermHash['wpdb'], $legacyTermHash['storage']);

    $missingWork = wp_fts_schema_repair_fixture();
    $missingWork['wpdb']->dbh->exec('DROP TABLE wp_fts_work');
    $missingWork['wpdb']->queries = [];
    $missingWork['storage']->create_tables();
    $missingWorkResult = wp_fts_schema_repair_result($missingWork['wpdb'], $missingWork['storage']);

    $mismatchedWork = wp_fts_schema_repair_fixture();
    $mismatchedWork['wpdb']->dbh->exec('ALTER TABLE wp_fts_work ADD COLUMN stale_queue_state TEXT');
    $mismatchedWork['wpdb']->queries = [];
    $mismatchedWork['storage']->create_tables();
    $mismatchedWorkResult = wp_fts_schema_repair_result($mismatchedWork['wpdb'], $mismatchedWork['storage']);

    $mixedDamage = wp_fts_schema_repair_fixture();
    $mixedDamage['wpdb']->dbh->exec('DROP TABLE wp_fts_documents');
    $mixedDamage['wpdb']->dbh->exec('ALTER TABLE wp_fts_work ADD COLUMN stale_queue_state TEXT');
    $mixedDamage['wpdb']->queries = [];
    $mixedDamage['storage']->create_tables();
    $mixedDamageResult = wp_fts_schema_repair_result($mixedDamage['wpdb'], $mixedDamage['storage']);

    $payload = [
        'before_valid' => $before['valid'] ?? false,
        'after_valid' => $after['valid'] ?? false,
        'dbdelta_calls' => $idempotentDbDeltaCalls,
        'drop_statements' => count(array_filter(
            $idempotentWpdb->queries,
            static fn(string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'DROP TABLE')
        )),
        'postings' => (int) $idempotentWpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings WHERE term_id=1 AND post_id=42 AND impact=4096')->fetchColumn(),
        'document' => (int) $idempotentWpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_documents WHERE post_id=42 AND snippet_text='kept'")->fetchColumn(),
        'epoch' => (int) $idempotentWpdb->dbh->query("SELECT generation FROM wp_fts_work WHERE job_key='meta:search-epoch'")->fetchColumn(),
        'incarnation' => (string) $idempotentWpdb->dbh->query("SELECT payload FROM wp_fts_work WHERE job_key='meta:search-epoch'")->fetchColumn(),
        'work_generation' => (int) $idempotentWpdb->dbh->query("SELECT generation FROM wp_fts_work WHERE job_key='post:42'")->fetchColumn(),
        'fresh' => $freshResult,
        'missing_document' => $missingDocumentResult,
        'missing_term' => $missingTermResult,
        'mismatched_document' => $mismatchedDocumentResult,
        'legacy_term_hash' => $legacyTermHashResult,
        'missing_work' => $missingWorkResult,
        'mismatched_work' => $mismatchedWorkResult,
        'mixed_damage' => $mixedDamageResult,
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
