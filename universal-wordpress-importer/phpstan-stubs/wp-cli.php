<?php
/**
 * PHPStan-only declarations for WP-CLI symbols used by the plugin.
 *
 * @package UniversalImporter
 */

/**
 * Minimal WP-CLI facade for static analysis.
 */
class WP_CLI {
	/**
	 * Registers a WP-CLI command.
	 *
	 * @param string               $name     Command name.
	 * @param callable|string      $callable Command callback or class name.
	 * @param array<string,mixed>  $args     Registration args.
	 * @return void
	 */
	public static function add_command( $name, $callable, array $args = array() ) {}

	/**
	 * Emits a success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( $message ) {}

	/**
	 * Emits a warning message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function warning( $message ) {}

	/**
	 * Emits a normal output line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function line( $message ) {}

	/**
	 * Emits a debug message.
	 *
	 * @param string $message Message.
	 * @param string $group   Debug group.
	 * @return void
	 */
	public static function debug( $message, $group = '' ) {}

	/**
	 * Emits an error message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function error( $message ) {}
}
