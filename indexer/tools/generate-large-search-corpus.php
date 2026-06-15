<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Streams deterministic multilingual JSONL corpus shards for search demos and
 * benchmarks. Corpus vocabulary is fixture text only; analyzer behavior stays
 * owned by the runtime language pipeline and analyzer packs.
 */
final class WP_FTS_LargeSearchCorpusGenerator
{
    private const CORPUS_ID = 'wp-fts-large-search-corpus-v1';
    private const DEFAULT_SEED = 'wp-fts-large-search-corpus-v1';
    private const DEFAULT_ENGLISH_DOCS = 100000;
    private const DEFAULT_PER_LANGUAGE_DOCS = 30000;
    private const SMOKE_ENGLISH_DOCS = 12;
    private const SMOKE_PER_LANGUAGE_DOCS = 4;
    private const SIZE_CLASSES = [
        'short' => [200, 450],
        'medium' => [700, 800],
        'long' => [1400, 2400],
        'very_long' => [5000, 5200],
    ];
    private const DEFAULT_LANGUAGE_ORDER = [
        'en',
        'ar',
        'bn',
        'ca',
        'de',
        'es',
        'fr',
        'hi',
        'id',
        'nl',
        'pl',
        'pt',
        'ru',
        'ur',
        'zh',
    ];
    private const LANGUAGE_PROFILES = [
        'en' => [
            'name' => 'English',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'search infrastructure'],
                ['id' => 'publishing', 'label' => 'editorial publishing'],
                ['id' => 'commerce', 'label' => 'catalog commerce'],
                ['id' => 'support', 'label' => 'customer support'],
                ['id' => 'research', 'label' => 'applied research'],
                ['id' => 'culture', 'label' => 'local culture'],
            ],
            'words' => [
                'index', 'ranking', 'document', 'archive', 'update', 'signal', 'workflow', 'review',
                'cluster', 'pipeline', 'storage', 'taxonomy', 'editor', 'query', 'result', 'snippet',
                'language', 'content', 'release', 'catalog', 'search', 'benchmark', 'analysis', 'operator',
                'queue', 'record', 'metadata', 'navigation', 'quality', 'evidence', 'dashboard', 'article',
            ],
        ],
        'ar' => [
            'name' => 'Arabic',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'بنية البحث'],
                ['id' => 'publishing', 'label' => 'النشر التحريري'],
                ['id' => 'commerce', 'label' => 'تجارة الفهارس'],
                ['id' => 'support', 'label' => 'دعم العملاء'],
                ['id' => 'research', 'label' => 'بحث تطبيقي'],
                ['id' => 'culture', 'label' => 'ثقافة محلية'],
            ],
            'words' => [
                'بحث', 'فهرس', 'وثيقة', 'نتيجة', 'مقالة', 'تحليل', 'لغة', 'محتوى',
                'تحديث', 'اشارة', 'ترتيب', 'ارشيف', 'محرر', 'دليل', 'خدمة', 'تجربة',
                'بيانات', 'مسار', 'جودة', 'تقرير', 'صفحة', 'مشروع', 'قائمة', 'سياق',
            ],
        ],
        'bn' => [
            'name' => 'Bengali',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'অনুসন্ধান অবকাঠামো'],
                ['id' => 'publishing', 'label' => 'সম্পাদনা প্রকাশনা'],
                ['id' => 'commerce', 'label' => 'পণ্য তালিকা'],
                ['id' => 'support', 'label' => 'গ্রাহক সহায়তা'],
                ['id' => 'research', 'label' => 'প্রয়োগ গবেষণা'],
                ['id' => 'culture', 'label' => 'স্থানীয় সংস্কৃতি'],
            ],
            'words' => [
                'অনুসন্ধান', 'সূচি', 'নথি', 'ফলাফল', 'প্রবন্ধ', 'বিশ্লেষণ', 'ভাষা', 'বিষয়বস্তু',
                'হালনাগাদ', 'সংকেত', 'ক্রম', 'সংরক্ষণ', 'সম্পাদক', 'নির্দেশিকা', 'সেবা', 'পরীক্ষা',
                'তথ্য', 'প্রবাহ', 'গুণমান', 'প্রতিবেদন', 'পৃষ্ঠা', 'প্রকল্প', 'তালিকা', 'প্রসঙ্গ',
            ],
        ],
        'ca' => [
            'name' => 'Catalan',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infraestructura de cerca'],
                ['id' => 'publishing', 'label' => 'publicacio editorial'],
                ['id' => 'commerce', 'label' => 'cataleg comercial'],
                ['id' => 'support', 'label' => 'suport al client'],
                ['id' => 'research', 'label' => 'recerca aplicada'],
                ['id' => 'culture', 'label' => 'cultura local'],
            ],
            'words' => [
                'cerca', 'index', 'document', 'resultat', 'article', 'analisi', 'llengua', 'contingut',
                'actualitzacio', 'senyal', 'ordre', 'arxiu', 'editor', 'guia', 'servei', 'prova',
                'dades', 'proces', 'qualitat', 'informe', 'pagina', 'projecte', 'llista', 'context',
            ],
        ],
        'de' => [
            'name' => 'German',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'Suchinfrastruktur'],
                ['id' => 'publishing', 'label' => 'redaktionelles Publizieren'],
                ['id' => 'commerce', 'label' => 'Kataloghandel'],
                ['id' => 'support', 'label' => 'Kundendienst'],
                ['id' => 'research', 'label' => 'angewandte Forschung'],
                ['id' => 'culture', 'label' => 'lokale Kultur'],
            ],
            'words' => [
                'suche', 'index', 'dokument', 'ergebnis', 'artikel', 'analyse', 'sprache', 'inhalt',
                'aktualisierung', 'signal', 'rang', 'archiv', 'redaktion', 'anleitung', 'dienst', 'probe',
                'daten', 'ablauf', 'qualitat', 'bericht', 'seite', 'projekt', 'liste', 'kontext',
            ],
        ],
        'es' => [
            'name' => 'Spanish',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infraestructura de busqueda'],
                ['id' => 'publishing', 'label' => 'publicacion editorial'],
                ['id' => 'commerce', 'label' => 'catalogo comercial'],
                ['id' => 'support', 'label' => 'soporte al cliente'],
                ['id' => 'research', 'label' => 'investigacion aplicada'],
                ['id' => 'culture', 'label' => 'cultura local'],
            ],
            'words' => [
                'busqueda', 'indice', 'documento', 'resultado', 'articulo', 'analisis', 'idioma', 'contenido',
                'actualizacion', 'senal', 'orden', 'archivo', 'editor', 'guia', 'servicio', 'prueba',
                'datos', 'flujo', 'calidad', 'informe', 'pagina', 'proyecto', 'lista', 'contexto',
            ],
        ],
        'fr' => [
            'name' => 'French',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infrastructure de recherche'],
                ['id' => 'publishing', 'label' => 'publication editoriale'],
                ['id' => 'commerce', 'label' => 'catalogue commercial'],
                ['id' => 'support', 'label' => 'support client'],
                ['id' => 'research', 'label' => 'recherche appliquee'],
                ['id' => 'culture', 'label' => 'culture locale'],
            ],
            'words' => [
                'recherche', 'index', 'document', 'resultat', 'article', 'analyse', 'langue', 'contenu',
                'miseajour', 'signal', 'rang', 'archive', 'editeur', 'guide', 'service', 'essai',
                'donnees', 'flux', 'qualite', 'rapport', 'page', 'projet', 'liste', 'contexte',
            ],
        ],
        'hi' => [
            'name' => 'Hindi',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'खोज संरचना'],
                ['id' => 'publishing', 'label' => 'संपादकीय प्रकाशन'],
                ['id' => 'commerce', 'label' => 'सूची व्यापार'],
                ['id' => 'support', 'label' => 'ग्राहक सहायता'],
                ['id' => 'research', 'label' => 'लागू शोध'],
                ['id' => 'culture', 'label' => 'स्थानीय संस्कृति'],
            ],
            'words' => [
                'खोज', 'सूचक', 'दस्तावेज', 'परिणाम', 'लेख', 'विश्लेषण', 'भाषा', 'सामग्री',
                'अद्यतन', 'संकेत', 'क्रम', 'संग्रह', 'संपादक', 'मार्गदर्शिका', 'सेवा', 'परीक्षण',
                'डेटा', 'प्रवाह', 'गुणवत्ता', 'रिपोर्ट', 'पृष्ठ', 'परियोजना', 'सूची', 'संदर्भ',
            ],
        ],
        'id' => [
            'name' => 'Indonesian',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infrastruktur pencarian'],
                ['id' => 'publishing', 'label' => 'penerbitan editorial'],
                ['id' => 'commerce', 'label' => 'katalog niaga'],
                ['id' => 'support', 'label' => 'dukungan pelanggan'],
                ['id' => 'research', 'label' => 'riset terapan'],
                ['id' => 'culture', 'label' => 'budaya lokal'],
            ],
            'words' => [
                'pencarian', 'indeks', 'dokumen', 'hasil', 'artikel', 'analisis', 'bahasa', 'konten',
                'pembaruan', 'sinyal', 'peringkat', 'arsip', 'editor', 'panduan', 'layanan', 'uji',
                'data', 'alur', 'mutu', 'laporan', 'halaman', 'proyek', 'daftar', 'konteks',
            ],
        ],
        'nl' => [
            'name' => 'Dutch',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'zoekinfrastructuur'],
                ['id' => 'publishing', 'label' => 'redactionele publicatie'],
                ['id' => 'commerce', 'label' => 'catalogushandel'],
                ['id' => 'support', 'label' => 'klantenservice'],
                ['id' => 'research', 'label' => 'toegepast onderzoek'],
                ['id' => 'culture', 'label' => 'lokale cultuur'],
            ],
            'words' => [
                'zoeken', 'index', 'document', 'resultaat', 'artikel', 'analyse', 'taal', 'inhoud',
                'update', 'signaal', 'rangorde', 'archief', 'redacteur', 'gids', 'dienst', 'proef',
                'gegevens', 'stroom', 'kwaliteit', 'rapport', 'pagina', 'project', 'lijst', 'context',
            ],
        ],
        'pl' => [
            'name' => 'Polish',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infrastruktura wyszukiwania'],
                ['id' => 'publishing', 'label' => 'publikacja redakcyjna'],
                ['id' => 'commerce', 'label' => 'handel katalogowy'],
                ['id' => 'support', 'label' => 'obsluga klienta'],
                ['id' => 'research', 'label' => 'badania stosowane'],
                ['id' => 'culture', 'label' => 'kultura lokalna'],
            ],
            'words' => [
                'wyszukiwanie', 'indeks', 'dokument', 'wynik', 'artykul', 'analiza', 'jezyk', 'tresc',
                'aktualizacja', 'sygnal', 'ranking', 'archiwum', 'redaktor', 'poradnik', 'usluga', 'test',
                'dane', 'przeplyw', 'jakosc', 'raport', 'strona', 'projekt', 'lista', 'kontekst',
            ],
        ],
        'pt' => [
            'name' => 'Portuguese',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'infraestrutura de busca'],
                ['id' => 'publishing', 'label' => 'publicacao editorial'],
                ['id' => 'commerce', 'label' => 'catalogo comercial'],
                ['id' => 'support', 'label' => 'suporte ao cliente'],
                ['id' => 'research', 'label' => 'pesquisa aplicada'],
                ['id' => 'culture', 'label' => 'cultura local'],
            ],
            'words' => [
                'busca', 'indice', 'documento', 'resultado', 'artigo', 'analise', 'idioma', 'conteudo',
                'atualizacao', 'sinal', 'ordem', 'arquivo', 'editor', 'guia', 'servico', 'teste',
                'dados', 'fluxo', 'qualidade', 'relatorio', 'pagina', 'projeto', 'lista', 'contexto',
            ],
        ],
        'ru' => [
            'name' => 'Russian',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'поисковая инфраструктура'],
                ['id' => 'publishing', 'label' => 'редакционная публикация'],
                ['id' => 'commerce', 'label' => 'каталог товаров'],
                ['id' => 'support', 'label' => 'поддержка клиентов'],
                ['id' => 'research', 'label' => 'прикладное исследование'],
                ['id' => 'culture', 'label' => 'местная культура'],
            ],
            'words' => [
                'поиск', 'индекс', 'документ', 'результат', 'статья', 'анализ', 'язык', 'контент',
                'обновление', 'сигнал', 'порядок', 'архив', 'редактор', 'руководство', 'сервис', 'тест',
                'данные', 'поток', 'качество', 'отчет', 'страница', 'проект', 'список', 'контекст',
            ],
        ],
        'ur' => [
            'name' => 'Urdu',
            'topics' => [
                ['id' => 'infrastructure', 'label' => 'تلاش کا ڈھانچا'],
                ['id' => 'publishing', 'label' => 'ادارتی اشاعت'],
                ['id' => 'commerce', 'label' => 'فہرست تجارت'],
                ['id' => 'support', 'label' => 'صارف مدد'],
                ['id' => 'research', 'label' => 'اطلاقی تحقیق'],
                ['id' => 'culture', 'label' => 'مقامی ثقافت'],
            ],
            'words' => [
                'تلاش', 'اشاریہ', 'دستاویز', 'نتیجہ', 'مضمون', 'تجزیہ', 'زبان', 'مواد',
                'تازہ', 'اشارہ', 'درجہ', 'محفوظ', 'مدیر', 'رہنما', 'خدمت', 'آزمائش',
                'اعداد', 'روانی', 'معیار', 'رپورٹ', 'صفحہ', 'منصوبہ', 'فہرست', 'سیاق',
            ],
        ],
        'zh' => [
            'name' => 'Chinese',
            'topics' => [
                ['id' => 'infrastructure', 'label' => '搜索基础设施'],
                ['id' => 'publishing', 'label' => '编辑发布'],
                ['id' => 'commerce', 'label' => '目录商务'],
                ['id' => 'support', 'label' => '客户支持'],
                ['id' => 'research', 'label' => '应用研究'],
                ['id' => 'culture', 'label' => '本地文化'],
            ],
            'words' => [
                '搜索', '索引', '文档', '结果', '文章', '分析', '语言', '内容',
                '更新', '信号', '排序', '档案', '编辑', '指南', '服务', '测试',
                '数据', '流程', '质量', '报告', '页面', '项目', '列表', '上下文',
            ],
        ],
    ];

    /** @var callable|null */
    private $clock;
    private ?bool $gzipAvailableOverride;

    /**
     * @param array{clock?:callable,gzip_available?:bool} $options
     */
    public function __construct(array $options = [])
    {
        $this->clock = is_callable($options['clock'] ?? null) ? $options['clock'] : null;
        $this->gzipAvailableOverride = array_key_exists('gzip_available', $options)
            ? (bool) $options['gzip_available']
            : null;
    }

    /**
     * @param array<int,string> $argv Arguments without the script name.
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $argv): array
    {
        $options = [];
        foreach ($argv as $arg) {
            $arg = (string) $arg;
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--smoke') {
                $options['smoke'] = true;
                continue;
            }
            if ($arg === '--plain') {
                $options['compression'] = 'plain';
                continue;
            }
            if ($arg === '--gzip') {
                $options['compression'] = 'gzip';
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                throw new InvalidArgumentException("Unexpected argument: {$arg}");
            }

            $equals = strpos($arg, '=');
            if ($equals === false) {
                throw new InvalidArgumentException("Expected --name=value or a supported flag, got {$arg}.");
            }

            $name = str_replace('-', '_', substr($arg, 2, $equals - 2));
            $value = substr($arg, $equals + 1);
            switch ($name) {
                case 'output':
                    $options['output'] = self::required_non_empty($value, 'output');
                    break;
                case 'seed':
                    $options['seed'] = self::required_non_empty($value, 'seed');
                    break;
                case 'english_docs':
                    $options['english_docs'] = self::parse_non_negative_int($value, 'english-docs');
                    break;
                case 'per_language_docs':
                    $options['per_language_docs'] = self::parse_non_negative_int($value, 'per-language-docs');
                    break;
                case 'languages':
                    $options['languages'] = self::parse_language_list($value);
                    break;
                case 'smoke':
                    $options['smoke'] = self::parse_bool($value, 'smoke');
                    break;
                case 'compression':
                    $value = strtolower(trim($value));
                    if (!in_array($value, ['auto', 'gzip', 'plain'], true)) {
                        throw new InvalidArgumentException('--compression must be auto, gzip, or plain.');
                    }
                    $options['compression'] = $value;
                    break;
                default:
                    throw new InvalidArgumentException("Unknown option: --" . str_replace('_', '-', $name));
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function generate(array $options): array
    {
        $normalized = $this->normalize_options($options);
        $started = $this->now();
        $this->prepare_output_directory($normalized['output']);

        $scope = self::derive_supported_language_scope(dirname(__DIR__));
        $shards = [];
        $totalDocuments = 0;
        $totalBytes = 0;
        foreach ($normalized['languages'] as $language) {
            $count = $language === 'en' ? $normalized['english_docs'] : $normalized['per_language_docs'];
            $shard = $this->write_language_shard(
                $normalized['output'],
                $normalized['seed'],
                $language,
                $count,
                $normalized['compression']
            );
            $shards[] = $shard;
            $totalDocuments += $shard['documents'];
            $totalBytes += $shard['bytes'];
        }

        $manifest = [
            'schema_version' => 1,
            'corpus_id' => self::CORPUS_ID,
            'seed' => $normalized['seed'],
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $started),
            'generation_time_seconds' => round(max(0.0, $this->now() - $started), 6),
            'output_format' => [
                'record' => 'jsonl',
                'encoding' => 'UTF-8',
                'compression' => $normalized['compression'],
            ],
            'requested_counts' => [
                'english_docs' => $normalized['english_docs'],
                'per_language_docs' => $normalized['per_language_docs'],
                'smoke' => $normalized['smoke'],
            ],
            'languages' => $normalized['languages'],
            'language_scope' => [
                'default_languages' => $scope['languages'],
                'generated_languages' => $normalized['languages'],
                'reasons' => $scope['reasons'],
                'sources' => $scope['sources'],
            ],
            'shards' => $shards,
            'totals' => [
                'documents' => $totalDocuments,
                'bytes' => $totalBytes,
            ],
        ];

        $manifestPath = $normalized['output'] . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->write_json_file($manifestPath, $manifest);
        $manifest['manifest_path'] = $manifestPath;

        return $manifest;
    }

    /**
     * @return array{
     *   languages:string[],
     *   reasons:array<string,string[]>,
     *   sources:array<string,mixed>
     * }
     */
    public static function derive_supported_language_scope(?string $pluginRoot = null): array
    {
        $pluginRoot ??= dirname(__DIR__);
        $readmePath = $pluginRoot . '/README.md';
        $stemmerPath = $pluginRoot . '/src/Stemmer.php';
        $pipelinePath = $pluginRoot . '/src/LanguagePipeline.php';
        $packRoot = $pluginRoot . '/resources/analyzer-packs';

        $readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';
        $stemmer = is_file($stemmerPath) ? (string) file_get_contents($stemmerPath) : '';
        $pipeline = is_file($pipelinePath) ? (string) file_get_contents($pipelinePath) : '';

        $topRouting = self::codes_from_paragraph($readme, 'baseline selectable and detectable routing set covers');
        $explicitPartitions = self::codes_from_paragraph($readme, 'other explicit partitions can be routed');
        if ($explicitPartitions === []) {
            $explicitPartitions = self::codes_from_paragraph($readme, 'Polish (`pl`), German (`de`), Russian (`ru`)');
        }
        $snowball = self::codes_from_snowball_stemmer($stemmer);
        $baseline = self::codes_from_baseline_stemmer($stemmer);
        $pipelineExplicit = self::codes_from_pipeline_explicit_routes($pipeline);
        $packLanguages = self::languages_from_analyzer_pack_manifests($packRoot);

        $reasons = [];
        self::add_language_reasons($reasons, $topRouting, 'README baseline selectable/detectable routing set');
        self::add_language_reasons($reasons, $explicitPartitions, 'README explicit conservative language partition');
        self::add_language_reasons($reasons, $snowball, 'WP_FTS_SnowballStemmer supported language');
        self::add_language_reasons($reasons, $baseline, 'WP_FTS_BaselineLanguageStemmer supported language');
        self::add_language_reasons($reasons, $pipelineExplicit, 'WP_FTS_LanguagePipeline explicit route');
        foreach ($packLanguages as $language => $packIds) {
            self::add_language_reasons($reasons, [$language], 'committed analyzer pack manifest: ' . implode(', ', $packIds));
        }

        $languages = self::ordered_languages(array_keys($reasons));
        foreach ($languages as $language) {
            $reasons[$language] = array_values(array_unique($reasons[$language]));
            sort($reasons[$language], SORT_STRING);
        }

        return [
            'languages' => $languages,
            'reasons' => $reasons,
            'sources' => [
                'readme' => $readmePath,
                'language_pipeline' => $pipelinePath,
                'stemmer' => $stemmerPath,
                'analyzer_pack_root' => $packRoot,
                'readme_top_routing_languages' => $topRouting,
                'readme_explicit_partition_languages' => $explicitPartitions,
                'snowball_languages' => $snowball,
                'baseline_languages' => $baseline,
                'pipeline_explicit_languages' => $pipelineExplicit,
                'analyzer_pack_languages' => $packLanguages,
            ],
        ];
    }

    public static function help_text(): string
    {
        return implode("\n", [
            'Usage: php tools/generate-large-search-corpus.php --output=/path/to/dir [options]',
            '',
            'Options:',
            '  --output=/path/to/dir        Required output directory.',
            '  --seed=value                 Deterministic seed. Default: ' . self::DEFAULT_SEED,
            '  --english-docs=100000        Documents for en when en is generated.',
            '  --per-language-docs=30000    Documents for each non-English language.',
            '  --languages=en,pl,zh         Override generated language partitions.',
            '  --smoke                      Small deterministic run for tests and previews.',
            '  --compression=auto|gzip|plain  Default auto uses gzip when zlib is available.',
            '  --plain / --gzip             Shortcuts for --compression=plain|gzip.',
            '',
            'Default languages are derived from README language support notes, the language',
            'pipeline/stemmer code, and committed analyzer-pack manifests. Generated data',
            'is JSONL, one shard per language, plus manifest.json.',
        ]) . "\n";
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   output:string,
     *   seed:string,
     *   english_docs:int,
     *   per_language_docs:int,
     *   languages:string[],
     *   smoke:bool,
     *   compression:string
     * }
     */
    private function normalize_options(array $options): array
    {
        $output = isset($options['output']) ? trim((string) $options['output']) : '';
        if ($output === '') {
            throw new InvalidArgumentException('Missing required --output=/path/to/dir.');
        }

        $smoke = (bool) ($options['smoke'] ?? false);
        $englishDocs = array_key_exists('english_docs', $options)
            ? self::parse_non_negative_int((string) $options['english_docs'], 'english-docs')
            : ($smoke ? self::SMOKE_ENGLISH_DOCS : self::DEFAULT_ENGLISH_DOCS);
        $perLanguageDocs = array_key_exists('per_language_docs', $options)
            ? self::parse_non_negative_int((string) $options['per_language_docs'], 'per-language-docs')
            : ($smoke ? self::SMOKE_PER_LANGUAGE_DOCS : self::DEFAULT_PER_LANGUAGE_DOCS);

        $languages = isset($options['languages'])
            ? self::normalize_language_list($options['languages'])
            : self::derive_supported_language_scope(dirname(__DIR__))['languages'];
        if ($languages === []) {
            throw new InvalidArgumentException('At least one language must be generated.');
        }

        $compression = strtolower((string) ($options['compression'] ?? 'auto'));
        if (!in_array($compression, ['auto', 'gzip', 'plain'], true)) {
            throw new InvalidArgumentException('--compression must be auto, gzip, or plain.');
        }
        if ($compression === 'auto') {
            $compression = $this->gzip_available() ? 'gzip' : 'plain';
        }
        if ($compression === 'gzip' && !$this->gzip_available()) {
            throw new RuntimeException('gzip compression was requested, but zlib gzip functions are unavailable.');
        }

        return [
            'output' => $output,
            'seed' => (string) ($options['seed'] ?? self::DEFAULT_SEED),
            'english_docs' => $englishDocs,
            'per_language_docs' => $perLanguageDocs,
            'languages' => $languages,
            'smoke' => $smoke,
            'compression' => $compression,
        ];
    }

    /**
     * @return array{language:string,path:string,documents:int,bytes:int,sha256:string,compression:string}
     */
    private function write_language_shard(string $output, string $seed, string $language, int $documents, string $compression): array
    {
        $relativePath = sprintf('search-corpus-%s.jsonl%s', $this->safe_filename_part($language), $compression === 'gzip' ? '.gz' : '');
        $path = $output . DIRECTORY_SEPARATOR . $relativePath;
        $handle = $compression === 'gzip' ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open shard for writing: {$path}");
        }

        try {
            for ($i = 0; $i < $documents; $i++) {
                $document = $this->build_document($seed, $language, $i);
                $line = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($line)) {
                    throw new RuntimeException('Could not encode corpus document.');
                }
                $line .= "\n";
                $written = $compression === 'gzip' ? gzwrite($handle, $line) : fwrite($handle, $line);
                if ($written === false || $written !== strlen($line)) {
                    throw new RuntimeException("Could not write corpus document to {$path}.");
                }
            }
        } finally {
            if ($compression === 'gzip') {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }

        $sha = hash_file('sha256', $path);
        $bytes = filesize($path);
        if (!is_string($sha) || !is_int($bytes)) {
            throw new RuntimeException("Could not finalize shard metadata for {$path}.");
        }

        return [
            'language' => $language,
            'path' => $relativePath,
            'documents' => $documents,
            'bytes' => $bytes,
            'sha256' => $sha,
            'compression' => $compression,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function build_document(string $seed, string $language, int $index): array
    {
        $profile = $this->language_profile($language);
        $topic = $this->pick($profile['topics'], $seed, $language, $index, 'topic');
        $sizeClass = $this->size_class($seed, $language, $index);
        $targetTokens = $this->target_token_count($seed, $language, $index, $sizeClass);
        $title = $this->title($profile, $topic, $seed, $language, $index);

        $paragraphs = [];
        $currentPlain = [];
        $currentHtml = [];
        $plainTokens = 0;
        $sentenceIndex = 0;
        while ($plainTokens < $targetTokens) {
            $sentence = $this->sentence($profile, $topic, $seed, $language, $index, $sentenceIndex);
            $currentPlain[] = $sentence['plain'];
            $currentHtml[] = $sentence['html'];
            $plainTokens += $sentence['tokens'];
            $sentenceIndex++;
            if (count($currentPlain) >= 4) {
                $paragraphs[] = [
                    'plain' => implode(' ', $currentPlain),
                    'html' => implode(' ', $currentHtml),
                ];
                $currentPlain = [];
                $currentHtml = [];
            }
        }
        if ($currentPlain !== []) {
            $paragraphs[] = [
                'plain' => implode(' ', $currentPlain),
                'html' => implode(' ', $currentHtml),
            ];
        }

        $plainContent = implode("\n\n", array_map(static fn(array $paragraph): string => $paragraph['plain'], $paragraphs));
        $htmlParagraphs = implode('', array_map(
            static fn(array $paragraph): string => '<p>' . $paragraph['html'] . '</p>',
            $paragraphs
        ));
        $contentHtml = sprintf(
            '<article lang="%s"><h1>%s</h1>%s</article>',
            htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $htmlParagraphs
        );

        return [
            'id' => sprintf('wpfts-%s-%012d-%s', $this->safe_filename_part($language), $index + 1, substr(hash('sha256', $seed . '|id|' . $language . '|' . $index), 0, 10)),
            'language' => $language,
            'title' => $title,
            'content_html' => $contentHtml,
            'plain_content' => $plainContent,
            'expected_visible_text' => $title . "\n\n" . $plainContent,
            'topic' => $topic['id'],
            'topic_label' => $topic['label'],
            'size_class' => $sizeClass,
            'approx_token_count' => $plainTokens,
            'source' => [
                'generator' => self::CORPUS_ID,
                'seed' => $seed,
                'sequence' => $index + 1,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array{id:string,label:string} $topic
     */
    private function title(array $profile, array $topic, string $seed, string $language, int $index): string
    {
        $words = [];
        for ($i = 0; $i < 4; $i++) {
            $words[] = $this->pick($profile['words'], $seed, $language, $index, 'title-' . $i);
        }

        return trim($topic['label'] . ' ' . implode(' ', $words) . ' ' . sprintf('%05d', $index + 1));
    }

    /**
     * @param array<string,mixed> $profile
     * @param array{id:string,label:string} $topic
     * @return array{plain:string,html:string,tokens:int}
     */
    private function sentence(array $profile, array $topic, string $seed, string $language, int $documentIndex, int $sentenceIndex): array
    {
        $wordCount = 10 + $this->number($seed, $language, $documentIndex, 'sentence-words-' . $sentenceIndex, 0, 8);
        $words = [$topic['label']];
        for ($i = 0; $i < $wordCount; $i++) {
            $words[] = $this->pick($profile['words'], $seed, $language, $documentIndex, 'sentence-' . $sentenceIndex . '-' . $i);
        }
        $words[] = sprintf('marker%s%06d', preg_replace('/[^a-z0-9]+/i', '', $language) ?: 'und', $documentIndex + 1);

        $plain = implode(' ', $words);
        $html = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($documentIndex % 7 === 0 && $sentenceIndex === 1) {
            $plain .= ' splitbenchmark';
            $html .= ' split<em>benchmark</em>';
        }
        if ($documentIndex % 11 === 0 && $sentenceIndex === 2) {
            $spanLanguage = $language === 'en' ? 'pl' : 'en';
            $spanText = $spanLanguage === 'pl' ? 'przenosny sygnal wyszukiwania' : 'portable search signal';
            $plain .= ' ' . $spanText;
            $html .= ' <span lang="' . $spanLanguage . '">' . htmlspecialchars($spanText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        return [
            'plain' => $plain . '.',
            'html' => $html . '.',
            'tokens' => $this->approx_tokens($plain),
        ];
    }

    /**
     * @param array<int,mixed> $items
     * @return mixed
     */
    private function pick(array $items, string $seed, string $language, int $index, string $purpose): mixed
    {
        return $items[$this->number($seed, $language, $index, $purpose, 0, count($items) - 1)];
    }

    private function size_class(string $seed, string $language, int $index): string
    {
        $bucket = $this->number($seed, $language, $index, 'size-class', 0, 99);
        if ($bucket < 20) {
            return 'short';
        }
        if ($bucket < 80) {
            return 'medium';
        }
        if ($bucket < 95) {
            return 'long';
        }

        return 'very_long';
    }

    private function target_token_count(string $seed, string $language, int $index, string $sizeClass): int
    {
        $range = self::SIZE_CLASSES[$sizeClass] ?? self::SIZE_CLASSES['short'];

        return $this->number($seed, $language, $index, 'target-token-count', $range[0], $range[1]);
    }

    private function number(string $seed, string $language, int $index, string $purpose, int $min, int $max): int
    {
        if ($max < $min) {
            throw new InvalidArgumentException('Invalid deterministic number range.');
        }
        $span = $max - $min + 1;
        $hex = substr(hash('sha256', $seed . '|' . $language . '|' . $index . '|' . $purpose), 0, 12);
        $number = (int) hexdec($hex);

        return $min + ($number % $span);
    }

    /**
     * @return array{name:string,topics:array<int,array{id:string,label:string}>,words:string[]}
     */
    private function language_profile(string $language): array
    {
        $base = explode('-', $language, 2)[0];
        if (isset(self::LANGUAGE_PROFILES[$base])) {
            return self::LANGUAGE_PROFILES[$base];
        }

        return [
            'name' => $language,
            'topics' => self::LANGUAGE_PROFILES['en']['topics'],
            'words' => array_merge(self::LANGUAGE_PROFILES['en']['words'], ['customlang' . preg_replace('/[^a-z0-9]+/i', '', $language)]),
        ];
    }

    private function approx_tokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text));
        if (!is_array($parts)) {
            return max(1, substr_count($text, ' ') + 1);
        }

        return max(1, count(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function prepare_output_directory(string $directory): void
    {
        if (is_file($directory)) {
            throw new RuntimeException("Output path is a file: {$directory}");
        }
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create output directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Output directory is not writable: {$directory}");
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write_json_file(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException("Could not encode JSON file: {$path}");
        }
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Could not write JSON file: {$path}");
        }
    }

    private function safe_filename_part(string $language): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $language) ?? 'und';
        $safe = trim($safe, '-_');

        return $safe === '' ? 'und' : $safe;
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return (float) ($this->clock)();
        }

        return microtime(true);
    }

    private function gzip_available(): bool
    {
        if ($this->gzipAvailableOverride !== null) {
            return $this->gzipAvailableOverride;
        }

        return WP_FTS_AnalyzerPackValidator::gzip_available() && function_exists('gzwrite');
    }

    private static function required_non_empty(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("--{$name} must not be empty.");
        }

        return $value;
    }

    private static function parse_non_negative_int(string $value, string $name): int
    {
        $value = trim($value);
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$name} must be a non-negative integer.");
        }

        return (int) $value;
    }

    private static function parse_bool(string $value, string $name): bool
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException("--{$name} must be true or false.");
    }

    /**
     * @return string[]
     */
    private static function parse_language_list(string $value): array
    {
        return self::normalize_language_list(explode(',', $value));
    }

    /**
     * @param mixed $languages
     * @return string[]
     */
    private static function normalize_language_list(mixed $languages): array
    {
        if (!is_array($languages)) {
            throw new InvalidArgumentException('--languages must be a comma-separated list or array.');
        }

        $normalized = [];
        foreach ($languages as $language) {
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            if ($language === 'und') {
                throw new InvalidArgumentException('Language list contains an empty or invalid language.');
            }
            $normalized[$language] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return string[]
     */
    private static function codes_from_paragraph(string $text, string $needle): array
    {
        $position = strpos($text, $needle);
        if ($position === false) {
            return [];
        }
        $end = strpos($text, "\n\n", $position);
        $paragraph = $end === false ? substr($text, $position) : substr($text, $position, $end - $position);

        return self::codes_from_text($paragraph);
    }

    /**
     * @return string[]
     */
    private static function codes_from_text(string $text): array
    {
        preg_match_all('/`([a-z]{2,3}(?:-[A-Za-z0-9]+)?)`/', $text, $matches);

        return self::ordered_languages($matches[1] ?? []);
    }

    /**
     * @return string[]
     */
    private static function codes_from_snowball_stemmer(string $stemmer): array
    {
        if (preg_match('/supportedLanguages\s*=\s*array_fill_keys\(\s*\[(.*?)\]\s*,\s*true\s*\)/s', $stemmer, $match) !== 1) {
            return [];
        }

        preg_match_all("/'([a-z]{2,3})'/", $match[1], $matches);

        return self::ordered_languages($matches[1] ?? []);
    }

    /**
     * @return string[]
     */
    private static function codes_from_baseline_stemmer(string $stemmer): array
    {
        if (preg_match('/return\s+match\s*\([^)]*\)\s*\{(.*?)default\s*=>/s', $stemmer, $match) !== 1) {
            return [];
        }

        preg_match_all("/'([a-z]{2,3})'\s*=>/", $match[1], $matches);

        return self::ordered_languages($matches[1] ?? []);
    }

    /**
     * @return string[]
     */
    private static function codes_from_pipeline_explicit_routes(string $pipeline): array
    {
        $languages = [];
        if (str_contains($pipeline, "\$base === 'pl'")) {
            $languages[] = 'pl';
        }
        if (preg_match('/in_array\(\$base,\s*\[(.*?)\]/s', $pipeline, $match) === 1) {
            preg_match_all("/'([a-z]{2,3})'/", $match[1], $matches);
            $languages = array_merge($languages, $matches[1] ?? []);
        }

        return self::ordered_languages($languages);
    }

    /**
     * @return array<string,string[]>
     */
    private static function languages_from_analyzer_pack_manifests(string $packRoot): array
    {
        $paths = glob($packRoot . '/*/manifest.json');
        if (!is_array($paths)) {
            return [];
        }

        $languages = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $json = file_get_contents($path);
            $manifest = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($manifest) || !is_scalar($manifest['language'] ?? null)) {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $manifest['language']);
            if ($language === 'und') {
                continue;
            }
            $packId = is_scalar($manifest['pack_id'] ?? null) ? (string) $manifest['pack_id'] : basename(dirname($path));
            $languages[$language][$packId] = true;
        }

        foreach ($languages as $language => $packIds) {
            $languages[$language] = array_keys($packIds);
            sort($languages[$language], SORT_STRING);
        }
        ksort($languages, SORT_STRING);

        return $languages;
    }

    /**
     * @param array<string,string[]> $reasons
     * @param string[] $languages
     */
    private static function add_language_reasons(array &$reasons, array $languages, string $reason): void
    {
        foreach ($languages as $language) {
            $language = WP_FTS_TermNamespace::canonicalize_lang($language);
            if ($language === 'und') {
                continue;
            }
            $reasons[$language][] = $reason;
        }
    }

    /**
     * @param string[] $languages
     * @return string[]
     */
    private static function ordered_languages(array $languages): array
    {
        $set = [];
        foreach ($languages as $language) {
            $language = WP_FTS_TermNamespace::canonicalize_lang($language);
            if ($language !== 'und') {
                $set[$language] = true;
            }
        }

        $ordered = [];
        foreach (self::DEFAULT_LANGUAGE_ORDER as $language) {
            if (isset($set[$language])) {
                $ordered[] = $language;
                unset($set[$language]);
            }
        }

        $rest = array_keys($set);
        sort($rest, SORT_STRING);

        return array_merge($ordered, $rest);
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_LargeSearchCorpusGenerator::parse_cli_options(array_slice($argv, 1));
        if ((bool) ($options['help'] ?? false)) {
            fwrite(STDOUT, WP_FTS_LargeSearchCorpusGenerator::help_text());
            exit(0);
        }

        $manifest = (new WP_FTS_LargeSearchCorpusGenerator())->generate($options);
        $summary = [
            'status' => 'ok',
            'manifest' => $manifest['manifest_path'],
            'languages' => $manifest['languages'],
            'documents' => $manifest['totals']['documents'],
            'bytes' => $manifest['totals']['bytes'],
            'compression' => $manifest['output_format']['compression'],
        ];
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, ($json === false ? '{"status":"ok"}' : $json) . "\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n\n" . WP_FTS_LargeSearchCorpusGenerator::help_text());
        exit(1);
    }
}
