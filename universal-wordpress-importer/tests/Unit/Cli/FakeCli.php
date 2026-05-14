<?php
/**
 * Fake WP-CLI facade for command tests.
 *
 * @package UniversalImporter\Tests\Unit\Cli
 */

namespace UniversalImporter\Tests\Unit\Cli;

use RuntimeException;

/**
 * Minimal WP-CLI facade for unit tests.
 */
final class FakeCli {
	/**
	 * Captured messages.
	 *
	 * @var array<int,string>
	 */
	public $messages = array();

	/**
	 * Captures a success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function success( $message ) {
		$this->messages[] = 'Success: ' . $message;
	}

	/**
	 * Captures a warning message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( $message ) {
		$this->messages[] = 'Warning: ' . $message;
	}

	/**
	 * Captures an output line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function line( $message ) {
		$this->messages[] = $message;
	}

	/**
	 * Captures a debug line.
	 *
	 * @param string $message Message.
	 * @param string $group   Debug group.
	 * @return void
	 */
	public function debug( $message, $group ) {
		$this->messages[] = 'Debug(' . $group . '): ' . $message;
	}

	/**
	 * Raises a CLI-style error.
	 *
	 * @param string $message Message.
	 * @return void
	 * @throws RuntimeException Always.
	 */
	public function error( $message ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only CLI diagnostic.
		throw new RuntimeException( $message );
	}
}
