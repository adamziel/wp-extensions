<?php
/**
 * Regression checks for static site generator Playground blueprints.
 *
 * @package WPExtensions
 */

$repo_root = dirname( __DIR__ );

$browser_blueprint   = ssgwp_blueprint_decode( $repo_root . '/blueprints/static-site-generator-browser.json' );
$brew_blueprint      = ssgwp_blueprint_decode( $repo_root . '/blueprints/static-site-generator-brewcommerce.json' );
$cli_blueprint       = ssgwp_blueprint_decode( $repo_root . '/blueprints/static-site-generator-cli-export.json' );
$portpress_blueprint = ssgwp_blueprint_decode( $repo_root . '/blueprints/portpress-demo.json' );

$browser_commands   = ssgwp_blueprint_wp_cli_commands( $browser_blueprint );
$brew_commands      = ssgwp_blueprint_wp_cli_commands( $brew_blueprint );
$cli_commands       = ssgwp_blueprint_wp_cli_commands( $cli_blueprint );
$portpress_commands = ssgwp_blueprint_wp_cli_commands( $portpress_blueprint );
$root_readme        = ssgwp_read_file( $repo_root . '/README.md' );
$plugin_readme      = ssgwp_read_file( $repo_root . '/static-site-generator/README.md' );
$plugin_file        = ssgwp_read_file( $repo_root . '/static-site-generator/static-site-generator.php' );
$plugin_admin       = ssgwp_read_file( $repo_root . '/static-site-generator/includes/class-plugin.php' );
$audit_plan         = ssgwp_read_file( $repo_root . '/static-site-generator/docs/audit-plan.md' );
$branding_research = ssgwp_read_file( $repo_root . '/static-site-generator/docs/branding-research.md' );
$brew_wxr           = ssgwp_read_file( $repo_root . '/blueprints/static-site-generator-brewcommerce-content.xml' );
$portpress_guide    = ssgwp_read_file( $repo_root . '/blueprints/portpress-demo-guide.php' );

$delete_default_post_index = ssgwp_find_command_index( $browser_commands, 'wp post delete 1 --force' );
$hello_world_index         = ssgwp_find_command_index( $browser_commands, '--post_name=hello-world' );

ssgwp_blueprint_assert(
	false !== $delete_default_post_index,
	'Browser demo blueprint deletes the default WordPress post.'
);

ssgwp_blueprint_assert(
	false !== $hello_world_index,
	'Browser demo blueprint creates the hello-world demo post.'
);

ssgwp_blueprint_assert(
	$delete_default_post_index < $hello_world_index,
	'Browser demo blueprint frees the hello-world slug before seeding that post.'
);

ssgwp_blueprint_assert(
	ssgwp_command_contains( $browser_commands, 'Hello World Field Report' ),
	'Browser demo blueprint includes the dated hello-world verification content.'
);

ssgwp_blueprint_assert(
	ssgwp_command_contains( $browser_commands, 'wp static-site export' ) === false,
	'Browser demo blueprint opens the admin exporter instead of auto-downloading a ZIP.'
);

ssgwp_blueprint_assert(
	'/wp-admin/tools.php?page=playground-static-site-generator' === $brew_blueprint['landingPage'],
	'BrewCommerce blueprint lands on the static exporter admin page.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_has_wordpress_org_plugin( $brew_blueprint, 'woocommerce' ),
	'BrewCommerce blueprint installs WooCommerce.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_has_static_generator_install( $brew_blueprint ),
	'BrewCommerce blueprint installs the static site generator from wp-extensions.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_uses_url( $brew_blueprint, 'theme.zip' ),
	'BrewCommerce blueprint installs the coffee shop theme from the upstream asset URL.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_uses_url( $brew_blueprint, 'uploads.zip' ),
	'BrewCommerce blueprint imports the coffee shop uploads from the upstream asset URL.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $brew_blueprint, 'adamziel/wp-extensions/main/blueprints/static-site-generator-brewcommerce-content.xml' ),
	'BrewCommerce blueprint imports the cleaned coffee shop WXR from this repository.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce_coming_soon' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce_store_pages_only' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, '_wc_activation_redirect' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce_coming_soon_exclude' ),
	'BrewCommerce blueprint launches the store before static export.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce_onboarding_profile' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce_task_list_hidden' ),
	'BrewCommerce blueprint skips the WooCommerce guided setup.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $brew_blueprint, 'communication-preferences' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'unresolved shortcode placeholder' ),
	'BrewCommerce blueprint replaces the imported AutomateWoo shortcode page with rendered content.'
);

