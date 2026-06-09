<?php
/**
 * Optional Cloudflare Workers deploy package smoke runner.
 *
 * @package PlaygroundStaticSiteGenerator
 */

/**
 * Runs local and credential-gated checks against a generated Cloudflare package.
 */
final class SSGWP_Cloudflare_Deploy_Package_Smoke_Runner {
	const DEPLOY_DIRECTORY = '_cloudflare-publish';

	const MODE_OFFLINE     = 'offline';
	const MODE_CREDENTIALS = 'credentials';
	const MODE_DRY_RUN     = 'dry-run';
	const MODE_DEPLOY      = 'deploy';

	const REQUIRED_ACCOUNT_ID = 'CLOUDFLARE_ACCOUNT_ID';
	const REQUIRED_API_TOKEN  = 'CLOUDFLARE_API_TOKEN';

	/**
	 * Run the selected smoke mode.
	 *
	 * @param array<string,mixed> $options Smoke options.
	 * @return array<string,mixed> Smoke summary.
	 */
	public function run( array $options ) {
		$options = array_merge(
			array(
				'input_path'                  => null,
				'mode'                        => self::MODE_OFFLINE,
				'confirm_deploy'              => false,
				'skip_if_missing_credentials' => false,
				'command_timeout_seconds'     => 120,
			),
			$options
		);

		$mode = (string) $options['mode'];
		$command_timeout_seconds = max( 1, (int) $options['command_timeout_seconds'] );

		if ( ! in_array( $mode, $this->valid_modes(), true ) ) {
			throw new RuntimeException( 'Unknown Cloudflare smoke mode: ' . $mode );
		}

		$package = $this->resolve_deploy_package( (string) $options['input_path'] );

		if ( self::MODE_DEPLOY === $mode && empty( $options['confirm_deploy'] ) ) {
			throw new RuntimeException( 'Refusing real Cloudflare deploy without --confirm-deploy.' );
		}

		$summary = array(
			'status'              => 'pending',
			'mode'                => $mode,
			'input_path'          => $package['input_path'],
			'package_path'        => $package['path'],
			'missing_credentials' => array(),
			'commands'            => array(),
		);

		$summary['commands'][] = $this->run_command(
			$this->offline_validation_command(),
			$package['path'],
			'validate the Cloudflare deploy package offline',
			$command_timeout_seconds
		);

		if ( self::MODE_OFFLINE === $mode ) {
			$summary['status'] = 'passed';
			return $summary;
		}

		$missing_credentials = self::missing_credentials();

		if ( ! empty( $missing_credentials ) && ! empty( $options['skip_if_missing_credentials'] ) ) {
			$summary['status']              = 'skipped';
			$summary['missing_credentials'] = $missing_credentials;
			$summary['skip_reason']         = 'Missing required environment variable(s): ' . implode( ', ', $missing_credentials ) . '.';

			return $summary;
		}

		$summary['commands'][] = $this->run_command(
			$this->credentials_validation_command(),
			$package['path'],
			'validate Cloudflare credential presence',
			$command_timeout_seconds
		);

		if ( self::MODE_CREDENTIALS === $mode ) {
			$summary['status'] = 'passed';
			return $summary;
		}

		$summary['commands'][] = $this->run_command(
			$this->wrangler_command_for_mode( $mode ),
			$package['path'],
			self::MODE_DEPLOY === $mode ? 'run a real Cloudflare Wrangler deploy' : 'run a Cloudflare Wrangler dry-run deploy',
			$command_timeout_seconds
		);

		$summary['status'] = 'passed';

		return $summary;
	}

	/**
	 * Resolve an input path to the generated Cloudflare package directory.
	 *
	 * @param string $input_path Package directory or export root.
	 * @return array{input_path:string,path:string}
	 */
	public function resolve_deploy_package( $input_path ) {
		if ( '' === trim( (string) $input_path ) ) {
			throw new RuntimeException( 'Usage error: provide a _cloudflare-publish directory or an export directory containing one.' );
		}

		$real = realpath( $input_path );

		if ( false === $real || ! is_dir( $real ) ) {
			throw new RuntimeException( 'Input path must be a directory: ' . $input_path );
		}

		$real = $this->normalize_path( $real );

		if ( $this->has_deploy_package_shape( $real ) ) {
			return array(
				'input_path' => $real,
				'path'       => $real,
			);
		}

		$candidate = $real . '/' . self::DEPLOY_DIRECTORY;

		if ( is_link( $candidate ) ) {
			throw new RuntimeException( 'Refusing to use a symlinked Cloudflare deploy package directory.' );
		}

		$real_candidate = realpath( $candidate );

		if ( false !== $real_candidate && is_dir( $real_candidate ) && $this->has_deploy_package_shape( $real_candidate ) ) {
			return array(
				'input_path' => $real,
				'path'       => $this->normalize_path( $real_candidate ),
			);
		}

		throw new RuntimeException( 'Input must be a _cloudflare-publish directory or an export directory containing _cloudflare-publish.' );
	}

