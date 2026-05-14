<?php
/**
 * Controlled crash exception for importer recovery tests.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Distinguishes intentional crash simulations from normal recoverable errors.
 */
final class ImportSimulatedCrashException extends RuntimeException {}