ssgwp_blueprint_assert(
	false === ssgwp_blueprint_step_contains( $brew_blueprint, '&#65533;' )
		&& false === ssgwp_blueprint_step_contains( $brew_blueprint, 'punctuation_replacements' )
		&& false === ssgwp_blueprint_step_contains( $brew_blueprint, 'replacement_character' )
		&& false === ssgwp_blueprint_step_contains( $brew_blueprint, html_entity_decode( '&#65533;', ENT_QUOTES, 'UTF-8' ) ),
	'BrewCommerce blueprint does not repair corrupted punctuation after import.'
);

ssgwp_blueprint_assert(
	false === strpos( $brew_wxr, html_entity_decode( '&#65533;', ENT_QUOTES, 'UTF-8' ) )
		&& false !== strpos( $brew_wxr, 'You may be interested in&hellip;' )
		&& false !== strpos( $brew_wxr, 'can&rsquo;t' ),
	'BrewCommerce cleaned WXR contains valid source content without replacement characters.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $brew_blueprint, 'brewcommerce-static-export-fallbacks' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'wc-block-product-template' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'grid-template-columns' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'aspect-ratio:1/1' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'object-fit:cover' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'wc-block-cart' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'wc-block-components-sidebar-layout' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'woocommerce-product-gallery' )
		&& ssgwp_blueprint_step_contains( $brew_blueprint, 'opacity:1!important' ),
	'BrewCommerce blueprint includes static product, cart, and gallery fallback styles.'
);

ssgwp_blueprint_assert(
	ssgwp_command_contains( $brew_commands, 'wp static-site export' ) === false,
	'BrewCommerce browser blueprint opens the admin exporter instead of auto-downloading a ZIP.'
);

ssgwp_blueprint_assert(
	ssgwp_command_contains( $cli_commands, 'wp static-site export --output=/exports/static-site.zip --fetch-mode=internal' ),
	'CLI export blueprint runs the static export command with the Playground-safe fetch mode.'
);

ssgwp_blueprint_assert(
	'/wp-admin/tools.php?page=portpress-demo' === $portpress_blueprint['landingPage'],
	'PortPress demo blueprint lands on the guided demo page.'
);

ssgwp_blueprint_assert(
	false !== strpos( $portpress_blueprint['meta']['title'], 'PortPress Demo' )
		&& ssgwp_blueprint_has_static_generator_install( $portpress_blueprint )
		&& ssgwp_blueprint_step_contains( $portpress_blueprint, 'universal-wordpress-importer-0.1.0.zip' ),
	'PortPress demo blueprint installs both import and static export tools.'
);

ssgwp_blueprint_assert(
	ssgwp_blueprint_step_contains( $portpress_blueprint, 'portpress-demo-guide.php' )
		&& ssgwp_blueprint_step_contains( $portpress_blueprint, '/wordpress/wp-content/uploads/portpress-source' )
		&& ssgwp_blueprint_step_contains( $portpress_blueprint, 'README.md' ),
	'PortPress demo blueprint writes the guide and README.md source folder.'
);

ssgwp_blueprint_assert(
	ssgwp_command_contains( $portpress_commands, 'wp static-site export' ) === false,
	'PortPress demo blueprint teaches the export workflow instead of auto-downloading a ZIP.'
);

ssgwp_blueprint_assert(
	false !== strpos( $portpress_guide, 'PortPress demo' )
		&& false !== strpos( $portpress_guide, 'Open importer' )
		&& false !== strpos( $portpress_guide, 'Open static export' )
		&& false !== strpos( $portpress_guide, '/wordpress/wp-content/uploads/portpress-source' ),
	'PortPress demo guide explains the two important actions after landing.'
);

