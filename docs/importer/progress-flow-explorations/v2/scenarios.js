window.ImporterPrototypeData = {
	metadata: {
		version: 1,
		productName: "Universal WordPress Importer",
		adminPath: "Tools > Universal Importer",
		adminUrl: "wp-admin/tools.php?page=universal-wordpress-importer",
		modelPurpose: "Content and state model for the importer progress-flow prototype.",
		behaviorNotes: [
			"Browser keepalive runs importer work through the same durable runner used by cron and CLI.",
			"GitHub repository imports currently queue files through sparse Git. They do not fall back to tree/blob, Contents API, or zipball imports.",
			"Dry runs still discover sources, prepare documents, infer URL treatment, and can pause for a URL decision, but they skip WordPress media and page writes.",
			"The current admin default writes published pages unless Import as drafts is checked."
		]
	},

	firstScreen: {
		title: "Import content into WordPress",
		intro: "Paste a source, browse a GitHub folder, or choose files from this computer. Start with a dry run when you want to inspect what WordPress would create before anything is written.",
		sourceInputLabel: "Source",
		sourceInputPlaceholder: "Paste a URL, GitHub repository, server path, feed, OPML file, WXR file, or document path",
		primaryActions: {
			default: "Start import",
			dryRun: "Start dry run",
			drafts: "Import as drafts",
			publish: "Import and publish pages"
		},
		defaultChoices: [
			{
				id: "url-treatment",
				label: "Ask when old URLs are found",
				value: "ask_when_found",
				behavior: "No URL decision is saved until the importer detects old domains. If domains are entered before start, the decision is saved immediately for those domains."
			},
			{
				id: "post-status",
				label: "Publish pages",
				value: "publish",
				behavior: "This is the current admin default. Checking Import as drafts stores draft as the initial post status."
			},
			{
				id: "dry-run",
				label: "Write to WordPress",
				value: "write",
				behavior: "Unchecked Dry run means media and pages can be created after source reading, URL treatment, and preparation are complete."
			}
		],
		setupSummaryFields: [
			"source label",
			"source type",
			"dry run or write",
			"publish or draft status",
			"URL treatment mode"
		]
	},

	sourceExamples: [
		{
			id: "github-docs-folder",
			sourceTypeId: "github_repository",
			label: "GitHub docs folder",
			value: "https://github.com/adamziel/wp-extensions/tree/main/universal-wordpress-importer/docs",
			shortcutLabel: "GitHub repo",
			helper: "Use Browse repository folders when the source points to a GitHub repository. The selected folder is written back into the source field before the import starts.",
			expectedResult: "Markdown and HTML documents are discovered from the selected repository path and imported as WordPress pages.",
			defaultOptions: {
				urlTreatment: "ask_when_found",
				importAsDrafts: true,
				dryRun: true
			},
			features: [
				"github-folder-browser",
				"dry-run",
				"durable-progress"
			]
		},
		{
			id: "wordpress-site",
			sourceTypeId: "wordpress_site",
			label: "WordPress site",
			value: "https://example.com/",
			shortcutLabel: "WordPress site",
			helper: "A WordPress homepage can lead to REST import when REST is discoverable. If it is not, the importer can fall back to a feed or a single remote document.",
			expectedResult: "Posts, pages, media, comments, terms, and exposed relationships are prepared when the source exposes them.",
			defaultOptions: {
				urlTreatment: "ask_when_found",
				importAsDrafts: true,
				dryRun: true
			},
			features: [
				"url-treatment",
				"relationship-decisions",
				"review-imported-content"
			]
		},
		{
			id: "feed-or-opml",
			sourceTypeId: "feed_or_opml",
			label: "Feed or OPML",
			value: "https://example.com/feed/",
			shortcutLabel: "Feed or OPML",
			helper: "RSS, Atom, RDF, and OPML sources are useful for current feed items and subscription lists.",
			expectedResult: "One prepared document is created for each importable feed item.",
			defaultOptions: {
				urlTreatment: "keep_unchanged",
				importAsDrafts: true,
				dryRun: true
			},
			features: [
				"dry-run",
				"technical-details"
			]
		},
		{
			id: "server-path-archive",
			sourceTypeId: "server_path",
			label: "Server path or archive",
			value: "/srv/imports/site-export.zip",
			shortcutLabel: "Server path",
			helper: "Server-side paths can point to files, folders, or archives already available to WordPress.",
			expectedResult: "The source walker expands archives and queues importable Markdown, HTML, text, EPUB, PDF, WXR, and feed files.",
			defaultOptions: {
				urlTreatment: "ask_when_found",
				importAsDrafts: true,
				dryRun: false
			},
			features: [
				"archive-expansion",
				"url-treatment",
				"abort-session"
			]
		},
		{
			id: "browser-folder",
			sourceTypeId: "browser_folder",
			label: "Browser folder",
			value: "",
			shortcutLabel: "Browser folder",
			helper: "Choosing files in the browser stages them under the importer cache. While files are selected, the source field is no longer required and GitHub browsing is hidden.",
			expectedResult: "Selected local files are uploaded into a durable import session and then handled like a local filesystem import.",
			defaultOptions: {
				urlTreatment: "ask_when_found",
				importAsDrafts: true,
				dryRun: true
			},
			features: [
				"browser-upload-tree",
				"upload-validation",
				"durable-progress"
			]
		},
		{
			id: "wxr-export",
			sourceTypeId: "wxr",
			label: "WordPress export file",
			value: "/srv/imports/wordpress-export.xml",
			shortcutLabel: "Server path",
			helper: "WXR imports can include posts, pages, attachments, comments, menu data, metadata, authors, terms, and old URLs.",
			expectedResult: "WordPress export entities are prepared, then URL, media, relationship, and navigation work continues through the same session runner.",
			defaultOptions: {
				urlTreatment: "ask_when_found",
				importAsDrafts: true,
				dryRun: true
			},
			features: [
				"url-treatment",
				"relationship-decisions",
				"media-review"
			]
		}
	],

	discoverableFeatures: [
		{
			id: "github-folder-browser",
			label: "Browse repository folders",
			surface: "source helper",
			trigger: "Visible when the source looks like a GitHub repository URL and no browser files are selected.",
			shortCopy: "Choose a repository folder before starting the import.",
			explanationId: "github-import"
		},
		{
			id: "browser-upload-tree",
			label: "Folder upload preview",
			surface: "source picker",
			trigger: "Visible after files or folders are selected in the browser.",
			shortCopy: "Preview selected files before the durable session is created.",
			explanationId: "browser-import"
		},
		{
			id: "dry-run",
			label: "Dry run",
			surface: "start options",
			trigger: "Available before starting any import.",
			shortCopy: "Discover and prepare content without writing WordPress posts or attachments.",
			explanationId: "dry-run"
		},
		{
			id: "import-as-drafts",
			label: "Import as drafts",
			surface: "start options",
			trigger: "Available before starting any non-dry-run import.",
			shortCopy: "Store draft as the initial WordPress page status.",
			explanationId: "post-status"
		},
		{
			id: "url-treatment",
			label: "URL treatment",
			surface: "start options and run blocker",
			trigger: "Available before start and shown again if the runner finds old first-party URLs without a saved decision.",
			shortCopy: "Choose whether old domains are rewritten to the current site URL.",
			explanationId: "url-treatment"
		},
		{
			id: "durable-progress",
			label: "Durable progress",
			surface: "run status",
			trigger: "Shown immediately after a session is created or when returning to an active session.",
			shortCopy: "The import can keep advancing through browser keepalive and scheduled runner ticks.",
			explanationId: "durable-progress"
		},
		{
			id: "relationship-decisions",
			label: "Relationship decisions",
			surface: "needs action panel",
			trigger: "Shown when imported REST or WXR relationships cannot be mapped automatically.",
			shortCopy: "Resolve missing author, term, or related object mappings before final completion.",
			explanationId: "relationship-decisions"
		},
		{
			id: "media-review",
			label: "Media review",
			surface: "run status and result",
			trigger: "Shown when queued media exists or media failures need attention.",
			shortCopy: "Separate page creation from media download and attachment review.",
			explanationId: "media"
		},
		{
			id: "abort-session",
			label: "Abort",
			surface: "run controls",
			trigger: "Available until a session is complete or already aborted.",
			shortCopy: "Stop future runner work for this session.",
			explanationId: "abort"
		},
		{
			id: "review-imported-content",
			label: "Review imported content",
			surface: "finish state",
			trigger: "Shown when a completed session has persisted WordPress pages.",
			shortCopy: "Open the Pages list filtered to this import session.",
			explanationId: "review"
		},
		{
			id: "technical-details",
			label: "Technical details",
			surface: "run details",
			trigger: "Available from the progress card.",
			shortCopy: "Inspect source counts, prepared documents, media references, remote backoff, warnings, and recent events.",
			explanationId: "technical-details"
		}
	],

	featureExplanations: {
		"github-import": {
			title: "GitHub imports use sparse Git",
			summary: "The picker helps choose a repository folder, then the import itself fetches files through sparse Git.",
			behavior: [
				"Before the first worker response, the run can show Starting with the action Queued to fetch GitHub repository files.",
				"While sparse Git is active, the run shows Fetching and the action Fetching repository files with sparse Git.",
				"File counts are unknown until repository files have been queued."
			],
			caveat: "If sparse Git cannot queue files, the run records a traversal failure. The current implementation does not use tree/blob, Contents API, or zipball fallback imports."
		},
		"browser-import": {
			title: "Browser files become a staged local source",
			summary: "Selected files are uploaded into importer cache and then processed by the local filesystem path.",
			behavior: [
				"Files are sent with parallel path data.",
				"The source input is ignored while browser files are selected.",
				"The importer validates file count, total upload size, duplicate normalized paths, parent-directory segments, and unreadable upload rows."
			],
			caveat: "Large uploads can wait before the durable session exists, so the prototype should make upload-in-progress visible."
		},
		"dry-run": {
			title: "Dry run is still a real scan",
			summary: "Dry run avoids WordPress writes but still performs source discovery, preparation, URL inference, media reference detection, and final summaries.",
			behavior: [
				"Media attachment writes are skipped.",
				"Page writes are skipped and the stage reads Dry run: no pages written.",
				"A dry run can still pause on URL treatment before it can finish."
			],
			caveat: "Do not describe dry run as passive preview only. It can still do importer-state work and ask for decisions."
		},
		"post-status": {
			title: "Post status is decided before writing pages",
			summary: "The start form saves a resolved import-post-status decision.",
			behavior: [
				"Import as drafts stores draft.",
				"Leaving it unchecked stores publish.",
				"The progress card labels the mode as Dry run, Creates drafts, or Publishes pages after the run starts."
			],
			caveat: "The current admin default publishes pages. Public docs often describe a draft-first flow, so the prototype should make the selected consequence visible before start."
		},
		"url-treatment": {
			title: "URL treatment can block the run",
			summary: "The importer asks what to do with old first-party domains before media, link rewriting, and page writes continue.",
			behavior: [
				"Ask when old URLs are found saves no decision unless domains are entered before start.",
				"Keep URLs unchanged saves an empty confirmed-domain decision and prevents later URL prompts.",
				"Rewrite listed domains requires domains and saves them as confirmed exact hosts.",
				"Confirmed HTTP and HTTPS URLs are rewritten to the current site URL while path, query, and fragment are preserved.",
				"Unconfirmed or outside hosts stay unchanged."
			],
			caveat: "The decision UI should say that the importer is paused until the URL choice is saved."
		},
		"durable-progress": {
			title: "The progress card drives work",
			summary: "The browser does more than poll. Keepalive requests can run bounded importer ticks and then refresh the snapshot.",
			behavior: [
				"Keepalive starts immediately after session creation and repeats while work remains.",
				"Keepalive stops for terminal states, pending decisions, failed attention states, or when no runnable work remains.",
				"The same runner model is shared by browser keepalive, WP-Cron, and WP-CLI."
			],
			caveat: "For long waits, show latest action, elapsed or last update copy, and the fact that the user can return later."
		},
		"relationship-decisions": {
			title: "Some mappings need a decision after pages exist",
			summary: "REST or WXR relationship warnings can be raised after a page has already been written.",
			behavior: [
				"The run can show Reviewing relationships after Write pages.",
				"Generic decisions currently use an editable JSON answer.",
				"Resolved decisions are applied by later runner ticks."
			],
			caveat: "This is a partial-completion moment, not a simple preflight warning."
		},
		media: {
			title: "Media is tracked separately from prepared documents",
			summary: "Media references can be queued, imported, skipped, or failed independently of page preparation.",
			behavior: [
				"Queued media makes Import media the active stage.",
				"Failed media blocks the stage and says drafts may still exist, but media references need review.",
				"Dry run detects media references but skips attachment writes."
			],
			caveat: "Do not imply that pages and media always finish at the same time."
		},
		abort: {
			title: "Abort is terminal and not a rollback",
			summary: "Abort marks the session aborted and stops future session work.",
			behavior: [
				"Abort is available until a session is done or already aborted.",
				"Already persisted posts, attachments, importer rows, and staged files are not deleted."
			],
			caveat: "Place rollback expectations near the control if the prototype exposes Abort prominently."
		},
		review: {
			title: "Finished imports can link to filtered Pages",
			summary: "When a completed session has persisted posts, the admin screen links to WordPress Pages filtered by import session id.",
			behavior: [
				"The review URL is wp-admin/edit.php?post_type=page&universal_importer_session_id=<session-id>.",
				"The link is not shown for dry runs, zero-post completions, incomplete sessions, or purely diagnostic results."
			],
			caveat: "A result summary should come before asking the user to inspect the Pages list."
		},
		"technical-details": {
			title: "Technical details contain troubleshooting evidence",
			summary: "The details disclosure exposes the raw signals behind the compact progress view.",
			behavior: [
				"Source item counts and recent source items.",
				"Prepared documents, persisted posts, comments, media references, and relationship warnings.",
				"Remote backoff, PDF/OCR notes, EPUB tables of contents, and recent event messages."
			],
			caveat: "The main path should still summarize blockers and results so users are not forced into technical details."
		}
	},

	sourceTypes: [
		{
			id: "github_repository",
			label: "GitHub repository",
			shortcutLabel: "GitHub repo",
			accepts: [
				"https://github.com/<owner>/<repo>",
				"https://github.com/<owner>/<repo>/tree/<ref>/<path>"
			],
			discoveryBehavior: "The folder browser resolves repository directories through the GitHub API. The import run queues files through php-toolkit sparse Git.",
			prepares: [
				"Markdown",
				"HTML",
				"text",
				"EPUB",
				"PDF",
				"WXR",
				"feeds",
				"archives found in the tree"
			],
			firstTimelineStepId: "github_sparse_fetch_pending",
			knownLimitations: [
				"File count is unknown until sparse Git queues repository files.",
				"Direct root URLs can be less predictable than URLs selected through the folder browser.",
				"No tree/blob, Contents API, or zipball fallback is used when sparse Git fails."
			]
		},
		{
			id: "wordpress_site",
			label: "WordPress site",
			shortcutLabel: "WordPress site",
			accepts: [
				"https://example.com/",
				"https://example.com/wp-json/wp/v2"
			],
			discoveryBehavior: "Remote HTTP sources try WordPress REST first when available, then can fall back to an advertised feed or a single remote document.",
			prepares: [
				"posts",
				"pages",
				"featured media",
				"comments",
				"terms",
				"relationships exposed by REST"
			],
			firstTimelineStepId: "file_discovery",
			knownLimitations: [
				"Authentication and unavailable REST endpoints can limit what is discoverable.",
				"Relationship mapping can pause after pages have been written."
			]
		},
		{
			id: "feed_or_opml",
			label: "Feed or OPML",
			shortcutLabel: "Feed or OPML",
			accepts: [
				"RSS",
				"Atom",
				"RDF",
				"OPML"
			],
			discoveryBehavior: "The source walker queues feed or OPML items as importable source items.",
			prepares: [
				"one prepared document per importable feed item",
				"subscription entries from OPML where supported"
			],
			firstTimelineStepId: "file_discovery",
			knownLimitations: [
				"Feeds usually expose current items, not a full historical archive."
			]
		},
		{
			id: "server_path",
			label: "Server path",
			shortcutLabel: "Server path",
			accepts: [
				"absolute server file paths",
				"absolute server directory paths",
				"archives already available to WordPress"
			],
			discoveryBehavior: "Local filesystem walking queues files and expands discovered ZIP archives into cache-backed child source items.",
			prepares: [
				"Markdown",
				"HTML",
				"text",
				"EPUB",
				"PDF",
				"WXR",
				"feed files"
			],
			firstTimelineStepId: "file_discovery",
			knownLimitations: [
				"Paths must be readable by the WordPress process.",
				"Source failures block continuation and require a corrected source plus a new import."
			]
		},
		{
			id: "browser_folder",
			label: "Browser folder",
			shortcutLabel: "Browser folder",
			accepts: [
				"selected files",
				"selected directories",
				"dragged files and folders"
			],
			discoveryBehavior: "Files are uploaded and staged under browser-uploads/<session-id>/tree, then processed as a local directory.",
			prepares: [
				"Markdown",
				"HTML",
				"text",
				"EPUB",
				"PDF",
				"WXR",
				"feed files",
				"archives"
			],
			firstTimelineStepId: "upload_staging",
			knownLimitations: [
				"Current validation rejects empty uploads, more than 500 files, more than 128 MiB, duplicate normalized paths, parent-directory segments, unsafe empty path segments, and unreadable rows.",
				"Upload can be a noticeable wait before the durable session exists."
			]
		},
		{
			id: "remote_document",
			label: "Remote document",
			shortcutLabel: "WordPress site",
			accepts: [
				"remote HTML page URL",
				"remote document URL",
				"remote archive URL"
			],
			discoveryBehavior: "Remote HTTP sources that are not GitHub or REST-capable WordPress sources can be treated as single remote documents.",
			prepares: [
				"HTML",
				"Markdown",
				"text",
				"EPUB",
				"PDF",
				"archives when supported by the source path"
			],
			firstTimelineStepId: "file_discovery",
			knownLimitations: [
				"Remote backoff can pause progress while the source is retried."
			]
		},
		{
			id: "wxr",
			label: "WXR export",
			shortcutLabel: "Server path",
			accepts: [
				"WordPress export XML",
				"WXR files inside archives",
				"WXR files from uploaded folders"
			],
			discoveryBehavior: "WXR files are prepared into WordPress entities and can create URL, media, menu, metadata, comments, attachment-parent, author, term, and relationship work.",
			prepares: [
				"posts",
				"pages",
				"attachments",
				"comments",
				"menus",
				"metadata",
				"terms",
				"relationship mappings"
			],
			firstTimelineStepId: "file_discovery",
			knownLimitations: [
				"WXR may pause for URL treatment or relationship mapping.",
				"Final completion waits for post writes, media, comments, navigation, metadata, attachment parent updates, and relationship work to settle."
			]
		}
	],

	urlTreatmentChoices: [
		{
			id: "ask_when_found",
			label: "Ask when old URLs are found",
			savesDecisionAtStart: false,
			requiresDomainsAtStart: false,
			runBehavior: "If old domains are detected and no decision exists, the run pauses at URL treatment."
		},
		{
			id: "keep_unchanged",
			label: "Keep URLs unchanged",
			savesDecisionAtStart: true,
			requiresDomainsAtStart: false,
			runBehavior: "Saves an empty confirmed-domain decision so later URL prompts are skipped."
		},
		{
			id: "rewrite_listed",
			label: "Rewrite listed domains",
			savesDecisionAtStart: true,
			requiresDomainsAtStart: true,
			runBehavior: "Saves listed domains as confirmed exact hosts. Matching absolute HTTP and HTTPS URLs are rewritten to the current site URL."
		}
	],

	importRunTimeline: [
		{
			id: "upload_staging",
			label: "Upload selected files",
			appliesToSourceTypeIds: [
				"browser_folder"
			],
			checklistKey: "read_source",
			statusLabel: "Creating session",
			state: "active",
			currentAction: "Uploading selected files to create an import session.",
			progressMode: "indeterminate",
			userMeaning: "The browser files are being validated and staged before the durable importer session exists.",
			countsShown: [
				"selected file count",
				"total selected size"
			],
			events: [
				"upload.started",
				"session.created"
			],
			nextStepId: "file_discovery"
		},
		{
			id: "github_sparse_fetch_pending",
			label: "GitHub sparse fetch pending",
			appliesToSourceTypeIds: [
				"github_repository"
			],
			checklistKey: "read_source",
			statusLabel: "Starting",
			state: "active",
			currentAction: "Queued to fetch GitHub repository files.",
			progressMode: "indeterminate",
			progressNote: "File count appears after GitHub repository discovery.",
			stageDetail: "Waiting to fetch repository files from GitHub.",
			userMeaning: "The session exists, but the first sparse Git worker response has not queued files yet.",
			events: [
				"session.created",
				"github.traversal_queued"
			],
			nextStepId: "github_sparse_fetch_active"
		},
		{
			id: "github_sparse_fetch_active",
			label: "Fetch repository files",
			appliesToSourceTypeIds: [
				"github_repository"
			],
			checklistKey: "read_source",
			statusLabel: "Fetching",
			state: "active",
			currentAction: "Fetching repository files with sparse Git.",
			progressMode: "indeterminate",
			progressNote: "Fetching repository files; file count appears after discovery.",
			stageDetail: "Fetching repository files with sparse Git.",
			userMeaning: "Sparse Git is pulling the selected repository path into importer state.",
			events: [
				"github.git_fetching"
			],
			nextStepId: "file_discovery"
		},
		{
			id: "file_discovery",
			label: "File discovery",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "read_source",
			statusLabel: "running",
			state: "active",
			currentAction: "Reading the source.",
			progressMode: "determinate when source total is known",
			stageDetail: "{sourceTotal} source items found.",
			userMeaning: "The importer is walking files, remote items, feeds, REST cursors, or archive contents and queueing source items.",
			countsShown: [
				"source_items.total",
				"source_items.statuses.queued",
				"source_items.statuses.processing",
				"source_items.statuses.discovered",
				"source_items.statuses.failed"
			],
			events: [
				"source.discovered",
				"archive.expanded",
				"remote.backoff"
			],
			blockedState: {
				when: "source_items.statuses.failed > 0",
				currentAction: "{failedCount} source item needs attention.",
				attentionMessage: "{failedCount} source item failed. The importer will not continue until the source problem is corrected and a new import is started."
			},
			nextStepId: "prepare_content"
		},
		{
			id: "prepare_content",
			label: "Prepare content",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "prepare_content",
			statusLabel: "running",
			state: "active",
			currentAction: "Preparing content.",
			progressMode: "determinate when source item total is known",
			stageDetail: "Preparing {discoveredCount} item.",
			doneDetail: "{documentTotal} documents ready.",
			emptyDetail: "No importable documents found.",
			userMeaning: "Importable documents are converted into prepared WordPress-ready documents before URL, media, and write work continue.",
			countsShown: [
				"prepared_documents.total",
				"prepared_documents.recent",
				"progress.completed",
				"progress.total"
			],
			events: [
				"document.prepared",
				"pdf.extracted",
				"epub.toc_discovered"
			],
			nextStepId: "url_treatment_decision"
		},
		{
			id: "url_treatment_decision",
			label: "URL treatment decision",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "url_treatment",
			statusLabel: "running",
			state: "blocked when pending_decisions contains confirm-first-party-domains",
			currentAction: "Choose URL treatment to continue.",
			attentionMessage: "Answer the prompt below to continue the import.",
			progressMode: "paused",
			stageDetail: "Choose how old URLs should be handled.",
			doneDetail: "URL choice is set.",
			userMeaning: "The importer found old first-party URL candidates and needs to know which domains should be rewritten to this site.",
			decision: {
				key: "confirm-first-party-domains",
				choices: [
					"Rewrite selected domains",
					"Yes, rewrite all",
					"No, keep all URLs"
				],
				answerShape: {
					confirmed_domains: [
						"example.com"
					]
				}
			},
			events: [
				"url.confirmation_required",
				"decision.resolved"
			],
			nextStepId: "import_media"
		},
		{
			id: "import_media",
			label: "Import media",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "import_media",
			statusLabel: "running",
			state: "active",
			currentAction: "Importing media.",
			progressMode: "determinate when queued media total is known",
			stageDetail: "{queuedMediaCount} media items queued.",
			doneDetail: "{mediaTotal} media items imported.",
			emptyDetail: "No media found.",
			userMeaning: "Confirmed media references are downloaded or attached before page writes and link fixes settle.",
			countsShown: [
				"media.total",
				"media.statuses.queued",
				"media.statuses.failed"
			],
			events: [
				"media.queued",
				"media.imported",
				"media.failed"
			],
			blockedState: {
				when: "media.statuses.failed > 0",
				currentAction: "{failedCount} media item needs attention.",
				attentionMessage: "{failedCount} media item failed. Drafts may still exist, but media references need review."
			},
			dryRunBehavior: "Media references can be detected, but attachment writes are skipped.",
			nextStepId: "write_pages"
		},
		{
			id: "write_pages",
			label: "Write pages",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "write_pages",
			statusLabel: "running",
			state: "active",
			currentAction: "Writing pages.",
			progressMode: "determinate when prepared document total is known",
			stageDetail: "{persistedPostCount} of {documentTotal} pages written.",
			dryRunDetail: "Dry run: no pages written.",
			userMeaning: "Prepared documents are persisted as WordPress page posts using the saved post-status decision.",
			countsShown: [
				"prepared_documents.total",
				"posts.persisted"
			],
			events: [
				"post.persisted",
				"session.dry_run_write_skipped",
				"post.relationships_partially_mapped"
			],
			postStatusModes: [
				{
					id: "draft",
					label: "Creates drafts",
					source: "Import as drafts is checked"
				},
				{
					id: "publish",
					label: "Publishes pages",
					source: "Import as drafts is unchecked"
				},
				{
					id: "dry_run",
					label: "Dry run",
					source: "Dry run is checked"
				}
			],
			nextStepId: "finish_review_drafts"
		},
		{
			id: "finish_review_drafts",
			label: "Finish and review drafts",
			appliesToSourceTypeIds: [
				"github_repository",
				"wordpress_site",
				"feed_or_opml",
				"server_path",
				"browser_folder",
				"remote_document",
				"wxr"
			],
			checklistKey: "finish",
			statusLabel: "done",
			state: "done",
			currentAction: "Import complete.",
			progressMode: "complete",
			stageDetail: "Complete.",
			activeDetail: "Final checks.",
			userMeaning: "The run is complete only after traversal, decisions, media, posts, links, comments, menus, metadata, attachment parents, and relationship mappings are settled.",
			countsShown: [
				"posts.persisted",
				"prepared_documents.total",
				"media.total",
				"progress.errors"
			],
			events: [
				"session.done"
			],
			reviewAction: {
				label: "View imported content",
				condition: "status is done and posts.persisted > 0",
				urlPattern: "wp-admin/edit.php?post_type=page&universal_importer_session_id=<session-id>"
			}
		}
	],

	scenarios: [
		{
			id: "github-docs-dry-run-then-drafts",
			label: "GitHub docs dry run, then import as drafts",
			sourceExampleId: "github-docs-folder",
			sourceTypeId: "github_repository",
			mode: {
				dryRun: true,
				importAsDrafts: true,
				urlTreatment: "ask_when_found"
			},
			goal: "Show the common long wait path: GitHub queued, sparse Git fetching, file discovery, prepared documents, URL decision, media detection, dry-run write skip, then a clear next action to import as drafts.",
			timelineStepIds: [
				"github_sparse_fetch_pending",
				"github_sparse_fetch_active",
				"file_discovery",
				"prepare_content",
				"url_treatment_decision",
				"import_media",
				"write_pages",
				"finish_review_drafts"
			],
			sampleSnapshot: {
				source_items: {
					total: 18,
					statuses: {
						done: 18
					}
				},
				prepared_documents: {
					total: 11
				},
				media: {
					total: 4,
					statuses: {}
				},
				posts: {
					persisted: 0
				},
				progress: {
					total: 18,
					completed: 18,
					errors: 0
				}
			},
			resultCopy: {
				title: "Dry run complete",
				summary: "11 documents are ready. No WordPress pages were written.",
				primaryAction: "Import as drafts with these settings"
			}
		},
		{
			id: "browser-folder-url-decision",
			label: "Browser folder pauses for old URL treatment",
			sourceExampleId: "browser-folder",
			sourceTypeId: "browser_folder",
			mode: {
				dryRun: false,
				importAsDrafts: true,
				urlTreatment: "ask_when_found"
			},
			goal: "Make the needs-action state obvious when old first-party URLs are found after content preparation.",
			timelineStepIds: [
				"upload_staging",
				"file_discovery",
				"prepare_content",
				"url_treatment_decision"
			],
			sampleDecision: {
				key: "confirm-first-party-domains",
				prompt: "Choose how old URLs should be handled.",
				domains: [
					{
						host: "old.example.com",
						exampleUrl: "https://old.example.com/media/header.jpg",
						selected: true
					},
					{
						host: "cdn.example.com",
						exampleUrl: "https://cdn.example.com/uploads/team.pdf",
						selected: false
					}
				]
			},
			resultCopy: {
				title: "Needs URL choice",
				summary: "The importer is paused until old domains are kept or rewritten.",
				primaryAction: "Rewrite selected domains"
			}
		},
		{
			id: "wordpress-rest-media-review",
			label: "WordPress REST import with media review",
			sourceExampleId: "wordpress-site",
			sourceTypeId: "wordpress_site",
			mode: {
				dryRun: false,
				importAsDrafts: true,
				urlTreatment: "rewrite_listed"
			},
			goal: "Show partial progress where pages may exist but failed media still needs review.",
			timelineStepIds: [
				"file_discovery",
				"prepare_content",
				"url_treatment_decision",
				"import_media"
			],
			sampleSnapshot: {
				source_items: {
					total: 42,
					statuses: {
						done: 42
					}
				},
				prepared_documents: {
					total: 31
				},
				media: {
					total: 17,
					statuses: {
						failed: 2
					}
				},
				posts: {
					persisted: 31
				},
				progress: {
					total: 42,
					completed: 42,
					errors: 2
				}
			},
			resultCopy: {
				title: "Media needs review",
				summary: "31 draft pages exist, but 2 media items failed and references need review.",
				primaryAction: "Review failed media"
			}
		}
	],

	resultStates: [
		{
			id: "complete_with_posts",
			label: "Complete",
			status: "done",
			condition: "posts.persisted > 0",
			primaryAction: "View imported content",
			summaryFields: [
				"pages written",
				"media imported",
				"warnings",
				"skipped items"
			]
		},
		{
			id: "dry_run_complete",
			label: "Dry run complete",
			status: "done",
			condition: "dry_run is true",
			primaryAction: "Run import with these settings",
			summaryFields: [
				"documents ready",
				"media references found",
				"URL choices",
				"skipped items"
			]
		},
		{
			id: "blocked_url_decision",
			label: "Needs URL choice",
			status: "running",
			condition: "pending_decisions contains confirm-first-party-domains",
			primaryAction: "Save URL choice",
			summaryFields: [
				"detected domains",
				"example URLs",
				"rewrite choice"
			]
		},
		{
			id: "blocked_source_failure",
			label: "Source needs attention",
			status: "running",
			condition: "source_items.statuses.failed > 0",
			primaryAction: "Start a new import after correcting the source",
			summaryFields: [
				"failed source count",
				"last source error",
				"source path or URL"
			]
		},
		{
			id: "blocked_media_failure",
			label: "Media needs review",
			status: "running",
			condition: "media.statuses.failed > 0",
			primaryAction: "Review failed media",
			summaryFields: [
				"failed media count",
				"affected pages",
				"remaining references"
			]
		},
		{
			id: "aborted",
			label: "Aborted",
			status: "aborted",
			condition: "session was aborted before completion",
			primaryAction: "Start another import",
			summaryFields: [
				"work completed before abort",
				"content that may already exist",
				"session id"
			]
		}
	]
};