	/**
	 * Return fixed required credential keys that may be named in diagnostics.
	 *
	 * @return string[]
	 */
	public static function required_credentials() {
		return array(
			self::REQUIRED_ACCOUNT_ID,
			self::REQUIRED_API_TOKEN,
		);
	}

	/**
	 * Return missing Cloudflare credential environment variable names.
	 *
	 * @return string[]
	 */
	public static function missing_credentials() {
		$missing = array();

		foreach ( self::required_credentials() as $name ) {
			$value = getenv( $name );

			if ( false === $value || '' === trim( (string) $value ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Redact current Cloudflare credential values from process diagnostics.
	 *
	 * @param string $text Diagnostic text.
	 * @return string Redacted text.
	 */
	public static function redact_cloudflare_env_values( $text ) {
		$redacted   = (string) $text;
		$redactions = array();

		foreach ( self::required_credentials() as $index => $name ) {
			$value = getenv( $name );

			if ( false === $value || '' === (string) $value ) {
				continue;
			}

			$value        = (string) $value;
			$redactions[] = array(
				'name'   => $name,
				'value'  => $value,
				'length' => strlen( $value ),
				'index'  => $index,
			);
		}

		usort(
			$redactions,
			static function ( array $left, array $right ) {
				if ( $left['length'] === $right['length'] ) {
					return $left['index'] - $right['index'];
				}

				return $right['length'] - $left['length'];
			}
		);

		foreach ( $redactions as $redaction ) {
			$redacted = str_replace( $redaction['value'], '[redacted:' . $redaction['name'] . ']', $redacted );
		}

		return $redacted;
	}

	/**
	 * Return the offline validation command.
	 *
	 * @return string[]
	 */
	public function offline_validation_command() {
		return array( 'node', 'cloudflare-deploy-check.mjs', '--offline' );
	}

	/**
	 * Return the credential-presence validation command.
	 *
	 * @return string[]
	 */
	public function credentials_validation_command() {
		return array( 'node', 'cloudflare-deploy-check.mjs', '--require-credentials' );
	}

	/**
	 * Return a Wrangler command for the selected network-capable mode.
	 *
	 * @param string $mode Smoke mode.
	 * @return string[]
	 */
	public function wrangler_command_for_mode( $mode ) {
		if ( self::MODE_DRY_RUN === $mode ) {
			return array( 'npx', 'wrangler', 'deploy', '--config', 'wrangler.jsonc', '--dry-run' );
		}

		if ( self::MODE_DEPLOY === $mode ) {
			return array( 'npx', 'wrangler', 'deploy', '--config', 'wrangler.jsonc' );
		}

		throw new RuntimeException( 'Mode does not have a Wrangler deploy command: ' . $mode );
	}

	/**
	 * Render a command for summaries without environment values.
	 *
	 * @param string[] $command Command argv.
	 * @return string Display command.
	 */
	public static function command_summary( array $command ) {
		$display = array();

		foreach ( $command as $arg ) {
			$display[] = self::display_arg( (string) $arg );
		}

		return implode( ' ', $display );
	}

	/**
	 * Return valid smoke modes.
	 *
	 * @return string[]
	 */
	private function valid_modes() {
		return array(
			self::MODE_OFFLINE,
			self::MODE_CREDENTIALS,
			self::MODE_DRY_RUN,
			self::MODE_DEPLOY,
		);
	}

	/**
	 * Check enough package shape to decide whether this directory is the root.
	 *
	 * @param string $path Directory path.
	 * @return bool Whether the directory looks like the generated deploy package.
	 */
	private function has_deploy_package_shape( $path ) {
		foreach (
			array(
				'package.json',
				'cloudflare-deploy-check.mjs',
				'wrangler.jsonc',
				'cloudflare-worker.js',
				'cloudflare-publish.json',
				'CLOUDFLARE-WORKERS.md',
			) as $file
		) {
			if ( ! is_file( rtrim( $path, '/\\' ) . '/' . $file ) ) {
				return false;
			}
		}

		return is_dir( rtrim( $path, '/\\' ) . '/site' );
	}

	/**
	 * Run a local command and return redacted output.
	 *
	 * @param string[] $command Command argv.
	 * @param string   $cwd     Working directory.
	 * @param string   $action  Human-readable action.
	 * @param int      $timeout_seconds Maximum command runtime.
	 * @return array{action:string,argv:string[],display:string,stdout:string,stderr:string}
	 */
	private function run_command( array $command, $cwd, $action, $timeout_seconds ) {
		if ( ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'proc_open is required to ' . $action . '.' );
		}

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$command_string  = implode( ' ', array_map( 'escapeshellarg', $command ) );
		$display_command = self::command_summary( $command );
		$process         = proc_open( $command_string, $descriptor_spec, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Unable to start command to ' . $action . ': ' . $display_command );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$started   = microtime( true );
		$timed_out = false;
		$exit_code = null;

		while ( true ) {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			$status  = proc_get_status( $process );

			if ( ! $status['running'] ) {
				$exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : 0;
				break;
			}

			if ( microtime( true ) - $started > $timeout_seconds ) {
				$timed_out = true;
				proc_terminate( $process );
				usleep( 200000 );
				$status = proc_get_status( $process );

				if ( $status['running'] ) {
					proc_terminate( $process, 9 );
				}
				break;
			}

			usleep( 10000 );
		}

		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$proc_close_code = proc_close( $process );

		if ( null === $exit_code ) {
			$exit_code = (int) $proc_close_code;
		}

		$stdout    = self::redact_cloudflare_env_values( (string) $stdout );
		$stderr    = self::redact_cloudflare_env_values( (string) $stderr );

		if ( $timed_out ) {
			$diagnostic = trim( $stdout . "\n" . $stderr );
			throw new RuntimeException(
				'Unable to ' . $action . ' because the command timed out after ' . $timeout_seconds . " seconds:\n"
				. 'Command: ' . $display_command
				. ( '' === $diagnostic ? '' : "\n" . $this->truncate_diagnostic( $diagnostic ) )
			);
		}

		if ( 0 !== $exit_code ) {
			$diagnostic = trim( $stdout . "\n" . $stderr );
			throw new RuntimeException(
				'Unable to ' . $action . ' (exit ' . $exit_code . "):\n"
				. 'Command: ' . $display_command
				. ( '' === $diagnostic ? '' : "\n" . $this->truncate_diagnostic( $diagnostic ) )
			);
		}

		return array(
			'action'  => $action,
			'argv'    => array_values( $command ),
			'display' => $display_command,
			'stdout'  => $stdout,
			'stderr'  => $stderr,
		);
	}

	/**
	 * Keep process diagnostics bounded for CI logs.
	 *
	 * @param string $diagnostic Full diagnostic.
	 * @return string Truncated diagnostic.
	 */
	private function truncate_diagnostic( $diagnostic ) {
		$diagnostic = (string) $diagnostic;

		if ( strlen( $diagnostic ) <= 6000 ) {
			return $diagnostic;
		}

		return substr( $diagnostic, 0, 6000 ) . "\n[truncated]";
	}

	/**
	 * Normalize a filesystem path for summaries.
	 *
	 * @param string $path Path.
	 * @return string Normalized path.
	 */
	private function normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}

	/**
	 * Render one argv item in a human-readable command summary.
	 *
	 * @param string $arg Command argument.
	 * @return string Display argument.
	 */
	private static function display_arg( $arg ) {
		if ( '' === $arg ) {
			return "''";
		}

		$length = strlen( $arg );

		for ( $i = 0; $i < $length; ++$i ) {
			$char       = $arg[ $i ];
			$is_letter  = ( $char >= 'a' && $char <= 'z' ) || ( $char >= 'A' && $char <= 'Z' );
			$is_digit   = $char >= '0' && $char <= '9';
			$is_allowed = $is_letter || $is_digit || in_array( $char, array( '-', '_', '.', '/', ':', '=', '@' ), true );

			if ( ! $is_allowed ) {
				return escapeshellarg( $arg );
			}
		}

		return $arg;
	}
}

/**
 * Parse command-line arguments.
 *
 * @param string[] $argv Raw argv.
 * @return array<string,mixed> Parsed options.
 */
function ssgwp_cloudflare_deploy_smoke_parse_args( array $argv ) {
	$options = array(
		'input_path'                  => null,
		'mode'                        => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_OFFLINE,
		'confirm_deploy'              => false,
		'skip_if_missing_credentials' => false,
	);
	$mode_was_set = false;

	for ( $i = 1; $i < count( $argv ); ++$i ) {
		$arg = (string) $argv[ $i ];

		if ( '--help' === $arg || '-h' === $arg ) {
			$options['help'] = true;
			continue;
		}

		$mode = ssgwp_cloudflare_deploy_smoke_mode_arg( $arg );

		if ( null !== $mode ) {
			if ( $mode_was_set ) {
				throw new RuntimeException( 'Only one Cloudflare smoke mode may be provided.' );
			}

			$options['mode'] = $mode;
			$mode_was_set    = true;
			continue;
		}

		if ( '--confirm-deploy' === $arg ) {
			$options['confirm_deploy'] = true;
			continue;
		}

		if ( '--skip-if-missing-credentials' === $arg ) {
			$options['skip_if_missing_credentials'] = true;
			continue;
		}

		if ( 0 === strpos( $arg, '--' ) ) {
			throw new RuntimeException( 'Unknown option: ' . $arg );
		}

		if ( null !== $options['input_path'] ) {
			throw new RuntimeException( 'Only one Cloudflare package or export path may be provided.' );
		}

		$options['input_path'] = $arg;
	}

	if ( empty( $options['help'] ) && null === $options['input_path'] ) {
		throw new RuntimeException( 'Usage error: provide a _cloudflare-publish directory or export directory.' );
	}

	return $options;
}

/**
 * Map a mode flag to the internal mode value.
 *
 * @param string $arg CLI argument.
 * @return string|null Mode or null.
 */
function ssgwp_cloudflare_deploy_smoke_mode_arg( $arg ) {
	$map = array(
		'--offline'     => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_OFFLINE,
		'--credentials' => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_CREDENTIALS,
		'--dry-run'     => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN,
		'--deploy'      => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DEPLOY,
	);

	return isset( $map[ $arg ] ) ? $map[ $arg ] : null;
}

/**
 * Render CLI usage.
 */
function ssgwp_cloudflare_deploy_smoke_usage() {
	echo "Usage: php static-site-generator/tools/smoke-cloudflare-deploy-package.php [mode] [options] <_cloudflare-publish-dir|export-dir>\n";
	echo "\n";
	echo "Modes:\n";
	echo "  --offline       Validate package structure only. Default; no credentials or network.\n";
	echo "  --credentials   Validate package structure and required credential presence.\n";
	echo "  --dry-run       Validate credentials, then run npx wrangler deploy --config wrangler.jsonc --dry-run.\n";
	echo "  --deploy        Validate credentials, then run a real Wrangler deploy. Requires --confirm-deploy.\n";
	echo "\n";
	echo "Options:\n";
	echo "  --skip-if-missing-credentials  Exit 0 with SKIP when CLOUDFLARE_ACCOUNT_ID or CLOUDFLARE_API_TOKEN is missing.\n";
	echo "  --confirm-deploy               Required with --deploy before any real deploy command can run.\n";
}

/**
 * Print the smoke result.
 *
 * @param array<string,mixed> $result Smoke summary.
 */
function ssgwp_cloudflare_deploy_smoke_print_result( array $result ) {
	if ( 'skipped' === $result['status'] ) {
		echo 'SKIP: Cloudflare deploy smoke was not run. ' . $result['skip_reason'] . "\n";
		echo 'Package: ' . $result['package_path'] . "\n";
		return;
	}

	echo 'PASS: Cloudflare deploy package smoke passed (' . $result['mode'] . ").\n";
	echo 'Package: ' . $result['package_path'] . "\n";

	foreach ( $result['commands'] as $command ) {
		echo 'Command: ' . $command['display'] . "\n";

		if ( '' !== trim( (string) $command['stdout'] ) ) {
			echo $command['stdout'];
		}

		if ( '' !== trim( (string) $command['stderr'] ) ) {
			fwrite( STDERR, $command['stderr'] );
		}
	}
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		$options = ssgwp_cloudflare_deploy_smoke_parse_args( $argv );

		if ( ! empty( $options['help'] ) ) {
			ssgwp_cloudflare_deploy_smoke_usage();
			exit( 0 );
		}

		$runner = new SSGWP_Cloudflare_Deploy_Package_Smoke_Runner();
		$result = $runner->run( $options );
		ssgwp_cloudflare_deploy_smoke_print_result( $result );
		exit( 0 );
	} catch ( RuntimeException $error ) {
		fwrite( STDERR, 'FAIL: ' . SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::redact_cloudflare_env_values( $error->getMessage() ) . "\n" );
		exit( 1 );
	}
}