ssgwp_blueprint_assert(
	false === strpos( $root_readme, 'try-it-in-playground.webp' )
		&& false === strpos( $plugin_readme, 'try-it-in-playground.webp' ),
	'Static generator README buttons do not use the WordPress Playground screenshot asset.'
);

ssgwp_blueprint_assert(
	false === strpos( $root_readme, 'adamziel/playground-preview' )
		&& false === strpos( $plugin_readme, 'adamziel/playground-preview' ),
	'Static generator README buttons use an asset from this repository.'
);

ssgwp_blueprint_assert(
	false !== strpos( $root_readme, 'static-site-generator/assets/try-it-in-playground.svg' )
		&& false !== strpos( $plugin_readme, 'assets/try-it-in-playground.svg' )
		&& file_exists( $repo_root . '/static-site-generator/assets/try-it-in-playground.svg' ),
	'Static generator README buttons point to the local branded Playground button asset.'
);

foreach (
	array(
		'Export correctness',
		'Dynamic WordPress behavior',
		'Preview and hosting',
		'CI coverage',
		'Documentation and demos',
		'Branding and GitHub Pages',
	) as $audit_heading
) {
	ssgwp_blueprint_assert(
		false !== strpos( $audit_plan, $audit_heading ),
		'Static generator audit plan includes ' . $audit_heading . '.'
	);
}

ssgwp_blueprint_assert(
	false !== strpos( $branding_research, 'StillPress' )
		&& false !== strpos( $branding_research, 'Simply Static' )
		&& false !== strpos( $branding_research, 'Staatic' )
		&& false !== strpos( $branding_research, 'WP2Static' )
		&& false !== strpos( $branding_research, 'Static Cache Wrangler' )
		&& false !== strpos( $branding_research, 'Naming Iterations' )
		&& false !== strpos( $branding_research, 'Logo Iterations' )
		&& false !== strpos( $branding_research, 'Selected direction' )
		&& file_exists( $repo_root . '/static-site-generator/assets/stillpress-logo.svg' ),
	'Static generator branding research records the selected name, competitors, logo iterations, and logo asset.'
);

ssgwp_blueprint_assert(
	false === strpos( $audit_plan, 'Planned output:' )
		&& false === strpos( $audit_plan, 'Planned checks:' )
		&& false !== strpos( $audit_plan, 'Completion evidence' ),
	'Static generator audit plan records completion evidence instead of open planned deliverables.'
);

ssgwp_blueprint_assert(
	false !== strpos( $plugin_file, 'Plugin Name: StillPress' )
		&& false !== strpos( $plugin_admin, 'StillPress' )
		&& false !== strpos( $root_readme, '## StillPress' )
		&& false !== strpos( $plugin_readme, '# StillPress' )
		&& false !== strpos( $browser_blueprint['meta']['title'], 'StillPress' )
		&& false !== strpos( $brew_blueprint['meta']['title'], 'StillPress' ),
	'Static generator user-facing plugin, docs, and Blueprint names use the selected StillPress brand.'
);

ssgwp_blueprint_assert(
	false === strpos( $plugin_file, 'Plugin Name: Playground Static Site Generator' )
		&& false === strpos( $plugin_readme, 'Playground Static Site Generator' )
		&& false === strpos( $root_readme, 'Playground Static Site Generator' ),
	'Static generator user-facing files do not keep the pre-brand Playground Static Site Generator name.'
);

/**
 * Decode a blueprint JSON file.
 *
 * @param string $path Blueprint path.
 * @return array<string,mixed>
 */
function ssgwp_blueprint_decode( $path ) {
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $contents ) {
		ssgwp_blueprint_fail( 'Could not read ' . $path );
	}

	try {
		$blueprint = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	} catch ( Exception $exception ) {
		ssgwp_blueprint_fail( $path . ' is not valid JSON: ' . $exception->getMessage() );
	}

	if ( ! is_array( $blueprint ) ) {
		ssgwp_blueprint_fail( $path . ' did not decode to an object.' );
	}

	return $blueprint;
}

