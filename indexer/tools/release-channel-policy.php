<?php
/**
 * Release-channel policy helpers for analyzer pack distribution.
 *
 * This file is intentionally standalone so packaging checks can run without
 * bootstrapping WordPress or Composer dependencies.
 *
 * @package WP_FTS
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_FTS_ReleaseChannelPolicy' ) ) {
	/**
	 * Centralizes which analyzer packs may be shipped in each release channel.
	 */
	final class WP_FTS_ReleaseChannelPolicy {
		public const PROFILE_CORE                    = 'core';
		public const PROFILE_WPORG_COMPATIBLE        = 'wporg-compatible';
		public const PROFILE_GITHUB_FULL             = 'github-full';
		public const PROFILE_EXTENDED_LANGUAGE_PACKS = 'extended-language-packs';

		private const GPL_COMPATIBLE_LICENSES = array(
			'BSD-2-Clause',
			'GPL-2.0-or-later',
			'GPL-3.0-or-later',
			'MIT',
		);

		private const SEPARATE_LICENSE_PACK_LICENSES = array(
			'CC-BY-SA-3.0',
			'CC-BY-SA-4.0',
		);

		private const BLOCKED_LICENSES = array(
			'upstream-license-not-declared',
			'unknown',
			'',
		);

		/**
		 * Normalizes a release profile name.
		 *
		 * @throws InvalidArgumentException If the profile is unknown.
		 */
		public static function normalize_profile( string $profile ): string {
			$normalized = strtolower( trim( $profile ) );

			$aliases = array(
				''                        => self::PROFILE_CORE,
				'wporg'                   => self::PROFILE_WPORG_COMPATIBLE,
				'wordpress.org'           => self::PROFILE_WPORG_COMPATIBLE,
				'wordpress-org'           => self::PROFILE_WPORG_COMPATIBLE,
				'wporg-compatible'        => self::PROFILE_WPORG_COMPATIBLE,
				'wordpress.org-compatible' => self::PROFILE_WPORG_COMPATIBLE,
				'github'                  => self::PROFILE_GITHUB_FULL,
				'full'                    => self::PROFILE_GITHUB_FULL,
				'extra-packs'             => self::PROFILE_EXTENDED_LANGUAGE_PACKS,
				'extended'                => self::PROFILE_EXTENDED_LANGUAGE_PACKS,
			);

			if ( isset( $aliases[ $normalized ] ) ) {
				return $aliases[ $normalized ];
			}

			if ( in_array( $normalized, self::profiles(), true ) ) {
				return $normalized;
			}

			throw new InvalidArgumentException(
				sprintf(
					'Unknown release profile "%s". Expected one of: %s.',
					$profile,
					implode( ', ', self::profiles() )
				)
			);
		}

		/**
		 * Returns every supported release profile.
		 *
		 * @return list<string>
		 */
		public static function profiles(): array {
			return array(
				self::PROFILE_CORE,
				self::PROFILE_WPORG_COMPATIBLE,
				self::PROFILE_GITHUB_FULL,
				self::PROFILE_EXTENDED_LANGUAGE_PACKS,
			);
		}

		/**
		 * Returns release profiles that create installable plugin ZIP files.
		 *
		 * @return list<string>
		 */
		public static function plugin_profiles(): array {
			return array(
				self::PROFILE_CORE,
				self::PROFILE_WPORG_COMPATIBLE,
				self::PROFILE_GITHUB_FULL,
			);
		}

		/**
		 * Normalizes and validates a plugin ZIP profile.
		 *
		 * @throws InvalidArgumentException If the profile is not a plugin profile.
		 */
		public static function normalize_plugin_profile( string $profile ): string {
			$normalized = self::normalize_profile( $profile );

			if ( ! in_array( $normalized, self::plugin_profiles(), true ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'Profile "%s" builds a language-pack bundle, not an installable plugin ZIP.',
						$normalized
					)
				);
			}

			return $normalized;
		}

		/**
		 * Returns analyzer pack policy rows for the plugin source root.
		 *
		 * @return list<array<string,mixed>>
		 */
		public static function analyzer_pack_rows( string $plugin_source ): array {
			$manifest_paths = self::analyzer_pack_manifest_paths( $plugin_source );
			$rows           = array();

			foreach ( $manifest_paths as $manifest_path ) {
				$metadata        = self::metadata_from_manifest_path( $manifest_path, $plugin_source );
				$metadata['path'] = self::relative_path( dirname( $manifest_path ), $plugin_source );
				$rows[]          = $metadata + self::policy_for_metadata( $metadata );
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return strcmp( (string) $a['pack_id'], (string) $b['pack_id'] );
				}
			);

			return $rows;
		}

		/**
		 * Returns rows included by a release profile.
		 *
		 * @return list<array<string,mixed>>
		 */
		public static function included_analyzer_pack_rows( string $plugin_source, string $profile ): array {
			$profile = self::normalize_profile( $profile );

			return array_values(
				array_filter(
					self::analyzer_pack_rows( $plugin_source ),
					static function ( array $row ) use ( $profile ): bool {
						return in_array( $profile, $row['included_profiles'], true );
					}
				)
			);
		}

		/**
		 * Applies a plugin profile to a staged installable plugin package.
		 *
		 * @return array<string,mixed>
		 */
		public static function apply_plugin_profile( string $staged_plugin_root, string $profile ): array {
			$profile = self::normalize_plugin_profile( $profile );
			$rows    = self::analyzer_pack_rows( $staged_plugin_root );

			$included      = array();
			$excluded      = array();
			$removed_paths = array();

			foreach ( $rows as $row ) {
				$pack_dir = $staged_plugin_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $row['path'] );
				$allowed  = in_array( $profile, $row['included_profiles'], true );

				if ( $allowed ) {
					$included[] = self::public_row( $row );
					continue;
				}

				$excluded[] = self::public_row( $row );
				if ( is_dir( $pack_dir ) ) {
					self::remove_path( $pack_dir );
					$removed_paths[] = 'indexer/' . str_replace( DIRECTORY_SEPARATOR, '/', self::relative_path( $pack_dir, $staged_plugin_root ) );
				}
			}

			self::write_plugin_profile_notice( $staged_plugin_root, $profile, $included, $excluded );

			sort( $removed_paths );

			return array(
				'profile'       => $profile,
				'included'      => $included,
				'excluded'      => $excluded,
				'removed_paths' => $removed_paths,
			);
		}

		/**
		 * Builds machine-readable rows without private filesystem paths.
		 *
		 * @return array<string,mixed>
		 */
		public static function public_row( array $row ): array {
			return array(
				'pack_id'           => (string) $row['pack_id'],
				'language'          => (string) $row['language'],
				'license_spdx'      => (string) $row['license_spdx'],
				'license_name'      => (string) $row['license_name'],
				'license_class'     => (string) $row['license_class'],
				'path'              => (string) $row['path'],
				'included_profiles' => array_values( $row['included_profiles'] ),
				'blocked_reason'    => (string) $row['blocked_reason'],
			);
		}

		/**
		 * Returns whether a metadata row is included by the profile.
		 */
		public static function profile_allows_row( string $profile, array $row ): bool {
			$profile = self::normalize_profile( $profile );

			if ( ! isset( $row['included_profiles'] ) ) {
				$row += self::policy_for_metadata( $row );
			}

			return in_array( $profile, $row['included_profiles'], true );
		}

		/**
		 * Writes an extended pack bundle notice, license summary, and manifest.
		 *
		 * @param list<array<string,mixed>> $included Included public rows.
		 * @param list<array<string,mixed>> $excluded Excluded public rows.
		 */
		public static function write_extended_bundle_metadata( string $bundle_root, array $included, array $excluded ): void {
			if ( ! is_dir( $bundle_root ) ) {
				if ( ! mkdir( $bundle_root, 0775, true ) && ! is_dir( $bundle_root ) ) {
					throw new RuntimeException( sprintf( 'Failed to create bundle root: %s', $bundle_root ) );
				}
			}

			$notice_lines = array(
				'Language FTS Extended Language Packs',
				'',
				'This bundle is an optional, separately licensed download. It is not bundled with the core plugin package.',
				'Review each included pack NOTICE, PROVENANCE, SOURCE.lock, and manifest before use.',
				'The plugin does not download, install, or activate these packs automatically.',
				'This bundle is not a WordPress.org submission, approval, endorsement, or hosted asset.',
				'',
				'Included packs:',
			);

			foreach ( $included as $row ) {
				$notice_lines[] = sprintf(
					'- %s (%s), license %s',
					$row['pack_id'],
					$row['language'],
					$row['license_spdx']
				);
			}

			$license_lines = array(
				'# Extended Language Pack License Summary',
				'',
				'This bundle contains only optional analyzer packs that are distributed separately from the core plugin package.',
				'',
				'| Pack | Language | License | Source |',
				'| --- | --- | --- | --- |',
			);

			foreach ( $included as $row ) {
				$license_lines[] = sprintf(
					'| `%s` | `%s` | `%s` | `%s` |',
					$row['pack_id'],
					$row['language'],
					$row['license_spdx'],
					$row['path']
				);
			}

			$manifest = array(
				'schema'          => 'language-fts-extended-language-packs-v1',
				'profile'         => self::PROFILE_EXTENDED_LANGUAGE_PACKS,
				'distribution'    => array(
					'optional'                         => true,
					'separately_licensed'              => true,
					'bundled_with_core_or_wporg_package' => false,
					'auto_download_or_install'          => false,
					'wordpress_org_hosted_or_endorsed'  => false,
				),
				'included_packs'  => $included,
				'excluded_packs'  => $excluded,
			);

			file_put_contents( $bundle_root . DIRECTORY_SEPARATOR . 'NOTICE.txt', implode( "\n", $notice_lines ) . "\n" );
			file_put_contents( $bundle_root . DIRECTORY_SEPARATOR . 'LICENSES.md', implode( "\n", $license_lines ) . "\n" );
			file_put_contents(
				$bundle_root . DIRECTORY_SEPARATOR . 'manifest.json',
				json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
			);
		}

		/**
		 * Returns pack metadata from a manifest path.
		 *
		 * @return array<string,mixed>
		 */
		private static function metadata_from_manifest_path( string $manifest_path, string $plugin_source ): array {
			$json = json_decode( (string) file_get_contents( $manifest_path ), true );
			if ( ! is_array( $json ) ) {
				throw new RuntimeException( sprintf( 'Invalid analyzer pack manifest JSON: %s', $manifest_path ) );
			}

			$license      = isset( $json['license'] ) && is_array( $json['license'] ) ? $json['license'] : array();
			$pack_dir     = dirname( $manifest_path );
			$notice_path  = isset( $json['notice_path'] ) ? (string) $json['notice_path'] : 'NOTICE.txt';
			$source_lock  = isset( $json['source_lock_path'] ) ? (string) $json['source_lock_path'] : 'SOURCE.lock.json';
			$provenance   = isset( $json['provenance_path'] ) ? (string) $json['provenance_path'] : 'PROVENANCE.md';
			$pack_id      = isset( $json['pack_id'] ) ? (string) $json['pack_id'] : basename( $pack_dir );
			$language     = isset( $json['lang'] ) ? (string) $json['lang'] : ( isset( $json['language'] ) ? (string) $json['language'] : '' );
			$license_spdx = isset( $license['spdx_id'] ) ? (string) $license['spdx_id'] : '';
			$license_name = isset( $license['name'] ) ? (string) $license['name'] : $license_spdx;

			return array(
				'pack_id'          => $pack_id,
				'language'         => $language,
				'license_spdx'     => $license_spdx,
				'license_name'     => $license_name,
				'fixture_only'     => ! empty( $json['fixture_only'] ),
				'path'             => self::relative_path( $pack_dir, $plugin_source ),
				'manifest_path'    => self::relative_path( $manifest_path, $plugin_source ),
				'has_notice'       => is_file( $pack_dir . DIRECTORY_SEPARATOR . $notice_path ),
				'has_provenance'   => is_file( $pack_dir . DIRECTORY_SEPARATOR . $provenance ),
				'has_source_lock'  => is_file( $pack_dir . DIRECTORY_SEPARATOR . $source_lock ),
				'raw_manifest'     => $json,
			);
		}

		/**
		 * Returns policy classification for a manifest metadata row.
		 *
		 * @return array<string,mixed>
		 */
		private static function policy_for_metadata( array $metadata ): array {
			$license = (string) ( $metadata['license_spdx'] ?? '' );

			if ( ! empty( $metadata['fixture_only'] ) ) {
				return array(
					'license_class'     => 'fixture-only',
					'included_profiles' => array(),
					'blocked_reason'    => 'Fixture-only analyzer packs are not distributable release assets.',
				);
			}

			if ( in_array( $license, self::BLOCKED_LICENSES, true ) ) {
				return array(
					'license_class'     => 'blocked',
					'included_profiles' => array(),
					'blocked_reason'    => '' === $license ? 'Missing upstream license evidence.' : 'Upstream license is not declared.',
				);
			}

			if ( in_array( $license, self::GPL_COMPATIBLE_LICENSES, true ) ) {
				return array(
					'license_class'     => 'gpl-compatible',
					'included_profiles' => array(
						self::PROFILE_GITHUB_FULL,
						self::PROFILE_EXTENDED_LANGUAGE_PACKS,
					),
					'blocked_reason'    => '',
				);
			}

			if ( in_array( $license, self::SEPARATE_LICENSE_PACK_LICENSES, true ) ) {
				return array(
					'license_class'     => 'separate-license',
					'included_profiles' => array(
						self::PROFILE_GITHUB_FULL,
						self::PROFILE_EXTENDED_LANGUAGE_PACKS,
					),
					'blocked_reason'    => '',
				);
			}

			return array(
				'license_class'     => 'blocked',
				'included_profiles' => array(),
				'blocked_reason'    => sprintf( 'License "%s" has no approved release channel policy.', $license ),
			);
		}

		/**
		 * Writes a notice into a staged plugin package.
		 *
		 * @param list<array<string,mixed>> $included Included public rows.
		 * @param list<array<string,mixed>> $excluded Excluded public rows.
		 */
		private static function write_plugin_profile_notice( string $staged_plugin_root, string $profile, array $included, array $excluded ): void {
			$lines = array(
				'Language FTS Release Channel',
				'',
				'Profile: ' . $profile,
				'',
				'The core package excludes analyzer-pack runtime data so the installable plugin ZIP remains a small, reviewable package.',
				'The GitHub full package may include separately licensed CC BY-SA UniMorph analyzer packs with notices and provenance, but unknown-license packs remain excluded.',
				'Optional analyzer packs are distributed through the signed language-pack bundle and verified before extraction.',
				'This package is not a WordPress.org submission, approval, endorsement, or hosted asset.',
				'',
				'Included analyzer packs:',
			);

			foreach ( $included as $row ) {
				$lines[] = sprintf(
					'- %s (%s), license %s',
					$row['pack_id'],
					$row['language'],
					$row['license_spdx']
				);
			}

			$lines[] = '';
			$lines[] = 'Excluded analyzer packs:';

			foreach ( $excluded as $row ) {
				$reason  = '' !== $row['blocked_reason'] ? $row['blocked_reason'] : 'Excluded by this release profile.';
				$lines[] = sprintf(
					'- %s (%s), license %s: %s',
					$row['pack_id'],
					$row['language'],
					$row['license_spdx'],
					$reason
				);
			}

			$filename = self::PROFILE_GITHUB_FULL === $profile ? 'MIXED-LICENSE-NOTICE.txt' : 'RELEASE-CHANNEL.txt';
			file_put_contents( $staged_plugin_root . DIRECTORY_SEPARATOR . $filename, implode( "\n", $lines ) . "\n" );
		}

		/**
		 * Returns analyzer pack manifest paths.
		 *
		 * @return list<string>
		 */
		private static function analyzer_pack_manifest_paths( string $plugin_source ): array {
			$base = rtrim( $plugin_source, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'analyzer-packs';

			if ( ! is_dir( $base ) ) {
				return array();
			}

			$paths = glob( $base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json' );
			if ( false === $paths ) {
				return array();
			}

			sort( $paths );
			return array_values( $paths );
		}

		/**
		 * Removes a file or directory recursively.
		 */
		private static function remove_path( string $path ): void {
			if ( is_link( $path ) || is_file( $path ) ) {
				if ( ! unlink( $path ) ) {
					throw new RuntimeException( sprintf( 'Failed to remove file: %s', $path ) );
				}
				return;
			}

			if ( ! is_dir( $path ) ) {
				return;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $item ) {
				/** @var SplFileInfo $item */
				$item_path = $item->getPathname();
				if ( $item->isDir() && ! $item->isLink() ) {
					if ( ! rmdir( $item_path ) ) {
						throw new RuntimeException( sprintf( 'Failed to remove directory: %s', $item_path ) );
					}
				} elseif ( ! unlink( $item_path ) ) {
					throw new RuntimeException( sprintf( 'Failed to remove file: %s', $item_path ) );
				}
			}

			if ( ! rmdir( $path ) ) {
				throw new RuntimeException( sprintf( 'Failed to remove directory: %s', $path ) );
			}
		}

		/**
		 * Returns $path relative to $base using forward slashes.
		 */
		private static function relative_path( string $path, string $base ): string {
			$path = str_replace( '\\', '/', realpath( $path ) ?: $path );
			$base = str_replace( '\\', '/', realpath( $base ) ?: $base );
			$base = rtrim( $base, '/' ) . '/';

			if ( 0 === strpos( $path, $base ) ) {
				return ltrim( substr( $path, strlen( $base ) ), '/' );
			}

			return ltrim( $path, '/' );
		}
	}
}
