<?php
/**
 * Admin guide for the PortPress Playground demo.
 *
 * @package WPExtensions
 */

add_action(
	'admin_menu',
	function () {
		add_management_page(
			'PortPress Demo',
			'PortPress Demo',
			'manage_options',
			'portpress-demo',
			'portpress_demo_render_guide'
		);
	},
	1
);

/**
 * Render the single Playground demo guide.
 *
 * @return void
 */
function portpress_demo_render_guide() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this demo.', 'default' ) );
	}

	$source_path = '/wordpress/wp-content/uploads/portpress-source';
	?>
	<div class="wrap portpress-demo-guide">
		<style>
			.portpress-demo-guide {
				--pp-ink: #111827;
				--pp-muted: #56616f;
				--pp-line: #d9e1e8;
				--pp-soft: #f6f8fa;
				--pp-blue: #3858e9;
				--pp-green: #0f766e;
				max-width: 1180px;
			}
			.portpress-demo-guide,
			.portpress-demo-guide * {
				box-sizing: border-box;
			}
			.portpress-demo-guide h1 {
				color: var(--pp-ink);
				font-size: 34px;
				line-height: 1.15;
				margin: 24px 0 8px;
			}
			.portpress-demo-guide h2 {
				color: var(--pp-ink);
				font-size: 22px;
				line-height: 1.2;
				margin: 0 0 10px;
			}
			.portpress-demo-guide h3 {
				color: var(--pp-ink);
				font-size: 16px;
				margin: 0 0 8px;
			}
			.portpress-demo-guide p,
			.portpress-demo-guide li {
				color: var(--pp-muted);
				font-size: 14px;
				line-height: 1.55;
			}
			.portpress-demo-lede {
				font-size: 16px;
				margin: 0 0 22px;
				max-width: 760px;
			}
			.portpress-demo-grid {
				display: grid;
				gap: 16px;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				margin: 20px 0;
			}
			.portpress-demo-card {
				background: #fff;
				border: 1px solid var(--pp-line);
				border-radius: 8px;
				box-shadow: 0 1px 2px rgba(17, 24, 39, .05);
				padding: 22px;
			}
			.portpress-demo-card.is-import {
				border-top: 5px solid var(--pp-green);
			}
			.portpress-demo-card.is-export {
				border-top: 5px solid var(--pp-blue);
			}
			.portpress-demo-kicker {
				color: #173f8a;
				display: block;
				font-size: 12px;
				font-weight: 800;
				letter-spacing: .07em;
				margin-bottom: 8px;
				text-transform: uppercase;
			}
			.portpress-demo-actions {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin-top: 16px;
			}
			.portpress-demo-code {
				align-items: center;
				background: var(--pp-soft);
				border: 1px solid var(--pp-line);
				border-radius: 7px;
				display: flex;
				gap: 10px;
				justify-content: space-between;
				margin: 14px 0;
				padding: 10px 12px;
			}
			.portpress-demo-code code {
				color: var(--pp-ink);
				font-size: 13px;
				overflow-wrap: anywhere;
				white-space: normal;
			}
			.portpress-demo-steps {
				counter-reset: pp-step;
				display: grid;
				gap: 10px;
				list-style: none;
				margin: 14px 0 0;
				padding: 0;
			}
			.portpress-demo-steps li {
				background: var(--pp-soft);
				border: 1px solid var(--pp-line);
				border-radius: 7px;
				margin: 0;
				padding: 12px 12px 12px 44px;
				position: relative;
			}
			.portpress-demo-steps li::before {
				align-items: center;
				background: #fff;
				border: 1px solid var(--pp-line);
				border-radius: 999px;
				color: #173f8a;
				content: counter(pp-step);
				counter-increment: pp-step;
				display: inline-flex;
				font-size: 12px;
				font-weight: 800;
				height: 24px;
				justify-content: center;
				left: 12px;
				position: absolute;
				top: 12px;
				width: 24px;
			}
			.portpress-demo-facts {
				display: grid;
				gap: 12px;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				margin-top: 16px;
			}
			.portpress-demo-fact {
				background: var(--pp-soft);
				border: 1px solid var(--pp-line);
				border-radius: 7px;
				padding: 14px;
			}
			.portpress-demo-fact p {
				margin: 0;
			}
			@media (max-width: 900px) {
				.portpress-demo-grid,
				.portpress-demo-facts {
					grid-template-columns: 1fr;
				}
			}
		</style>

		<h1>PortPress demo</h1>
		<p class="portpress-demo-lede">This Playground has both PortPress directions ready: import a small source folder into WordPress drafts, then export the WordPress site as static HTML and assets. Start with the import path, then run the export.</p>

		<div class="portpress-demo-grid">
			<section class="portpress-demo-card is-import">
				<span class="portpress-demo-kicker">1. Static files to WordPress</span>
				<h2>Import README.md and friends</h2>
				<p>A sample source folder is already inside this Playground. It contains <strong>README.md</strong>, a short HTML page, Markdown notes, and a text file.</p>
				<div class="portpress-demo-code">
					<code id="portpress-demo-source-path"><?php echo esc_html( $source_path ); ?></code>
					<button type="button" class="button" data-portpress-copy="#portpress-demo-source-path">Copy path</button>
				</div>
				<ol class="portpress-demo-steps">
					<li>Open the importer and paste the source path into <strong>URL or server path</strong>.</li>
					<li>Leave <strong>Ask me when unsure</strong> selected for URLs. Use <strong>Dry run</strong> first if you want to inspect the source without creating posts.</li>
					<li>Run the import, then open <strong>Pages</strong> or <strong>Posts</strong> to review the generated WordPress drafts.</li>
				</ol>
				<p class="portpress-demo-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=universal-wordpress-importer' ) ); ?>">Open importer</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">Review posts</a>
				</p>
			</section>

			<section class="portpress-demo-card is-export">
				<span class="portpress-demo-kicker">2. WordPress to static HTML</span>
				<h2>Export the demo site</h2>
				<p>The WordPress site already has sample pages and posts, so you can export immediately or import the source folder first and export the result.</p>
				<ol class="portpress-demo-steps">
					<li>Open the static export screen.</li>
					<li>Keep <strong>Media library files</strong> selected. Use <strong>Anywhere</strong> for URL mode unless you know the final host.</li>
					<li>Click <strong>Download Static Site ZIP</strong>. Extract the ZIP and preview it over a local HTTP server.</li>
				</ol>
				<p class="portpress-demo-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=playground-static-site-generator' ) ); ?>">Open static export</a>
					<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">View demo site</a>
				</p>
			</section>
		</div>

		<section class="portpress-demo-card">
			<h2>What is set up here</h2>
			<div class="portpress-demo-facts">
				<div class="portpress-demo-fact">
					<h3>Source files</h3>
					<p>The folder at <code><?php echo esc_html( $source_path ); ?></code> is the input for the import workflow.</p>
				</div>
				<div class="portpress-demo-fact">
					<h3>WordPress content</h3>
					<p>The site has seeded pages, dated posts, categories, and internal links for static export testing.</p>
				</div>
				<div class="portpress-demo-fact">
					<h3>Export tool</h3>
					<p>The static exporter packages rendered pages, same-site links, frontend assets, and media into a portable ZIP.</p>
				</div>
			</div>
		</section>
	</div>

	<script>
		(function() {
			document.querySelectorAll('[data-portpress-copy]').forEach(function(button) {
				button.addEventListener('click', function() {
					var target = document.querySelector(button.getAttribute('data-portpress-copy'));
					var text = target ? target.textContent : '';

					if (!text) {
						return;
					}

					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text);
					}

					button.textContent = 'Copied';
					window.setTimeout(function() {
						button.textContent = 'Copy path';
					}, 1400);
				});
			});
		}());
	</script>
	<?php
}