/**
 * Read a required text file.
 *
 * @param string $path File path.
 * @return string File contents.
 */
function ssgwp_read_file( $path ) {
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! is_string( $contents ) ) {
		ssgwp_blueprint_fail( 'Could not read ' . $path );
	}

	return $contents;
}

/**
 * Return WP-CLI commands from a blueprint.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return array<int,string>
 */
function ssgwp_blueprint_wp_cli_commands( array $blueprint ) {
	$commands = array();
	$steps    = isset( $blueprint['steps'] ) && is_array( $blueprint['steps'] )
		? $blueprint['steps']
		: array();

	foreach ( $steps as $step ) {
		if (
			is_array( $step )
			&& isset( $step['step'], $step['command'] )
			&& 'wp-cli' === $step['step']
		) {
			$commands[] = (string) $step['command'];
		}
	}

	return $commands;
}

/**
 * Find the first command containing a substring.
 *
 * @param array<int,string> $commands Commands.
 * @param string            $needle   Substring.
 * @return int|false
 */
function ssgwp_find_command_index( array $commands, $needle ) {
	foreach ( $commands as $index => $command ) {
		if ( false !== strpos( $command, $needle ) ) {
			return $index;
		}
	}

	return false;
}

/**
 * Check whether any command contains a substring.
 *
 * @param array<int,string> $commands Commands.
 * @param string            $needle   Substring.
 * @return bool
 */
function ssgwp_command_contains( array $commands, $needle ) {
	return false !== ssgwp_find_command_index( $commands, $needle );
}

/**
 * Check whether a blueprint installs a wordpress.org plugin slug.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @param string              $slug      Plugin slug.
 * @return bool
 */
function ssgwp_blueprint_has_wordpress_org_plugin( array $blueprint, $slug ) {
	foreach ( ssgwp_blueprint_steps( $blueprint ) as $step ) {
		if (
			'installPlugin' === $step['step']
			&& isset( $step['pluginData']['resource'], $step['pluginData']['slug'] )
			&& 'wordpress.org/plugins' === $step['pluginData']['resource']
			&& $slug === $step['pluginData']['slug']
		) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a blueprint installs this repo's static generator plugin.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return bool
 */
function ssgwp_blueprint_has_static_generator_install( array $blueprint ) {
	foreach ( ssgwp_blueprint_steps( $blueprint ) as $step ) {
		if (
			'installPlugin' === $step['step']
			&& isset( $step['pluginData']['resource'], $step['pluginData']['path'] )
			&& 'git:directory' === $step['pluginData']['resource']
			&& 'static-site-generator' === $step['pluginData']['path']
		) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a blueprint references an upstream URL ending in a filename.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @param string              $filename  Filename.
 * @return bool
 */
function ssgwp_blueprint_step_uses_url( array $blueprint, $filename ) {
	foreach ( ssgwp_blueprint_steps( $blueprint ) as $step ) {
		if ( false !== strpos( wp_json_encode_fallback( $step ), '/brewcommerce/' . $filename ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a blueprint step contains a substring.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @param string              $needle    Substring.
 * @return bool
 */
function ssgwp_blueprint_step_contains( array $blueprint, $needle ) {
	foreach ( ssgwp_blueprint_steps( $blueprint ) as $step ) {
		if ( false !== strpos( wp_json_encode_fallback( $step ), $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return normalized blueprint step arrays.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return array<int,array<string,mixed>>
 */
function ssgwp_blueprint_steps( array $blueprint ) {
	return isset( $blueprint['steps'] ) && is_array( $blueprint['steps'] )
		? array_filter( $blueprint['steps'], 'is_array' )
		: array();
}

/**
 * Encode data for simple fixture inspection.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_fallback( $data ) {
	$json = json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

	return is_string( $json ) ? $json : '';
}

/**
 * Assert a blueprint condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function ssgwp_blueprint_assert( $condition, $message ) {
	if ( ! $condition ) {
		ssgwp_blueprint_fail( $message );
	}
}

/**
 * Fail the blueprint test.
 *
 * @param string $message Failure message.
 * @return void
 */
function ssgwp_blueprint_fail( $message ) {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
