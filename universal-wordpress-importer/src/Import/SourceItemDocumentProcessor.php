<?php
/**
 * First-pass document source item processor.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;
use SimpleXMLElement;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\ImportEntity;
use ZipArchive;

/**
 * Classifies discovered local files and prepares initial block markup.
 */
final class SourceItemDocumentProcessor {
	const DEFAULT_ITEM_LIMIT       = 100;
	const READ_CHUNK_BYTES         = 65536;
	const TEXT_CHUNK_BYTES         = 262144;
	const SCRIPT_CARRY_BYTES       = 128;
	const WXR_POST_LIMIT           = 25;
	const EPUB_SPINE_LIMIT         = 25;
	const EPUB_ENTRY_LIMIT         = 8388608;
	const EPUB_TOC_LIMIT           = 500;
	const PDF_FILE_LIMIT           = 16777216;
	const PDF_TEXT_LIMIT           = 1048576;
	const PDF_TEXT_TIMEOUT         = 60;
	const PDF_OCR_TIMEOUT          = 60;
	const PDF_OCR_ERROR_LIMIT      = 2048;
	const PDF_MEDIA_LIMIT          = 10;
	const PDF_MEDIA_SCAN_LIMIT     = 5;
	const PDF_STRUCTURE_SCAN_LIMIT = 5;
	const PDF_TEXT_SCAN_LIMIT      = 5;
	const PDF_MEDIA_FILE_LIMIT     = 8388608;
	const PDF_TABLE_MIN_ROWS       = 2;

	/**
	 * EPUB manifest media types handled by the attachment pipeline.
	 *
	 * @var array<string,string>
	 */
	private static $epub_media_types = array(
		'image/jpeg'      => ImportMediaReference::TYPE_IMAGE,
		'image/jpg'       => ImportMediaReference::TYPE_IMAGE,
		'image/png'       => ImportMediaReference::TYPE_IMAGE,
		'image/gif'       => ImportMediaReference::TYPE_IMAGE,
		'image/webp'      => ImportMediaReference::TYPE_IMAGE,
		'image/avif'      => ImportMediaReference::TYPE_IMAGE,
		'image/svg+xml'   => ImportMediaReference::TYPE_IMAGE,
		'audio/mpeg'      => ImportMediaReference::TYPE_AUDIO,
		'audio/mp4'       => ImportMediaReference::TYPE_AUDIO,
		'audio/ogg'       => ImportMediaReference::TYPE_AUDIO,
		'audio/wav'       => ImportMediaReference::TYPE_AUDIO,
		'video/mp4'       => ImportMediaReference::TYPE_VIDEO,
		'video/webm'      => ImportMediaReference::TYPE_VIDEO,
		'video/quicktime' => ImportMediaReference::TYPE_VIDEO,
		'application/pdf' => ImportMediaReference::TYPE_FILE,
	);

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Importer-owned cache directory for extracted package assets.
	 *
	 * @var ImportCacheDirectory
	 */
	private $cache_directory;

	/**
	 * Hidden test controls.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore $store           Durable store.
	 * @param ImportCacheDirectory|null   $cache_directory Optional cache directory.
	 * @param ImportRunnerControls|null   $controls        Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportCacheDirectory $cache_directory = null, ImportRunnerControls $controls = null ) {
		$this->store           = $store;
		$this->cache_directory = null === $cache_directory ? ImportCacheDirectory::from_environment() : $cache_directory;
		$this->controls        = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances document processing for discovered files.
	 *
	 * @param ImportSession $session Session to advance.
	 * @param int           $limit   Maximum discovered file items to inspect.
	 * @return array{imported:int,skipped:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_ITEM_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => 'No discovered document items were ready to process.',
		);

		foreach ( $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), $limit ) as $item ) {
			if ( ImportSourceItem::TYPE_FILE !== $item->get_type() ) {
				continue;
			}

			$item_summary = $this->process_item( $session, $item );
			++$summary[ $item_summary ];
		}

		if ( 0 < $summary['imported'] || 0 < $summary['skipped'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Document processor handled discovered file items.';
		}

		return $summary;
	}

	/**
	 * Processes one discovered file item.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 */
	private function process_item( ImportSession $session, ImportSourceItem $item ) {
		$format = $this->classify( $item );

		if ( null === $format ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_SKIPPED )->with_metadata(
					array(
						'processor_status' => 'skipped',
						'skip_reason'      => 'Unsupported file extension for the first document processor pass.',
					)
				)
			);
			$this->record_event( $session, 'document.skipped_unsupported', 'Source item was skipped because its format is not supported yet.', $item, array() );
			return 'skipped';
		}

		if ( 'wxr' === $format ) {
			return $this->process_wxr_item( $session, $item );
		}

		if ( 'epub' === $format ) {
			return $this->process_epub_item( $session, $item );
		}

		if ( 'pdf' === $format ) {
			return $this->process_pdf_item( $session, $item );
		}

		if ( 'text' === $format ) {
			return $this->process_text_item( $session, $item );
		}

		if ( 'markdown' === $format && $this->should_process_markdown_incrementally( $item ) ) {
			return $this->process_markdown_item( $session, $item );
		}

		if ( 'html' === $format && $this->should_process_html_incrementally( $item ) ) {
			return $this->process_html_item( $session, $item );
		}

		try {
			$document = $this->prepare_document( $session, $item, $format );
			$metadata = array(
				'processor_status' => 'imported',
				'document_format'  => $format,
				'content_hash'     => $document['content_hash'],
				'title'            => $document['title'],
				'block_count'      => $document['block_count'],
			);
			if ( ! empty( $document['metadata'] ) ) {
				$metadata = array_merge( $metadata, $document['metadata'] );
			}
			if ( ! empty( $document['absolute_url_domains'] ) ) {
				$metadata['absolute_url_domains'] = $document['absolute_url_domains'];
			}
			$prepared_document = new ImportPreparedDocument(
				$session->get_id(),
				$item->get_key(),
				$format,
				$document['title'],
				$document['block_markup'],
				$document['block_count'],
				$document['content_hash'],
				array_merge(
					array(
						'relative_path'         => $item->get_relative_path(),
						'source_uri'            => $item->get_source_uri(),
						'absolute_url_domains'  => $document['absolute_url_domains'],
						'absolute_url_examples' => $document['absolute_url_examples'],
					),
					$document['metadata']
				)
			);

			$this->store->save_prepared_document( $prepared_document );
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'document-blocks:' . $item->get_key(),
					'prepared_document',
					$prepared_document->get_source_item_key(),
					$document['content_hash']
				)
			);
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_IMPORTED )->with_metadata( $metadata ) );
			if ( 'pdf' === $format ) {
				$this->record_pdf_structure_warning_event( $session, $item, $metadata );
			}
			$this->record_event(
				$session,
				'document.prepared',
				'Source item was converted into initial block markup.',
				$item,
				array(
					'format'      => $format,
					'block_count' => $document['block_count'],
				)
			);
			return 'imported';
		} catch ( RuntimeException $exception ) {
			$failure_metadata = array(
				'processor_status' => 'failed',
				'document_format'  => $format,
				'error'            => $exception->getMessage(),
			);
			if ( $exception instanceof ImportDocumentProcessingException ) {
				$failure_metadata = array_merge( $failure_metadata, $exception->get_metadata() );
			}
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata( $failure_metadata )
			);
			if ( 'pdf' === $format ) {
				$this->record_pdf_structure_warning_event( $session, $item, $failure_metadata );
			}
			$this->record_event( $session, 'document.failed', $exception->getMessage(), $item, array( 'format' => $format ) );
			return 'failed';
		}
	}

	/**
	 * Processes a PDF item after a bounded, durable embedded-media scan.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 */
	private function process_pdf_item( ImportSession $session, ImportSourceItem $item ) {
		try {
			$this->assert_pdf_file_within_first_pass_limit( $item->get_source_uri() );
			$structure_summary = $this->process_pdf_structure_scan( $session, $item );

			if ( empty( $structure_summary['complete'] ) ) {
				return 'imported';
			}

			if ( ! empty( $structure_summary['metadata'] ) ) {
				$item = $item->with_replaced_metadata( $structure_summary['metadata'] );
			}

			$pdf_asset_summary = $this->queue_pdf_embedded_media_references( $session, $item );

			if ( empty( $pdf_asset_summary['complete'] ) ) {
				$this->store->save_source_item(
					$item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata(
						$this->pdf_media_scan_metadata( $item->get_metadata(), $pdf_asset_summary, false )
					)
				);
				$this->record_event(
					$session,
					'document.pdf_media_progress',
					'PDF embedded media scan was partially completed and can resume from its stored object cursor.',
					$item,
					array(
						'queued'      => $pdf_asset_summary['queued'],
						'unsupported' => $pdf_asset_summary['unsupported'],
						'cursor'      => $pdf_asset_summary['next_index'],
					)
				);

				return 'imported';
			}

			$text_scan_result = $this->process_pdf_native_text_scan( $session, $item, $pdf_asset_summary );
			if ( null !== $text_scan_result ) {
				return $text_scan_result;
			}

			$document = $this->prepare_document( $session, $item, 'pdf', $pdf_asset_summary );
			$metadata = array(
				'processor_status' => 'imported',
				'document_format'  => 'pdf',
				'content_hash'     => $document['content_hash'],
				'title'            => $document['title'],
				'block_count'      => $document['block_count'],
			);
			if ( ! empty( $document['metadata'] ) ) {
				$metadata = array_merge( $metadata, $document['metadata'] );
			}
			if ( ! empty( $document['absolute_url_domains'] ) ) {
				$metadata['absolute_url_domains'] = $document['absolute_url_domains'];
			}

			$prepared_document = new ImportPreparedDocument(
				$session->get_id(),
				$item->get_key(),
				'pdf',
				$document['title'],
				$document['block_markup'],
				$document['block_count'],
				$document['content_hash'],
				array_merge(
					array(
						'relative_path'         => $item->get_relative_path(),
						'source_uri'            => $item->get_source_uri(),
						'absolute_url_domains'  => $document['absolute_url_domains'],
						'absolute_url_examples' => $document['absolute_url_examples'],
					),
					$document['metadata']
				)
			);

			$this->store->save_prepared_document( $prepared_document );
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'document-blocks:' . $item->get_key(),
					'prepared_document',
					$prepared_document->get_source_item_key(),
					$document['content_hash']
				)
			);
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_IMPORTED )->with_replaced_metadata( $metadata ) );
			$this->record_pdf_structure_warning_event( $session, $item, $metadata );
			$this->record_event(
				$session,
				'document.prepared',
				'Source item was converted into initial block markup.',
				$item,
				array(
					'format'      => 'pdf',
					'block_count' => $document['block_count'],
				)
			);

			return 'imported';
		} catch ( RuntimeException $exception ) {
			$failure_metadata = array(
				'processor_status' => 'failed',
				'document_format'  => 'pdf',
				'error'            => $exception->getMessage(),
			);
			if ( $exception instanceof ImportDocumentProcessingException ) {
				$failure_metadata = array_merge( $failure_metadata, $exception->get_metadata() );
			}
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata( $failure_metadata )
			);
			$this->record_pdf_structure_warning_event( $session, $item, $failure_metadata );
			$this->record_event( $session, 'document.failed', $exception->getMessage(), $item, array( 'format' => 'pdf' ) );

			return 'failed';
		}
	}

	/**
	 * Incrementally scans PDF structure diagnostics before media/text work.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    PDF source item.
	 * @return array{complete:bool,metadata:array<string,mixed>}
	 */
	private function process_pdf_structure_scan( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		if ( ! empty( $metadata['pdf_structure_scan_complete'] ) ) {
			return array(
				'complete' => true,
				'metadata' => $metadata,
			);
		}

		if ( ! is_file( $item->get_source_uri() ) || ! is_readable( $item->get_source_uri() ) ) {
			return array(
				'complete' => true,
				'metadata' => $metadata,
			);
		}

		$next_offset  = isset( $metadata['pdf_structure_next_offset'] ) ? max( 0, (int) $metadata['pdf_structure_next_offset'] ) : 0;
		$stream_index = isset( $metadata['pdf_structure_stream_index'] ) ? max( 0, (int) $metadata['pdf_structure_stream_index'] ) : 0;
		$diagnostics  = isset( $metadata['pdf_structure_scan_diagnostics'] ) && is_array( $metadata['pdf_structure_scan_diagnostics'] ) ? $metadata['pdf_structure_scan_diagnostics'] : array();
		$pdf          = $this->read_pdf_scan_bytes( $item->get_source_uri(), $next_offset );

		if ( false === $pdf || '' === $pdf ) {
			$next_metadata = $this->pdf_structure_scan_metadata( $metadata, $diagnostics, true, $next_offset, $stream_index, $next_offset, 0 );
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata ) );

			return array(
				'complete' => true,
				'metadata' => $next_metadata,
			);
		}

		$scan          = $this->extract_pdf_structure_scan( $pdf, 0, self::PDF_STRUCTURE_SCAN_LIMIT, $stream_index, $next_offset );
		$diagnostics   = $this->merge_pdf_structure_scan_diagnostics( $diagnostics, $scan['diagnostics'] );
		$is_complete   = ! empty( $scan['complete'] );
		$next_metadata = $this->pdf_structure_scan_metadata(
			$metadata,
			$diagnostics,
			$is_complete,
			$scan['next_offset'],
			$scan['next_index'],
			$next_offset,
			strlen( $pdf )
		);

		$this->store->save_source_item(
			$item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata )
		);

		if ( $is_complete ) {
			$this->record_pdf_structure_warning_event( $session, $item, $next_metadata );
		}

		if ( ! $is_complete ) {
			$this->record_event(
				$session,
				'document.pdf_structure_progress',
				'PDF structure diagnostics were partially scanned and can resume from the stored stream cursor.',
				$item,
				array(
					'offset' => $scan['next_offset'],
					'stream' => $scan['next_index'],
				)
			);

			if ( $this->controls->should_simulate_fatal_after_pdf_structure_cursor() ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_ERROR,
						'runner.simulated_fatal_after_pdf_structure_cursor',
						'Runner is terminating PHP after a durable PDF structure cursor write for recovery testing.',
						array(
							'item_key' => $item->get_key(),
							'offset'   => $scan['next_offset'],
							'stream'   => $scan['next_index'],
						)
					)
				);

				exit( 119 );
			}
		}

		return array(
			'complete' => $is_complete,
			'metadata' => $next_metadata,
		);
	}

	/**
	 * Incrementally scans native PDF text streams into prepared document chunks.
	 *
	 * The first pass stays on the historical single-document path when all
	 * native text streams fit in one bounded scan. Once a PDF needs multiple
	 * scan batches, each extracted native text stream is staged as an idempotent
	 * prepared document chunk and the source item stores the next byte cursor.
	 *
	 * @param ImportSession       $session           Session.
	 * @param ImportSourceItem    $item              PDF source item.
	 * @param array<string,mixed> $pdf_asset_summary Pre-scanned embedded media summary.
	 * @return string|null Summary bucket when the incremental path handled the item, otherwise null.
	 */
	private function process_pdf_native_text_scan( ImportSession $session, ImportSourceItem $item, array $pdf_asset_summary ) {
		$metadata       = $item->get_metadata();
		$is_resuming    = isset( $metadata['pdf_processing_phase'] ) && 'text_scan' === $metadata['pdf_processing_phase'];
		$next_offset    = isset( $metadata['pdf_text_next_offset'] ) ? max( 0, (int) $metadata['pdf_text_next_offset'] ) : 0;
		$stream_index   = isset( $metadata['pdf_text_stream_index'] ) ? max( 0, (int) $metadata['pdf_text_stream_index'] ) : 0;
		$chunk_index    = isset( $metadata['pdf_text_chunk_index'] ) ? max( 0, (int) $metadata['pdf_text_chunk_index'] ) : 0;
		$chunks_so_far  = isset( $metadata['pdf_text_chunks_prepared'] ) ? max( 0, (int) $metadata['pdf_text_chunks_prepared'] ) : 0;
		$streams_so_far = isset( $metadata['pdf_text_streams_scanned'] ) ? max( 0, (int) $metadata['pdf_text_streams_scanned'] ) : 0;

		if ( ! is_file( $item->get_source_uri() ) || ! is_readable( $item->get_source_uri() ) ) {
			return null;
		}

		$pdf = $this->read_pdf_scan_bytes( $item->get_source_uri(), $next_offset );
		if ( false === $pdf || '' === $pdf ) {
			return null;
		}

		$scan = $this->extract_pdf_text_stream_scan( $pdf, 0, self::PDF_TEXT_SCAN_LIMIT, $stream_index, $next_offset );

		if ( ! $is_resuming && ! empty( $scan['complete'] ) ) {
			return null;
		}

		if ( ! $is_resuming && (int) $scan['diagnostics']['text_operators'] < self::PDF_TEXT_SCAN_LIMIT ) {
			return null;
		}

		$structure_diagnostics = $this->pdf_structure_metadata_from_existing_metadata( $metadata );
		if ( empty( $structure_diagnostics ) && 0 === $next_offset ) {
			$structure_diagnostics = $this->analyze_pdf_structure( $pdf );
		}
		$fragments       = isset( $metadata['pdf_text_fragments'] ) && is_array( $metadata['pdf_text_fragments'] ) ? array_values( $metadata['pdf_text_fragments'] ) : array();
		$fragments_added = 0;
		$url_domains     = isset( $metadata['absolute_url_examples'] ) && is_array( $metadata['absolute_url_examples'] ) ? $metadata['absolute_url_examples'] : array();
		$last_end        = $next_offset;
		$last_stream     = $stream_index;
		$diagnostics     = isset( $metadata['pdf_text_scan_diagnostics'] ) && is_array( $metadata['pdf_text_scan_diagnostics'] ) ? $metadata['pdf_text_scan_diagnostics'] : array();
		$diagnostics     = $this->merge_pdf_text_scan_diagnostics( $diagnostics, $scan['diagnostics'] );

		foreach ( $scan['streams'] as $stream ) {
			$text = $this->normalize_extracted_pdf_text( $this->extract_pdf_text_operators( $stream['content'] ) );

			$last_end    = (int) $stream['next_offset'];
			$last_stream = (int) $stream['index'] + 1;

			if ( '' === trim( $text ) ) {
				continue;
			}

			$chunk_domains  = $this->extract_absolute_url_domains( $text );
			$url_domains    = $this->merge_absolute_url_domain_examples( $url_domains, $chunk_domains );
			$fragment_count = count( $fragments );
			$fragments      = $this->append_pdf_text_fragment( $fragments, $text );
			if ( count( $fragments ) > $fragment_count ) {
				++$fragments_added;
				++$chunk_index;
			}
		}

		$chunks_total  = $chunks_so_far + $fragments_added;
		$streams_total = max( $streams_so_far + count( $scan['streams'] ), $last_stream );
		$is_complete   = ! empty( $scan['complete'] );

		if ( 0 === $chunks_total && $is_complete ) {
			return null;
		}

		$next_metadata = $this->pdf_text_scan_metadata(
			$metadata,
			$pdf_asset_summary,
			$diagnostics,
			$is_complete,
			$last_end,
			$last_stream,
			$chunk_index,
			$chunks_total,
			$streams_total,
			$url_domains,
			$structure_diagnostics,
			$next_offset
		);

		if ( $is_complete ) {
			unset( $next_metadata['pdf_text_fragments'] );
			$next_metadata = $this->save_pdf_text_scan_document( $session, $item, $fragments, $pdf_asset_summary, $next_metadata );
		} else {
			$next_metadata['pdf_text_fragments'] = $fragments;
		}

		$this->store->save_source_item(
			$item->with_status( $is_complete ? ImportSourceItem::STATUS_IMPORTED : ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata )
		);
		if ( empty( $metadata['pdf_structure_warning'] ) ) {
			$this->record_pdf_structure_warning_event( $session, $item, $next_metadata );
		}

		$this->record_event(
			$session,
			$is_complete ? 'document.pdf_text_complete' : 'document.pdf_text_progress',
			$is_complete ? 'PDF native text streams were scanned into prepared block chunks.' : 'PDF native text streams were partially scanned and can resume from the stored stream cursor.',
			$item,
			array(
				'offset'   => $last_end,
				'stream'   => $last_stream,
				'chunks'   => $chunks_total,
				'prepared' => $is_complete ? 1 : 0,
			)
		);

		return 'imported';
	}

	/**
	 * Classifies a source item by extension.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return string|null
	 */
	private function classify( ImportSourceItem $item ) {
		$metadata  = $item->get_metadata();
		$extension = isset( $metadata['extension'] ) ? strtolower( (string) $metadata['extension'] ) : strtolower( pathinfo( $item->get_source_uri(), PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'md', 'markdown', 'mdown' ), true ) ) {
			return 'markdown';
		}

		if ( in_array( $extension, array( 'html', 'htm' ), true ) ) {
			return 'html';
		}

		if ( in_array( $extension, array( 'txt', 'text' ), true ) ) {
			return 'text';
		}

		if ( in_array( $extension, array( 'wxr', 'xml' ), true ) ) {
			return 'wxr';
		}

		if ( 'epub' === $extension ) {
			return 'epub';
		}

		if ( 'pdf' === $extension ) {
			return 'pdf';
		}

		return null;
	}

	/**
	 * Prepares EPUB spine documents incrementally.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 * @throws RuntimeException When EPUB processing fails before a failure event can be recorded.
	 */
	private function process_epub_item( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();
		$cursor   = isset( $metadata['epub_spine_index'] ) ? max( 0, (int) $metadata['epub_spine_index'] ) : 0;

		try {
			if ( ! class_exists( ZipArchive::class ) ) {
				throw new RuntimeException( 'EPUB processing is unavailable because the PHP zip extension is not loaded.' );
			}

			if ( ! is_file( $item->get_source_uri() ) || ! is_readable( $item->get_source_uri() ) ) {
				throw new RuntimeException( 'Discovered EPUB item is no longer a readable file.' );
			}

			$zip = new ZipArchive();

			if ( true !== $zip->open( $item->get_source_uri() ) ) {
				throw new RuntimeException( 'Unable to open discovered EPUB archive.' );
			}

			try {
				$epub              = $this->read_epub_package( $zip );
				$spine             = $epub['spine'];
				$navigation        = $epub['navigation'];
				$should_record_toc = empty( $metadata['epub_toc_recorded'] ) && ( ! empty( $navigation['entries'] ) || ! empty( $navigation['error'] ) );
				$prepared          = 0;
				$skipped           = 0;
				$limit             = min( count( $spine ), $cursor + self::EPUB_SPINE_LIMIT );
				$base_metadata     = $this->epub_source_metadata( $metadata, $epub, $navigation, false, 0, 0 );

				for ( $index = $cursor; $index < $limit; ++$index ) {
					$itemref = $spine[ $index ];

					if ( empty( $epub['manifest'][ $itemref ] ) ) {
						++$skipped;
						continue;
					}

					if ( $this->prepare_epub_spine_document( $session, $item, $zip, $epub, $itemref, $index ) ) {
						++$prepared;
					}

					$progress_metadata                     = $this->epub_source_metadata( $base_metadata, $epub, $navigation, false, $prepared, $skipped );
					$progress_metadata['epub_spine_index'] = $index + 1;
					$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $progress_metadata ) );

					if ( $this->controls->should_simulate_fatal_after_epub_spine_cursor() && ( $index + 1 ) < count( $spine ) ) {
						$this->store->record_event(
							$session->get_id(),
							new ImportProgressEvent(
								ImportProgressEvent::LEVEL_ERROR,
								'runner.simulated_fatal_after_epub_spine_cursor',
								'Runner is terminating PHP after a durable EPUB spine cursor write for recovery testing.',
								array(
									'item_key' => $item->get_key(),
									'cursor'   => $index + 1,
									'prepared' => $prepared,
									'skipped'  => $skipped,
								)
							)
						);

						exit( 123 );
					}
				}
			} finally {
				$zip->close();
			}

			$next_cursor = $limit;
			$is_complete = $next_cursor >= count( $spine );
			$metadata    = $this->epub_source_metadata( $base_metadata, $epub, $navigation, $is_complete, $prepared, $skipped );
			if ( $should_record_toc ) {
				$metadata['epub_toc_recorded'] = true;
			}

			if ( $is_complete ) {
				unset( $metadata['epub_spine_index'] );
				$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_IMPORTED )->with_replaced_metadata( $metadata ) );
				$this->record_event(
					$session,
					'document.epub_complete',
					'EPUB source item was fully parsed into prepared spine documents.',
					$item,
					array(
						'prepared' => $prepared,
						'skipped'  => $skipped,
						'chapters' => count( $spine ),
					)
				);
			} else {
				$metadata['epub_spine_index'] = $next_cursor;
				$this->store->save_source_item( $item->with_metadata( $metadata ) );
				$this->record_event(
					$session,
					'document.epub_progress',
					'EPUB source item was partially parsed and can resume from its stored spine index.',
					$item,
					array(
						'prepared' => $prepared,
						'skipped'  => $skipped,
						'cursor'   => $next_cursor,
					)
				);
			}

			if ( $should_record_toc ) {
				$this->record_event(
					$session,
					empty( $navigation['error'] ) ? 'document.epub_toc_staged' : 'document.epub_toc_failed',
					empty( $navigation['error'] ) ? 'EPUB navigation metadata was staged from the package TOC.' : $navigation['error'],
					$item,
					array(
						'toc_source' => $navigation['source'],
						'toc_entry'  => $navigation['entry'],
						'toc_count'  => count( $navigation['entries'] ),
					)
				);
			}

			return 'imported';
		} catch ( RuntimeException $exception ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
					array(
						'processor_status' => 'failed',
						'document_format'  => 'epub',
						'error'            => $exception->getMessage(),
					)
				)
			);
			$this->record_event( $session, 'document.failed', $exception->getMessage(), $item, array( 'format' => 'epub' ) );
			return 'failed';
		}
	}

	/**
	 * Builds durable EPUB source item metadata after a spine cursor advances.
	 *
	 * @param array<string,mixed> $metadata   Existing metadata.
	 * @param array<string,mixed> $epub       Parsed EPUB package data.
	 * @param array<string,mixed> $navigation Parsed navigation data.
	 * @param bool                $complete   Whether processing is complete.
	 * @param int                 $prepared   Prepared count in this pass.
	 * @param int                 $skipped    Skipped count in this pass.
	 * @return array<string,mixed>
	 */
	private function epub_source_metadata( array $metadata, array $epub, array $navigation, $complete, $prepared, $skipped ) {
		$metadata = array_merge(
			$metadata,
			array(
				'processor_status'       => $complete ? 'imported' : 'partial',
				'document_format'        => 'epub',
				'epub_title'             => $epub['title'],
				'epub_spine_count'       => count( $epub['spine'] ),
				'epub_chapters_prepared' => ( isset( $metadata['epub_chapters_prepared'] ) ? (int) $metadata['epub_chapters_prepared'] : 0 ) + (int) $prepared,
				'epub_chapters_skipped'  => ( isset( $metadata['epub_chapters_skipped'] ) ? (int) $metadata['epub_chapters_skipped'] : 0 ) + (int) $skipped,
				'epub_toc_source'        => $navigation['source'],
				'epub_toc_count'         => count( $navigation['entries'] ),
			)
		);

		if ( ! empty( $navigation['entry'] ) ) {
			$metadata['epub_toc_entry'] = $navigation['entry'];
		}

		if ( ! empty( $navigation['entries'] ) ) {
			$metadata['epub_toc_entries'] = $navigation['entries'];
		}

		if ( ! empty( $navigation['error'] ) ) {
			$metadata['epub_toc_error'] = $navigation['error'];
		}

		return $metadata;
	}

	/**
	 * Reads EPUB package metadata, manifest, and spine.
	 *
	 * @param ZipArchive $zip Open EPUB archive.
	 * @return array{package_path:string,package_dir:string,title:string,manifest:array<string,array<string,mixed>>,manifest_by_entry:array<string,array<string,mixed>>,spine:array<int,string>,spine_entries:array<string,array{index:int,idref:string}>,navigation:array{source:string,entry:string,entries:array<int,array<string,mixed>>,error:string}}
	 * @throws RuntimeException When required EPUB structures are missing.
	 */
	private function read_epub_package( ZipArchive $zip ) {
		$container_xml = $this->read_zip_entry( $zip, 'META-INF/container.xml' );
		$container     = $this->parse_xml( $container_xml, 'EPUB container metadata is not valid XML.' );
		$rootfiles     = $container->xpath( '//*[local-name()="rootfile"]' );

		if ( empty( $rootfiles ) ) {
			throw new RuntimeException( 'EPUB container does not declare a package document.' );
		}

		$package_path = (string) $rootfiles[0]['full-path'];

		if ( '' === $package_path || $this->is_unsafe_epub_path( $package_path ) ) {
			throw new RuntimeException( 'EPUB package document path is missing or unsafe.' );
		}

		$package_xml = $this->read_zip_entry( $zip, $package_path );
		$package     = $this->parse_xml( $package_xml, 'EPUB package document is not valid XML.' );
		$package_dir = dirname( $package_path );

		if ( '.' === $package_dir ) {
			$package_dir = '';
		}

		$title_nodes       = $package->xpath( '//*[local-name()="metadata"]/*[local-name()="title"]' );
		$title             = empty( $title_nodes ) ? '' : trim( (string) $title_nodes[0] );
		$manifest          = array();
		$manifest_by_entry = array();

		foreach ( $package->xpath( '//*[local-name()="manifest"]/*[local-name()="item"]' ) as $manifest_item ) {
			$id   = trim( (string) $manifest_item['id'] );
			$href = trim( (string) $manifest_item['href'] );

			if ( '' === $id || '' === $href || $this->is_unsafe_epub_path( $href ) ) {
				continue;
			}

			$entry = array(
				'href'       => $this->join_epub_path( $package_dir, $href ),
				'raw_href'   => $href,
				'media_type' => trim( (string) $manifest_item['media-type'] ),
				'properties' => $this->split_epub_properties( (string) $manifest_item['properties'] ),
			);

			$manifest[ $id ]                     = $entry;
			$manifest_by_entry[ $entry['href'] ] = $entry;
		}

		$spine         = array();
		$spine_entries = array();

		$spine_toc_id = '';
		$spine_nodes  = $package->xpath( '//*[local-name()="spine"]' );

		if ( ! empty( $spine_nodes ) ) {
			$spine_toc_id = trim( (string) $spine_nodes[0]['toc'] );
		}

		foreach ( $package->xpath( '//*[local-name()="spine"]/*[local-name()="itemref"]' ) as $itemref ) {
			$idref = trim( (string) $itemref['idref'] );

			if ( '' !== $idref ) {
				$spine[] = $idref;
			}
		}

		if ( empty( $manifest ) || empty( $spine ) ) {
			throw new RuntimeException( 'EPUB package document must include a manifest and spine.' );
		}

		foreach ( $spine as $index => $idref ) {
			if ( isset( $manifest[ $idref ] ) ) {
				$spine_entries[ $manifest[ $idref ]['href'] ] = array(
					'index' => $index,
					'idref' => $idref,
				);
			}
		}

		return array(
			'package_path'      => $package_path,
			'package_dir'       => $package_dir,
			'title'             => '' === $title ? 'EPUB document' : $title,
			'manifest'          => $manifest,
			'manifest_by_entry' => $manifest_by_entry,
			'spine'             => $spine,
			'spine_entries'     => $spine_entries,
			'navigation'        => $this->read_epub_navigation( $zip, $manifest, $spine_entries, $spine_toc_id ),
		);
	}

	/**
	 * Splits an EPUB manifest properties attribute into normalized tokens.
	 *
	 * @param string $properties Raw manifest properties.
	 * @return array<int,string>
	 */
	private function split_epub_properties( $properties ) {
		$tokens = preg_split( '/\s+/', strtolower( trim( (string) $properties ) ) );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return array_values( array_filter( $tokens ) );
	}

	/**
	 * Reads EPUB navigation metadata from an EPUB 3 nav document or EPUB 2 NCX manifest.
	 *
	 * @param ZipArchive                                  $zip            Open archive.
	 * @param array<string,array<string,mixed>>           $manifest       Parsed manifest.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @param string                                      $spine_toc_id   OPF spine toc id.
	 * @return array{source:string,entry:string,entries:array<int,array<string,mixed>>,error:string}
	 */
	private function read_epub_navigation( ZipArchive $zip, array $manifest, array $spine_entries, $spine_toc_id ) {
		$fallback = array(
			'source'  => 'none',
			'entry'   => '',
			'entries' => array(),
			'error'   => '',
		);

		$nav_item = $this->find_epub_nav_manifest_item( $manifest );

		if ( null !== $nav_item ) {
			try {
				$xml     = $this->read_zip_entry( $zip, $nav_item['href'] );
				$entries = $this->parse_epub_nav_document( $xml, $nav_item['href'], $spine_entries );

				return array(
					'source'  => 'nav',
					'entry'   => $nav_item['href'],
					'entries' => $entries,
					'error'   => '',
				);
			} catch ( RuntimeException $exception ) {
				return array(
					'source'  => 'nav',
					'entry'   => isset( $nav_item['href'] ) ? (string) $nav_item['href'] : '',
					'entries' => array(),
					'error'   => 'EPUB navigation document could not be parsed: ' . $exception->getMessage(),
				);
			}
		}

		$ncx_item = $this->find_epub_ncx_manifest_item( $manifest, $spine_toc_id );

		if ( null === $ncx_item ) {
			return $fallback;
		}

		try {
			$xml     = $this->read_zip_entry( $zip, $ncx_item['href'] );
			$entries = $this->parse_epub_ncx_document( $xml, $ncx_item['href'], $spine_entries );

			return array(
				'source'  => 'ncx',
				'entry'   => $ncx_item['href'],
				'entries' => $entries,
				'error'   => '',
			);
		} catch ( RuntimeException $exception ) {
			return array(
				'source'  => 'ncx',
				'entry'   => isset( $ncx_item['href'] ) ? (string) $ncx_item['href'] : '',
				'entries' => array(),
				'error'   => 'EPUB NCX table of contents could not be parsed: ' . $exception->getMessage(),
			);
		}
	}

	/**
	 * Finds the EPUB 3 navigation manifest item.
	 *
	 * @param array<string,array<string,mixed>> $manifest Parsed manifest.
	 * @return array<string,mixed>|null
	 */
	private function find_epub_nav_manifest_item( array $manifest ) {
		foreach ( $manifest as $item ) {
			$properties = isset( $item['properties'] ) && is_array( $item['properties'] ) ? $item['properties'] : array();

			if ( in_array( 'nav', $properties, true ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Finds the EPUB 2 NCX manifest item.
	 *
	 * @param array<string,array<string,mixed>> $manifest     Parsed manifest.
	 * @param string                            $spine_toc_id OPF spine toc id.
	 * @return array<string,mixed>|null
	 */
	private function find_epub_ncx_manifest_item( array $manifest, $spine_toc_id ) {
		$spine_toc_id = trim( (string) $spine_toc_id );

		if ( '' !== $spine_toc_id && isset( $manifest[ $spine_toc_id ] ) ) {
			return $manifest[ $spine_toc_id ];
		}

		foreach ( $manifest as $item ) {
			$media_type = isset( $item['media_type'] ) ? strtolower( trim( (string) $item['media_type'] ) ) : '';

			if ( 'application/x-dtbncx+xml' === $media_type ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Parses an EPUB 3 navigation document into flat, bounded TOC entries.
	 *
	 * @param string                                      $xml           Navigation document XML.
	 * @param string                                      $entry_path    Navigation entry archive path.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When the navigation document is invalid.
	 */
	private function parse_epub_nav_document( $xml, $entry_path, array $spine_entries ) {
		$document = $this->parse_xml( $xml, 'EPUB navigation document is not valid XML.' );
		$navs     = $document->xpath( '//*[local-name()="nav" and contains(concat(" ", normalize-space(@*[local-name()="type"]), " "), " toc ")]' );

		if ( empty( $navs ) ) {
			$navs = $document->xpath( '//*[local-name()="nav"]' );
		}

		if ( empty( $navs ) ) {
			return array();
		}

		$entry_dir = dirname( (string) $entry_path );

		if ( '.' === $entry_dir ) {
			$entry_dir = '';
		}

		$entries = array();
		$items   = $navs[0]->xpath( './*[local-name()="ol"]/*[local-name()="li"]' );
		$this->collect_epub_nav_list_items( is_array( $items ) ? $items : array(), $entry_dir, $spine_entries, 1, $entries );

		return $entries;
	}

	/**
	 * Collects EPUB 3 nav list items recursively.
	 *
	 * @param array<int,SimpleXMLElement>                 $items         List item nodes.
	 * @param string                                      $entry_dir     Navigation entry directory.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @param int                                         $depth         TOC depth.
	 * @param array<int,array<string,mixed>>              $entries       Collected entries.
	 * @return void
	 */
	private function collect_epub_nav_list_items( array $items, $entry_dir, array $spine_entries, $depth, array &$entries ) {
		foreach ( $items as $item ) {
			if ( self::EPUB_TOC_LIMIT <= count( $entries ) ) {
				return;
			}

			$links = $item->xpath( '(./*[local-name()="a" or local-name()="span"])[1]' );

			if ( ! empty( $links ) ) {
				$label = $this->epub_node_text( $links[0] );
				$href  = (string) $links[0]['href'];

				if ( '' !== $label ) {
					$entries[] = $this->normalize_epub_toc_entry( $label, $href, $entry_dir, $spine_entries, $depth, array() );
				}
			}

			$children = $item->xpath( './*[local-name()="ol"]/*[local-name()="li"]' );
			$this->collect_epub_nav_list_items( is_array( $children ) ? $children : array(), $entry_dir, $spine_entries, $depth + 1, $entries );
		}
	}

	/**
	 * Parses an EPUB 2 NCX document into flat, bounded TOC entries.
	 *
	 * @param string                                      $xml           NCX XML.
	 * @param string                                      $entry_path    NCX entry archive path.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When the NCX document is invalid.
	 */
	private function parse_epub_ncx_document( $xml, $entry_path, array $spine_entries ) {
		$document  = $this->parse_xml( $xml, 'EPUB NCX document is not valid XML.' );
		$entry_dir = dirname( (string) $entry_path );

		if ( '.' === $entry_dir ) {
			$entry_dir = '';
		}

		$entries = array();
		$points  = $document->xpath( '//*[local-name()="navMap"]/*[local-name()="navPoint"]' );
		$this->collect_epub_ncx_nav_points( is_array( $points ) ? $points : array(), $entry_dir, $spine_entries, 1, $entries );

		return $entries;
	}

	/**
	 * Collects EPUB 2 NCX nav points recursively.
	 *
	 * @param array<int,SimpleXMLElement>                 $points        Nav point nodes.
	 * @param string                                      $entry_dir     NCX entry directory.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @param int                                         $depth         TOC depth.
	 * @param array<int,array<string,mixed>>              $entries       Collected entries.
	 * @return void
	 */
	private function collect_epub_ncx_nav_points( array $points, $entry_dir, array $spine_entries, $depth, array &$entries ) {
		foreach ( $points as $point ) {
			if ( self::EPUB_TOC_LIMIT <= count( $entries ) ) {
				return;
			}

			$labels   = $point->xpath( './*[local-name()="navLabel"]/*[local-name()="text"][1]' );
			$contents = $point->xpath( './*[local-name()="content"][1]' );
			$label    = empty( $labels ) ? '' : $this->epub_node_text( $labels[0] );
			$href     = empty( $contents ) ? '' : (string) $contents[0]['src'];

			if ( '' !== $label ) {
				$entries[] = $this->normalize_epub_toc_entry(
					$label,
					$href,
					$entry_dir,
					$spine_entries,
					$depth,
					array( 'play_order' => '' === (string) $point['playOrder'] ? null : (int) $point['playOrder'] )
				);
			}

			$children = $point->xpath( './*[local-name()="navPoint"]' );
			$this->collect_epub_ncx_nav_points( is_array( $children ) ? $children : array(), $entry_dir, $spine_entries, $depth + 1, $entries );
		}
	}

	/**
	 * Normalizes one EPUB TOC entry.
	 *
	 * @param string                                      $label         Entry label.
	 * @param string                                      $href          Raw EPUB href/src.
	 * @param string                                      $entry_dir     Navigation entry directory.
	 * @param array<string,array{index:int,idref:string}> $spine_entries Spine entries keyed by archive path.
	 * @param int                                         $depth         TOC depth.
	 * @param array<string,mixed>                         $extra         Extra fields.
	 * @return array<string,mixed>
	 */
	private function normalize_epub_toc_entry( $label, $href, $entry_dir, array $spine_entries, $depth, array $extra ) {
		$entry = array(
			'label' => $this->bounded_epub_text( $label, 200 ),
			'depth' => max( 1, (int) $depth ),
		);

		$href = trim( (string) $href );

		if ( '' !== $href ) {
			$entry['href'] = $this->bounded_epub_text( $href, 500 );
			$target        = $this->resolve_epub_reference_path( $entry_dir, $href );

			if ( null !== $target ) {
				$entry['epub_target_entry'] = $target['path'];

				if ( '' !== $target['fragment'] ) {
					$entry['target_fragment'] = $target['fragment'];
				}

				if ( isset( $spine_entries[ $target['path'] ] ) ) {
					$entry['epub_target_spine_index'] = $spine_entries[ $target['path'] ]['index'];
				}
			}
		}

		foreach ( $extra as $key => $value ) {
			if ( null !== $value ) {
				$entry[ $key ] = $value;
			}
		}

		return $entry;
	}

	/**
	 * Extracts readable text from a SimpleXML node.
	 *
	 * @param SimpleXMLElement $node XML node.
	 * @return string
	 */
	private function epub_node_text( SimpleXMLElement $node ) {
		$xml  = $node->asXML();
		$text = false === $xml ? (string) $node : preg_replace( '#<[^>]*>#', ' ', $xml );

		return $this->bounded_epub_text( $text, 200 );
	}

	/**
	 * Normalizes bounded EPUB metadata text.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum bytes.
	 * @return string
	 */
	private function bounded_epub_text( $text, $limit ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( is_string( $text ) ? $text : '' );

		if ( strlen( $text ) > (int) $limit ) {
			$text = substr( $text, 0, (int) $limit );
		}

		return $text;
	}

	/**
	 * Returns TOC entries that target a prepared EPUB spine document.
	 *
	 * @param array<string,mixed> $epub  Parsed EPUB package data.
	 * @param int                 $index Spine index.
	 * @return array<int,array<string,mixed>>
	 */
	private function epub_navigation_entries_for_spine_index( array $epub, $index ) {
		if ( empty( $epub['navigation']['entries'] ) || ! is_array( $epub['navigation']['entries'] ) ) {
			return array();
		}

		$matches = array();

		foreach ( $epub['navigation']['entries'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['epub_target_spine_index'] ) && (int) $entry['epub_target_spine_index'] === (int) $index ) {
				$matches[] = $entry;
			}
		}

		return $matches;
	}

	/**
	 * Stages one EPUB spine entry as a prepared document.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    EPUB source item.
	 * @param ZipArchive          $zip     Open archive.
	 * @param array<string,mixed> $epub    Parsed EPUB package data.
	 * @param string              $itemref Spine item reference id.
	 * @param int                 $index   Spine index.
	 * @return bool Whether a new prepared document was staged.
	 */
	private function prepare_epub_spine_document( ImportSession $session, ImportSourceItem $item, ZipArchive $zip, array $epub, $itemref, $index ) {
		$manifest_item = $epub['manifest'][ $itemref ];

		if ( ! in_array( strtolower( $manifest_item['media_type'] ), array( 'application/xhtml+xml', 'text/html' ), true ) ) {
			return false;
		}

		$document_key = $item->get_key() . ':epub-spine:' . $index;
		$content      = $this->read_zip_entry( $zip, $manifest_item['href'] );
		$content      = $this->extract_html_body( $content );
		$content      = $this->strip_scripts( $content );
		$references   = $this->rewrite_epub_internal_references( $session, $item, $zip, $epub, $manifest_item['href'], $document_key, $content );
		$content      = $references['content'];
		$anchor       = $this->epub_document_anchor( $document_key );
		$content      = '<span id="' . htmlspecialchars( $anchor, ENT_QUOTES, 'UTF-8' ) . '"></span>' . $content;
		$block_markup = $this->html_to_blocks( $content );
		$content_hash = hash( 'sha256', 'epub' . "\n" . $document_key . "\n" . $content );
		$url_domains  = $this->extract_absolute_url_domains( $content );
		$title        = $this->epub_chapter_title( $content, $epub['title'], $index );
		$existing     = $this->store->find_prepared_document( $session->get_id(), $document_key );
		$toc_entries  = $this->epub_navigation_entries_for_spine_index( $epub, $index );
		$doc_metadata = array(
			'relative_path'         => $item->get_relative_path(),
			'source_uri'            => $item->get_source_uri(),
			'epub_package_path'     => $epub['package_path'],
			'epub_manifest_id'      => $itemref,
			'epub_entry'            => $manifest_item['href'],
			'epub_spine_index'      => $index,
			'epub_anchor'           => $anchor,
			'epub_assets_queued'    => $references['assets_queued'],
			'epub_internal_links'   => $references['internal_links'],
			'absolute_url_domains'  => array_keys( $url_domains ),
			'absolute_url_examples' => $url_domains,
		);

		if ( ! empty( $toc_entries ) ) {
			$doc_metadata['epub_toc_entries'] = $toc_entries;
			$doc_metadata['epub_toc_label']   = $toc_entries[0]['label'];
		}

		$prepared_item = new ImportPreparedDocument(
			$session->get_id(),
			$document_key,
			'epub',
			$title,
			$block_markup,
			$this->count_blocks( $block_markup ),
			$content_hash,
			$doc_metadata
		);

		$this->store->save_prepared_document( $prepared_item );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord(
				'document-blocks:' . $document_key,
				'prepared_document',
				$document_key,
				$content_hash
			)
		);

		if ( null === $existing ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'document.epub_chapter_prepared',
					'EPUB spine item was staged as prepared block markup.',
					array(
						'item_key'        => $item->get_key(),
						'source_item_key' => $document_key,
						'epub_entry'      => $manifest_item['href'],
						'assets_queued'   => $references['assets_queued'],
						'title'           => $title,
					)
				)
			);
		}

		return null === $existing;
	}

	/**
	 * Rewrites EPUB package-local references and queues embedded media assets.
	 *
	 * @param ImportSession       $session      Session.
	 * @param ImportSourceItem    $item         EPUB source item.
	 * @param ZipArchive          $zip          Open archive.
	 * @param array<string,mixed> $epub         Parsed EPUB package data.
	 * @param string              $entry_path   Current spine entry path.
	 * @param string              $document_key Current prepared document key.
	 * @param string              $content      Chapter body HTML.
	 * @return array{content:string,assets_queued:int,internal_links:array<int,array<string,mixed>>}
	 */
	private function rewrite_epub_internal_references( ImportSession $session, ImportSourceItem $item, ZipArchive $zip, array $epub, $entry_path, $document_key, $content ) {
		$assets_queued  = 0;
		$internal_links = array();
		$entry_dir      = dirname( (string) $entry_path );

		if ( '.' === $entry_dir ) {
			$entry_dir = '';
		}

		$content = preg_replace_callback(
			'/\b(src|href|poster)\s*=\s*(["\'])(.*?)\2/is',
			function ( $matches ) use ( $session, $item, $zip, $epub, $entry_dir, $document_key, &$assets_queued, &$internal_links ) {
				$attribute = strtolower( $matches[1] );
				$quote     = $matches[2];
				$raw_url   = html_entity_decode( $matches[3], ENT_QUOTES, 'UTF-8' );
				$target    = $this->resolve_epub_reference_path( $entry_dir, $raw_url );

				if ( null === $target ) {
					return $matches[0];
				}

				if ( isset( $epub['manifest_by_entry'][ $target['path'] ] ) && $this->is_epub_media_manifest_item( $epub['manifest_by_entry'][ $target['path'] ] ) ) {
					if ( $this->queue_epub_media_reference( $session, $item, $zip, $epub['manifest_by_entry'][ $target['path'] ], $document_key, $matches[3] ) ) {
						++$assets_queued;
					}

					return $matches[0];
				}

				if ( 'href' === $attribute && isset( $epub['spine_entries'][ $target['path'] ] ) ) {
					$target_key       = $item->get_key() . ':epub-spine:' . $epub['spine_entries'][ $target['path'] ]['index'];
					$rewritten_href   = '#' . $this->epub_document_anchor( $target_key );
					$internal_links[] = array(
						'original_href'           => $matches[3],
						'epub_target_entry'       => $target['path'],
						'epub_target_spine_index' => $epub['spine_entries'][ $target['path'] ]['index'],
						'target_fragment'         => $target['fragment'],
						'rewritten_href'          => $rewritten_href,
					);

					return $matches[1] . '=' . $quote . htmlspecialchars( $rewritten_href, ENT_QUOTES, 'UTF-8' ) . $quote;
				}

				return $matches[0];
			},
			$content
		);

		if ( ! is_string( $content ) ) {
			$content = '';
		}

		return array(
			'content'        => $content,
			'assets_queued'  => $assets_queued,
			'internal_links' => $internal_links,
		);
	}

	/**
	 * Queues one embedded EPUB media asset through the shared media pipeline.
	 *
	 * @param ImportSession       $session       Session.
	 * @param ImportSourceItem    $item          EPUB source item.
	 * @param ZipArchive          $zip           Open archive.
	 * @param array<string,mixed> $manifest_item Manifest item.
	 * @param string              $document_key  Prepared document key.
	 * @param string              $original_url  Original attribute value.
	 * @return bool Whether a new media reference was queued.
	 * @throws RuntimeException When cache extraction fails.
	 */
	private function queue_epub_media_reference( ImportSession $session, ImportSourceItem $item, ZipArchive $zip, array $manifest_item, $document_key, $original_url ) {
		$entry_path = isset( $manifest_item['href'] ) ? (string) $manifest_item['href'] : '';
		$media_type = $this->epub_media_type_for_manifest_item( $manifest_item );

		if ( '' === $entry_path || null === $media_type ) {
			return false;
		}

		$target_path = $this->epub_asset_cache_path( $session, $item, $entry_path );

		if ( ! is_file( $target_path ) ) {
			$this->cache_directory->ensure_parent_directory( $target_path );
			$content = $this->read_zip_entry( $zip, $entry_path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The importer writes bounded extracted EPUB entries into its managed cache.
			if ( false === file_put_contents( $target_path, $content ) ) {
				throw new RuntimeException( 'Unable to write embedded EPUB asset into importer cache.' );
			}
		}

		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'epub-media:' . hash( 'sha256', $item->get_key() . "\n" . $document_key . "\n" . $entry_path . "\n" . $original_url ),
			$document_key,
			(string) $original_url,
			$target_path,
			$media_type,
			array_merge(
				array(
					'reference_scope'      => 'epub-embedded-asset',
					'document_title'       => $item->get_relative_path(),
					'extension'            => strtolower( pathinfo( $entry_path, PATHINFO_EXTENSION ) ),
					'epub_source_item_key' => $item->get_key(),
					'epub_asset_entry'     => $entry_path,
					'source'               => 'epub',
				),
				$this->cache_directory->metadata_for( 'epub', $target_path )
			)
		);

		$existing = $this->store->find_media_reference( $session->get_id(), $reference->get_key() );
		$this->store->save_media_reference( $reference );

		if ( null === $existing ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'media.epub_asset_queued',
					'Embedded EPUB media asset was extracted and queued for attachment import.',
					array(
						'item_key'        => $item->get_key(),
						'source_item_key' => $document_key,
						'reference_key'   => $reference->get_key(),
						'epub_entry'      => $entry_path,
						'media_type'      => $reference->get_media_type(),
					)
				)
			);
		}

		return null === $existing;
	}

	/**
	 * Builds a stable cache path for an extracted EPUB asset.
	 *
	 * @param ImportSession    $session    Session.
	 * @param ImportSourceItem $item    EPUB source item.
	 * @param string           $entry_path EPUB archive entry path.
	 * @return string
	 */
	private function epub_asset_cache_path( ImportSession $session, ImportSourceItem $item, $entry_path ) {
		$basename = basename( (string) $entry_path );
		$basename = '' === $basename ? 'asset' : $basename;

		return $this->cache_directory->path_for(
			$session->get_id(),
			'epub',
			array(
				hash( 'sha256', $item->get_key() . "\n" . (string) $entry_path ),
				$basename,
			)
		);
	}

	/**
	 * Resolves an EPUB-relative URL to an archive entry path and fragment.
	 *
	 * @param string $entry_dir Current spine entry directory.
	 * @param string $url       Relative URL.
	 * @return array{path:string,fragment:string}|null
	 */
	private function resolve_epub_reference_path( $entry_dir, $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

		if (
			'' === $url ||
			0 === strpos( $url, '#' ) ||
			0 === strpos( $url, '//' ) ||
			preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url )
		) {
			return null;
		}

		$without_query = preg_replace( '/\?.*$/', '', $url );
		$parts         = explode( '#', is_string( $without_query ) ? $without_query : $url, 2 );
		$path          = rawurldecode( $parts[0] );
		$fragment      = isset( $parts[1] ) ? (string) $parts[1] : '';

		if ( '' === $path ) {
			return null;
		}

		try {
			return array(
				'path'     => $this->join_epub_path( $entry_dir, $path ),
				'fragment' => $fragment,
			);
		} catch ( RuntimeException $exception ) {
			unset( $exception );
			return null;
		}
	}

	/**
	 * Returns whether a manifest item is an importable EPUB media asset.
	 *
	 * @param array<string,mixed> $manifest_item Manifest item.
	 * @return bool
	 */
	private function is_epub_media_manifest_item( array $manifest_item ) {
		return null !== $this->epub_media_type_for_manifest_item( $manifest_item );
	}

	/**
	 * Maps an EPUB manifest item to an importer media type.
	 *
	 * @param array<string,mixed> $manifest_item Manifest item.
	 * @return string|null
	 */
	private function epub_media_type_for_manifest_item( array $manifest_item ) {
		$declared = isset( $manifest_item['media_type'] ) ? strtolower( trim( (string) $manifest_item['media_type'] ) ) : '';

		if ( isset( self::$epub_media_types[ $declared ] ) ) {
			return self::$epub_media_types[ $declared ];
		}

		$extension = isset( $manifest_item['href'] ) ? strtolower( pathinfo( (string) $manifest_item['href'], PATHINFO_EXTENSION ) ) : '';

		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'gif':
			case 'webp':
			case 'avif':
			case 'svg':
				return ImportMediaReference::TYPE_IMAGE;

			case 'mp3':
			case 'm4a':
			case 'ogg':
			case 'wav':
				return ImportMediaReference::TYPE_AUDIO;

			case 'mp4':
			case 'm4v':
			case 'webm':
			case 'mov':
				return ImportMediaReference::TYPE_VIDEO;

			case 'pdf':
				return ImportMediaReference::TYPE_FILE;
		}

		return null;
	}

	/**
	 * Builds a stable fragment id for EPUB spine documents.
	 *
	 * @param string $document_key Prepared document key.
	 * @return string
	 */
	private function epub_document_anchor( $document_key ) {
		return 'universal-importer-epub-' . substr( hash( 'sha256', (string) $document_key ), 0, 16 );
	}

	/**
	 * Prepares plain text files incrementally with a durable byte cursor.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 */
	private function process_text_item( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();
		$path     = $item->get_source_uri();

		if ( ! is_file( $path ) ) {
			return $this->fail_text_item( $session, $item, $metadata, 'Discovered text item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			return $this->fail_text_item( $session, $item, $metadata, 'Discovered text file is not readable.' );
		}

		$size = filesize( $path );
		if ( false === $size ) {
			return $this->fail_text_item( $session, $item, $metadata, 'Unable to determine discovered text file size.' );
		}

		$size         = (int) $size;
		$offset       = isset( $metadata['text_next_offset'] ) ? max( 0, (int) $metadata['text_next_offset'] ) : 0;
		$chunk_index  = isset( $metadata['text_chunk_index'] ) ? max( 0, (int) $metadata['text_chunk_index'] ) : 0;
		$script_carry = isset( $metadata['text_script_carry'] ) ? (string) $metadata['text_script_carry'] : '';
		$in_script    = ! empty( $metadata['text_in_script'] );

		if ( $offset > $size ) {
			$offset = $size;
		}

		$stream = $this->open_document_stream( $path );

		try {
			$stream->seek( $offset );
			$pulled = $stream->pull( self::TEXT_CHUNK_BYTES, ByteReadStream::PULL_NO_MORE_THAN );

			if ( 0 === $pulled && $offset < $size ) {
				return $this->fail_text_item( $session, $item, $metadata, 'Unable to read discovered text file stream.' );
			}

			$raw_chunk = 0 === $pulled ? '' : $stream->consume( $pulled );
		} catch ( ByteStreamException $exception ) {
			return $this->fail_text_item( $session, $item, $metadata, 'Unable to read discovered text file stream.' );
		} finally {
			$stream->close_reading();
		}

		$next_offset = $offset + strlen( $raw_chunk );
		$is_complete = $next_offset >= $size;

		if ( ! $is_complete && '' !== $raw_chunk ) {
			$split_at = strrpos( $raw_chunk, "\n" );
			if ( false !== $split_at && 0 < $split_at ) {
				$raw_chunk   = substr( $raw_chunk, 0, $split_at + 1 );
				$next_offset = $offset + strlen( $raw_chunk );
			}
		}

		$content  = ( ! $in_script && '' === $script_carry && false === strpos( $raw_chunk, '<' ) )
			? $raw_chunk
			: $this->strip_script_chunk( $raw_chunk, $script_carry, $in_script, $is_complete );
		$prepared = false;

		if ( '' !== trim( $content ) ) {
			$document_key = ( 0 === $chunk_index && $is_complete ) ? $item->get_key() : $item->get_key() . ':text-chunk:' . $chunk_index;
			$title        = $this->title_for_text_chunk( $item, $content, $chunk_index, $is_complete );
			$block_markup = $this->text_to_blocks( $content );
			$block_count  = $this->count_blocks( $block_markup );
			$content_hash = hash( 'sha256', "text\n" . $document_key . "\n" . $content );
			$url_domains  = $this->extract_absolute_url_domains( $content );

			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					$document_key,
					'text',
					$title,
					$block_markup,
					$block_count,
					$content_hash,
					array(
						'relative_path'         => $item->get_relative_path(),
						'source_uri'            => $item->get_source_uri(),
						'text_source_item_key'  => $item->get_key(),
						'text_chunk_index'      => $chunk_index,
						'text_chunk_start'      => $offset,
						'text_chunk_end'        => $next_offset,
						'absolute_url_domains'  => array_keys( $url_domains ),
						'absolute_url_examples' => $url_domains,
					)
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord( 'document-blocks:' . $document_key, 'prepared_document', $document_key, $content_hash )
			);
			$prepared = true;
		}

		$next_metadata = array_merge(
			$metadata,
			array(
				'processor_status'     => $is_complete ? 'imported' : 'partial',
				'document_format'      => 'text',
				'content_hash'         => hash( 'sha256', 'text-source' . "\n" . $item->get_key() . "\n" . $size . "\n" . (int) filemtime( $path ) ),
				'text_bytes_total'     => $size,
				'text_chunks_prepared' => max( isset( $metadata['text_chunks_prepared'] ) ? (int) $metadata['text_chunks_prepared'] : 0, $chunk_index + ( $prepared ? 1 : 0 ) ),
			)
		);

		if ( $is_complete ) {
			unset( $next_metadata['text_next_offset'], $next_metadata['text_chunk_index'], $next_metadata['text_script_carry'], $next_metadata['text_in_script'] );
		} else {
			$next_metadata['text_next_offset']  = $next_offset;
			$next_metadata['text_chunk_index']  = $chunk_index + 1;
			$next_metadata['text_script_carry'] = $script_carry;
			$next_metadata['text_in_script']    = $in_script;
		}

		$this->store->save_source_item(
			$item->with_status( $is_complete ? ImportSourceItem::STATUS_IMPORTED : ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata )
		);

		$this->record_event(
			$session,
			$is_complete ? 'document.text_complete' : 'document.text_progress',
			$is_complete ? 'Text source item was fully parsed into prepared block markup.' : 'Text source item was partially parsed and can resume from its stored byte offset.',
			$item,
			array(
				'offset'   => $next_offset,
				'bytes'    => $size,
				'chunk'    => $chunk_index,
				'prepared' => $prepared,
			)
		);

		return 'imported';
	}

	/**
	 * Marks text processing failed with durable metadata and an event.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Existing metadata.
	 * @param string              $message  Failure message.
	 * @return string Summary bucket.
	 */
	private function fail_text_item( ImportSession $session, ImportSourceItem $item, array $metadata, $message ) {
		$this->store->save_source_item(
			$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
				array_merge(
					$metadata,
					array(
						'processor_status' => 'failed',
						'document_format'  => 'text',
						'error'            => (string) $message,
					)
				)
			)
		);
		$this->record_event( $session, 'document.failed', (string) $message, $item, array( 'format' => 'text' ) );

		return 'failed';
	}

	/**
	 * Returns whether a Markdown file should use durable byte-cursor processing.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return bool
	 */
	private function should_process_markdown_incrementally( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		if ( isset( $metadata['markdown_next_offset'] ) || isset( $metadata['markdown_chunk_index'] ) ) {
			return true;
		}

		$path = $item->get_source_uri();
		if ( ! is_file( $path ) ) {
			return true;
		}

		$size = filesize( $path );

		return false !== $size && (int) $size > self::TEXT_CHUNK_BYTES;
	}

	/**
	 * Prepares large Markdown files incrementally with a durable byte cursor.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 */
	private function process_markdown_item( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();
		$path     = $item->get_source_uri();

		if ( ! is_file( $path ) ) {
			return $this->fail_markdown_item( $session, $item, $metadata, 'Discovered Markdown item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			return $this->fail_markdown_item( $session, $item, $metadata, 'Discovered Markdown file is not readable.' );
		}

		$size = filesize( $path );
		if ( false === $size ) {
			return $this->fail_markdown_item( $session, $item, $metadata, 'Unable to determine discovered Markdown file size.' );
		}

		$size         = (int) $size;
		$offset       = isset( $metadata['markdown_next_offset'] ) ? max( 0, (int) $metadata['markdown_next_offset'] ) : 0;
		$chunk_index  = isset( $metadata['markdown_chunk_index'] ) ? max( 0, (int) $metadata['markdown_chunk_index'] ) : 0;
		$script_carry = isset( $metadata['markdown_script_carry'] ) ? (string) $metadata['markdown_script_carry'] : '';
		$in_script    = ! empty( $metadata['markdown_in_script'] );

		if ( $offset > $size ) {
			$offset = $size;
		}

		$stream = $this->open_document_stream( $path );

		try {
			$stream->seek( $offset );
			$pulled = $stream->pull( self::TEXT_CHUNK_BYTES, ByteReadStream::PULL_NO_MORE_THAN );

			if ( 0 === $pulled && $offset < $size ) {
				return $this->fail_markdown_item( $session, $item, $metadata, 'Unable to read discovered Markdown file stream.' );
			}

			$raw_chunk = 0 === $pulled ? '' : $stream->consume( $pulled );
		} catch ( ByteStreamException $exception ) {
			return $this->fail_markdown_item( $session, $item, $metadata, 'Unable to read discovered Markdown file stream.' );
		} finally {
			$stream->close_reading();
		}

		$next_offset = $offset + strlen( $raw_chunk );
		$is_complete = $next_offset >= $size;

		if ( ! $is_complete && '' !== $raw_chunk ) {
			$split_at = $this->markdown_chunk_split_offset( $raw_chunk );
			if ( 0 < $split_at ) {
				$raw_chunk   = substr( $raw_chunk, 0, $split_at );
				$next_offset = $offset + strlen( $raw_chunk );
			}
		}

		$content = $this->strip_script_chunk( $raw_chunk, $script_carry, $in_script, $is_complete );
		$content = trim( $content );

		$chunk_metadata      = array();
		$markdown_title      = '';
		$markdown_references = array();
		if ( 0 === $chunk_index ) {
			$markdown_document = $this->extract_markdown_front_matter( $content );
			$content           = trim( $markdown_document['content'] );
			if ( '' !== $markdown_document['title'] ) {
				$markdown_title                                = $markdown_document['title'];
				$chunk_metadata['markdown_front_matter_title'] = $markdown_title;
			}
			if ( $markdown_document['detected'] ) {
				$chunk_metadata['markdown_front_matter'] = true;
			}
		}

		$markdown_reference_document = $this->extract_markdown_reference_definitions( $content );
		$content                     = $markdown_reference_document['content'];
		$markdown_references         = $markdown_reference_document['references'];
		if ( ! empty( $markdown_references ) ) {
			$chunk_metadata['markdown_reference_count'] = count( $markdown_references );
		}

		$prepared = false;
		if ( '' !== trim( $content ) ) {
			$document_key = ( 0 === $chunk_index && $is_complete ) ? $item->get_key() : $item->get_key() . ':markdown-chunk:' . $chunk_index;
			$title        = '' !== $markdown_title ? $markdown_title : $this->title_for_text_chunk( $item, $content, $chunk_index, $is_complete );
			$block_markup = $this->markdown_to_blocks( $content, $markdown_references );
			$block_count  = $this->count_blocks( $block_markup );
			$content_hash = hash( 'sha256', "markdown\n" . $document_key . "\n" . $content );
			$url_domains  = $this->merge_absolute_url_domain_examples( $this->extract_absolute_url_domains( $content ), $this->extract_absolute_url_domains( $block_markup ) );

			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					$document_key,
					'markdown',
					$title,
					$block_markup,
					$block_count,
					$content_hash,
					array_merge(
						array(
							'relative_path'            => $item->get_relative_path(),
							'source_uri'               => $item->get_source_uri(),
							'markdown_source_item_key' => $item->get_key(),
							'markdown_chunk_index'     => $chunk_index,
							'markdown_chunk_start'     => $offset,
							'markdown_chunk_end'       => $next_offset,
							'absolute_url_domains'     => array_keys( $url_domains ),
							'absolute_url_examples'    => $url_domains,
						),
						$chunk_metadata
					)
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord( 'document-blocks:' . $document_key, 'prepared_document', $document_key, $content_hash )
			);
			$prepared = true;
		}

		$next_metadata = array_merge(
			$metadata,
			array(
				'processor_status'         => $is_complete ? 'imported' : 'partial',
				'document_format'          => 'markdown',
				'content_hash'             => hash( 'sha256', 'markdown-source' . "\n" . $item->get_key() . "\n" . $size . "\n" . (int) filemtime( $path ) ),
				'markdown_bytes_total'     => $size,
				'markdown_chunks_prepared' => max( isset( $metadata['markdown_chunks_prepared'] ) ? (int) $metadata['markdown_chunks_prepared'] : 0, $chunk_index + ( $prepared ? 1 : 0 ) ),
			)
		);

		if ( $is_complete ) {
			unset( $next_metadata['markdown_next_offset'], $next_metadata['markdown_chunk_index'], $next_metadata['markdown_script_carry'], $next_metadata['markdown_in_script'] );
		} else {
			$next_metadata['markdown_next_offset']  = $next_offset;
			$next_metadata['markdown_chunk_index']  = $chunk_index + 1;
			$next_metadata['markdown_script_carry'] = $script_carry;
			$next_metadata['markdown_in_script']    = $in_script;
		}

		$this->store->save_source_item(
			$item->with_status( $is_complete ? ImportSourceItem::STATUS_IMPORTED : ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata )
		);

		if ( ! $is_complete && $this->controls->should_simulate_fatal_after_markdown_cursor() ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_ERROR,
					'runner.simulated_fatal_after_markdown_cursor',
					'Runner is terminating PHP after a durable Markdown byte cursor write for recovery testing.',
					array(
						'item_key' => $item->get_key(),
						'offset'   => $next_offset,
					)
				)
			);

			exit( 118 );
		}

		$this->record_event(
			$session,
			$is_complete ? 'document.markdown_complete' : 'document.markdown_progress',
			$is_complete ? 'Markdown source item was fully parsed into prepared block markup.' : 'Markdown source item was partially parsed and can resume from its stored byte offset.',
			$item,
			array(
				'offset'   => $next_offset,
				'bytes'    => $size,
				'chunk'    => $chunk_index,
				'prepared' => $prepared,
			)
		);

		return 'imported';
	}

	/**
	 * Returns a conservative byte split point for a partial Markdown chunk.
	 *
	 * @param string $chunk Raw chunk bytes.
	 * @return int
	 */
	private function markdown_chunk_split_offset( $chunk ) {
		$chunk      = (string) $chunk;
		$candidates = array();

		foreach ( array( "\r\n\r\n", "\n\n", "\r\r" ) as $separator ) {
			$position = strrpos( $chunk, $separator );
			if ( false !== $position && 0 < $position ) {
				$candidates[] = $position + strlen( $separator );
			}
		}

		if ( ! empty( $candidates ) ) {
			return max( $candidates );
		}

		$line_break = strrpos( $chunk, "\n" );
		if ( false !== $line_break && 0 < $line_break ) {
			return $line_break + 1;
		}

		return 0;
	}

	/**
	 * Marks Markdown processing failed with durable metadata and an event.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Existing metadata.
	 * @param string              $message  Failure message.
	 * @return string Summary bucket.
	 */
	private function fail_markdown_item( ImportSession $session, ImportSourceItem $item, array $metadata, $message ) {
		$this->store->save_source_item(
			$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
				array_merge(
					$metadata,
					array(
						'processor_status' => 'failed',
						'document_format'  => 'markdown',
						'error'            => (string) $message,
					)
				)
			)
		);
		$this->record_event( $session, 'document.failed', (string) $message, $item, array( 'format' => 'markdown' ) );

		return 'failed';
	}

	/**
	 * Returns whether an HTML file should use durable byte-cursor processing.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return bool
	 */
	private function should_process_html_incrementally( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		if ( isset( $metadata['html_next_offset'] ) || isset( $metadata['html_chunk_index'] ) ) {
			return true;
		}

		$path = $item->get_source_uri();
		if ( ! is_file( $path ) ) {
			return true;
		}

		$size = filesize( $path );

		return false !== $size && (int) $size > self::TEXT_CHUNK_BYTES;
	}

	/**
	 * Prepares large HTML files incrementally with a durable byte cursor.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 */
	private function process_html_item( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();
		$path     = $item->get_source_uri();

		if ( ! is_file( $path ) ) {
			return $this->fail_html_item( $session, $item, $metadata, 'Discovered HTML item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			return $this->fail_html_item( $session, $item, $metadata, 'Discovered HTML file is not readable.' );
		}

		$size = filesize( $path );
		if ( false === $size ) {
			return $this->fail_html_item( $session, $item, $metadata, 'Unable to determine discovered HTML file size.' );
		}

		$size         = (int) $size;
		$offset       = isset( $metadata['html_next_offset'] ) ? max( 0, (int) $metadata['html_next_offset'] ) : 0;
		$chunk_index  = isset( $metadata['html_chunk_index'] ) ? max( 0, (int) $metadata['html_chunk_index'] ) : 0;
		$script_carry = isset( $metadata['html_script_carry'] ) ? (string) $metadata['html_script_carry'] : '';
		$in_script    = ! empty( $metadata['html_in_script'] );

		if ( $offset > $size ) {
			$offset = $size;
		}

		$stream = $this->open_document_stream( $path );

		try {
			$stream->seek( $offset );
			$pulled = $stream->pull( self::TEXT_CHUNK_BYTES, ByteReadStream::PULL_NO_MORE_THAN );

			if ( 0 === $pulled && $offset < $size ) {
				return $this->fail_html_item( $session, $item, $metadata, 'Unable to read discovered HTML file stream.' );
			}

			$raw_chunk = 0 === $pulled ? '' : $stream->consume( $pulled );
		} catch ( ByteStreamException $exception ) {
			return $this->fail_html_item( $session, $item, $metadata, 'Unable to read discovered HTML file stream.' );
		} finally {
			$stream->close_reading();
		}

		$next_offset = $offset + strlen( $raw_chunk );
		$is_complete = $next_offset >= $size;

		if ( ! $is_complete && '' !== $raw_chunk ) {
			$split_at = $this->html_chunk_split_offset( $raw_chunk );
			if ( 0 < $split_at ) {
				$raw_chunk   = substr( $raw_chunk, 0, $split_at );
				$next_offset = $offset + strlen( $raw_chunk );
			}
		}

		$content  = $this->strip_script_chunk( $raw_chunk, $script_carry, $in_script, $is_complete );
		$content  = $this->html_chunk_fragment( $content, $chunk_index, $is_complete );
		$prepared = false;

		if ( '' !== trim( $content ) ) {
			$document_key = ( 0 === $chunk_index && $is_complete ) ? $item->get_key() : $item->get_key() . ':html-chunk:' . $chunk_index;
			$title        = $this->title_for_text_chunk( $item, $content, $chunk_index, $is_complete );
			$html_summary = array();
			$block_markup = $this->html_to_blocks( $content, $html_summary );
			$block_count  = $this->count_blocks( $block_markup );
			$content_hash = hash( 'sha256', "html\n" . $document_key . "\n" . $content );
			$url_domains  = $this->merge_absolute_url_domain_examples( $this->extract_absolute_url_domains( $content ), $this->extract_absolute_url_domains( $block_markup ) );

			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					$document_key,
					'html',
					$title,
					$block_markup,
					$block_count,
					$content_hash,
					array_merge(
						array(
							'relative_path'         => $item->get_relative_path(),
							'source_uri'            => $item->get_source_uri(),
							'html_source_item_key'  => $item->get_key(),
							'html_chunk_index'      => $chunk_index,
							'html_chunk_start'      => $offset,
							'html_chunk_end'        => $next_offset,
							'absolute_url_domains'  => array_keys( $url_domains ),
							'absolute_url_examples' => $url_domains,
						),
						$html_summary
					)
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord( 'document-blocks:' . $document_key, 'prepared_document', $document_key, $content_hash )
			);
			$prepared = true;
		}

		$next_metadata = array_merge(
			$metadata,
			array(
				'processor_status'     => $is_complete ? 'imported' : 'partial',
				'document_format'      => 'html',
				'content_hash'         => hash( 'sha256', 'html-source' . "\n" . $item->get_key() . "\n" . $size . "\n" . (int) filemtime( $path ) ),
				'html_bytes_total'     => $size,
				'html_chunks_prepared' => max( isset( $metadata['html_chunks_prepared'] ) ? (int) $metadata['html_chunks_prepared'] : 0, $chunk_index + ( $prepared ? 1 : 0 ) ),
			)
		);

		if ( $is_complete ) {
			unset( $next_metadata['html_next_offset'], $next_metadata['html_chunk_index'], $next_metadata['html_script_carry'], $next_metadata['html_in_script'] );
		} else {
			$next_metadata['html_next_offset']  = $next_offset;
			$next_metadata['html_chunk_index']  = $chunk_index + 1;
			$next_metadata['html_script_carry'] = $script_carry;
			$next_metadata['html_in_script']    = $in_script;
		}

		$this->store->save_source_item(
			$item->with_status( $is_complete ? ImportSourceItem::STATUS_IMPORTED : ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata( $next_metadata )
		);

		$this->record_event(
			$session,
			$is_complete ? 'document.html_complete' : 'document.html_progress',
			$is_complete ? 'HTML source item was fully parsed into prepared block markup.' : 'HTML source item was partially parsed and can resume from its stored byte offset.',
			$item,
			array(
				'offset'   => $next_offset,
				'bytes'    => $size,
				'chunk'    => $chunk_index,
				'prepared' => $prepared,
			)
		);

		return 'imported';
	}

	/**
	 * Returns a conservative byte split point for a partial HTML chunk.
	 *
	 * @param string $chunk Raw chunk bytes.
	 * @return int
	 */
	private function html_chunk_split_offset( $chunk ) {
		$chunk     = (string) $chunk;
		$best      = 0;
		$selectors = array( '</p>', '</div>', '</section>', '</article>', '</ul>', '</ol>', '</table>', '</blockquote>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br/>' );

		foreach ( $selectors as $selector ) {
			$position = strripos( $chunk, $selector );
			if ( false !== $position ) {
				$best = max( $best, $position + strlen( $selector ) );
			}
		}

		if ( 0 < $best ) {
			return $best;
		}

		$tag_end = strrpos( $chunk, '>' );
		if ( false !== $tag_end && 0 < $tag_end ) {
			return $tag_end + 1;
		}

		$line_break = strrpos( $chunk, "\n" );
		if ( false !== $line_break && 0 < $line_break ) {
			return $line_break + 1;
		}

		return 0;
	}

	/**
	 * Trims whole-document wrappers from a streamed HTML chunk where possible.
	 *
	 * @param string $content     Chunk content.
	 * @param int    $chunk_index Chunk index.
	 * @param bool   $is_complete Whether this chunk completed the file.
	 * @return string
	 */
	private function html_chunk_fragment( $content, $chunk_index, $is_complete ) {
		$content = (string) $content;

		if ( 0 === (int) $chunk_index ) {
			if ( preg_match( '#<body\b[^>]*>#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$content = substr( $content, $matches[0][1] + strlen( $matches[0][0] ) );
			} elseif ( preg_match( '#</head\s*>#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$content = substr( $content, $matches[0][1] + strlen( $matches[0][0] ) );
			}
		}

		if ( $is_complete && preg_match( '#</body\s*>#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$content = substr( $content, 0, $matches[0][1] );
		}

		return trim( $content );
	}

	/**
	 * Marks HTML processing failed with durable metadata and an event.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Existing metadata.
	 * @param string              $message  Failure message.
	 * @return string Summary bucket.
	 */
	private function fail_html_item( ImportSession $session, ImportSourceItem $item, array $metadata, $message ) {
		$this->store->save_source_item(
			$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
				array_merge(
					$metadata,
					array(
						'processor_status' => 'failed',
						'document_format'  => 'html',
						'error'            => (string) $message,
					)
				)
			)
		);
		$this->record_event( $session, 'document.failed', (string) $message, $item, array( 'format' => 'html' ) );

		return 'failed';
	}

	/**
	 * Prepares WordPress export posts from a WXR file incrementally.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @return string Summary bucket.
	 * @throws RuntimeException When WXR reader creation fails before a failure event can be recorded.
	 */
	private function process_wxr_item( ImportSession $session, ImportSourceItem $item ) {
		$metadata = $item->get_metadata();
		$cursor   = isset( $metadata['wxr_cursor'] ) ? (string) $metadata['wxr_cursor'] : null;
		$stream   = null;

		try {
			$stream = $this->open_document_stream( $item->get_source_uri() );
			$reader = WXREntityReader::create( $stream, $cursor );
			if ( false === $reader ) {
				throw new RuntimeException( 'Unable to resume WXR reader from the stored cursor.' );
			}

			$inspected = 0;
			$prepared  = 0;
			$skipped   = 0;

			while ( $inspected < self::WXR_POST_LIMIT && $reader->next_entity() ) {
				$entity = $reader->get_entity();

				if ( ! $entity instanceof ImportEntity ) {
					continue;
				}

				if ( ImportEntity::TYPE_POST !== $entity->get_type() ) {
					$metadata = $this->process_wxr_related_entity( $session, $item, $metadata, $entity );
					continue;
				}

				++$inspected;
				$data = $entity->get_data();

				if ( ImportWxrAttachment::is_attachment_post( $data ) ) {
					if ( $this->queue_wxr_attachment_reference( $session, $item, $data ) ) {
						$metadata['wxr_attachments_queued'] = ( isset( $metadata['wxr_attachments_queued'] ) ? (int) $metadata['wxr_attachments_queued'] : 0 ) + 1;
					} else {
						$metadata['wxr_attachments_skipped'] = ( isset( $metadata['wxr_attachments_skipped'] ) ? (int) $metadata['wxr_attachments_skipped'] : 0 ) + 1;
					}
					continue;
				}

				if ( $this->is_wxr_nav_menu_item( $data ) ) {
					$metadata = $this->stage_wxr_nav_menu_item( $session, $item, $metadata, $data );
					++$skipped;
					continue;
				}

				if ( ! $this->is_importable_wxr_post( $data ) ) {
					++$skipped;
					continue;
				}

				if ( $this->prepare_wxr_post_document( $session, $item, $data, $prepared, $metadata ) ) {
					++$prepared;
				}
			}

			$is_complete = self::WXR_POST_LIMIT > $inspected && ! $reader->is_paused_at_incomplete_input();
			$metadata    = array_merge(
				$metadata,
				array(
					'processor_status'    => $is_complete ? 'imported' : 'partial',
					'document_format'     => 'wxr',
					'wxr_posts_prepared'  => ( isset( $metadata['wxr_posts_prepared'] ) ? (int) $metadata['wxr_posts_prepared'] : 0 ) + $prepared,
					'wxr_posts_skipped'   => ( isset( $metadata['wxr_posts_skipped'] ) ? (int) $metadata['wxr_posts_skipped'] : 0 ) + $skipped,
					'wxr_posts_inspected' => ( isset( $metadata['wxr_posts_inspected'] ) ? (int) $metadata['wxr_posts_inspected'] : 0 ) + $inspected,
				)
			);

			if ( $is_complete ) {
				unset( $metadata['wxr_cursor'] );
				$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_IMPORTED )->with_replaced_metadata( $metadata ) );
				$this->record_event(
					$session,
					'document.wxr_complete',
					'WXR source item was fully parsed into prepared post documents.',
					$item,
					array(
						'prepared' => $prepared,
						'skipped'  => $skipped,
					)
				);
			} else {
				$metadata['wxr_cursor'] = $reader->get_reentrancy_cursor();
				$this->store->save_source_item( $item->with_metadata( $metadata ) );

				if ( $this->controls->should_simulate_fatal_after_wxr_cursor() ) {
					$this->store->record_event(
						$session->get_id(),
						new ImportProgressEvent(
							ImportProgressEvent::LEVEL_ERROR,
							'runner.simulated_fatal_after_wxr_cursor',
							'Runner is terminating PHP after a durable WXR cursor write for recovery testing.',
							array(
								'item_key'  => $item->get_key(),
								'prepared'  => $prepared,
								'skipped'   => $skipped,
								'inspected' => $inspected,
							)
						)
					);

					exit( 122 );
				}

				$this->record_event(
					$session,
					'document.wxr_progress',
					'WXR source item was partially parsed and can resume from its stored cursor.',
					$item,
					array(
						'prepared' => $prepared,
						'skipped'  => $skipped,
					)
				);
			}

			return 'imported';
		} catch ( ByteStreamException $exception ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
					array(
						'processor_status'  => 'failed',
						'document_format'   => 'wxr',
						'error'             => 'Unable to parse WXR stream.',
						'exception_message' => $exception->getMessage(),
					)
				)
			);
			$this->record_event( $session, 'document.failed', 'Unable to parse WXR stream.', $item, array( 'format' => 'wxr' ) );
			return 'failed';
		} catch ( RuntimeException $exception ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
					array(
						'processor_status' => 'failed',
						'document_format'  => 'wxr',
						'error'            => $exception->getMessage(),
					)
				)
			);
			$this->record_event( $session, 'document.failed', $exception->getMessage(), $item, array( 'format' => 'wxr' ) );
			return 'failed';
		} finally {
			if ( $stream instanceof FileReadStream ) {
				$this->close_stream_safely( $stream );
			}
		}
	}

	/**
	 * Opens a readable document stream.
	 *
	 * @param string $path Source path.
	 * @return FileReadStream
	 * @throws RuntimeException When the file cannot be opened.
	 */
	private function open_document_stream( $path ) {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Discovered document item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Discovered document file is not readable.' );
		}

		try {
			return FileReadStream::from_path( $path );
		} catch ( ByteStreamException $exception ) {
			throw new RuntimeException( 'Unable to open discovered document file for streaming.' );
		}
	}

	/**
	 * Closes a stream without masking the original processing result.
	 *
	 * @param FileReadStream $stream Stream to close.
	 * @return void
	 */
	private function close_stream_safely( FileReadStream $stream ) {
		try {
			$stream->close_reading();
		} catch ( ByteStreamException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Processes non-post WXR entities that enrich prepared documents.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source WXR item.
	 * @param array<string,mixed> $metadata Source item metadata accumulated during this pass.
	 * @param ImportEntity        $entity   WXR entity.
	 * @return array<string,mixed> Updated source item metadata.
	 */
	private function process_wxr_related_entity( ImportSession $session, ImportSourceItem $item, array $metadata, ImportEntity $entity ) {
		$data = $entity->get_data();

		if ( ! is_array( $data ) ) {
			return $metadata;
		}

		switch ( $entity->get_type() ) {
			case ImportEntity::TYPE_USER:
				return $this->remember_wxr_author( $metadata, $data );

			case ImportEntity::TYPE_TERM:
			case ImportEntity::TYPE_TAG:
			case ImportEntity::TYPE_CATEGORY:
				return $this->remember_wxr_term( $metadata, $entity->get_type(), $data );

			case ImportEntity::TYPE_POST_META:
				$this->attach_wxr_post_meta( $session, $item, $data );
				$this->attach_wxr_attachment_meta( $session, $item, $data );
				return $this->attach_wxr_nav_menu_meta( $metadata, $data );

			case ImportEntity::TYPE_COMMENT:
				$this->attach_wxr_comment( $session, $item, $data );
				return $metadata;
		}

		return $metadata;
	}

	/**
	 * Whether a WXR post entity should become a draft page candidate.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return bool
	 */
	private function is_importable_wxr_post( array $data ) {
		$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';

		if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
			return false;
		}

		return isset( $data['post_content'] ) && '' !== trim( (string) $data['post_content'] );
	}

	/**
	 * Whether a WXR post entity represents a navigation menu item.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return bool
	 */
	private function is_wxr_nav_menu_item( array $data ) {
		return isset( $data['post_type'] ) && 'nav_menu_item' === (string) $data['post_type'];
	}

	/**
	 * Stages a WXR nav_menu_item post for later local menu persistence.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source WXR item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @param array<string,mixed> $data     WXR nav menu item post data.
	 * @return array<string,mixed> Updated source item metadata.
	 */
	private function stage_wxr_nav_menu_item( ImportSession $session, ImportSourceItem $item, array $metadata, array $data ) {
		$post_id = isset( $data['post_id'] ) && '' !== trim( (string) $data['post_id'] ) ? (string) $data['post_id'] : $this->wxr_fallback_post_id( $data, 0 );
		$menu    = $this->wxr_nav_menu_for_item( $data );

		if ( ! isset( $metadata['wxr_nav_menu_items_by_id'] ) || ! is_array( $metadata['wxr_nav_menu_items_by_id'] ) ) {
			$metadata['wxr_nav_menu_items_by_id'] = array();
		}

		$existing = isset( $metadata['wxr_nav_menu_items_by_id'][ $post_id ] ) && is_array( $metadata['wxr_nav_menu_items_by_id'][ $post_id ] )
			? $metadata['wxr_nav_menu_items_by_id'][ $post_id ]
			: array();

		$metadata['wxr_nav_menu_items_by_id'][ $post_id ] = array_merge(
			$existing,
			array(
				'id'         => $post_id,
				'title'      => isset( $data['post_title'] ) ? $this->strip_scripts( (string) $data['post_title'] ) : '',
				'status'     => isset( $data['post_status'] ) ? (string) $data['post_status'] : '',
				'menu_order' => isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0,
				'menu_slug'  => $menu['slug'],
				'menu_name'  => $menu['name'],
				'link'       => isset( $data['link'] ) ? (string) $data['link'] : '',
				'guid'       => isset( $data['guid'] ) ? (string) $data['guid'] : '',
				'meta'       => isset( $existing['meta'] ) && is_array( $existing['meta'] ) ? $existing['meta'] : array(),
				'source'     => 'wxr',
			)
		);

		$metadata['wxr_nav_menu_item_count'] = count( $metadata['wxr_nav_menu_items_by_id'] );

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'document.wxr_nav_menu_item_staged',
				'WXR navigation menu item was staged for local menu persistence.',
				array(
					'item_key'             => $item->get_key(),
					'wxr_nav_menu_item_id' => $post_id,
					'menu_slug'            => $menu['slug'],
				)
			)
		);

		return $metadata;
	}

	/**
	 * Resolves the WXR nav menu term for a nav_menu_item post.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return array{slug:string,name:string}
	 */
	private function wxr_nav_menu_for_item( array $data ) {
		if ( ! empty( $data['terms'] ) && is_array( $data['terms'] ) ) {
			foreach ( $data['terms'] as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}

				$taxonomy = isset( $term['taxonomy'] ) ? trim( (string) $term['taxonomy'] ) : '';

				if ( 'nav_menu' !== $taxonomy ) {
					continue;
				}

				$slug = isset( $term['slug'] ) ? trim( (string) $term['slug'] ) : '';
				$name = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';

				if ( '' !== $slug ) {
					return array(
						'slug' => $slug,
						'name' => '' === $name ? $slug : $name,
					);
				}
			}
		}

		return array(
			'slug' => 'imported-menu',
			'name' => 'Imported Menu',
		);
	}

	/**
	 * Stages one WXR post entity as a prepared document.
	 *
	 * @param ImportSession       $session       Session.
	 * @param ImportSourceItem    $item          Source WXR item.
	 * @param array<string,mixed> $data          WXR post data.
	 * @param int                 $fallback_index Per-tick fallback index.
	 * @param array<string,mixed> $source_metadata Source WXR item metadata accumulated so far.
	 * @return bool Whether a new prepared document was staged.
	 */
	private function prepare_wxr_post_document( ImportSession $session, ImportSourceItem $item, array $data, $fallback_index, array $source_metadata = array() ) {
		$post_id       = isset( $data['post_id'] ) && '' !== (string) $data['post_id'] ? (string) $data['post_id'] : $this->wxr_fallback_post_id( $data, $fallback_index );
		$document_key  = $this->wxr_document_key( $item, $post_id );
		$content       = $this->strip_scripts( isset( $data['post_content'] ) ? (string) $data['post_content'] : '' );
		$block_markup  = $this->wxr_content_to_blocks( $content );
		$content_hash  = hash( 'sha256', 'wxr' . "\n" . $document_key . "\n" . $content );
		$url_domains   = $this->extract_absolute_url_domains( $content );
		$remote_terms  = $this->wxr_post_terms( $data );
		$title         = $this->wxr_title( $data, $post_id );
		$metadata      = array(
			'relative_path'         => $item->get_relative_path(),
			'source_uri'            => $item->get_source_uri(),
			'wxr_source_item_key'   => $item->get_key(),
			'wxr_post_id'           => $post_id,
			'wxr_post_type'         => isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post',
			'wxr_post_status'       => isset( $data['post_status'] ) ? (string) $data['post_status'] : '',
			'wxr_post_name'         => isset( $data['post_name'] ) ? (string) $data['post_name'] : '',
			'wxr_link'              => isset( $data['link'] ) ? (string) $data['link'] : '',
			'wxr_guid'              => isset( $data['guid'] ) ? (string) $data['guid'] : '',
			'absolute_url_domains'  => array_keys( $url_domains ),
			'absolute_url_examples' => $url_domains,
		);
		$remote_author = $this->wxr_post_author( $source_metadata, $data );

		if ( null !== $remote_author ) {
			$metadata['remote_author_id'] = isset( $remote_author['id'] ) ? (int) $remote_author['id'] : null;
			$metadata['remote_author']    = $remote_author;
		}

		if ( ! empty( $remote_terms ) ) {
			$metadata['remote_terms'] = $remote_terms;
		}

		$existing = $this->store->find_prepared_document( $session->get_id(), $document_key );

		$prepared_document = new ImportPreparedDocument(
			$session->get_id(),
			$document_key,
			'wxr',
			$title,
			$block_markup,
			$this->count_blocks( $block_markup ),
			$content_hash,
			$metadata
		);

		$this->store->save_prepared_document( $prepared_document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord(
				'document-blocks:' . $document_key,
				'prepared_document',
				$document_key,
				$content_hash
			)
		);
		if ( null === $existing ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'document.wxr_post_prepared',
					'WXR post entity was staged as prepared block markup.',
					array(
						'item_key'        => $item->get_key(),
						'source_item_key' => $document_key,
						'wxr_post_id'     => $post_id,
						'title'           => $title,
					)
				)
			);
		}

		return null === $existing;
	}

	/**
	 * Queues a WXR attachment post through the shared media import pipeline.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    Source WXR item.
	 * @param array<string,mixed> $data    WXR attachment post data.
	 * @return bool Whether a new media reference was queued.
	 */
	private function queue_wxr_attachment_reference( ImportSession $session, ImportSourceItem $item, array $data ) {
		$attachment_id = ImportWxrAttachment::attachment_id_from_post( $data );
		$source_url    = ImportWxrAttachment::source_url_from_post( $data );

		if ( null === $attachment_id || null === $source_url ) {
			$this->record_event( $session, 'document.wxr_attachment_skipped', 'WXR attachment was skipped because it did not include a usable attachment URL.', $item, array() );
			return false;
		}

		$media_type = ImportWxrAttachment::media_type_for_url( $source_url );

		if ( null === $media_type ) {
			$this->record_event(
				$session,
				'document.wxr_attachment_skipped',
				'WXR attachment was skipped because its media extension is not supported yet.',
				$item,
				array(
					'wxr_attachment_id' => $attachment_id,
					'attachment_url'    => $source_url,
				)
			);
			return false;
		}

		$reference = ImportMediaReference::queued(
			$session->get_id(),
			ImportWxrAttachment::reference_key( $item->get_key(), $attachment_id ),
			ImportWxrAttachment::source_item_key( $item->get_key(), $attachment_id ),
			$source_url,
			$source_url,
			$media_type,
			array(
				'reference_scope'         => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
				'document_title'          => isset( $data['post_title'] ) ? (string) $data['post_title'] : '',
				'extension'               => ImportWxrAttachment::extension_for_url( $source_url ),
				'absolute_url_domain'     => ImportWxrAttachment::domain_for_url( $source_url ),
				'wxr_attachment_id'       => (string) $attachment_id,
				'wxr_post_parent'         => isset( $data['post_parent'] ) ? (string) $data['post_parent'] : '',
				'wxr_guid'                => isset( $data['guid'] ) ? (string) $data['guid'] : '',
				'wxr_attachment_metadata' => $this->wxr_attachment_metadata_from_post( $data ),
				'source'                  => 'wxr',
			)
		);

		$existing = $this->store->find_media_reference( $session->get_id(), $reference->get_key() );
		$this->store->save_media_reference( $reference );

		if ( null === $existing ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'media.wxr_attachment_queued',
					'WXR attachment was queued for first-party confirmation and media import.',
					array(
						'item_key'          => $item->get_key(),
						'reference_key'     => $reference->get_key(),
						'wxr_attachment_id' => $attachment_id,
						'attachment_url'    => $source_url,
						'media_type'        => $reference->get_media_type(),
					)
				)
			);
		}

		return null === $existing;
	}

	/**
	 * Normalizes WXR attachment post fields for later local attachment metadata persistence.
	 *
	 * @param array<string,mixed> $data WXR attachment post data.
	 * @return array<string,mixed>
	 */
	private function wxr_attachment_metadata_from_post( array $data ) {
		if ( isset( $data['post_excerpt'] ) ) {
			$data['post_excerpt'] = $this->strip_scripts( (string) $data['post_excerpt'] );
		}

		if ( isset( $data['post_content'] ) ) {
			$data['post_content'] = $this->strip_scripts( (string) $data['post_content'] );
		}

		return ImportWxrAttachment::metadata_from_post( $data );
	}

	/**
	 * Builds the prepared-document key for a WXR post id.
	 *
	 * @param ImportSourceItem $item    Source WXR item.
	 * @param string|int       $post_id WXR post id.
	 * @return string
	 */
	private function wxr_document_key( ImportSourceItem $item, $post_id ) {
		return $item->get_key() . ':wxr-post:' . (string) $post_id;
	}

	/**
	 * Stores WXR author metadata for later post relationship staging.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @param array<string,mixed> $data     WXR author data.
	 * @return array<string,mixed>
	 */
	private function remember_wxr_author( array $metadata, array $data ) {
		$login = isset( $data['user_login'] ) ? trim( (string) $data['user_login'] ) : '';

		if ( '' === $login ) {
			return $metadata;
		}

		if ( ! isset( $metadata['wxr_authors_by_login'] ) || ! is_array( $metadata['wxr_authors_by_login'] ) ) {
			$metadata['wxr_authors_by_login'] = array();
		}

		$metadata['wxr_authors_by_login'][ $login ] = array(
			'id'         => isset( $data['ID'] ) ? (int) $data['ID'] : null,
			'slug'       => $login,
			'name'       => isset( $data['display_name'] ) && '' !== trim( (string) $data['display_name'] ) ? (string) $data['display_name'] : $login,
			'email'      => isset( $data['user_email'] ) ? (string) $data['user_email'] : '',
			'first_name' => isset( $data['first_name'] ) ? (string) $data['first_name'] : '',
			'last_name'  => isset( $data['last_name'] ) ? (string) $data['last_name'] : '',
			'source'     => 'wxr',
		);
		$metadata['wxr_author_count']               = count( $metadata['wxr_authors_by_login'] );

		return $metadata;
	}

	/**
	 * Stores WXR term/category/tag dictionaries for post relationship staging.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @param string              $type     WXR entity type.
	 * @param array<string,mixed> $data     WXR term data.
	 * @return array<string,mixed>
	 */
	private function remember_wxr_term( array $metadata, $type, array $data ) {
		$term = $this->normalize_wxr_term( $type, $data );

		if ( null === $term ) {
			return $metadata;
		}

		if ( ! isset( $metadata['wxr_terms_by_taxonomy_slug'] ) || ! is_array( $metadata['wxr_terms_by_taxonomy_slug'] ) ) {
			$metadata['wxr_terms_by_taxonomy_slug'] = array();
		}

		if ( ! isset( $metadata['wxr_terms_by_taxonomy_slug'][ $term['taxonomy'] ] ) || ! is_array( $metadata['wxr_terms_by_taxonomy_slug'][ $term['taxonomy'] ] ) ) {
			$metadata['wxr_terms_by_taxonomy_slug'][ $term['taxonomy'] ] = array();
		}

		$metadata['wxr_terms_by_taxonomy_slug'][ $term['taxonomy'] ][ $term['slug'] ] = $term;
		$metadata['wxr_term_count'] = $this->count_wxr_terms( $metadata['wxr_terms_by_taxonomy_slug'] );

		return $metadata;
	}

	/**
	 * Normalizes a WXR term-like entity.
	 *
	 * @param string              $type WXR entity type.
	 * @param array<string,mixed> $data WXR term data.
	 * @return array<string,mixed>|null
	 */
	private function normalize_wxr_term( $type, array $data ) {
		$taxonomy = isset( $data['taxonomy'] ) ? trim( (string) $data['taxonomy'] ) : '';

		if ( ImportEntity::TYPE_CATEGORY === $type && '' === $taxonomy ) {
			$taxonomy = 'category';
		} elseif ( ImportEntity::TYPE_TAG === $type && '' === $taxonomy ) {
			$taxonomy = 'post_tag';
		}

		$slug = isset( $data['slug'] ) ? trim( (string) $data['slug'] ) : '';
		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';

		if ( '' === $taxonomy || '' === $slug ) {
			return null;
		}

		return array(
			'id'          => isset( $data['term_id'] ) ? (int) $data['term_id'] : null,
			'taxonomy'    => $taxonomy,
			'slug'        => $slug,
			'name'        => '' === $name ? $slug : $name,
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'parent'      => isset( $data['parent'] ) ? (string) $data['parent'] : '',
			'source'      => 'wxr',
		);
	}

	/**
	 * Counts remembered WXR terms.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $terms Terms by taxonomy and slug.
	 * @return int
	 */
	private function count_wxr_terms( array $terms ) {
		$count = 0;

		foreach ( $terms as $taxonomy_terms ) {
			if ( is_array( $taxonomy_terms ) ) {
				$count += count( $taxonomy_terms );
			}
		}

		return $count;
	}

	/**
	 * Resolves WXR post author data from the export author dictionary or creator field.
	 *
	 * @param array<string,mixed> $source_metadata Source item metadata.
	 * @param array<string,mixed> $data            WXR post data.
	 * @return array<string,mixed>|null
	 */
	private function wxr_post_author( array $source_metadata, array $data ) {
		$login = isset( $data['post_author'] ) ? trim( (string) $data['post_author'] ) : '';

		if ( '' === $login ) {
			return null;
		}

		if (
			isset( $source_metadata['wxr_authors_by_login'] ) &&
			is_array( $source_metadata['wxr_authors_by_login'] ) &&
			isset( $source_metadata['wxr_authors_by_login'][ $login ] ) &&
			is_array( $source_metadata['wxr_authors_by_login'][ $login ] )
		) {
			return $source_metadata['wxr_authors_by_login'][ $login ];
		}

		return array(
			'id'     => null,
			'slug'   => $login,
			'name'   => $login,
			'source' => 'wxr',
		);
	}

	/**
	 * Normalizes WXR post term references into the prepared document relationship shape.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function wxr_post_terms( array $data ) {
		if ( empty( $data['terms'] ) || ! is_array( $data['terms'] ) ) {
			return array();
		}

		$terms = array();

		foreach ( $data['terms'] as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}

			$taxonomy = isset( $term['taxonomy'] ) ? trim( (string) $term['taxonomy'] ) : '';
			$slug     = isset( $term['slug'] ) ? trim( (string) $term['slug'] ) : '';
			$name     = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';

			if ( '' === $name && isset( $term['description'] ) ) {
				$name = trim( (string) $term['description'] );
			}

			if ( '' === $taxonomy || '' === $slug ) {
				continue;
			}

			if ( ! isset( $terms[ $taxonomy ] ) ) {
				$terms[ $taxonomy ] = array();
			}

			$terms[ $taxonomy ][] = array(
				'id'          => isset( $term['term_id'] ) ? (int) $term['term_id'] : null,
				'taxonomy'    => $taxonomy,
				'slug'        => $slug,
				'name'        => '' === $name ? $slug : $name,
				'description' => isset( $term['description'] ) ? (string) $term['description'] : '',
				'source'      => 'wxr',
			);
		}

		return $terms;
	}

	/**
	 * Appends WXR postmeta onto an already staged prepared document.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    Source WXR item.
	 * @param array<string,mixed> $data    WXR postmeta data.
	 * @return void
	 */
	private function attach_wxr_post_meta( ImportSession $session, ImportSourceItem $item, array $data ) {
		$post_id = isset( $data['post_id'] ) ? (string) $data['post_id'] : '';

		if ( '' === $post_id ) {
			return;
		}

		$document = $this->store->find_prepared_document( $session->get_id(), $this->wxr_document_key( $item, $post_id ) );

		if ( null === $document ) {
			return;
		}

		$metadata = $document->get_metadata();
		$entry    = array(
			'key'    => isset( $data['meta_key'] ) ? (string) $data['meta_key'] : '',
			'value'  => isset( $data['meta_value'] ) ? (string) $data['meta_value'] : '',
			'source' => 'wxr',
		);

		if ( '' === $entry['key'] ) {
			return;
		}

		if ( ! isset( $metadata['wxr_postmeta'] ) || ! is_array( $metadata['wxr_postmeta'] ) ) {
			$metadata['wxr_postmeta'] = array();
		}

		if ( ! $this->contains_assoc_entry( $metadata['wxr_postmeta'], $entry ) ) {
			$metadata['wxr_postmeta'][] = $entry;
		}

		$metadata                       = $this->merge_absolute_url_domain_metadata( $metadata, $this->extract_absolute_url_domains( $entry['value'] ) );
		$metadata['wxr_postmeta_count'] = count( $metadata['wxr_postmeta'] );
		$this->store->save_prepared_document( $document->with_metadata( $metadata ) );
	}

	/**
	 * Appends WXR attachment-specific postmeta onto an already queued media reference.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    Source WXR item.
	 * @param array<string,mixed> $data    WXR postmeta data.
	 * @return void
	 */
	private function attach_wxr_attachment_meta( ImportSession $session, ImportSourceItem $item, array $data ) {
		$post_id = isset( $data['post_id'] ) ? trim( (string) $data['post_id'] ) : '';

		if ( '' === $post_id ) {
			return;
		}

		$reference = $this->store->find_media_reference(
			$session->get_id(),
			ImportWxrAttachment::reference_key( $item->get_key(), $post_id )
		);

		if ( null === $reference ) {
			return;
		}

		$metadata                            = $reference->get_metadata();
		$metadata['wxr_attachment_metadata'] = ImportWxrAttachment::metadata_with_postmeta(
			isset( $metadata['wxr_attachment_metadata'] ) && is_array( $metadata['wxr_attachment_metadata'] ) ? $metadata['wxr_attachment_metadata'] : array(),
			isset( $data['meta_key'] ) ? (string) $data['meta_key'] : '',
			isset( $data['meta_value'] ) ? (string) $data['meta_value'] : ''
		);

		$this->store->save_media_reference( $reference->with_metadata( $metadata ) );
	}

	/**
	 * Appends nav menu item postmeta onto source-item WXR menu metadata.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @param array<string,mixed> $data     WXR postmeta data.
	 * @return array<string,mixed> Updated metadata.
	 */
	private function attach_wxr_nav_menu_meta( array $metadata, array $data ) {
		$post_id = isset( $data['post_id'] ) ? trim( (string) $data['post_id'] ) : '';
		$key     = isset( $data['meta_key'] ) ? trim( (string) $data['meta_key'] ) : '';

		if ( '' === $post_id || '' === $key || 0 !== strpos( $key, '_menu_item_' ) ) {
			return $metadata;
		}

		if ( empty( $metadata['wxr_nav_menu_items_by_id'][ $post_id ] ) || ! is_array( $metadata['wxr_nav_menu_items_by_id'][ $post_id ] ) ) {
			return $metadata;
		}

		$value = isset( $data['meta_value'] ) ? (string) $data['meta_value'] : '';
		$metadata['wxr_nav_menu_items_by_id'][ $post_id ]['meta'][ $key ] = $value;

		if ( '_menu_item_url' === $key ) {
			$metadata                                      = $this->merge_absolute_url_domain_metadata( $metadata, $this->extract_absolute_url_domains( $value ) );
			$metadata['wxr_nav_menu_absolute_url_domains'] = isset( $metadata['absolute_url_domains'] ) && is_array( $metadata['absolute_url_domains'] )
				? $metadata['absolute_url_domains']
				: array();
		}

		return $metadata;
	}

	/**
	 * Appends a WXR comment onto an already staged prepared document.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    Source WXR item.
	 * @param array<string,mixed> $data    WXR comment data.
	 * @return void
	 */
	private function attach_wxr_comment( ImportSession $session, ImportSourceItem $item, array $data ) {
		$post_id    = isset( $data['post_id'] ) ? (string) $data['post_id'] : '';
		$comment_id = isset( $data['comment_id'] ) ? (int) $data['comment_id'] : 0;

		if ( '' === $post_id || $comment_id < 1 ) {
			return;
		}

		$document_key = $this->wxr_document_key( $item, $post_id );
		$document     = $this->store->find_prepared_document( $session->get_id(), $document_key );

		if ( null === $document ) {
			return;
		}

		$metadata = $document->get_metadata();
		$comment  = array(
			'remote_comment_id' => $comment_id,
			'remote_parent_id'  => isset( $data['comment_parent'] ) ? (int) $data['comment_parent'] : 0,
			'author_name'       => isset( $data['comment_author'] ) ? (string) $data['comment_author'] : '',
			'author_url'        => isset( $data['comment_author_url'] ) ? (string) $data['comment_author_url'] : '',
			'content'           => $this->strip_scripts( isset( $data['comment_content'] ) ? (string) $data['comment_content'] : '' ),
			'date'              => isset( $data['comment_date'] ) ? (string) $data['comment_date'] : '',
			'date_gmt'          => isset( $data['comment_date_gmt'] ) ? (string) $data['comment_date_gmt'] : '',
			'status'            => $this->wxr_comment_status( isset( $data['comment_approved'] ) ? (string) $data['comment_approved'] : '' ),
			'type'              => isset( $data['comment_type'] ) ? (string) $data['comment_type'] : 'comment',
			'source_item_key'   => $document_key,
			'source'            => 'wxr',
		);

		if ( ! isset( $metadata['remote_comments'] ) || ! is_array( $metadata['remote_comments'] ) ) {
			$metadata['remote_comments'] = array();
		}

		$metadata['remote_comments']      = $this->upsert_remote_comment( $metadata['remote_comments'], $comment );
		$metadata['remote_comment_count'] = count( $metadata['remote_comments'] );
		$this->store->save_prepared_document( $document->with_metadata( $metadata ) );

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'document.wxr_comment_staged',
				'WXR comment entity was staged for local WordPress comment persistence.',
				array(
					'item_key'          => $item->get_key(),
					'source_item_key'   => $document_key,
					'wxr_post_id'       => $post_id,
					'remote_comment_id' => $comment_id,
				)
			)
		);
	}

	/**
	 * Upserts a normalized remote comment by remote id.
	 *
	 * @param array<int,array<string,mixed>> $comments Existing comments.
	 * @param array<string,mixed>            $comment  New comment.
	 * @return array<int,array<string,mixed>>
	 */
	private function upsert_remote_comment( array $comments, array $comment ) {
		$remote_comment_id = isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : 0;

		foreach ( $comments as $index => $existing ) {
			if ( is_array( $existing ) && isset( $existing['remote_comment_id'] ) && (int) $existing['remote_comment_id'] === $remote_comment_id ) {
				$comments[ $index ] = $comment;
				return array_values( $comments );
			}
		}

		$comments[] = $comment;

		return array_values( $comments );
	}

	/**
	 * Normalizes WXR comment approval state to the existing comment persister vocabulary.
	 *
	 * @param string $approved Raw WXR approval value.
	 * @return string
	 */
	private function wxr_comment_status( $approved ) {
		$approved = strtolower( trim( (string) $approved ) );

		if ( '1' === $approved || 'approve' === $approved || 'approved' === $approved ) {
			return 'approved';
		}

		if ( 'spam' === $approved ) {
			return 'spam';
		}

		if ( 'trash' === $approved ) {
			return 'trash';
		}

		return 'hold';
	}

	/**
	 * Checks whether an associative entry already exists in a list.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @param array<string,mixed>            $entry   Candidate entry.
	 * @return bool
	 */
	private function contains_assoc_entry( array $entries, array $entry ) {
		foreach ( $entries as $existing ) {
			if ( is_array( $existing ) && $existing === $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a bounded entry from an EPUB zip archive.
	 *
	 * @param ZipArchive $zip        Open archive.
	 * @param string     $entry_name Entry name.
	 * @return string
	 * @throws RuntimeException When the entry is missing, unsafe, or too large.
	 */
	private function read_zip_entry( ZipArchive $zip, $entry_name ) {
		$entry_name = str_replace( '\\', '/', (string) $entry_name );

		if ( $this->is_unsafe_epub_path( $entry_name ) ) {
			throw new RuntimeException( 'EPUB entry path is unsafe.' );
		}

		$entry = $zip->statName( $entry_name );

		if ( false === $entry ) {
			throw new RuntimeException( 'Required EPUB entry is missing.' );
		}

		if ( isset( $entry['size'] ) && self::EPUB_ENTRY_LIMIT < (int) $entry['size'] ) {
			throw new RuntimeException( 'EPUB entry exceeds the per-entry read limit.' );
		}

		$content = $zip->getFromName( $entry_name );

		if ( false === $content ) {
			throw new RuntimeException( 'Unable to read EPUB entry.' );
		}

		return (string) $content;
	}

	/**
	 * Parses XML without allowing network access.
	 *
	 * @param string $xml     XML string.
	 * @param string $message Failure message.
	 * @return SimpleXMLElement
	 * @throws RuntimeException When XML parsing fails.
	 */
	private function parse_xml( $xml, $message ) {
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( (string) $xml, SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $parsed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal diagnostic, not direct output.
			throw new RuntimeException( $message );
		}

		return $parsed;
	}

	/**
	 * Returns whether an EPUB archive path is unsafe.
	 *
	 * @param string $path Entry path.
	 * @return bool
	 */
	private function is_unsafe_epub_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		if ( '' === trim( $path ) || '/' === substr( $path, 0, 1 ) || false !== strpos( $path, "\0" ) ) {
			return true;
		}

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Joins a package directory and relative EPUB href.
	 *
	 * @param string $base_dir Package directory.
	 * @param string $href     Manifest href.
	 * @return string
	 * @throws RuntimeException When the resulting path is unsafe.
	 */
	private function join_epub_path( $base_dir, $href ) {
		$href_without_fragment = strtok( str_replace( '\\', '/', (string) $href ), '#' );
		$href                  = rawurldecode( false === $href_without_fragment ? '' : $href_without_fragment );
		$parts                 = array();

		foreach ( explode( '/', trim( (string) $base_dir . '/' . $href, '/' ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $part;
		}

		$path = implode( '/', $parts );

		if ( $this->is_unsafe_epub_path( $path ) ) {
			throw new RuntimeException( 'EPUB manifest href resolves to an unsafe entry path.' );
		}

		return $path;
	}

	/**
	 * Extracts body contents from XHTML/HTML when possible.
	 *
	 * @param string $content Raw HTML.
	 * @return string
	 */
	private function extract_html_body( $content ) {
		return ( new ImportHtmlBlockConverter() )->extract_body( $content );
	}

	/**
	 * Chooses a readable title for an EPUB spine document.
	 *
	 * @param string $content    Chapter body HTML.
	 * @param string $book_title EPUB package title.
	 * @param int    $index      Spine index.
	 * @return string
	 */
	private function epub_chapter_title( $content, $book_title, $index ) {
		if ( preg_match( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', (string) $content, $matches ) ) {
			$title = trim( html_entity_decode( preg_replace( '#<[^>]+>#', '', $matches[1] ), ENT_QUOTES, 'UTF-8' ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$book_title = trim( (string) $book_title );

		return ( '' === $book_title ? 'EPUB document' : $book_title ) . ' - Chapter ' . ( (int) $index + 1 );
	}

	/**
	 * Builds a stable fallback id for WXR posts without wp:post_id.
	 *
	 * @param array<string,mixed> $data           WXR post data.
	 * @param int                 $fallback_index Per-tick fallback index.
	 * @return string
	 */
	private function wxr_fallback_post_id( array $data, $fallback_index ) {
		$parts = array();

		foreach ( array( 'post_title', 'post_name', 'guid', 'link', 'post_date', 'post_content' ) as $field ) {
			$parts[] = isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
		}

		$parts[] = (string) $fallback_index;

		return 'hash-' . substr( hash( 'sha256', implode( "\n", $parts ) ), 0, 16 );
	}

	/**
	 * Converts WXR post content into block markup.
	 *
	 * @param string $content WXR post content.
	 * @return string
	 */
	private function wxr_content_to_blocks( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content ) {
			return '';
		}

		if ( preg_match( '/<!--\s+wp:/', $content ) ) {
			return $content;
		}

		return $this->html_to_blocks( $content );
	}

	/**
	 * Chooses a WXR post title.
	 *
	 * @param array<string,mixed> $data    WXR post data.
	 * @param string              $post_id WXR post id.
	 * @return string
	 */
	private function wxr_title( array $data, $post_id ) {
		foreach ( array( 'post_title', 'post_name', 'guid', 'link' ) as $field ) {
			if ( isset( $data[ $field ] ) && '' !== trim( (string) $data[ $field ] ) ) {
				return trim( (string) $data[ $field ] );
			}
		}

		return 'WXR post ' . $post_id;
	}

	/**
	 * Converts a file into initial block markup metadata.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Source item.
	 * @param string           $format  Document format.
	 * @param array|null       $pdf_asset_summary Pre-scanned PDF embedded media summary.
	 * @return array{title:string,block_markup:string,block_count:int,content_hash:string,absolute_url_domains:array<int,string>,absolute_url_examples:array<string,array<int,string>>,metadata:array<string,mixed>}
	 * @throws ImportDocumentProcessingException When PDF processing fails with durable diagnostics.
	 */
	private function prepare_document( ImportSession $session, ImportSourceItem $item, $format, array $pdf_asset_summary = null ) {
		$should_scan_pdf_assets    = null === $pdf_asset_summary;
		$default_pdf_asset_summary = array(
			'queued'              => 0,
			'unsupported'         => 0,
			'unsupported_filters' => array(),
			'unsupported_reasons' => array(),
			'assets'              => array(),
			'complete'            => true,
			'next_offset'         => 0,
			'next_index'          => 0,
		);

		if ( null === $pdf_asset_summary ) {
			$pdf_asset_summary = $default_pdf_asset_summary;
		}

		if ( 'pdf' === $format && $should_scan_pdf_assets ) {
			$this->assert_pdf_file_within_first_pass_limit( $item->get_source_uri() );
			$pdf_asset_summary = $this->queue_pdf_embedded_media_references( $session, $item );
		}

		try {
			$document = 'pdf' === $format ? $this->read_pdf_text( $item->get_source_uri(), $this->pdf_structure_metadata_from_existing_metadata( $item->get_metadata() ) ) : $this->read_streamed_file( $item->get_source_uri(), $format );
		} catch ( ImportDocumentProcessingException $exception ) {
			if ( 'pdf' !== $format || empty( $pdf_asset_summary['assets'] ) ) {
				throw $exception;
			}

			$document = array(
				'content'      => '',
				'content_hash' => $this->pdf_asset_only_content_hash( $item, $pdf_asset_summary['assets'] ),
				'metadata'     => array_merge(
					$exception->get_metadata(),
					array(
						'pdf_text_extraction_status' => 'no_text_assets_imported',
						'pdf_text_warning'           => $exception->getMessage(),
					)
				),
			);
		}

		$content      = $document['content'];
		$content_hash = $document['content_hash'];
		$metadata     = isset( $document['metadata'] ) && is_array( $document['metadata'] ) ? $document['metadata'] : array();

		if ( 'html' === $format ) {
			$content = $this->extract_html_body( $content );
		}

		$markdown_title      = '';
		$markdown_references = array();
		if ( 'markdown' === $format ) {
			$markdown_document = $this->extract_markdown_front_matter( $content );
			$content           = $markdown_document['content'];
			if ( '' !== $markdown_document['title'] ) {
				$markdown_title                          = $markdown_document['title'];
				$metadata['markdown_front_matter_title'] = $markdown_title;
			}
			if ( $markdown_document['detected'] ) {
				$metadata['markdown_front_matter'] = true;
			}

			$markdown_reference_document = $this->extract_markdown_reference_definitions( $content );
			$content                     = $markdown_reference_document['content'];
			$markdown_references         = $markdown_reference_document['references'];
			if ( ! empty( $markdown_references ) ) {
				$metadata['markdown_reference_count'] = count( $markdown_references );
			}
		}

		$url_domains = $this->extract_absolute_url_domains( $content );
		$pdf_assets  = 'pdf' === $format ? $pdf_asset_summary['assets'] : array();

		if ( 'pdf' === $format ) {
			if ( 0 < $pdf_asset_summary['queued'] ) {
				$metadata['pdf_embedded_media_detected']          = true;
				$metadata['pdf_embedded_media_extraction_status'] = 0 < $pdf_asset_summary['unsupported'] ? 'partial' : 'queued';
				$metadata['pdf_embedded_media_queued']            = $pdf_asset_summary['queued'];
				$metadata['pdf_embedded_media_assets']            = $pdf_asset_summary['assets'];
				$metadata['pdf_embedded_media_hint']              = 'PDF contains embedded JPEG image streams; extracted images were queued for media attachment import. Other embedded PDF media or vector content may still need operator review.';
			}

			if ( isset( $pdf_asset_summary['read_offset'] ) ) {
				$metadata['pdf_media_scan_read_offset'] = (int) $pdf_asset_summary['read_offset'];
			}
			if ( isset( $pdf_asset_summary['read_bytes'] ) ) {
				$metadata['pdf_media_scan_read_bytes'] = (int) $pdf_asset_summary['read_bytes'];
			}

			if ( 0 < $pdf_asset_summary['unsupported'] ) {
				$metadata['pdf_unsupported_embedded_media_count']         = $pdf_asset_summary['unsupported'];
				$metadata['pdf_unsupported_embedded_media_filter_counts'] = $pdf_asset_summary['unsupported_filters'];
				$metadata['pdf_unsupported_embedded_media_reason_counts'] = $pdf_asset_summary['unsupported_reasons'];

				if ( ! empty( $pdf_asset_summary['unsupported_filters'] ) ) {
					$metadata['pdf_unsupported_embedded_media_filters'] = array_keys( $pdf_asset_summary['unsupported_filters'] );
				}

				if ( ! empty( $pdf_asset_summary['unsupported_reasons'] ) ) {
					$metadata['pdf_unsupported_embedded_media_reasons'] = array_keys( $pdf_asset_summary['unsupported_reasons'] );
				}

				if ( isset( $pdf_asset_summary['unsupported_reasons']['file_size_limit'] ) ) {
					$metadata['pdf_embedded_media_file_limit_bytes'] = self::PDF_MEDIA_FILE_LIMIT;
				}

				if ( isset( $pdf_asset_summary['unsupported_reasons']['extraction_limit'] ) ) {
					$metadata['pdf_embedded_media_limit'] = self::PDF_MEDIA_LIMIT;
				}

				$unsupported_hint = $this->pdf_unsupported_media_hint( $pdf_asset_summary );
				if ( 0 < $pdf_asset_summary['queued'] ) {
					$metadata['pdf_embedded_media_hint'] .= ' ' . $unsupported_hint;
				} else {
					$metadata['pdf_embedded_media_detected']          = true;
					$metadata['pdf_embedded_media_extraction_status'] = 'unsupported';
					$metadata['pdf_embedded_media_hint']              = $unsupported_hint;
				}

				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_WARNING,
						'media.pdf_asset_unsupported',
						'Embedded PDF media streams were detected but could not be extracted by the first-pass importer.',
						array(
							'item_key' => $item->get_key(),
							'count'    => $pdf_asset_summary['unsupported'],
							'filters'  => array_keys( $pdf_asset_summary['unsupported_filters'] ),
							'reasons'  => array_keys( $pdf_asset_summary['unsupported_reasons'] ),
							'limits'   => array(
								'per_pdf_media'        => self::PDF_MEDIA_LIMIT,
								'media_file_bytes'     => self::PDF_MEDIA_FILE_LIMIT,
								'first_pass_pdf_bytes' => self::PDF_FILE_LIMIT,
							),
						)
					)
				);
			}
		}

		if ( 'html' === $format ) {
			$html_summary = array();
			$block_markup = $this->html_to_blocks( $content, $html_summary );
			$block_count  = $this->count_blocks( $block_markup );
			$metadata     = array_merge( $metadata, $html_summary );
		} elseif ( 'markdown' === $format ) {
			$block_markup = $this->markdown_to_blocks( $content, $markdown_references );
			$block_count  = $this->count_blocks( $block_markup );
			$url_domains  = $this->merge_absolute_url_domain_examples( $url_domains, $this->extract_absolute_url_domains( $block_markup ) );
		} elseif ( 'pdf' === $format ) {
			$pdf_table_summary = array(
				'tables'      => 0,
				'rows'        => 0,
				'max_columns' => 0,
			);
			$block_markup      = $this->pdf_text_to_blocks( $content, $pdf_table_summary );
			if ( 0 < $pdf_table_summary['tables'] ) {
				$metadata['pdf_table_block_count']      = $pdf_table_summary['tables'];
				$metadata['pdf_table_row_count']        = $pdf_table_summary['rows'];
				$metadata['pdf_table_max_column_count'] = $pdf_table_summary['max_columns'];
				$metadata['pdf_layout_warning']         = 'PDF tabular text runs were converted to WordPress table blocks where detected. Complex columns, merged cells, or vector-only tables may still need operator review.';
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_INFO,
						'document.pdf_table_blocks',
						'PDF tabular text was converted into WordPress table blocks.',
						array(
							'item_key'    => $item->get_key(),
							'table_count' => $pdf_table_summary['tables'],
							'row_count'   => $pdf_table_summary['rows'],
						)
					)
				);
			}
			if ( ! empty( $pdf_assets ) ) {
				$block_markup = trim( $block_markup . "\n\n" . $this->pdf_embedded_media_blocks( $pdf_assets ) );
			}
			$block_count = $this->count_blocks( $block_markup );
		} else {
			$block_markup = $this->text_to_blocks( $content );
			$block_count  = $this->count_blocks( $block_markup );
		}

		return array(
			'title'                 => '' === $markdown_title ? $this->title_for_item( $item, $content ) : $markdown_title,
			'block_markup'          => $block_markup,
			'block_count'           => $block_count,
			'content_hash'          => $content_hash,
			'absolute_url_domains'  => array_keys( $url_domains ),
			'absolute_url_examples' => $url_domains,
			'metadata'              => $metadata,
		);
	}

	/**
	 * Verifies a PDF is safe for bounded first-pass parsing before media scans.
	 *
	 * @param string $path Source path.
	 * @return void
	 * @throws RuntimeException When the file cannot be read or exceeds the PDF limit.
	 */
	private function assert_pdf_file_within_first_pass_limit( $path ) {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Discovered PDF item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Discovered PDF file is not readable.' );
		}

		$size = filesize( $path );

		if ( false === $size ) {
			throw new RuntimeException( 'Unable to determine discovered PDF file size.' );
		}

		if ( self::PDF_FILE_LIMIT < (int) $size ) {
			throw new RuntimeException( 'PDF file exceeds the first-pass extraction size limit.' );
		}
	}

	/**
	 * Reads a bounded PDF scan suffix from a durable byte cursor.
	 *
	 * @param string $path   Source path.
	 * @param int    $offset Absolute byte offset.
	 * @return string|false
	 */
	private function read_pdf_scan_bytes( $path, $offset ) {
		$offset = max( 0, (int) $offset );
		$size   = filesize( $path );

		if ( false === $size || $offset >= (int) $size ) {
			return '';
		}

		$length = max( 0, min( self::PDF_FILE_LIMIT, (int) $size ) - $offset );
		if ( 0 >= $length ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PDF scan reads are bounded and can resume from a stored byte offset.
		return file_get_contents( $path, false, null, $offset, $length );
	}

	/**
	 * Reads and extracts first-pass text from a bounded PDF file.
	 *
	 * This is intentionally conservative: it handles common text streams and
	 * records diagnostics for PDFs that need OCR or richer parsing later.
	 *
	 * @param string              $path               Source path.
	 * @param array<string,mixed> $structure_metadata Existing durable structure diagnostics.
	 * @return array{content:string,content_hash:string,metadata?:array<string,mixed>}
	 * @throws RuntimeException When the file cannot be read or has no text.
	 * @throws ImportDocumentProcessingException When PDF text and OCR extraction fail.
	 */
	private function read_pdf_text( $path, array $structure_metadata = array() ) {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Discovered PDF item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Discovered PDF file is not readable.' );
		}

		$size = filesize( $path );

		if ( false === $size ) {
			throw new RuntimeException( 'Unable to determine discovered PDF file size.' );
		}

		if ( self::PDF_FILE_LIMIT < (int) $size ) {
			throw new RuntimeException( 'PDF file exceeds the first-pass extraction size limit.' );
		}

		$stream       = $this->open_document_stream( $path );
		$hash_context = hash_init( 'sha256' );
		$pdf          = '';

		hash_update( $hash_context, "pdf\n" );

		try {
			while ( ! $stream->reached_end_of_data() ) {
				$pulled = $stream->pull( self::READ_CHUNK_BYTES, ByteReadStream::PULL_NO_MORE_THAN );

				if ( 0 === $pulled ) {
					break;
				}

				$chunk = $stream->consume( $pulled );
				hash_update( $hash_context, $chunk );
				$pdf .= $chunk;
			}
		} catch ( ByteStreamException $exception ) {
			throw new RuntimeException( 'Unable to read discovered PDF file stream.' );
		} finally {
			$stream->close_reading();
		}

		$source_hash = hash_final( $hash_context );
		$text        = $this->extract_pdf_text( $pdf );
		$diagnostics = empty( $structure_metadata ) ? $this->analyze_pdf_structure( $pdf ) : $structure_metadata;
		$external    = null;

		if ( ! empty( $diagnostics['pdf_object_stream_count'] ) ) {
			$external = $this->extract_pdf_text_with_external_command( $path );

			if ( '' !== trim( $external['content'] ) ) {
				return array(
					'content'      => $external['content'],
					'content_hash' => hash( 'sha256', "pdf-external\n" . $source_hash . "\n" . $external['content'] ),
					'metadata'     => array_merge(
						array(
							'pdf_text_engine' => 'external',
						),
						$diagnostics,
						$external['metadata']
					),
				);
			}
		}

		if ( '' === trim( $text ) ) {
			if ( null === $external ) {
				$external = $this->extract_pdf_text_with_external_command( $path );
			}

			if ( '' !== trim( $external['content'] ) ) {
				return array(
					'content'      => $external['content'],
					'content_hash' => hash( 'sha256', "pdf-external\n" . $source_hash . "\n" . $external['content'] ),
					'metadata'     => array_merge(
						array(
							'pdf_text_engine' => 'external',
						),
						$diagnostics,
						$external['metadata']
					),
				);
			}

			$ocr = $this->extract_pdf_text_with_ocr( $path );

			if ( '' === trim( $ocr['content'] ) ) {
				$external_status = isset( $external['metadata']['pdf_external_text_status'] ) ? (string) $external['metadata']['pdf_external_text_status'] : '';
				$ocr_status      = isset( $ocr['metadata']['pdf_ocr_status'] ) ? (string) $ocr['metadata']['pdf_ocr_status'] : '';
				$message         = $ocr['message'];

				if ( 'not_configured' === $external_status && 'not_configured' === $ocr_status ) {
					$message = 'PDF text extraction produced no importable text. Configure UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND for a text extractor such as pdftotext, or UNIVERSAL_IMPORTER_PDF_OCR_COMMAND for scanned PDFs.';
				} elseif ( 'not_configured' !== $external_status && '' !== trim( $external['message'] ) ) {
					$message = $external['message'];
					if ( 'not_configured' === $ocr_status ) {
						$message .= ' OCR is not configured; set UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to try scanned-PDF fallback.';
					}
				}

				throw new ImportDocumentProcessingException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is persisted as an operational diagnostic, not rendered here.
					$message,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Metadata is persisted as structured diagnostics, not rendered here.
					array_merge( $diagnostics, $external['metadata'], $ocr['metadata'] )
				);
			}

			$external_metadata = $external['metadata'];
			if ( 'not_configured' === $external_metadata['pdf_external_text_status'] ) {
				$external_metadata = array();
			}

			return array(
				'content'      => $ocr['content'],
				'content_hash' => hash( 'sha256', "pdf-ocr\n" . $source_hash . "\n" . $ocr['content'] ),
				'metadata'     => array_merge(
					array(
						'pdf_text_engine' => 'ocr',
						'pdf_ocr_status'  => 'succeeded',
					),
					$diagnostics,
					$external_metadata,
					$ocr['metadata']
				),
			);
		}

		return array(
			'content'      => $text,
			'content_hash' => $source_hash,
			'metadata'     => array(
				'pdf_text_engine' => 'native',
			) + $diagnostics,
		);
	}

	/**
	 * Builds durable diagnostics about PDF features the first-pass text reader cannot preserve.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<string,mixed>
	 */
	private function analyze_pdf_structure( $pdf ) {
		$segments    = array( (string) $pdf );
		$stream_info = array();

		foreach ( $this->extract_pdf_stream_segments( $pdf, $stream_info ) as $segment ) {
			$segments[] = $segment;
		}

		$text_operator_count   = 0;
		$image_reference_count = 0;
		$rectangle_count       = 0;
		$line_segment_count    = 0;
		$metadata              = array(
			'pdf_stream_count' => isset( $stream_info['matched_streams'] ) ? (int) $stream_info['matched_streams'] : 0,
		);
		$structure_reasons     = array();
		$object_stream_count   = $this->count_pattern_matches( '#/Type\s*/ObjStm\b#i', $pdf );
		$decode_failure_count  = isset( $stream_info['decode_failures'] ) ? (int) $stream_info['decode_failures'] : 0;
		$malformed_count       = isset( $stream_info['malformed_streams'] ) ? (int) $stream_info['malformed_streams'] : 0;

		if ( 0 !== strpos( ltrim( (string) $pdf ), '%PDF-' ) ) {
			$structure_reasons[]              = 'missing_pdf_header';
			$metadata['pdf_header_valid']     = false;
			$metadata['pdf_structure_status'] = 'suspect';
		}

		if ( false === strpos( (string) $pdf, '%%EOF' ) ) {
			$structure_reasons[]             = 'missing_eof_marker';
			$metadata['pdf_eof_marker_seen'] = false;
		}

		if ( 0 < $malformed_count ) {
			$structure_reasons[]                    = 'malformed_stream_marker';
			$metadata['pdf_malformed_stream_count'] = $malformed_count;
		}

		if ( 0 < $decode_failure_count ) {
			$structure_reasons[]                         = 'stream_decode_failure';
			$metadata['pdf_stream_decode_failure_count'] = $decode_failure_count;
		}

		if ( 0 < $object_stream_count ) {
			$structure_reasons[]                 = 'object_streams_present';
			$metadata['pdf_object_stream_count'] = $object_stream_count;
		}

		foreach ( $segments as $segment ) {
			$text_operator_count   += $this->count_pattern_matches( '/(?:\[[^\]]*\]\s*TJ|(?:\((?:\\\\.|[^\\\\\)])*\)|<[\da-fA-F\s]+>)\s*(?:Tj|\'|"))/s', $segment );
			$image_reference_count += $this->count_pattern_matches( '/\/[A-Za-z0-9_.-]+\s+Do\b/', $segment );
			$rectangle_count       += $this->count_pattern_matches( '/(?:^|\s)[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:\s+[+-]?(?:\d+(?:\.\d+)?|\.\d+)){3}\s+re\b/', $segment );
			$line_segment_count    += $this->count_pattern_matches( '/(?:^|\s)[+-]?(?:\d+(?:\.\d+)?|\.\d+)\s+[+-]?(?:\d+(?:\.\d+)?|\.\d+)\s+l\b/', $segment );
		}

		$image_reference_count += $this->count_pattern_matches( '/\/Subtype\s*\/Image\b/i', $pdf );

		if ( 0 < $text_operator_count ) {
			$metadata['pdf_text_operator_count'] = $text_operator_count;
		}

		if ( 0 < $image_reference_count ) {
			$metadata['pdf_embedded_media_detected'] = true;
			$metadata['pdf_image_reference_count']   = $image_reference_count;
			$metadata['pdf_embedded_media_hint']     = 'PDF contains embedded image references; first-pass PDF processing records text only and does not extract embedded PDF images as media attachments yet.';
		}

		if ( 3 <= $rectangle_count || 4 <= $line_segment_count ) {
			$metadata['pdf_vector_drawing_count'] = $rectangle_count + $line_segment_count;
			$metadata['pdf_layout_warning']       = 'PDF contains table/vector layout signals; first-pass PDF processing imports normalized text blocks and may not preserve table structure or columns. Configure UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND with a layout-aware extractor such as pdftotext -layout for better text order.';
		}

		if ( ! empty( $structure_reasons ) ) {
			$metadata['pdf_structure_status']  = isset( $metadata['pdf_structure_status'] ) ? $metadata['pdf_structure_status'] : 'limited';
			$metadata['pdf_structure_reasons'] = array_values( array_unique( $structure_reasons ) );
			$metadata['pdf_structure_warning'] = $this->pdf_structure_warning( $metadata );
		}

		return $metadata;
	}

	/**
	 * Records a warning event for notable PDF structure diagnostics.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return void
	 */
	private function record_pdf_structure_warning_event( ImportSession $session, ImportSourceItem $item, array $metadata ) {
		if ( empty( $metadata['pdf_structure_warning'] ) ) {
			return;
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'document.pdf_structure_warning',
				(string) $metadata['pdf_structure_warning'],
				array(
					'item_key' => $item->get_key(),
					'reasons'  => isset( $metadata['pdf_structure_reasons'] ) && is_array( $metadata['pdf_structure_reasons'] ) ? $metadata['pdf_structure_reasons'] : array(),
					'limits'   => array(
						'first_pass_pdf_bytes' => self::PDF_FILE_LIMIT,
						'text_bytes'           => self::PDF_TEXT_LIMIT,
					),
				)
			)
		);
	}

	/**
	 * Builds an operator-facing PDF structure warning.
	 *
	 * @param array<string,mixed> $metadata PDF diagnostics metadata.
	 * @return string
	 */
	private function pdf_structure_warning( array $metadata ) {
		$parts = array( 'PDF structure diagnostics indicate the first-pass importer may not have inspected every object.' );

		if ( ! empty( $metadata['pdf_object_stream_count'] ) ) {
			$parts[] = 'Compressed object streams were detected; nested objects inside them are not expanded by the built-in first-pass parser.';
		}

		if ( ! empty( $metadata['pdf_stream_decode_failure_count'] ) ) {
			$parts[] = 'One or more compressed streams could not be decoded and were skipped.';
		}

		if ( ! empty( $metadata['pdf_malformed_stream_count'] ) ) {
			$parts[] = 'At least one stream marker was missing its matching endstream marker.';
		}

		if ( isset( $metadata['pdf_header_valid'] ) && false === $metadata['pdf_header_valid'] ) {
			$parts[] = 'The file does not start with a normal %PDF header.';
		}

		if ( isset( $metadata['pdf_eof_marker_seen'] ) && false === $metadata['pdf_eof_marker_seen'] ) {
			$parts[] = 'The file is missing a %%EOF trailer marker.';
		}

		$parts[] = 'Use an external text/PDF extraction command for higher-fidelity recovery if this diagnostic appears on important content.';

		return implode( ' ', $parts );
	}

	/**
	 * Builds a stable content hash for a PDF document that only yielded media assets.
	 *
	 * @param ImportSourceItem                $item   PDF source item.
	 * @param array<int,array<string,string>> $assets Queued PDF asset summaries.
	 * @return string
	 */
	private function pdf_asset_only_content_hash( ImportSourceItem $item, array $assets ) {
		$source_hash = is_file( $item->get_source_uri() ) ? hash_file( 'sha256', $item->get_source_uri() ) : false;
		$parts       = array( 'pdf-assets', false === $source_hash ? $item->get_key() : $source_hash );

		foreach ( $assets as $asset ) {
			$parts[] = isset( $asset['original_url'] ) ? (string) $asset['original_url'] : '';
		}

		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Extracts supported embedded PDF image streams into the media pipeline.
	 *
	 * This intentionally handles only directly embedded JPEG/DCTDecode image
	 * streams. Other encodings remain visible through PDF fidelity diagnostics.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    PDF source item.
	 * @return array{queued:int,unsupported:int,unsupported_filters:array<string,int>,unsupported_reasons:array<string,int>,assets:array<int,array<string,string>>,complete:bool,next_offset:int,next_index:int,read_offset:int,read_bytes:int}
	 */
	private function queue_pdf_embedded_media_references( ImportSession $session, ImportSourceItem $item ) {
		$base_metadata = $item->get_metadata();
		$summary       = $this->pdf_media_scan_summary_from_metadata( $base_metadata );

		if ( ! is_file( $item->get_source_uri() ) || ! is_readable( $item->get_source_uri() ) ) {
			$summary['complete'] = true;
			return $summary;
		}

		$pdf = $this->read_pdf_scan_bytes( $item->get_source_uri(), $summary['next_offset'] );
		if ( false === $pdf || '' === $pdf ) {
			$summary['complete'] = true;
			return $summary;
		}

		$read_offset             = $summary['next_offset'];
		$scan                    = $this->extract_pdf_image_streams( $pdf, 0, self::PDF_MEDIA_SCAN_LIMIT, $summary['next_index'], $read_offset );
		$summary['complete']     = $scan['complete'];
		$summary['read_offset']  = $read_offset;
		$summary['read_bytes']   = strlen( $pdf );
		$summary['next_offset']  = $scan['next_offset'];
		$summary['next_index']   = $scan['next_index'];
		$progress_metadata_saved = false;

		foreach ( $scan['images'] as $image ) {
			if ( self::PDF_MEDIA_LIMIT <= $summary['queued'] ) {
				$this->count_unsupported_pdf_asset( $summary, 'extraction_limit', $image['filter'] );
			} elseif ( 'DCTDecode' !== $image['filter'] ) {
				$this->count_unsupported_pdf_asset( $summary, 'unsupported_filter', $image['filter'] );
			} elseif ( ! empty( $image['malformed_stream'] ) ) {
				$this->count_unsupported_pdf_asset( $summary, 'malformed_stream', $image['filter'] );
			} elseif ( 0 >= (int) $image['width'] || 0 >= (int) $image['height'] ) {
				$this->count_unsupported_pdf_asset( $summary, 'missing_dimensions', $image['filter'] );
			} elseif ( '' === (string) $image['stream'] ) {
				$this->count_unsupported_pdf_asset( $summary, 'empty_stream', $image['filter'] );
			} elseif ( self::PDF_MEDIA_FILE_LIMIT < strlen( $image['stream'] ) ) {
				$this->count_unsupported_pdf_asset( $summary, 'file_size_limit', $image['filter'] );
			} elseif ( ! $this->pdf_stream_has_jpeg_signature( $image['stream'] ) ) {
				$this->count_unsupported_pdf_asset( $summary, 'invalid_jpeg', $image['filter'] );
			} else {
				$asset = $this->queue_pdf_jpeg_media_reference( $session, $item, $image, $image['index'] );
				if ( null !== $asset ) {
					$summary['assets'][] = $asset;
					++$summary['queued'];
				}
			}

			$summary['next_offset'] = $image['next_offset'];
			$summary['next_index']  = (int) $image['index'] + 1;
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata(
					$this->pdf_media_scan_metadata( $base_metadata, $summary, false )
				)
			);
			$progress_metadata_saved = true;
		}

		if ( ! $progress_metadata_saved && empty( $summary['complete'] ) ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_replaced_metadata(
					$this->pdf_media_scan_metadata( $base_metadata, $summary, false )
				)
			);
		}

		return $summary;
	}

	/**
	 * Rebuilds a PDF media scan summary from source item metadata.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array{queued:int,unsupported:int,unsupported_filters:array<string,int>,unsupported_reasons:array<string,int>,assets:array<int,array<string,string>>,complete:bool,next_offset:int,next_index:int,read_offset:int,read_bytes:int}
	 */
	private function pdf_media_scan_summary_from_metadata( array $metadata ) {
		return array(
			'queued'              => isset( $metadata['pdf_embedded_media_queued'] ) ? max( 0, (int) $metadata['pdf_embedded_media_queued'] ) : 0,
			'unsupported'         => isset( $metadata['pdf_unsupported_embedded_media_count'] ) ? max( 0, (int) $metadata['pdf_unsupported_embedded_media_count'] ) : 0,
			'unsupported_filters' => isset( $metadata['pdf_unsupported_embedded_media_filter_counts'] ) && is_array( $metadata['pdf_unsupported_embedded_media_filter_counts'] ) ? $metadata['pdf_unsupported_embedded_media_filter_counts'] : array(),
			'unsupported_reasons' => isset( $metadata['pdf_unsupported_embedded_media_reason_counts'] ) && is_array( $metadata['pdf_unsupported_embedded_media_reason_counts'] ) ? $metadata['pdf_unsupported_embedded_media_reason_counts'] : array(),
			'assets'              => isset( $metadata['pdf_embedded_media_assets'] ) && is_array( $metadata['pdf_embedded_media_assets'] ) ? $metadata['pdf_embedded_media_assets'] : array(),
			'complete'            => false,
			'next_offset'         => isset( $metadata['pdf_media_next_offset'] ) ? max( 0, (int) $metadata['pdf_media_next_offset'] ) : 0,
			'next_index'          => isset( $metadata['pdf_media_next_index'] ) ? max( 0, (int) $metadata['pdf_media_next_index'] ) : 0,
			'read_offset'         => isset( $metadata['pdf_media_scan_read_offset'] ) ? max( 0, (int) $metadata['pdf_media_scan_read_offset'] ) : 0,
			'read_bytes'          => isset( $metadata['pdf_media_scan_read_bytes'] ) ? max( 0, (int) $metadata['pdf_media_scan_read_bytes'] ) : 0,
		);
	}

	/**
	 * Builds durable source item metadata for a PDF embedded-media scan.
	 *
	 * @param array<string,mixed> $metadata Existing source item metadata.
	 * @param array<string,mixed> $summary  PDF embedded-media scan summary.
	 * @param bool                $complete Whether the scan has reached the end of the PDF object stream.
	 * @return array<string,mixed>
	 */
	private function pdf_media_scan_metadata( array $metadata, array $summary, $complete ) {
		$metadata['processor_status']           = $complete ? 'imported' : 'partial';
		$metadata['document_format']            = 'pdf';
		$metadata['pdf_processing_phase']       = $complete ? 'document_prepare' : 'media_scan';
		$metadata['pdf_embedded_media_queued']  = (int) $summary['queued'];
		$metadata['pdf_embedded_media_assets']  = $summary['assets'];
		$metadata['pdf_media_scan_read_offset'] = isset( $summary['read_offset'] ) ? (int) $summary['read_offset'] : 0;
		$metadata['pdf_media_scan_read_bytes']  = isset( $summary['read_bytes'] ) ? (int) $summary['read_bytes'] : 0;

		$metadata['pdf_unsupported_embedded_media_count']         = (int) $summary['unsupported'];
		$metadata['pdf_unsupported_embedded_media_filter_counts'] = $summary['unsupported_filters'];
		$metadata['pdf_unsupported_embedded_media_reason_counts'] = $summary['unsupported_reasons'];
		$metadata['pdf_unsupported_embedded_media_filters']       = array_keys( $summary['unsupported_filters'] );
		$metadata['pdf_unsupported_embedded_media_reasons']       = array_keys( $summary['unsupported_reasons'] );

		if ( $complete ) {
			unset( $metadata['pdf_media_next_offset'], $metadata['pdf_media_next_index'] );
		} else {
			$metadata['pdf_media_next_offset'] = (int) $summary['next_offset'];
			$metadata['pdf_media_next_index']  = (int) $summary['next_index'];
		}

		if ( 0 === (int) $summary['unsupported'] ) {
			unset(
				$metadata['pdf_unsupported_embedded_media_count'],
				$metadata['pdf_unsupported_embedded_media_filter_counts'],
				$metadata['pdf_unsupported_embedded_media_reason_counts'],
				$metadata['pdf_unsupported_embedded_media_filters'],
				$metadata['pdf_unsupported_embedded_media_reasons']
			);
		}

		if ( empty( $summary['assets'] ) ) {
			unset( $metadata['pdf_embedded_media_assets'] );
		}

		return $metadata;
	}

	/**
	 * Builds durable source item metadata for a PDF native text scan.
	 *
	 * @param array<string,mixed>             $metadata          Existing source item metadata.
	 * @param array<string,mixed>             $pdf_asset_summary Embedded-media summary.
	 * @param array<string,int>               $diagnostics       Text scan diagnostics.
	 * @param bool                            $complete          Whether the text scan is complete.
	 * @param int                             $next_offset       Next PDF byte offset.
	 * @param int                             $stream_index      Next stream index.
	 * @param int                             $chunk_index       Next chunk index.
	 * @param int                             $chunks_prepared   Total chunks prepared.
	 * @param int                             $streams_scanned   Total streams scanned.
	 * @param array<string,array<int,string>> $url_domains        Absolute URL domains.
	 * @param array<string,mixed>             $structure_metadata Full-PDF structure diagnostics.
	 * @param int                             $read_offset        File byte offset used for this scan read.
	 * @return array<string,mixed>
	 */
	private function pdf_text_scan_metadata( array $metadata, array $pdf_asset_summary, array $diagnostics, $complete, $next_offset, $stream_index, $chunk_index, $chunks_prepared, $streams_scanned, array $url_domains, array $structure_metadata = array(), $read_offset = 0 ) {
		$metadata = $this->pdf_media_scan_metadata( $metadata, $pdf_asset_summary, true );

		$metadata['processor_status']          = $complete ? 'imported' : 'partial';
		$metadata['document_format']           = 'pdf';
		$metadata['pdf_processing_phase']      = $complete ? 'document_prepare' : 'text_scan';
		$metadata['pdf_text_engine']           = 'native';
		$metadata['pdf_text_chunks_prepared']  = (int) $chunks_prepared;
		$metadata['pdf_text_streams_scanned']  = (int) $streams_scanned;
		$metadata['pdf_stream_count']          = isset( $diagnostics['matched_streams'] ) ? (int) $diagnostics['matched_streams'] : (int) $streams_scanned;
		$metadata['pdf_text_operator_count']   = isset( $diagnostics['text_operators'] ) ? (int) $diagnostics['text_operators'] : 0;
		$metadata['absolute_url_domains']      = array_keys( $url_domains );
		$metadata['absolute_url_examples']     = $url_domains;
		$metadata['pdf_text_scan_diagnostics'] = $diagnostics;
		$metadata['pdf_text_scan_read_offset'] = max( 0, (int) $read_offset );

		if ( isset( $diagnostics['decode_failures'] ) && 0 < (int) $diagnostics['decode_failures'] ) {
			$metadata['pdf_stream_decode_failure_count'] = (int) $diagnostics['decode_failures'];
			$metadata['pdf_structure_status']            = 'limited';
			$metadata['pdf_structure_reasons']           = array( 'stream_decode_failure' );
			$metadata['pdf_structure_warning']           = $this->pdf_structure_warning( $metadata );
		}
		$metadata = $this->merge_pdf_structure_metadata( $metadata, $structure_metadata );

		if ( $complete ) {
			unset(
				$metadata['pdf_text_next_offset'],
				$metadata['pdf_text_stream_index'],
				$metadata['pdf_text_chunk_index']
			);
		} else {
			$metadata['pdf_text_next_offset']  = (int) $next_offset;
			$metadata['pdf_text_stream_index'] = (int) $stream_index;
			$metadata['pdf_text_chunk_index']  = (int) $chunk_index;
		}

		return $metadata;
	}

	/**
	 * Appends a native PDF text fragment without exceeding the first-pass text limit.
	 *
	 * @param array<int,string> $fragments Existing fragments.
	 * @param string            $text      New fragment text.
	 * @return array<int,string>
	 */
	private function append_pdf_text_fragment( array $fragments, $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return $fragments;
		}

		$current = strlen( implode( "\n\n", $fragments ) );
		$space   = self::PDF_TEXT_LIMIT - $current - ( empty( $fragments ) ? 0 : 2 );

		if ( 0 >= $space ) {
			return $fragments;
		}

		if ( strlen( $text ) > $space ) {
			$text = substr( $text, 0, $space );
		}

		if ( '' !== trim( $text ) ) {
			$fragments[] = $text;
		}

		return $fragments;
	}

	/**
	 * Saves one prepared document for an incrementally scanned PDF.
	 *
	 * @param ImportSession       $session           Session.
	 * @param ImportSourceItem    $item              PDF source item.
	 * @param array<int,string>   $fragments         Accumulated text fragments.
	 * @param array<string,mixed> $pdf_asset_summary Embedded media summary.
	 * @param array<string,mixed> $metadata          Source item metadata.
	 * @return array<string,mixed> Updated source item metadata.
	 */
	private function save_pdf_text_scan_document( ImportSession $session, ImportSourceItem $item, array $fragments, array $pdf_asset_summary, array $metadata ) {
		$content = $this->normalize_extracted_pdf_text( implode( "\n\n", $fragments ) );

		if ( '' === $content && empty( $pdf_asset_summary['assets'] ) ) {
			return $metadata;
		}

		$table_summary = array(
			'tables'      => 0,
			'rows'        => 0,
			'max_columns' => 0,
		);
		$block_markup  = '' === $content ? '' : $this->pdf_text_to_blocks( $content, $table_summary );

		if ( ! empty( $pdf_asset_summary['assets'] ) ) {
			$block_markup = trim( $block_markup . "\n\n" . $this->pdf_embedded_media_blocks( $pdf_asset_summary['assets'] ) );
		}

		if ( '' === trim( $block_markup ) ) {
			return $metadata;
		}

		if ( 0 < $table_summary['tables'] ) {
			$metadata['pdf_table_block_count']      = $table_summary['tables'];
			$metadata['pdf_table_row_count']        = $table_summary['rows'];
			$metadata['pdf_table_max_column_count'] = $table_summary['max_columns'];
			$metadata['pdf_layout_warning']         = 'PDF tabular text runs were converted to WordPress table blocks where detected. Complex columns, merged cells, or vector-only tables may still need operator review.';
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'document.pdf_table_blocks',
					'PDF tabular text was converted into WordPress table blocks.',
					array(
						'item_key'    => $item->get_key(),
						'table_count' => $table_summary['tables'],
						'row_count'   => $table_summary['rows'],
					)
				)
			);
		}

		$content_hash = hash( 'sha256', "pdf-native-text\n" . $item->get_key() . "\n" . $content . "\n" . $block_markup );
		$document     = new ImportPreparedDocument(
			$session->get_id(),
			$item->get_key(),
			'pdf',
			$this->title_for_text_chunk( $item, $content, 0, true ),
			$block_markup,
			$this->count_blocks( $block_markup ),
			$content_hash,
			array_merge(
				array(
					'relative_path'       => $item->get_relative_path(),
					'source_uri'          => $item->get_source_uri(),
					'pdf_source_item_key' => $item->get_key(),
				),
				$metadata
			)
		);

		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'document-blocks:' . $item->get_key(), 'prepared_document', $document->get_source_item_key(), $content_hash )
		);

		$metadata['content_hash'] = $content_hash;
		$metadata['title']        = $document->get_title();
		$metadata['block_count']  = $document->get_block_count();

		return $metadata;
	}

	/**
	 * Builds durable source item metadata for a PDF structure scan.
	 *
	 * @param array<string,mixed> $metadata     Existing source item metadata.
	 * @param array<string,mixed> $diagnostics  Accumulated structure diagnostics.
	 * @param bool                $complete     Whether structure scanning is complete.
	 * @param int                 $next_offset  Next PDF byte offset.
	 * @param int                 $stream_index Next stream index.
	 * @param int                 $read_offset  File byte offset used for this scan read.
	 * @param int                 $read_bytes   Bytes read for this scan.
	 * @return array<string,mixed>
	 */
	private function pdf_structure_scan_metadata( array $metadata, array $diagnostics, $complete, $next_offset, $stream_index, $read_offset, $read_bytes ) {
		$metadata['processor_status']               = 'partial';
		$metadata['document_format']                = 'pdf';
		$metadata['pdf_processing_phase']           = $complete ? 'document_prepare' : 'pdf_structure_scan';
		$metadata['pdf_structure_scan_read_offset'] = max( 0, (int) $read_offset );
		$metadata['pdf_structure_scan_read_bytes']  = max( 0, (int) $read_bytes );
		$metadata['pdf_structure_streams_scanned']  = isset( $diagnostics['matched_streams'] ) ? max( 0, (int) $diagnostics['matched_streams'] ) : max( 0, (int) $stream_index );
		$metadata['pdf_structure_scan_diagnostics'] = $diagnostics;
		$metadata['pdf_structure_scan_complete']    = (bool) $complete;
		$metadata                                   = array_merge( $metadata, $this->pdf_structure_metadata_from_scan_diagnostics( $diagnostics ) );

		if ( $complete ) {
			unset(
				$metadata['pdf_structure_next_offset'],
				$metadata['pdf_structure_stream_index']
			);
		} else {
			$metadata['pdf_structure_next_offset']  = (int) $next_offset;
			$metadata['pdf_structure_stream_index'] = (int) $stream_index;
		}

		return $metadata;
	}

	/**
	 * Converts accumulated structure scan counters to public PDF diagnostics.
	 *
	 * @param array<string,mixed> $diagnostics Accumulated structure scan diagnostics.
	 * @return array<string,mixed>
	 */
	private function pdf_structure_metadata_from_scan_diagnostics( array $diagnostics ) {
		$metadata = array(
			'pdf_stream_count' => isset( $diagnostics['matched_streams'] ) ? max( 0, (int) $diagnostics['matched_streams'] ) : 0,
		);
		$reasons  = array();

		if ( ! empty( $diagnostics['missing_pdf_header'] ) ) {
			$reasons[]                        = 'missing_pdf_header';
			$metadata['pdf_header_valid']     = false;
			$metadata['pdf_structure_status'] = 'suspect';
		}

		if ( ! empty( $diagnostics['missing_eof_marker'] ) ) {
			$reasons[]                       = 'missing_eof_marker';
			$metadata['pdf_eof_marker_seen'] = false;
		}

		if ( ! empty( $diagnostics['malformed_streams'] ) ) {
			$reasons[]                              = 'malformed_stream_marker';
			$metadata['pdf_malformed_stream_count'] = (int) $diagnostics['malformed_streams'];
		}

		if ( ! empty( $diagnostics['decode_failures'] ) ) {
			$reasons[]                                   = 'stream_decode_failure';
			$metadata['pdf_stream_decode_failure_count'] = (int) $diagnostics['decode_failures'];
		}

		if ( ! empty( $diagnostics['object_streams'] ) ) {
			$reasons[]                           = 'object_streams_present';
			$metadata['pdf_object_stream_count'] = (int) $diagnostics['object_streams'];
		}

		if ( ! empty( $diagnostics['text_operators'] ) ) {
			$metadata['pdf_text_operator_count'] = (int) $diagnostics['text_operators'];
		}

		if ( ! empty( $diagnostics['image_references'] ) ) {
			$metadata['pdf_embedded_media_detected'] = true;
			$metadata['pdf_image_reference_count']   = (int) $diagnostics['image_references'];
			$metadata['pdf_embedded_media_hint']     = 'PDF contains embedded image references; first-pass PDF processing records text only and does not extract embedded PDF images as media attachments yet.';
		}

		$vector_count = ( isset( $diagnostics['rectangle_operators'] ) ? (int) $diagnostics['rectangle_operators'] : 0 ) + ( isset( $diagnostics['line_segment_operators'] ) ? (int) $diagnostics['line_segment_operators'] : 0 );
		if ( 0 < $vector_count && ( 3 <= (int) $diagnostics['rectangle_operators'] || 4 <= (int) $diagnostics['line_segment_operators'] ) ) {
			$metadata['pdf_vector_drawing_count'] = $vector_count;
			$metadata['pdf_layout_warning']       = 'PDF contains table/vector layout signals; first-pass PDF processing imports normalized text blocks and may not preserve table structure or columns. Configure UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND with a layout-aware extractor such as pdftotext -layout for better text order.';
		}

		if ( ! empty( $reasons ) ) {
			$metadata['pdf_structure_status']  = isset( $metadata['pdf_structure_status'] ) ? $metadata['pdf_structure_status'] : 'limited';
			$metadata['pdf_structure_reasons'] = array_values( array_unique( $reasons ) );
			$metadata['pdf_structure_warning'] = $this->pdf_structure_warning( $metadata );
		}

		return $metadata;
	}

	/**
	 * Merges per-batch PDF structure scan diagnostics.
	 *
	 * @param array<string,mixed> $existing Existing diagnostics.
	 * @param array<string,mixed> $next     Next diagnostics.
	 * @return array<string,mixed>
	 */
	private function merge_pdf_structure_scan_diagnostics( array $existing, array $next ) {
		foreach ( array( 'matched_streams', 'decode_failures', 'malformed_streams', 'text_operators', 'image_references', 'rectangle_operators', 'line_segment_operators', 'object_streams' ) as $key ) {
			$existing[ $key ] = ( isset( $existing[ $key ] ) ? (int) $existing[ $key ] : 0 ) + ( isset( $next[ $key ] ) ? (int) $next[ $key ] : 0 );
		}

		foreach ( array( 'missing_pdf_header', 'missing_eof_marker' ) as $key ) {
			if ( ! empty( $next[ $key ] ) ) {
				$existing[ $key ] = true;
			}
		}

		return $existing;
	}

	/**
	 * Preserves full-PDF structure diagnostics on incremental PDF text metadata.
	 *
	 * @param array<string,mixed> $metadata           Existing metadata.
	 * @param array<string,mixed> $structure_metadata Structure diagnostics.
	 * @return array<string,mixed>
	 */
	private function merge_pdf_structure_metadata( array $metadata, array $structure_metadata ) {
		$keys = array(
			'pdf_header_valid',
			'pdf_eof_marker_seen',
			'pdf_stream_count',
			'pdf_text_operator_count',
			'pdf_embedded_media_detected',
			'pdf_image_reference_count',
			'pdf_embedded_media_hint',
			'pdf_vector_drawing_count',
			'pdf_layout_warning',
			'pdf_malformed_stream_count',
			'pdf_stream_decode_failure_count',
			'pdf_object_stream_count',
			'pdf_structure_status',
			'pdf_structure_reasons',
			'pdf_structure_warning',
		);

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $structure_metadata ) ) {
				$metadata[ $key ] = $structure_metadata[ $key ];
			}
		}

		return $metadata;
	}

	/**
	 * Extracts previously persisted PDF structure diagnostics.
	 *
	 * @param array<string,mixed> $metadata Existing source item metadata.
	 * @return array<string,mixed>
	 */
	private function pdf_structure_metadata_from_existing_metadata( array $metadata ) {
		return $this->merge_pdf_structure_metadata( array(), $metadata );
	}

	/**
	 * Saves a prepared document containing PDF embedded-media placeholder blocks.
	 *
	 * @param ImportSession                   $session Session.
	 * @param ImportSourceItem                $item    PDF source item.
	 * @param array<int,array<string,string>> $assets Embedded-media assets.
	 * @param int                             $chunk_index Chunk index.
	 * @return int Number of prepared documents saved.
	 */
	private function save_pdf_embedded_media_prepared_document( ImportSession $session, ImportSourceItem $item, array $assets, $chunk_index ) {
		if ( empty( $assets ) ) {
			return 0;
		}

		$document_key = $item->get_key() . ':pdf-media-assets';
		$block_markup = $this->pdf_embedded_media_blocks( $assets );

		if ( '' === trim( $block_markup ) ) {
			return 0;
		}

		$content_hash = hash( 'sha256', "pdf-media-assets\n" . $document_key . "\n" . $block_markup );

		$this->store->save_prepared_document(
			new ImportPreparedDocument(
				$session->get_id(),
				$document_key,
				'pdf',
				$this->title_for_text_chunk( $item, 'PDF embedded media', $chunk_index, false ),
				$block_markup,
				$this->count_blocks( $block_markup ),
				$content_hash,
				array(
					'relative_path'       => $item->get_relative_path(),
					'source_uri'          => $item->get_source_uri(),
					'pdf_source_item_key' => $item->get_key(),
					'pdf_media_document'  => true,
					'pdf_text_engine'     => 'native',
				)
			)
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'document-blocks:' . $document_key, 'prepared_document', $document_key, $content_hash )
		);

		return 1;
	}

	/**
	 * Records an unsupported embedded PDF media stream in the extraction summary.
	 *
	 * @param array<string,mixed> $summary Extraction summary.
	 * @param string              $reason  Stable skip reason.
	 * @param string              $filter  PDF image filter.
	 * @return void
	 */
	private function count_unsupported_pdf_asset( array &$summary, $reason, $filter ) {
		$reason = '' === (string) $reason ? 'unknown' : (string) $reason;
		$filter = '' === (string) $filter ? 'unknown' : (string) $filter;

		++$summary['unsupported'];

		if ( ! isset( $summary['unsupported_reasons'][ $reason ] ) ) {
			$summary['unsupported_reasons'][ $reason ] = 0;
		}
		++$summary['unsupported_reasons'][ $reason ];

		if ( ! isset( $summary['unsupported_filters'][ $filter ] ) ) {
			$summary['unsupported_filters'][ $filter ] = 0;
		}
		++$summary['unsupported_filters'][ $filter ];
	}

	/**
	 * Builds an operator-facing hint for embedded PDF media streams not extracted.
	 *
	 * @param array<string,mixed> $summary Extraction summary.
	 * @return string
	 */
	private function pdf_unsupported_media_hint( array $summary ) {
		$filters = ! empty( $summary['unsupported_filters'] ) && is_array( $summary['unsupported_filters'] )
			? array_keys( $summary['unsupported_filters'] )
			: array();
		$reasons = ! empty( $summary['unsupported_reasons'] ) && is_array( $summary['unsupported_reasons'] )
			? array_keys( $summary['unsupported_reasons'] )
			: array();
		$hint    = 'PDF contains embedded media streams that the first-pass importer did not extract.';

		if ( ! empty( $filters ) ) {
			$hint .= ' Unsupported filters: ' . implode( ', ', $filters ) . '.';
		}

		if ( in_array( 'file_size_limit', $reasons, true ) ) {
			$hint .= ' At least one embedded media stream exceeded the bounded extraction size limit of ' . $this->format_bytes_for_diagnostic( self::PDF_MEDIA_FILE_LIMIT ) . '.';
		}

		if ( in_array( 'extraction_limit', $reasons, true ) ) {
			$hint .= ' The per-PDF embedded media extraction limit of ' . self::PDF_MEDIA_LIMIT . ' assets was reached.';
		}

		if ( in_array( 'missing_dimensions', $reasons, true ) ) {
			$hint .= ' At least one embedded JPEG image stream used missing or indirect dimensions that the bounded parser could not resolve.';
		}

		if ( in_array( 'empty_stream', $reasons, true ) ) {
			$hint .= ' At least one embedded JPEG image stream was empty.';
		}

		if ( in_array( 'malformed_stream', $reasons, true ) ) {
			$hint .= ' At least one embedded JPEG image stream was malformed and missing its stream terminator.';
		}

		if ( in_array( 'invalid_jpeg', $reasons, true ) ) {
			$hint .= ' At least one DCTDecode image stream did not contain a recognizable JPEG payload.';
		}

		if ( in_array( 'unsupported_filter', $reasons, true ) ) {
			$hint .= ' Use a richer PDF media extractor or review the source PDF manually for these assets.';
		}

		return $hint;
	}

	/**
	 * Formats a byte limit for persisted operational diagnostics.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private function format_bytes_for_diagnostic( $bytes ) {
		$bytes = max( 0, (int) $bytes );

		if ( 0 === $bytes % 1048576 ) {
			return (string) ( $bytes / 1048576 ) . ' MiB';
		}

		if ( 0 === $bytes % 1024 ) {
			return (string) ( $bytes / 1024 ) . ' KiB';
		}

		return (string) $bytes . ' bytes';
	}

	/**
	 * Extracts image streams from simple PDF image XObject objects.
	 *
	 * @param string   $pdf          Raw PDF bytes.
	 * @param int      $start_offset Byte offset inside the provided PDF bytes.
	 * @param int|null $limit        Maximum image entries to return.
	 * @param int      $start_index  Stable image index to assign to the first image returned.
	 * @param int      $base_offset  Absolute byte offset represented by byte 0 of $pdf.
	 * @return array{images:array<int,array{object:string,filter:string,stream:string,width:int,height:int,malformed_stream:bool,index:int,next_offset:int}>,complete:bool,next_offset:int,next_index:int}
	 */
	private function extract_pdf_image_streams( $pdf, $start_offset = 0, $limit = null, $start_index = 0, $base_offset = 0 ) {
		$images      = array();
		$pdf         = (string) $pdf;
		$offset      = max( 0, (int) $start_offset );
		$image_index = max( 0, (int) $start_index );
		$limit       = null === $limit ? null : max( 1, (int) $limit );
		$base_offset = max( 0, (int) $base_offset );
		$complete    = true;

		while ( preg_match( '/(\d+)\s+\d+\s+obj\s*<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			if ( null !== $limit && count( $images ) >= $limit ) {
				$complete = false;
				break;
			}

			$object       = (string) $match[1][0];
			$dictionary   = (string) $match[2][0];
			$stream_start = $match[0][1] + strlen( $match[0][0] );
			$stream_end   = $this->pdf_stream_end_from_declared_length( $pdf, $dictionary, $stream_start );
			$uses_length  = null !== $stream_end;
			$is_image     = false !== stripos( $dictionary, '/Subtype' ) && preg_match( '#/Subtype\s*/Image\b#i', $dictionary );

			if ( null === $stream_end ) {
				$stream_end = strpos( $pdf, 'endstream', $stream_start );
			}

			if ( false === $stream_end ) {
				if ( $is_image ) {
					$images[] = $this->pdf_image_stream_entry( $object, $dictionary, '', true, $image_index, $base_offset + strlen( $pdf ) );
					++$image_index;
				}
				$offset = strlen( $pdf );
				break;
			}

			if ( ! $uses_length && preg_match( '/\n\s*\d+\s+\d+\s+obj\s*<</', $pdf, $next_object, PREG_OFFSET_CAPTURE, $stream_start ) && $next_object[0][1] < $stream_end ) {
				$offset = $next_object[0][1] + 1;
				if ( $is_image ) {
					$images[] = $this->pdf_image_stream_entry( $object, $dictionary, '', true, $image_index, $base_offset + $offset );
					++$image_index;
				}

				continue;
			}

			$offset = $stream_end + strlen( 'endstream' );

			if ( ! $is_image ) {
				continue;
			}

			$images[] = $this->pdf_image_stream_entry(
				$object,
				$dictionary,
				$this->trim_pdf_stream_delimiters( substr( $pdf, $stream_start, $stream_end - $stream_start ) ),
				false,
				$image_index,
				$base_offset + $offset
			);
			++$image_index;
		}

		return array(
			'images'      => $images,
			'complete'    => $complete,
			'next_offset' => $base_offset + $offset,
			'next_index'  => $image_index,
		);
	}

	/**
	 * Builds a normalized extracted PDF image stream entry.
	 *
	 * @param string $object_number    PDF object number.
	 * @param string $dictionary       PDF object dictionary.
	 * @param string $stream           Extracted image stream bytes.
	 * @param bool   $malformed_stream Whether the stream terminator was missing.
	 * @param int    $index            Stable image index in the PDF scan.
	 * @param int    $next_offset      Next byte offset after this image object.
	 * @return array{object:string,filter:string,stream:string,width:int,height:int,malformed_stream:bool,index:int,next_offset:int}
	 */
	private function pdf_image_stream_entry( $object_number, $dictionary, $stream, $malformed_stream, $index, $next_offset ) {
		return array(
			'object'           => (string) $object_number,
			'filter'           => $this->pdf_image_filter( $dictionary ),
			'stream'           => (string) $stream,
			'width'            => $this->pdf_dictionary_int( $dictionary, 'Width' ),
			'height'           => $this->pdf_dictionary_int( $dictionary, 'Height' ),
			'malformed_stream' => (bool) $malformed_stream,
			'index'            => max( 0, (int) $index ),
			'next_offset'      => max( 0, (int) $next_offset ),
		);
	}

	/**
	 * Determines the primary image stream filter.
	 *
	 * @param string $dictionary PDF object dictionary.
	 * @return string
	 */
	private function pdf_image_filter( $dictionary ) {
		if ( preg_match( '#/Filter\s*(\[[^\]]+\]|/[A-Za-z0-9]+)#i', (string) $dictionary, $matches ) ) {
			if ( '[' === substr( trim( $matches[1] ), 0, 1 ) && preg_match_all( '#/([A-Za-z0-9]+)#', $matches[1], $filter_matches ) ) {
				$filters = array_map( array( $this, 'normalize_pdf_image_filter' ), $filter_matches[1] );
				$filters = array_values( array_unique( $filters ) );

				return 1 === count( $filters ) ? $filters[0] : implode( '+', $filters );
			}

			return $this->normalize_pdf_image_filter( ltrim( trim( $matches[1] ), '/' ) );
		}

		if ( preg_match( '#/(?:DCTDecode|DCT)\b#i', (string) $dictionary ) ) {
			return 'DCTDecode';
		}

		if ( preg_match( '#/(?:JPXDecode|JPX)\b#i', (string) $dictionary ) ) {
			return 'JPXDecode';
		}

		if ( preg_match( '#/(?:FlateDecode|Fl)\b#i', (string) $dictionary ) ) {
			return 'FlateDecode';
		}

		return 'unknown';
	}

	/**
	 * Expands common PDF filter abbreviations used in image stream dictionaries.
	 *
	 * @param string $filter PDF image filter name.
	 * @return string
	 */
	private function normalize_pdf_image_filter( $filter ) {
		$filter = (string) $filter;

		if ( 0 === strcasecmp( $filter, 'DCT' ) ) {
			return 'DCTDecode';
		}

		if ( 0 === strcasecmp( $filter, 'JPX' ) ) {
			return 'JPXDecode';
		}

		if ( 0 === strcasecmp( $filter, 'Fl' ) ) {
			return 'FlateDecode';
		}

		if ( 0 === strcasecmp( $filter, 'A85' ) ) {
			return 'ASCII85Decode';
		}

		if ( 0 === strcasecmp( $filter, 'AHx' ) ) {
			return 'ASCIIHexDecode';
		}

		if ( 0 === strcasecmp( $filter, 'CCF' ) ) {
			return 'CCITTFaxDecode';
		}

		if ( 0 === strcasecmp( $filter, 'RL' ) ) {
			return 'RunLengthDecode';
		}

		return $filter;
	}

	/**
	 * Reads an integer value from a PDF object dictionary.
	 *
	 * @param string $dictionary PDF object dictionary.
	 * @param string $name       Dictionary key.
	 * @return int
	 */
	private function pdf_dictionary_int( $dictionary, $name ) {
		if ( preg_match( '#/' . preg_quote( (string) $name, '#' ) . '\s+(\d+)(?!\s+\d+\s+R\b)#', (string) $dictionary, $matches ) ) {
			return max( 0, (int) $matches[1] );
		}

		return 0;
	}

	/**
	 * Finds a stream end offset from a direct /Length value when the terminator follows it.
	 *
	 * @param string $pdf          PDF bytes.
	 * @param string $dictionary   PDF object dictionary.
	 * @param int    $stream_start Byte offset where stream payload starts.
	 * @return int|null
	 */
	private function pdf_stream_end_from_declared_length( $pdf, $dictionary, $stream_start ) {
		$length = $this->pdf_dictionary_int( $dictionary, 'Length' );

		if ( $length < 1 ) {
			return null;
		}

		$pdf        = (string) $pdf;
		$stream_end = (int) $stream_start + $length;

		if ( $stream_end > strlen( $pdf ) ) {
			return null;
		}

		$terminator_offset = $stream_end;

		if ( "\r\n" === substr( $pdf, $terminator_offset, 2 ) ) {
			$terminator_offset += 2;
		} elseif ( "\n" === substr( $pdf, $terminator_offset, 1 ) || "\r" === substr( $pdf, $terminator_offset, 1 ) ) {
			++$terminator_offset;
		}

		return 0 === strncmp( substr( $pdf, $terminator_offset, strlen( 'endstream' ) ), 'endstream', strlen( 'endstream' ) ) ? $stream_end : null;
	}

	/**
	 * Checks whether an extracted PDF DCTDecode stream starts with a JPEG SOI marker.
	 *
	 * @param string $stream Extracted image stream bytes.
	 * @return bool
	 */
	private function pdf_stream_has_jpeg_signature( $stream ) {
		return "\xff\xd8" === substr( (string) $stream, 0, 2 );
	}

	/**
	 * Removes stream delimiter line endings without touching the binary payload.
	 *
	 * @param string $stream Raw stream capture.
	 * @return string
	 */
	private function trim_pdf_stream_delimiters( $stream ) {
		$stream = (string) $stream;

		if ( 0 === strpos( $stream, "\r\n" ) ) {
			$stream = substr( $stream, 2 );
		} elseif ( 0 === strpos( $stream, "\n" ) || 0 === strpos( $stream, "\r" ) ) {
			$stream = substr( $stream, 1 );
		}

		if ( "\r\n" === substr( $stream, -2 ) ) {
			$stream = substr( $stream, 0, -2 );
		} elseif ( "\n" === substr( $stream, -1 ) || "\r" === substr( $stream, -1 ) ) {
			$stream = substr( $stream, 0, -1 );
		}

		return $stream;
	}

	/**
	 * Queues one extracted PDF JPEG image through the shared media pipeline.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $item    PDF source item.
	 * @param array<string,mixed> $image   Extracted image data.
	 * @param int                 $index   Image index.
	 * @return array<string,string>|null Queued asset summary.
	 * @throws RuntimeException When cache extraction fails.
	 */
	private function queue_pdf_jpeg_media_reference( ImportSession $session, ImportSourceItem $item, array $image, $index ) {
		$stream = isset( $image['stream'] ) ? (string) $image['stream'] : '';

		if ( '' === $stream ) {
			return null;
		}

		$hash      = hash( 'sha256', $stream );
		$filename  = 'embedded-image-' . ( (int) $index + 1 ) . '-' . substr( $hash, 0, 12 ) . '.jpg';
		$cache_key = substr( hash( 'sha256', $item->get_key() ), 0, 16 );
		$path      = $this->cache_directory->path_for( $session->get_id(), 'pdf', array( $cache_key, $filename ) );

		if ( ! is_file( $path ) ) {
			$this->cache_directory->ensure_parent_directory( $path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The importer writes bounded extracted PDF media into its managed cache.
			if ( false === file_put_contents( $path, $stream ) ) {
				throw new RuntimeException( 'Unable to write embedded PDF image into importer cache.' );
			}
		}

		$original_url = 'uwi-pdf-asset://' . $cache_key . '/' . $filename;
		$reference    = ImportMediaReference::queued(
			$session->get_id(),
			'pdf-media:' . hash( 'sha256', $item->get_key() . "\n" . (string) $image['object'] . "\n" . $hash ),
			$item->get_key(),
			$original_url,
			$path,
			ImportMediaReference::TYPE_IMAGE,
			array_merge(
				array(
					'reference_scope'       => 'pdf-embedded-asset',
					'document_title'        => $item->get_relative_path(),
					'extension'             => 'jpg',
					'pdf_source_item_key'   => $item->get_key(),
					'pdf_image_object'      => (string) $image['object'],
					'pdf_image_filter'      => (string) $image['filter'],
					'pdf_image_index'       => (int) $image['index'],
					'pdf_image_width'       => (int) $image['width'],
					'pdf_image_height'      => (int) $image['height'],
					'pdf_image_next_offset' => (int) $image['next_offset'],
					'pdf_image_source_hash' => $hash,
					'source'                => 'pdf',
				),
				$this->cache_directory->metadata_for( 'pdf', $path )
			)
		);

		$existing = $this->store->find_media_reference( $session->get_id(), $reference->get_key() );
		$this->store->save_media_reference( $reference );

		if ( null === $existing ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'media.pdf_asset_queued',
					'Embedded PDF JPEG image was extracted and queued for attachment import.',
					array(
						'item_key'      => $item->get_key(),
						'reference_key' => $reference->get_key(),
						'media_type'    => $reference->get_media_type(),
						'object'        => (string) $image['object'],
					)
				)
			);
		}

		return array(
			'original_url' => $original_url,
			'alt'          => 'Embedded PDF image ' . ( (int) $index + 1 ),
		);
	}

	/**
	 * Builds placeholder image blocks for extracted PDF media.
	 *
	 * @param array<int,array<string,string>> $assets Queued asset summaries.
	 * @return string
	 */
	private function pdf_embedded_media_blocks( array $assets ) {
		$blocks = array();

		foreach ( $assets as $asset ) {
			$url = isset( $asset['original_url'] ) ? (string) $asset['original_url'] : '';
			$alt = isset( $asset['alt'] ) ? (string) $asset['alt'] : '';

			if ( '' === $url ) {
				continue;
			}

			$blocks[] = '<!-- wp:image -->' . "\n"
				. '<figure class="wp-block-image"><img src="' . $this->escape_html( $url ) . '" alt="' . $this->escape_html( $alt ) . '"/></figure>' . "\n"
				. '<!-- /wp:image -->';
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Counts regular expression matches without leaking preg_match_all false values.
	 *
	 * @param string $pattern Regular expression.
	 * @param string $subject Subject string.
	 * @return int
	 */
	private function count_pattern_matches( $pattern, $subject ) {
		$count = preg_match_all( $pattern, (string) $subject );

		return false === $count ? 0 : (int) $count;
	}

	/**
	 * Attempts operator-configured external text extraction for PDFs.
	 *
	 * The command template must include `{input}` and may include `{output}` and
	 * `{scratch}`. When `{output}` is present, text is read from that sidecar
	 * file; otherwise stdout is used.
	 *
	 * @param string $path Source PDF path.
	 * @return array{content:string,message:string,metadata:array<string,mixed>}
	 */
	private function extract_pdf_text_with_external_command( $path ) {
		$command       = $this->pdf_external_text_command();
		$timeout       = $this->pdf_external_text_timeout();
		$base_metadata = array(
			'pdf_external_text_timeout' => $timeout,
		);

		if ( '' === $command ) {
			return array(
				'content'  => '',
				'message'  => 'No external PDF text extractor is configured.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_external_text_status' => 'not_configured',
						'pdf_external_text_hint'   => 'Set UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND to a command template containing {input}; for example, pdftotext -layout {input} {output}.',
					)
				),
			);
		}

		if ( false === strpos( $command, '{input}' ) ) {
			return array(
				'content'  => '',
				'message'  => 'External PDF text command is misconfigured; the template must include an {input} placeholder.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_external_text_status' => 'misconfigured',
						'pdf_external_text_error'  => 'External PDF text command is misconfigured; the template must include an {input} placeholder.',
						'pdf_external_text_hint'   => 'Set UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND to a command template containing {input}; for example, pdftotext -layout {input} {output}.',
					)
				),
			);
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return array(
				'content'  => '',
				'message'  => 'External PDF text command cannot run because proc_open() is unavailable.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_external_text_status' => 'unavailable',
						'pdf_external_text_error'  => 'External PDF text command cannot run because proc_open() is unavailable.',
					)
				),
			);
		}

		$output_path = tempnam( sys_get_temp_dir(), 'universal-importer-pdf-text-' );
		$scratch_pdf = tempnam( sys_get_temp_dir(), 'universal-importer-pdf-scratch-' );

		if ( false === $output_path || false === $scratch_pdf ) {
			$this->remove_temp_file( $output_path );
			$this->remove_temp_file( $scratch_pdf );
			return array(
				'content'  => '',
				'message'  => 'External PDF text command cannot run because temporary files could not be created.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_external_text_status' => 'unavailable',
						'pdf_external_text_error'  => 'External PDF text command cannot run because temporary files could not be created.',
					)
				),
			);
		}

		try {
			$result = $this->run_pdf_text_command( $command, $path, $output_path, $scratch_pdf, $timeout );
			$text   = false !== strpos( $command, '{output}' ) && is_file( $output_path ) ? $this->read_pdf_text_output_file( $output_path ) : $result['stdout'];

			if ( false === $text ) {
				return array(
					'content'  => '',
					'message'  => 'External PDF text command completed but its text output could not be read.',
					'metadata' => array_merge(
						$base_metadata,
						array(
							'pdf_external_text_status' => 'output_unreadable',
							'pdf_external_text_error'  => 'External PDF text command completed but its text output could not be read.',
						)
					),
				);
			}

			$text = $this->normalize_extracted_pdf_text( $text, true );

			if ( '' === $text ) {
				return array(
					'content'  => '',
					'message'  => 'External PDF text command completed but produced no importable text.',
					'metadata' => array_merge(
						$base_metadata,
						array(
							'pdf_external_text_status' => 'empty',
							'pdf_external_text_error'  => 'External PDF text command completed but produced no importable text.',
							'pdf_external_text_stderr' => $this->truncate_pdf_ocr_diagnostic( $result['stderr'] ),
						)
					),
				);
			}

			return array(
				'content'  => $text,
				'message'  => '',
				'metadata' => array(
					'pdf_external_text_status' => 'succeeded',
					'pdf_external_text_stdout' => $this->truncate_pdf_ocr_diagnostic( $result['stdout'] ),
					'pdf_external_text_stderr' => $this->truncate_pdf_ocr_diagnostic( $result['stderr'] ),
				),
			);
		} catch ( RuntimeException $exception ) {
			return array(
				'content'  => '',
				'message'  => $exception->getMessage(),
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_external_text_status' => 'failed',
						'pdf_external_text_error'  => $this->truncate_pdf_ocr_diagnostic( $exception->getMessage() ),
					)
				),
			);
		} finally {
			$this->remove_temp_file( $output_path );
			$this->remove_temp_file( $scratch_pdf );
		}
	}

	/**
	 * Returns the configured external PDF text command template.
	 *
	 * @return string
	 */
	private function pdf_external_text_command() {
		$command = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$command = false === $command ? '' : trim( (string) $command );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'universal_importer_pdf_text_command', $command );
			$command  = is_string( $filtered ) ? trim( $filtered ) : $command;
		}

		return $command;
	}

	/**
	 * Returns the configured external PDF text timeout in seconds.
	 *
	 * @return int
	 */
	private function pdf_external_text_timeout() {
		$timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$timeout = false === $timeout || '' === trim( (string) $timeout ) ? self::PDF_TEXT_TIMEOUT : (int) $timeout;

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'universal_importer_pdf_text_timeout', $timeout );
			$timeout  = is_numeric( $filtered ) ? (int) $filtered : $timeout;
		}

		return max( 1, min( 300, $timeout ) );
	}

	/**
	 * Attempts operator-configured OCR for a PDF that has no native text.
	 *
	 * The command template must include `{input}` and may include `{output}` and
	 * `{scratch}`. When `{output}` is present, OCR text is read from that file;
	 * otherwise stdout is used.
	 *
	 * @param string $path Source PDF path.
	 * @return array{content:string,message:string,metadata:array<string,mixed>}
	 */
	private function extract_pdf_text_with_ocr( $path ) {
		$command       = $this->pdf_ocr_command();
		$timeout       = $this->pdf_ocr_timeout();
		$base_metadata = array(
			'pdf_text_engine' => 'native',
			'pdf_ocr_timeout' => $timeout,
		);

		if ( '' === $command ) {
			return array(
				'content'  => '',
				'message'  => 'PDF text extraction produced no importable text. Configure UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to enable OCR for scanned PDFs.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_ocr_status' => 'not_configured',
						'pdf_ocr_hint'   => 'Set UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to a command template containing {input}; use {output} when the OCR tool writes text to a sidecar file.',
					)
				),
			);
		}

		if ( false === strpos( $command, '{input}' ) ) {
			return array(
				'content'  => '',
				'message'  => 'PDF OCR command is misconfigured; the template must include an {input} placeholder.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_ocr_status' => 'misconfigured',
						'pdf_ocr_error'  => 'PDF OCR command is misconfigured; the template must include an {input} placeholder.',
						'pdf_ocr_hint'   => 'Set UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to a command template containing {input}; use {output} when the OCR tool writes text to a sidecar file.',
					)
				),
			);
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return array(
				'content'  => '',
				'message'  => 'PDF OCR command cannot run because proc_open() is unavailable.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_ocr_status' => 'unavailable',
						'pdf_ocr_error'  => 'PDF OCR command cannot run because proc_open() is unavailable.',
					)
				),
			);
		}

		$output_path = tempnam( sys_get_temp_dir(), 'universal-importer-ocr-text-' );
		$scratch_pdf = tempnam( sys_get_temp_dir(), 'universal-importer-ocr-pdf-' );

		if ( false === $output_path || false === $scratch_pdf ) {
			$this->remove_temp_file( $output_path );
			$this->remove_temp_file( $scratch_pdf );
			return array(
				'content'  => '',
				'message'  => 'PDF OCR command cannot run because temporary files could not be created.',
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_ocr_status' => 'unavailable',
						'pdf_ocr_error'  => 'PDF OCR command cannot run because temporary files could not be created.',
					)
				),
			);
		}

		try {
			$result = $this->run_pdf_ocr_command( $command, $path, $output_path, $scratch_pdf, $timeout );
			$text   = false !== strpos( $command, '{output}' ) && is_file( $output_path ) ? $this->read_pdf_ocr_output_file( $output_path ) : $result['stdout'];

			if ( false === $text ) {
				return array(
					'content'  => '',
					'message'  => 'PDF OCR command completed but its text output could not be read.',
					'metadata' => array_merge(
						$base_metadata,
						array(
							'pdf_ocr_status' => 'output_unreadable',
							'pdf_ocr_error'  => 'PDF OCR command completed but its text output could not be read.',
						)
					),
				);
			}

			$text = $this->normalize_extracted_pdf_text( $text );

			if ( '' === $text ) {
				return array(
					'content'  => '',
					'message'  => 'PDF OCR command completed but produced no importable text.',
					'metadata' => array_merge(
						$base_metadata,
						array(
							'pdf_ocr_status' => 'empty',
							'pdf_ocr_error'  => 'PDF OCR command completed but produced no importable text.',
							'pdf_ocr_stderr' => $this->truncate_pdf_ocr_diagnostic( $result['stderr'] ),
						)
					),
				);
			}

			return array(
				'content'  => $text,
				'message'  => '',
				'metadata' => array(
					'pdf_ocr_status' => 'succeeded',
					'pdf_ocr_stdout' => $this->truncate_pdf_ocr_diagnostic( $result['stdout'] ),
					'pdf_ocr_stderr' => $this->truncate_pdf_ocr_diagnostic( $result['stderr'] ),
				),
			);
		} catch ( RuntimeException $exception ) {
			return array(
				'content'  => '',
				'message'  => $exception->getMessage(),
				'metadata' => array_merge(
					$base_metadata,
					array(
						'pdf_ocr_status' => 'failed',
						'pdf_ocr_error'  => $this->truncate_pdf_ocr_diagnostic( $exception->getMessage() ),
					)
				),
			);
		} finally {
			$this->remove_temp_file( $output_path );
			$this->remove_temp_file( $scratch_pdf );
		}
	}

	/**
	 * Returns the configured PDF OCR command template.
	 *
	 * @return string
	 */
	private function pdf_ocr_command() {
		$command = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$command = false === $command ? '' : trim( (string) $command );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'universal_importer_pdf_ocr_command', $command );
			$command  = is_string( $filtered ) ? trim( $filtered ) : $command;
		}

		return $command;
	}

	/**
	 * Returns the configured PDF OCR timeout in seconds.
	 *
	 * @return int
	 */
	private function pdf_ocr_timeout() {
		$timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT' );
		$timeout = false === $timeout || '' === trim( (string) $timeout ) ? self::PDF_OCR_TIMEOUT : (int) $timeout;

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'universal_importer_pdf_ocr_timeout', $timeout );
			$timeout  = is_numeric( $filtered ) ? (int) $filtered : $timeout;
		}

		return max( 1, min( 300, $timeout ) );
	}

	/**
	 * Reads bounded OCR sidecar text.
	 *
	 * @param string $path Sidecar text path.
	 * @return string|false
	 */
	private function read_pdf_ocr_output_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This reads a bounded importer-owned local OCR sidecar file.
		return file_get_contents( $path, false, null, 0, self::PDF_TEXT_LIMIT + self::PDF_OCR_ERROR_LIMIT );
	}

	/**
	 * Reads bounded external PDF text sidecar output.
	 *
	 * @param string $path Sidecar text path.
	 * @return string|false
	 */
	private function read_pdf_text_output_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This reads a bounded importer-owned local text-extractor sidecar file.
		return file_get_contents( $path, false, null, 0, self::PDF_TEXT_LIMIT + self::PDF_OCR_ERROR_LIMIT );
	}

	/**
	 * Runs the configured external PDF text command and captures bounded output.
	 *
	 * @param string $template    Command template.
	 * @param string $input_path  Source PDF path.
	 * @param string $output_path Text sidecar path.
	 * @param string $scratch_pdf Optional scratch PDF path.
	 * @param int    $timeout     Timeout in seconds.
	 * @return array{stdout:string,stderr:string}
	 * @throws RuntimeException When the command fails or times out.
	 */
	private function run_pdf_text_command( $template, $input_path, $output_path, $scratch_pdf, $timeout ) {
		return $this->run_pdf_process_command( $template, $input_path, $output_path, $scratch_pdf, $timeout, 'External PDF text command' );
	}

	/**
	 * Runs the configured OCR command and captures bounded output.
	 *
	 * @param string $template    Command template.
	 * @param string $input_path  Source PDF path.
	 * @param string $output_path OCR text sidecar path.
	 * @param string $scratch_pdf Optional OCR PDF output path.
	 * @param int    $timeout     Timeout in seconds.
	 * @return array{stdout:string,stderr:string}
	 * @throws RuntimeException When the command fails or times out.
	 */
	private function run_pdf_ocr_command( $template, $input_path, $output_path, $scratch_pdf, $timeout ) {
		return $this->run_pdf_process_command( $template, $input_path, $output_path, $scratch_pdf, $timeout, 'PDF OCR command' );
	}

	/**
	 * Runs an operator-configured PDF helper command and captures bounded output.
	 *
	 * @param string $template    Command template.
	 * @param string $input_path  Source PDF path.
	 * @param string $output_path Text sidecar path.
	 * @param string $scratch_pdf Optional scratch PDF path.
	 * @param int    $timeout     Timeout in seconds.
	 * @param string $label       Human-readable command label for diagnostics.
	 * @return array{stdout:string,stderr:string}
	 * @throws RuntimeException When the command fails or times out.
	 */
	private function run_pdf_process_command( $template, $input_path, $output_path, $scratch_pdf, $timeout, $label ) {
		$command = strtr(
			$template,
			array(
				'{input}'   => escapeshellarg( $input_path ),
				'{output}'  => escapeshellarg( $output_path ),
				'{scratch}' => escapeshellarg( $scratch_pdf ),
			)
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- OCR is an explicit operator-configured local command for scanned PDFs.
		$process = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Label is an internal diagnostic string.
			throw new RuntimeException( $label . ' could not be started.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes an importer-owned process pipe.
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout             = '';
		$stderr             = '';
		$deadline           = microtime( true ) + $timeout;
		$timed_out          = false;
		$observed_exit_code = null;

		do {
			$stdout .= $this->read_ocr_pipe( $pipes[1], self::PDF_TEXT_LIMIT + self::PDF_OCR_ERROR_LIMIT, $stdout );
			$stderr .= $this->read_ocr_pipe( $pipes[2], self::PDF_OCR_ERROR_LIMIT, $stderr );

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				if ( isset( $status['exitcode'] ) && -1 !== $status['exitcode'] ) {
					$observed_exit_code = (int) $status['exitcode'];
				}
				break;
			}

			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				proc_terminate( $process );
				break;
			}

			usleep( 100000 );
		} while ( true );

		$stdout .= $this->read_ocr_pipe( $pipes[1], self::PDF_TEXT_LIMIT + self::PDF_OCR_ERROR_LIMIT, $stdout );
		$stderr .= $this->read_ocr_pipe( $pipes[2], self::PDF_OCR_ERROR_LIMIT, $stderr );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes an importer-owned process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes an importer-owned process pipe.
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		if ( -1 === $exit_code && null !== $observed_exit_code ) {
			$exit_code = $observed_exit_code;
		}

		if ( $timed_out ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Timeout is an operational diagnostic, not rendered here.
			throw new RuntimeException( $label . ' timed out after ' . $timeout . ' seconds.' );
		}

		if ( 0 !== $exit_code ) {
			$diagnostic = $this->truncate_pdf_ocr_diagnostic( '' !== trim( $stderr ) ? $stderr : $stdout );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exit details are operational diagnostics, not rendered here.
			throw new RuntimeException( $label . ' failed with exit code ' . $exit_code . ( '' === $diagnostic ? '.' : ': ' . $diagnostic ) );
		}

		return array(
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Reads bounded bytes from a process pipe.
	 *
	 * @param resource $pipe      Process pipe.
	 * @param int      $limit     Maximum retained bytes.
	 * @param string   $existing  Existing captured output.
	 * @return string
	 */
	private function read_ocr_pipe( $pipe, $limit, $existing ) {
		$remaining = max( 0, (int) $limit - strlen( (string) $existing ) );

		if ( 0 === $remaining ) {
			return '';
		}

		$data = stream_get_contents( $pipe, $remaining );

		return false === $data ? '' : $data;
	}

	/**
	 * Truncates OCR command output for durable diagnostics.
	 *
	 * @param string $value Diagnostic output.
	 * @return string
	 */
	private function truncate_pdf_ocr_diagnostic( $value ) {
		$value = trim( str_replace( "\0", '', (string) $value ) );

		if ( self::PDF_OCR_ERROR_LIMIT < strlen( $value ) ) {
			return substr( $value, 0, self::PDF_OCR_ERROR_LIMIT );
		}

		return $value;
	}

	/**
	 * Removes a temporary file if it exists.
	 *
	 * @param string|false $path File path.
	 * @return void
	 */
	private function remove_temp_file( $path ) {
		if ( is_string( $path ) && is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary OCR files are importer-owned local files.
			unlink( $path );
		}
	}

	/**
	 * Extracts text from uncompressed and Flate-compressed PDF content streams.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return string
	 */
	private function extract_pdf_text( $pdf ) {
		$segments = array( (string) $pdf );

		foreach ( $this->extract_pdf_stream_segments( $pdf ) as $segment ) {
			$segments[] = $segment;
		}

		$parts = array();
		$bytes = 0;

		foreach ( $segments as $segment ) {
			$text = $this->extract_pdf_text_operators( $segment );

			if ( '' === $text ) {
				continue;
			}

			$parts[] = $text;
			$bytes  += strlen( $text );

			if ( self::PDF_TEXT_LIMIT <= $bytes ) {
				break;
			}
		}

		$text = $this->normalize_extracted_pdf_text( implode( "\n\n", $parts ) );

		if ( self::PDF_TEXT_LIMIT < strlen( $text ) ) {
			$text = substr( $text, 0, self::PDF_TEXT_LIMIT );
		}

		return $text;
	}

	/**
	 * Returns one bounded page of decoded PDF stream segments for native text scanning.
	 *
	 * @param string $pdf          Raw PDF bytes or a suffix from a durable cursor.
	 * @param int    $offset       Byte offset inside the provided PDF bytes.
	 * @param int    $limit        Maximum stream objects to inspect.
	 * @param int    $stream_index Stable stream index at the cursor.
	 * @param int    $base_offset  Absolute byte offset represented by byte 0 of $pdf.
	 * @return array{streams:array<int,array{content:string,start_offset:int,next_offset:int,index:int}>,complete:bool,next_offset:int,next_index:int,diagnostics:array<string,int>}
	 */
	private function extract_pdf_text_stream_scan( $pdf, $offset, $limit, $stream_index, $base_offset = 0 ) {
		$streams         = array();
		$decode_failures = 0;
		$malformed       = 0;
		$matched_streams = 0;
		$text_operators  = 0;
		$pdf             = (string) $pdf;
		$offset          = max( 0, (int) $offset );
		$limit           = max( 1, (int) $limit );
		$stream_index    = max( 0, (int) $stream_index );
		$base_offset     = max( 0, (int) $base_offset );
		$complete        = true;

		$text_operator_pattern = '/(?:\[[^\]]*\]\s*TJ|(?:\((?:\\\\.|[^\\\\\)])*\)|<[\da-fA-F\s]+>)\s*(?:Tj|\'|"))/s';
		$stream_count          = count( $streams );
		while ( $stream_count < $limit && preg_match( '/<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$dictionary   = (string) $match[1][0];
			$stream_start = $match[0][1] + strlen( $match[0][0] );
			$stream_end   = $this->pdf_stream_end_from_declared_length( $pdf, $dictionary, $stream_start );
			$uses_length  = null !== $stream_end;

			if ( null === $stream_end ) {
				$stream_end = strpos( $pdf, 'endstream', $stream_start );
			}

			if ( false === $stream_end ) {
				++$malformed;
				$complete = true;
				break;
			}

			if ( ! $uses_length && preg_match( '/\n\s*\d+\s+\d+\s+obj\s*<</', $pdf, $next_object, PREG_OFFSET_CAPTURE, $stream_start ) && $next_object[0][1] < $stream_end ) {
				++$malformed;
				$offset = $next_object[0][1] + 1;
				++$stream_index;
				continue;
			}

			$next_offset = $stream_end + strlen( 'endstream' );
			$stream      = substr( $pdf, $stream_start, $stream_end - $stream_start );

			if ( ! $uses_length ) {
				$stream = $this->trim_pdf_stream_delimiters( $stream );
			}

			++$matched_streams;

			if ( false !== stripos( $dictionary, '/FlateDecode' ) ) {
				if ( ! function_exists( 'gzuncompress' ) ) {
					$offset = $next_offset;
					++$stream_index;
					continue;
				}

				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are skipped.

				if ( false === $decoded ) {
					$decoded = @gzdecode( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are skipped.
				}

				if ( false === $decoded ) {
					++$decode_failures;
					$offset = $next_offset;
					++$stream_index;
					continue;
				}

				$stream = $decoded;
			}

			$text_operators += $this->count_pattern_matches( $text_operator_pattern, $stream );
			$streams[]       = array(
				'content'      => (string) $stream,
				'start_offset' => $base_offset + $stream_start,
				'next_offset'  => $base_offset + $next_offset,
				'index'        => $stream_index,
			);
			$stream_count    = count( $streams );
			$offset          = $next_offset;
			++$stream_index;
		}

		if ( count( $streams ) >= $limit && preg_match( '/<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $next_match, PREG_OFFSET_CAPTURE, $offset ) ) {
			unset( $next_match );
			$complete = false;
		}

		return array(
			'streams'     => $streams,
			'complete'    => $complete,
			'next_offset' => $base_offset + $offset,
			'next_index'  => $stream_index,
			'diagnostics' => array(
				'matched_streams'   => $matched_streams,
				'decode_failures'   => $decode_failures,
				'malformed_streams' => $malformed,
				'text_operators'    => $text_operators,
			),
		);
	}

	/**
	 * Scans a bounded page of PDF stream structure diagnostics.
	 *
	 * @param string   $pdf          Raw PDF bytes from the current cursor.
	 * @param int      $offset       Byte offset inside the provided PDF bytes.
	 * @param int|null $limit        Maximum stream entries to inspect.
	 * @param int      $stream_index Stable stream index for the first scanned stream.
	 * @param int      $base_offset  Absolute byte offset represented by byte 0 of $pdf.
	 * @return array{complete:bool,next_offset:int,next_index:int,diagnostics:array<string,mixed>}
	 */
	private function extract_pdf_structure_scan( $pdf, $offset, $limit, $stream_index, $base_offset = 0 ) {
		$decode_failures        = 0;
		$malformed              = 0;
		$matched_streams        = 0;
		$text_operators         = 0;
		$image_references       = 0;
		$rectangle_operators    = 0;
		$line_segment_operators = 0;
		$object_streams         = 0;
		$pdf                    = (string) $pdf;
		$offset                 = max( 0, (int) $offset );
		$limit                  = null === $limit ? null : max( 1, (int) $limit );
		$stream_index           = max( 0, (int) $stream_index );
		$base_offset            = max( 0, (int) $base_offset );
		$complete               = true;

		$text_operator_pattern = '/(?:\[[^\]]*\]\s*TJ|(?:\((?:\\\\.|[^\\\\\)])*\)|<[\da-fA-F\s]+>)\s*(?:Tj|\'|"))/s';
		$scanned_streams       = 0;

		while ( ( null === $limit || $scanned_streams < $limit ) && preg_match( '/<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$dictionary   = (string) $match[1][0];
			$stream_start = $match[0][1] + strlen( $match[0][0] );
			$stream_end   = $this->pdf_stream_end_from_declared_length( $pdf, $dictionary, $stream_start );
			$uses_length  = null !== $stream_end;
			$is_image     = false !== stripos( $dictionary, '/Subtype' ) && preg_match( '#/Subtype\s*/Image\b#i', $dictionary );

			if ( false !== stripos( $dictionary, '/Type' ) && preg_match( '#/Type\s*/ObjStm\b#i', $dictionary ) ) {
				++$object_streams;
			}

			if ( $is_image ) {
				++$image_references;
			}

			if ( null === $stream_end ) {
				$stream_end = strpos( $pdf, 'endstream', $stream_start );
			}

			if ( false === $stream_end ) {
				++$malformed;
				$offset = strlen( $pdf );
				break;
			}

			if ( ! $uses_length && preg_match( '/\n\s*\d+\s+\d+\s+obj\s*<</', $pdf, $next_object, PREG_OFFSET_CAPTURE, $stream_start ) && $next_object[0][1] < $stream_end ) {
				++$malformed;
				$offset = $next_object[0][1] + 1;
				++$stream_index;
				++$scanned_streams;
				continue;
			}

			$next_offset = $stream_end + strlen( 'endstream' );
			$stream      = substr( $pdf, $stream_start, $stream_end - $stream_start );

			if ( ! $uses_length ) {
				$stream = $this->trim_pdf_stream_delimiters( $stream );
			}

			++$matched_streams;

			if ( false !== stripos( $dictionary, '/FlateDecode' ) ) {
				if ( ! function_exists( 'gzuncompress' ) ) {
					$offset = $next_offset;
					++$stream_index;
					++$scanned_streams;
					continue;
				}

				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are counted as structure diagnostics.

				if ( false === $decoded ) {
					$decoded = @gzdecode( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are counted as structure diagnostics.
				}

				if ( false === $decoded ) {
					++$decode_failures;
					$offset = $next_offset;
					++$stream_index;
					++$scanned_streams;
					continue;
				}

				$stream = $decoded;
			}

			$text_operators         += $this->count_pattern_matches( $text_operator_pattern, $stream );
			$image_references       += $this->count_pattern_matches( '/\/[A-Za-z0-9_.-]+\s+Do\b/', $stream );
			$rectangle_operators    += $this->count_pattern_matches( '/(?:^|\s)[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:\s+[+-]?(?:\d+(?:\.\d+)?|\.\d+)){3}\s+re\b/', $stream );
			$line_segment_operators += $this->count_pattern_matches( '/(?:^|\s)[+-]?(?:\d+(?:\.\d+)?|\.\d+)\s+[+-]?(?:\d+(?:\.\d+)?|\.\d+)\s+l\b/', $stream );
			$offset                  = $next_offset;
			++$stream_index;
			++$scanned_streams;
		}

		if ( null !== $limit && $scanned_streams >= $limit && preg_match( '/<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $next_match, PREG_OFFSET_CAPTURE, $offset ) ) {
			unset( $next_match );
			$complete = false;
		}

		$diagnostics = array(
			'matched_streams'        => $matched_streams,
			'decode_failures'        => $decode_failures,
			'malformed_streams'      => $malformed,
			'text_operators'         => $text_operators,
			'image_references'       => $image_references,
			'rectangle_operators'    => $rectangle_operators,
			'line_segment_operators' => $line_segment_operators,
			'object_streams'         => $object_streams,
		);

		if ( 0 === $base_offset && 0 !== strpos( ltrim( $pdf ), '%PDF-' ) ) {
			$diagnostics['missing_pdf_header'] = true;
		}

		if ( $complete && false === strpos( $pdf, '%%EOF' ) ) {
			$diagnostics['missing_eof_marker'] = true;
		}

		return array(
			'complete'    => $complete,
			'next_offset' => $base_offset + $offset,
			'next_index'  => $stream_index,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Merges per-batch PDF native text scan diagnostics.
	 *
	 * @param array<string,int> $existing Existing diagnostics.
	 * @param array<string,int> $next     Next diagnostics.
	 * @return array<string,int>
	 */
	private function merge_pdf_text_scan_diagnostics( array $existing, array $next ) {
		foreach ( array( 'matched_streams', 'decode_failures', 'malformed_streams', 'text_operators' ) as $key ) {
			$existing[ $key ] = ( isset( $existing[ $key ] ) ? (int) $existing[ $key ] : 0 ) + ( isset( $next[ $key ] ) ? (int) $next[ $key ] : 0 );
		}

		return $existing;
	}

	/**
	 * Returns decoded candidate content streams from a PDF.
	 *
	 * @param string                 $pdf         Raw PDF bytes.
	 * @param array<string,int>|null $diagnostics Optional stream diagnostics.
	 * @return array<int,string>
	 */
	private function extract_pdf_stream_segments( $pdf, &$diagnostics = null ) {
		$segments        = array();
		$decode_failures = 0;
		$malformed       = 0;
		$matched_streams = 0;
		$pdf             = (string) $pdf;
		$offset          = 0;

		while ( preg_match( '/<<(.*?)>>\s*stream(?:\r\n|\n|\r)?/s', $pdf, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$dictionary   = (string) $match[1][0];
			$stream_start = $match[0][1] + strlen( $match[0][0] );
			$stream_end   = $this->pdf_stream_end_from_declared_length( $pdf, $dictionary, $stream_start );
			$uses_length  = null !== $stream_end;

			if ( null === $stream_end ) {
				$stream_end = strpos( $pdf, 'endstream', $stream_start );
			}

			if ( false === $stream_end ) {
				++$malformed;
				break;
			}

			if ( ! $uses_length && preg_match( '/\n\s*\d+\s+\d+\s+obj\s*<</', $pdf, $next_object, PREG_OFFSET_CAPTURE, $stream_start ) && $next_object[0][1] < $stream_end ) {
				++$malformed;
				$offset = $next_object[0][1] + 1;
				continue;
			}

			$offset = $stream_end + strlen( 'endstream' );
			$stream = substr( $pdf, $stream_start, $stream_end - $stream_start );

			if ( ! $uses_length ) {
				$stream = $this->trim_pdf_stream_delimiters( $stream );
			}

			++$matched_streams;

			if ( false !== stripos( $dictionary, '/FlateDecode' ) ) {
				if ( ! function_exists( 'gzuncompress' ) ) {
					continue;
				}

				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are skipped.

				if ( false === $decoded ) {
					$decoded = @gzdecode( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF streams are skipped.
				}

				if ( false === $decoded ) {
					++$decode_failures;
					continue;
				}

				$stream = $decoded;
			}

			$segments[] = (string) $stream;
		}

		if ( is_array( $diagnostics ) ) {
			$diagnostics['matched_streams']   = $matched_streams;
			$diagnostics['decode_failures']   = $decode_failures;
			$diagnostics['malformed_streams'] = $malformed;
		}

		return $segments;
	}

	/**
	 * Extracts text operands from common PDF text-showing operators.
	 *
	 * @param string $content Candidate PDF content stream.
	 * @return string
	 */
	private function extract_pdf_text_operators( $content ) {
		$tokens    = $this->tokenize_pdf_content_stream( $content );
		$operands  = array();
		$lines     = array();
		$line      = '';
		$current_x = null;
		$current_y = null;

		foreach ( $tokens as $token ) {
			if ( 'operator' !== $token['type'] ) {
				$operands[] = $token['value'];
				continue;
			}

			$operator = $token['value'];

			if ( 'Tj' === $operator ) {
				$this->append_pdf_text_to_line( $line, $this->decode_pdf_text_operand( $this->last_pdf_operand( $operands ) ) );
			} elseif ( 'TJ' === $operator ) {
				$this->append_pdf_text_to_line( $line, $this->extract_pdf_strings_from_operands( $this->pdf_array_operand_body( $this->last_pdf_operand( $operands ) ) ) );
			} elseif ( "'" === $operator || '"' === $operator ) {
				$this->finish_pdf_text_line( $lines, $line );
				$this->append_pdf_text_to_line( $line, $this->decode_pdf_text_operand( $this->last_pdf_operand( $operands ) ) );
			} elseif ( 'T*' === $operator ) {
				$this->finish_pdf_text_line( $lines, $line );
			} elseif ( 'Td' === $operator || 'TD' === $operator ) {
				$move = $this->last_pdf_numeric_operands( $operands, 2 );
				if ( null !== $move && '' !== trim( $line ) ) {
					if ( 0.01 < abs( $move[1] ) ) {
						$this->finish_pdf_text_line( $lines, $line );
					} elseif ( 24 < $move[0] && ! preg_match( '/\s$/', $line ) ) {
						$line .= ' ';
					}
				}
			} elseif ( 'Tm' === $operator ) {
				$matrix = $this->last_pdf_numeric_operands( $operands, 6 );
				if ( null !== $matrix ) {
					$next_x = $matrix[4];
					$next_y = $matrix[5];
					if ( null !== $current_y && '' !== trim( $line ) ) {
						if ( 2 < abs( $next_y - $current_y ) || ( null !== $current_x && $next_x < $current_x - 2 ) ) {
							$this->finish_pdf_text_line( $lines, $line );
						} elseif ( null !== $current_x && 24 < $next_x - $current_x && ! preg_match( '/\s$/', $line ) ) {
							$line .= ' ';
						}
					}
					$current_x = $next_x;
					$current_y = $next_y;
				}
			} elseif ( 'BT' === $operator || 'ET' === $operator ) {
				$this->finish_pdf_text_line( $lines, $line );
				$current_x = null;
				$current_y = null;
			}

			$operands = array();
		}

		$this->finish_pdf_text_line( $lines, $line );

		return implode( "\n", $lines );
	}

	/**
	 * Tokenizes the subset of PDF content stream syntax needed for text extraction.
	 *
	 * @param string $content Candidate PDF content stream.
	 * @return array<int,array{type:string,value:string}>
	 */
	private function tokenize_pdf_content_stream( $content ) {
		$tokens  = array();
		$content = (string) $content;
		$pattern = '/\((?:\\\\.|[^\\\\\)])*\)|\[(?:\\\\.|[^\]])*\]|<[\da-fA-F\s]+>|<<|>>|\/[^\s<>\[\]\(\)]+|[+-]?(?:\d+\.\d+|\d+|\.\d+)|T\*|Tj|TJ|Td|TD|Tm|BT|ET|\'|"|[A-Za-z][A-Za-z0-9\*]*/s';

		if ( ! preg_match_all( $pattern, $content, $matches ) ) {
			return $tokens;
		}

		$text_operators = array( 'Tj', 'TJ', "'", '"', 'T*', 'Td', 'TD', 'Tm', 'BT', 'ET' );

		foreach ( $matches[0] as $match ) {
			$value    = (string) $match;
			$tokens[] = array(
				'type'  => in_array( $value, $text_operators, true ) ? 'operator' : 'operand',
				'value' => $value,
			);
		}

		return $tokens;
	}

	/**
	 * Returns the last PDF operand from a stack.
	 *
	 * @param array<int,string> $operands Operand stack.
	 * @return string
	 */
	private function last_pdf_operand( array $operands ) {
		if ( empty( $operands ) ) {
			return '';
		}

		return (string) $operands[ count( $operands ) - 1 ];
	}

	/**
	 * Returns an array operand body without brackets.
	 *
	 * @param string $operand Array operand.
	 * @return string
	 */
	private function pdf_array_operand_body( $operand ) {
		$operand = trim( (string) $operand );

		if ( '[' === substr( $operand, 0, 1 ) && ']' === substr( $operand, -1 ) ) {
			return substr( $operand, 1, -1 );
		}

		return '';
	}

	/**
	 * Returns the last numeric operands from a stack.
	 *
	 * @param array<int,string> $operands Operand stack.
	 * @param int               $count    Number of operands.
	 * @return array<int,float>|null
	 */
	private function last_pdf_numeric_operands( array $operands, $count ) {
		$count = max( 1, (int) $count );

		if ( count( $operands ) < $count ) {
			return null;
		}

		$values = array_slice( $operands, -$count );
		$nums   = array();

		foreach ( $values as $value ) {
			if ( ! is_numeric( $value ) ) {
				return null;
			}
			$nums[] = (float) $value;
		}

		return $nums;
	}

	/**
	 * Appends decoded PDF text without treating every text-showing operator as a line break.
	 *
	 * @param string $line Current line, by reference.
	 * @param string $text Decoded text.
	 * @return void
	 */
	private function append_pdf_text_to_line( &$line, $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return;
		}

		$line .= $text;
	}

	/**
	 * Completes the current PDF text line.
	 *
	 * @param array<int,string> $lines Completed lines, by reference.
	 * @param string            $line  Current line, by reference.
	 * @return void
	 */
	private function finish_pdf_text_line( array &$lines, &$line ) {
		$line = trim( (string) $line );

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		$line = '';
	}

	/**
	 * Extracts text strings from a PDF array operand.
	 *
	 * @param string $operands Raw array operand body.
	 * @return string
	 */
	private function extract_pdf_strings_from_operands( $operands ) {
		if ( ! preg_match_all( '/\((?:\\\\.|[^\\\\\)])*\)|<[\da-fA-F\s]+>/s', (string) $operands, $matches ) ) {
			return '';
		}

		$parts = array();

		foreach ( $matches[0] as $operand ) {
			$text = $this->decode_pdf_text_operand( $operand );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Decodes a PDF literal or hex string operand.
	 *
	 * @param string $operand Operand token.
	 * @return string
	 */
	private function decode_pdf_text_operand( $operand ) {
		$operand = trim( (string) $operand );

		if ( '' === $operand ) {
			return '';
		}

		if ( '(' === $operand[0] && ')' === substr( $operand, -1 ) ) {
			return $this->decode_pdf_literal_string( substr( $operand, 1, -1 ) );
		}

		if ( '<' === $operand[0] && '>' === substr( $operand, -1 ) ) {
			return $this->decode_pdf_hex_string( substr( $operand, 1, -1 ) );
		}

		return '';
	}

	/**
	 * Decodes a PDF literal string body.
	 *
	 * @param string $value Literal string body.
	 * @return string
	 */
	private function decode_pdf_literal_string( $value ) {
		$output = '';
		$length = strlen( (string) $value );

		for ( $index = 0; $index < $length; ++$index ) {
			$char = $value[ $index ];

			if ( '\\' !== $char || $index + 1 >= $length ) {
				$output .= $char;
				continue;
			}

			++$index;
			$escaped = $value[ $index ];

			if ( in_array( $escaped, array( 'n', 'r', 't', 'b', 'f' ), true ) ) {
				$output .= array(
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\x0c",
				)[ $escaped ];
				continue;
			}

			if ( "\n" === $escaped || "\r" === $escaped ) {
				if ( "\r" === $escaped && $index + 1 < $length && "\n" === $value[ $index + 1 ] ) {
					++$index;
				}
				continue;
			}

			if ( preg_match( '/[0-7]/', $escaped ) ) {
				$octal  = $escaped;
				$digits = 0;

				while ( $digits < 2 && $index + 1 < $length && preg_match( '/[0-7]/', $value[ $index + 1 ] ) ) {
					++$index;
					++$digits;
					$octal .= $value[ $index ];
				}
				$output .= chr( octdec( $octal ) );
				continue;
			}

			$output .= $escaped;
		}

		return $output;
	}

	/**
	 * Decodes a PDF hexadecimal string operand.
	 *
	 * @param string $value Hex string body.
	 * @return string
	 */
	private function decode_pdf_hex_string( $value ) {
		$hex = preg_replace( '/\s+/', '', (string) $value );

		if ( ! is_string( $hex ) || '' === $hex ) {
			return '';
		}

		if ( 1 === strlen( $hex ) % 2 ) {
			$hex .= '0';
		}

		$binary = @hex2bin( $hex ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid PDF hex strings are ignored.

		if ( false === $binary ) {
			return '';
		}

		if ( 0 === strpos( $binary, "\xfe\xff" ) && function_exists( 'mb_convert_encoding' ) ) {
			$converted = mb_convert_encoding( substr( $binary, 2 ), 'UTF-8', 'UTF-16BE' );
			return false === $converted ? '' : $converted;
		}

		return $binary;
	}

	/**
	 * Normalizes extracted PDF text into paragraph-friendly plain text.
	 *
	 * @param string $text            Raw extracted text.
	 * @param bool   $preserve_layout Whether horizontal spacing should be preserved.
	 * @return string
	 */
	private function normalize_extracted_pdf_text( $text, $preserve_layout = false ) {
		$text = str_replace( "\0", '', (string) $text );
		if ( $preserve_layout ) {
			$text = str_replace( "\t", '    ', $text );
			$text = preg_replace( '/[ \t]+$/m', '', $text );
		} else {
			$text = preg_replace( '/[ \t]+/', ' ', $text );
		}
		$text = preg_replace( "/\r\n?/", "\n", is_string( $text ) ? $text : '' );
		$text = preg_replace( "/\n{3,}/", "\n\n", is_string( $text ) ? $text : '' );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Reads a local file through a byte stream and strips script blocks.
	 *
	 * @param string $path Source path.
	 * @param string $format Document format.
	 * @return array{content:string,content_hash:string}
	 * @throws RuntimeException When the file is not readable.
	 */
	private function read_streamed_file( $path, $format ) {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Discovered document item is no longer a file.' );
		}

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Discovered document file is not readable.' );
		}

		$stream = $this->open_document_stream( $path );

		$hash_context = hash_init( 'sha256' );
		hash_update( $hash_context, $format . "\n" );
		$content   = '';
		$carry     = '';
		$in_script = false;

		try {
			while ( ! $stream->reached_end_of_data() ) {
				$pulled = $stream->pull( self::READ_CHUNK_BYTES, ByteReadStream::PULL_NO_MORE_THAN );

				if ( 0 === $pulled ) {
					break;
				}

				$chunk = $stream->consume( $pulled );
				hash_update( $hash_context, $chunk );
				$content .= $this->strip_script_chunk( $chunk, $carry, $in_script, false );
			}

			$content .= $this->strip_script_chunk( '', $carry, $in_script, true );
		} catch ( ByteStreamException $exception ) {
			throw new RuntimeException( 'Unable to read discovered document file stream.' );
		} finally {
			$stream->close_reading();
		}

		return array(
			'content'      => $content,
			'content_hash' => hash_final( $hash_context ),
		);
	}

	/**
	 * Converts simple Markdown into block markup.
	 *
	 * @param string                                       $content    Markdown content.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string
	 */
	private function markdown_to_blocks( $content, array $references = array() ) {
		$blocks = array();

		foreach ( preg_split( '/\R{2,}/', trim( $content ) ) as $chunk ) {
			$chunk = trim( (string) $chunk );

			if ( '' === $chunk ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $chunk, $matches ) ) {
				$level    = strlen( $matches[1] );
				$blocks[] = '<!-- wp:heading {"level":' . $level . '} -->' . "\n" . '<h' . $level . '>' . $this->markdown_inline_to_html( $matches[2], $references ) . '</h' . $level . '>' . "\n" . '<!-- /wp:heading -->';
				continue;
			}

			$setext_heading = $this->markdown_setext_heading( $chunk );
			if ( null !== $setext_heading ) {
				$level    = $setext_heading['level'];
				$blocks[] = '<!-- wp:heading {"level":' . $level . '} -->' . "\n" . '<h' . $level . '>' . $this->markdown_inline_to_html( $setext_heading['text'], $references ) . '</h' . $level . '>' . "\n" . '<!-- /wp:heading -->';
				continue;
			}

			if ( preg_match( '/^(?:-{3,}|\*{3,}|_{3,})$/', $chunk ) ) {
				$blocks[] = '<!-- wp:separator -->' . "\n" . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" . '<!-- /wp:separator -->';
				continue;
			}

			if ( preg_match( '/^```[^\r\n]*\R.*\R```$/s', $chunk ) ) {
				$lines = preg_split( '/\R/', $chunk );
				array_shift( $lines );
				array_pop( $lines );
				$blocks[] = '<!-- wp:code -->' . "\n" . '<pre class="wp-block-code"><code>' . $this->escape_html( implode( "\n", $lines ) ) . '</code></pre>' . "\n" . '<!-- /wp:code -->';
				continue;
			}

			$image = $this->markdown_image_to_block( $chunk, $references );
			if ( null !== $image ) {
				$blocks[] = $image;
				continue;
			}

			$table = $this->markdown_table_to_block( $chunk, $references );
			if ( null !== $table ) {
				$blocks[] = $table;
				continue;
			}

			$list = $this->markdown_list_to_block( $chunk, $references );
			if ( null !== $list ) {
				$blocks[] = $list;
				continue;
			}

			$quote = $this->markdown_quote_to_block( $chunk, $references );
			if ( null !== $quote ) {
				$blocks[] = $quote;
				continue;
			}

			$blocks[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . nl2br( $this->markdown_inline_to_html( $chunk, $references ), false ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Parses one conservative Markdown setext heading chunk.
	 *
	 * @param string $chunk Markdown chunk.
	 * @return array{level:int,text:string}|null
	 */
	private function markdown_setext_heading( $chunk ) {
		$lines = preg_split( '/\R/', trim( (string) $chunk ) );

		if ( ! is_array( $lines ) || 2 !== count( $lines ) ) {
			return null;
		}

		$text      = trim( (string) $lines[0] );
		$underline = trim( (string) $lines[1] );

		if ( '' === $text || $this->markdown_setext_heading_text_is_ambiguous( $text ) ) {
			return null;
		}

		if ( preg_match( '/^={3,}$/', $underline ) ) {
			return array(
				'level' => 1,
				'text'  => $text,
			);
		}

		if ( preg_match( '/^-{3,}$/', $underline ) ) {
			return array(
				'level' => 2,
				'text'  => $text,
			);
		}

		return null;
	}

	/**
	 * Returns whether a setext heading candidate looks like another block type.
	 *
	 * @param string $text Candidate heading text.
	 * @return bool
	 */
	private function markdown_setext_heading_text_is_ambiguous( $text ) {
		$text = trim( (string) $text );

		return false !== strpos( $text, '|' )
			|| preg_match( '/^\s*(?:[-*+]\s+|\d+[.)]\s+|>\s?|#{1,6}\s+|```)/', $text );
	}

	/**
	 * Extracts conservative leading Markdown front matter metadata.
	 *
	 * @param string $content Markdown content.
	 * @return array{content:string,title:string,detected:bool}
	 */
	private function extract_markdown_front_matter( $content ) {
		$content = (string) $content;
		if ( "\xEF\xBB\xBF" === substr( $content, 0, 3 ) ) {
			$content = substr( $content, 3 );
		}

		if ( ! preg_match( '/\A---[ \t]*\R/', $content ) ) {
			return array(
				'content'  => $content,
				'title'    => '',
				'detected' => false,
			);
		}

		$lines = preg_split( '/\R/', $content );
		if ( ! is_array( $lines ) || count( $lines ) < 3 ) {
			return array(
				'content'  => $content,
				'title'    => '',
				'detected' => false,
			);
		}

		$closing_index = null;
		$line_count    = count( $lines );
		for ( $i = 1; $i < $line_count; ++$i ) {
			if ( '---' === trim( (string) $lines[ $i ] ) ) {
				$closing_index = $i;
				break;
			}
		}

		if ( null === $closing_index ) {
			return array(
				'content'  => $content,
				'title'    => '',
				'detected' => false,
			);
		}

		$title = '';
		for ( $i = 1; $i < $closing_index; ++$i ) {
			if ( preg_match( '/^\s*title\s*:\s*(.+?)\s*$/i', (string) $lines[ $i ], $matches ) ) {
				$title = $this->normalize_markdown_front_matter_scalar( $matches[1] );
				break;
			}
		}

		return array(
			'content'  => ltrim( implode( "\n", array_slice( $lines, $closing_index + 1 ) ) ),
			'title'    => $title,
			'detected' => true,
		);
	}

	/**
	 * Normalizes one simple Markdown front matter scalar.
	 *
	 * @param string $value Raw scalar value.
	 * @return string
	 */
	private function normalize_markdown_front_matter_scalar( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$quote = substr( $value, 0, 1 );
		if ( ( '"' === $quote || "'" === $quote ) && substr( $value, -1 ) === $quote ) {
			$value = substr( $value, 1, -1 );
		}

		return trim( html_entity_decode( $this->html_text( $value ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Extracts conservative single-line Markdown reference definitions.
	 *
	 * @param string $content Markdown content.
	 * @return array{content:string,references:array<string,array{url:string,title:string}>}
	 */
	private function extract_markdown_reference_definitions( $content ) {
		$lines      = preg_split( '/\R/', (string) $content );
		$output     = array();
		$references = array();
		$in_fence   = false;

		if ( ! is_array( $lines ) ) {
			return array(
				'content'    => (string) $content,
				'references' => $references,
			);
		}

		foreach ( $lines as $line ) {
			$line = (string) $line;
			if ( preg_match( '/^\s*```/', $line ) ) {
				$in_fence = ! $in_fence;
				$output[] = $line;
				continue;
			}

			if ( ! $in_fence && preg_match( '/^\s{0,3}\[([^\]\r\n]+)\]:\s*(\S+)(?:\s+([\'"])(.*?)\3)?\s*$/', $line, $matches ) ) {
				$id = $this->markdown_reference_id( $matches[1] );
				if ( '' !== $id ) {
					$references[ $id ] = array(
						'url'   => (string) $matches[2],
						'title' => isset( $matches[4] ) ? (string) $matches[4] : '',
					);
				}
				continue;
			}

			$output[] = $line;
		}

		return array(
			'content'    => trim( implode( "\n", $output ) ),
			'references' => $references,
		);
	}

	/**
	 * Normalizes a Markdown reference identifier.
	 *
	 * @param string $id Raw reference id.
	 * @return string
	 */
	private function markdown_reference_id( $id ) {
		$id = preg_replace( '/\s+/', ' ', strtolower( trim( (string) $id ) ) );

		return is_string( $id ) ? $id : '';
	}

	/**
	 * Converts one standalone Markdown image to a native Image block.
	 *
	 * @param string                                       $chunk Markdown chunk.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string|null
	 */
	private function markdown_image_to_block( $chunk, array $references = array() ) {
		if ( preg_match( '/^!\[([^\]]*)\]\[([^\]\r\n]*)\]$/', (string) $chunk, $matches ) ) {
			$id = $this->markdown_reference_id( '' === (string) $matches[2] ? $matches[1] : $matches[2] );
			if ( '' === $id || ! isset( $references[ $id ] ) ) {
				return null;
			}

			$url   = $this->normalize_markdown_link_url( $references[ $id ]['url'] );
			$alt   = (string) $matches[1];
			$title = $references[ $id ]['title'];
			if ( null === $url ) {
				return null;
			}

			return $this->markdown_image_block( $url, $alt, $title );
		}

		if ( ! preg_match( '/^!\[([^\]]*)\]\(\s*(\S+?)(?:\s+([\'"])(.*?)\3)?\s*\)$/', (string) $chunk, $matches ) ) {
			return null;
		}

		$url = $this->normalize_markdown_link_url( $matches[2] );
		if ( null === $url ) {
			return null;
		}

		$alt   = (string) $matches[1];
		$title = isset( $matches[4] ) ? (string) $matches[4] : '';

		return $this->markdown_image_block( $url, $alt, $title );
	}

	/**
	 * Builds one native Image block from normalized Markdown image data.
	 *
	 * @param string $url   Image URL.
	 * @param string $alt   Image alt text.
	 * @param string $title Optional image title.
	 * @return string
	 */
	private function markdown_image_block( $url, $alt, $title ) {
		$image = '<img src="' . $this->escape_html( $url ) . '" alt="' . $this->escape_html( $alt ) . '"';

		if ( '' !== $title ) {
			$image .= ' title="' . $this->escape_html( $title ) . '"';
		}

		$image .= '/>';

		return '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image">' . $image . '</figure>' . "\n" . '<!-- /wp:image -->';
	}

	/**
	 * Converts one conservative Markdown pipe table to a native Table block.
	 *
	 * @param string                                       $chunk      Markdown chunk.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string|null
	 */
	private function markdown_table_to_block( $chunk, array $references = array() ) {
		$lines = preg_split( '/\R/', (string) $chunk );

		if ( ! is_array( $lines ) || count( $lines ) < 3 ) {
			return null;
		}

		$header = $this->markdown_table_row_cells( $lines[0] );

		if ( null === $header || count( $header ) < 2 || ! $this->markdown_table_row_has_content( $header ) || ! $this->is_markdown_table_separator( $lines[1], count( $header ) ) ) {
			return null;
		}

		$line_count = count( $lines );
		$rows       = array();
		for ( $i = 2; $i < $line_count; ++$i ) {
			$cells = $this->markdown_table_row_cells( $lines[ $i ] );

			if ( null === $cells || count( $cells ) !== count( $header ) || ! $this->markdown_table_row_has_content( $cells ) ) {
				return null;
			}

			$rows[] = $cells;
		}

		if ( empty( $rows ) ) {
			return null;
		}

		$table = '<table><thead><tr>';
		foreach ( $header as $cell ) {
			$table .= '<th>' . $this->markdown_inline_to_html( $cell, $references ) . '</th>';
		}
		$table .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$table .= '<tr>';
			foreach ( $row as $cell ) {
				$table .= '<td>' . $this->markdown_inline_to_html( $cell, $references ) . '</td>';
			}
			$table .= '</tr>';
		}

		$table .= '</tbody></table>';

		return '<!-- wp:table -->' . "\n" . '<figure class="wp-block-table">' . $table . '</figure>' . "\n" . '<!-- /wp:table -->';
	}

	/**
	 * Splits a Markdown pipe table row into cells.
	 *
	 * @param string $line Markdown table row line.
	 * @return array<int,string>|null
	 */
	private function markdown_table_row_cells( $line ) {
		$line = trim( (string) $line );

		if ( '' === $line || false === strpos( $line, '|' ) ) {
			return null;
		}

		if ( '|' === substr( $line, 0, 1 ) ) {
			$line = substr( $line, 1 );
		}

		if ( '|' === substr( $line, -1 ) ) {
			$line = substr( $line, 0, -1 );
		}

		$cells = preg_split( '/(?<!\\\\)\|/', $line );

		if ( ! is_array( $cells ) || count( $cells ) < 2 ) {
			return null;
		}

		return array_map(
			function ( $cell ) {
				return trim( str_replace( '\\|', '|', (string) $cell ) );
			},
			$cells
		);
	}

	/**
	 * Returns whether a Markdown table delimiter row matches the header.
	 *
	 * @param string $line          Markdown delimiter row.
	 * @param int    $expected_cols Expected number of columns.
	 * @return bool
	 */
	private function is_markdown_table_separator( $line, $expected_cols ) {
		$cells = $this->markdown_table_row_cells( $line );

		if ( null === $cells || count( $cells ) !== $expected_cols ) {
			return false;
		}

		foreach ( $cells as $cell ) {
			if ( ! preg_match( '/^:?-{3,}:?$/', trim( $cell ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns whether a Markdown table row contains any non-empty cell content.
	 *
	 * @param array<int,string> $cells Table cells.
	 * @return bool
	 */
	private function markdown_table_row_has_content( array $cells ) {
		foreach ( $cells as $cell ) {
			if ( '' !== trim( $cell ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts conservative Markdown inline syntax into safe HTML.
	 *
	 * @param string                                       $text       Markdown inline text.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string
	 */
	private function markdown_inline_to_html( $text, array $references = array() ) {
		$tokens = array();
		$text   = preg_replace_callback(
			'/`([^`\r\n]+)`/',
			function ( $matches ) use ( &$tokens ) {
				$placeholder            = "\x1A" . count( $tokens ) . "\x1A";
				$tokens[ $placeholder ] = '<code>' . $this->escape_html( $matches[1] ) . '</code>';

				return $placeholder;
			},
			(string) $text
		);
		$text   = preg_replace_callback(
			'/(?<!!)\[([^\]\r\n]+)\]\[([^\]\r\n]*)\]/',
			function ( $matches ) use ( &$tokens, $references ) {
				$id = $this->markdown_reference_id( '' === (string) $matches[2] ? $matches[1] : $matches[2] );

				if ( '' === $id || ! isset( $references[ $id ] ) ) {
					return $matches[0];
				}

				$url = $this->normalize_markdown_link_url( $references[ $id ]['url'] );
				if ( null === $url ) {
					return $matches[1];
				}

				$link = '<a href="' . $this->escape_html( $url ) . '"';
				if ( '' !== $references[ $id ]['title'] ) {
					$link .= ' title="' . $this->escape_html( $references[ $id ]['title'] ) . '"';
				}
				$link .= '>' . $this->escape_html( $matches[1] ) . '</a>';

				$placeholder            = "\x1A" . count( $tokens ) . "\x1A";
				$tokens[ $placeholder ] = $link;

				return $placeholder;
			},
			is_string( $text ) ? $text : ''
		);
		$text   = preg_replace_callback(
			'/(?<!!)\[([^\]\r\n]+)\]\(\s*(\S+?)(?:\s+([\'"])(.*?)\3)?\s*\)/',
			function ( $matches ) use ( &$tokens ) {
				$url = $this->normalize_markdown_link_url( $matches[2] );

				if ( null === $url ) {
					return $matches[1];
				}

				$link = '<a href="' . $this->escape_html( $url ) . '"';
				if ( isset( $matches[4] ) && '' !== (string) $matches[4] ) {
					$link .= ' title="' . $this->escape_html( $matches[4] ) . '"';
				}
				$link .= '>' . $this->escape_html( $matches[1] ) . '</a>';

				$placeholder            = "\x1A" . count( $tokens ) . "\x1A";
				$tokens[ $placeholder ] = $link;

				return $placeholder;
			},
			(string) $text
		);

		$html = $this->escape_html( is_string( $text ) ? $text : '' );
		$html = preg_replace( '/(?<!\*)\*\*([^*\r\n]+)\*\*(?!\*)/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/(?<!_)__([^_\r\n]+)__(?!_)/', '<strong>$1</strong>', is_string( $html ) ? $html : '' );
		$html = preg_replace( '/(?<!\*)\*([^*\r\n]+)\*(?!\*)/', '<em>$1</em>', is_string( $html ) ? $html : '' );
		$html = preg_replace( '/(?<!_)_([^_\r\n]+)_(?!_)/', '<em>$1</em>', is_string( $html ) ? $html : '' );
		$html = is_string( $html ) ? $html : '';

		foreach ( $tokens as $placeholder => $link ) {
			$html = str_replace( $placeholder, $link, $html );
		}

		return $html;
	}

	/**
	 * Normalizes a Markdown link URL, returning null for unsafe schemes.
	 *
	 * @param string $url Raw Markdown URL.
	 * @return string|null
	 */
	private function normalize_markdown_link_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return null;
		}

		if ( '<' === substr( $url, 0, 1 ) && '>' === substr( $url, -1 ) ) {
			$url = substr( $url, 1, -1 );
		}

		if ( '' === $url || 0 === strpos( $url, '//' ) || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return null;
		}

		$decoded = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		$compact = preg_replace( '/[\x00-\x20\x7F]+/', '', is_string( $decoded ) ? $decoded : $url );
		$compact = is_string( $compact ) ? $compact : $url;

		if ( 0 === strpos( $compact, '//' ) ) {
			return null;
		}

		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $compact, $matches ) && ! in_array( strtolower( $matches[1] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Converts one simple Markdown list chunk to a native List block.
	 *
	 * @param string                                       $chunk      Markdown chunk.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string|null
	 */
	private function markdown_list_to_block( $chunk, array $references = array() ) {
		$lines   = preg_split( '/\R/', (string) $chunk );
		$ordered = null;
		$items   = array();

		foreach ( $lines as $line ) {
			$line = rtrim( (string) $line );

			if ( preg_match( '/^\s*[-*+]\s+(.+)$/', $line, $matches ) ) {
				if ( true === $ordered ) {
					return null;
				}

				$ordered = false;
				$items[] = $matches[1];
				continue;
			}

			if ( preg_match( '/^\s*\d+[.)]\s+(.+)$/', $line, $matches ) ) {
				if ( false === $ordered ) {
					return null;
				}

				$ordered = true;
				$items[] = $matches[1];
				continue;
			}

			return null;
		}

		if ( empty( $items ) ) {
			return null;
		}

		$tag  = $ordered ? 'ol' : 'ul';
		$list = '<' . $tag . '>';
		foreach ( $items as $item ) {
			$list .= '<li>' . $this->markdown_inline_to_html( $item, $references ) . '</li>';
		}
		$list .= '</' . $tag . '>';

		$attributes = $ordered ? ' {"ordered":true}' : '';

		return '<!-- wp:list' . $attributes . ' -->' . "\n" . $list . "\n" . '<!-- /wp:list -->';
	}

	/**
	 * Converts one simple Markdown blockquote chunk to a native Quote block.
	 *
	 * @param string                                       $chunk      Markdown chunk.
	 * @param array<string,array{url:string,title:string}> $references Reference definitions.
	 * @return string|null
	 */
	private function markdown_quote_to_block( $chunk, array $references = array() ) {
		$lines = preg_split( '/\R/', (string) $chunk );
		$quote = array();

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\s*>\s?(.*)$/', (string) $line, $matches ) ) {
				return null;
			}

			$quote[] = $matches[1];
		}

		if ( empty( $quote ) ) {
			return null;
		}

		return '<!-- wp:quote -->' . "\n" . '<blockquote class="wp-block-quote"><p>' . nl2br( $this->markdown_inline_to_html( implode( "\n", $quote ), $references ), false ) . '</p></blockquote>' . "\n" . '<!-- /wp:quote -->';
	}

	/**
	 * Converts text into paragraph blocks.
	 *
	 * @param string $content Text content.
	 * @return string
	 */
	private function text_to_blocks( $content ) {
		return $this->markdown_to_blocks( $content );
	}

	/**
	 * Converts PDF text into block markup, preserving simple tabular layouts.
	 *
	 * @param string            $content       PDF text content.
	 * @param array<string,int> $table_summary Mutable table conversion summary.
	 * @return string
	 */
	private function pdf_text_to_blocks( $content, array &$table_summary ) {
		$blocks = array();

		foreach ( preg_split( '/\n{2,}/', trim( (string) $content ) ) as $chunk ) {
			$chunk = trim( (string) $chunk );

			if ( '' === $chunk ) {
				continue;
			}

			$table = $this->parse_pdf_table_candidate( $chunk );
			if ( null !== $table ) {
				$blocks[] = $this->pdf_table_to_block( $table );
				++$table_summary['tables'];
				$table_summary['rows']       += count( $table );
				$table_summary['max_columns'] = max( $table_summary['max_columns'], count( $table[0] ) );
				continue;
			}

			$block = $this->markdown_to_blocks( $chunk );
			if ( '' !== trim( $block ) ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Parses a plain-text table candidate from layout-aware PDF text.
	 *
	 * @param string $chunk Candidate paragraph chunk.
	 * @return array<int,array<int,string>>|null Parsed rows, or null when the chunk is not table-like.
	 */
	private function parse_pdf_table_candidate( $chunk ) {
		$lines = preg_split( '/\n/', trim( (string) $chunk ) );

		if ( ! is_array( $lines ) || self::PDF_TABLE_MIN_ROWS > count( $lines ) ) {
			return null;
		}

		$rows          = array();
		$column_count  = null;
		$has_separator = false;

		foreach ( $lines as $line ) {
			$line = rtrim( (string) $line );

			if ( '' === trim( $line ) ) {
				return null;
			}

			$row = $this->parse_pdf_table_row( $line );
			if ( null === $row ) {
				return null;
			}

			if ( null === $column_count ) {
				$column_count = count( $row );
			} elseif ( count( $row ) !== $column_count ) {
				return null;
			}

			$has_separator = $has_separator || false !== strpos( $line, '|' ) || false !== strpos( $line, "\t" ) || (bool) preg_match( '/\S\s{2,}\S/', $line );
			$rows[]        = $row;
		}

		if ( ! $has_separator || null === $column_count || 2 > $column_count ) {
			return null;
		}

		return $rows;
	}

	/**
	 * Parses one row from fixed-width, tab-separated, or pipe-separated PDF text.
	 *
	 * @param string $line Row line.
	 * @return array<int,string>|null Parsed cells, or null when not table-like.
	 */
	private function parse_pdf_table_row( $line ) {
		$line = trim( (string) $line );

		if ( '' === $line ) {
			return null;
		}

		if ( false !== strpos( $line, '|' ) ) {
			$cells = explode( '|', trim( $line, " \t|" ) );
		} elseif ( false !== strpos( $line, "\t" ) ) {
			$cells = preg_split( '/\t+/', $line );
		} elseif ( preg_match( '/\S\s{2,}\S/', $line ) ) {
			$cells = preg_split( '/\s{2,}/', $line );
		} else {
			return null;
		}

		if ( ! is_array( $cells ) ) {
			return null;
		}

		$cells = array_values(
			array_filter(
				array_map( 'trim', $cells ),
				function ( $cell ) {
					return '' !== $cell;
				}
			)
		);

		return 2 <= count( $cells ) ? $cells : null;
	}

	/**
	 * Converts parsed PDF table rows into a WordPress table block.
	 *
	 * @param array<int,array<int,string>> $rows Table rows.
	 * @return string
	 */
	private function pdf_table_to_block( array $rows ) {
		$html = '<!-- wp:table -->' . "\n" . '<figure class="wp-block-table"><table><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $row as $cell ) {
				$html .= '<td>' . $this->escape_html( $cell ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table></figure>' . "\n" . '<!-- /wp:table -->';
	}

	/**
	 * Converts HTML into inferred block markup with classic fallback.
	 *
	 * @param string                   $content HTML content.
	 * @param array<string,mixed>|null $summary Optional conversion summary.
	 * @return string
	 */
	private function html_to_blocks( $content, &$summary = null ) {
		return ( new ImportHtmlBlockConverter() )->convert( $content, $summary );
	}

	/**
	 * Removes script blocks from imported content.
	 *
	 * @param string $content Source content.
	 * @return string
	 */
	private function strip_scripts( $content ) {
		return ( new ImportHtmlBlockConverter() )->strip_scripts( $content );
	}

	/**
	 * Removes script blocks while preserving enough lookbehind for split tags.
	 *
	 * @param string $chunk     Next raw chunk.
	 * @param string $carry     Carried bytes from the previous chunk.
	 * @param bool   $in_script Whether the stream is currently inside a script block.
	 * @param bool   $is_final  Whether this is the final flush.
	 * @return string
	 */
	private function strip_script_chunk( $chunk, &$carry, &$in_script, $is_final ) {
		$buffer = $carry . (string) $chunk;
		$carry  = '';

		if ( ! $is_final && strlen( $buffer ) <= self::SCRIPT_CARRY_BYTES ) {
			$carry = $buffer;
			return '';
		}

		if ( ! $is_final ) {
			$carry  = substr( $buffer, - self::SCRIPT_CARRY_BYTES );
			$buffer = substr( $buffer, 0, - self::SCRIPT_CARRY_BYTES );
		}

		$output = '';

		while ( '' !== $buffer ) {
			if ( $in_script ) {
				if ( ! preg_match( '#</script\s*>#i', $buffer, $matches, PREG_OFFSET_CAPTURE ) ) {
					return $output;
				}

				$closing_tag = $matches[0][0];
				$offset      = $matches[0][1];
				$buffer      = substr( $buffer, $offset + strlen( $closing_tag ) );
				$in_script   = false;
				continue;
			}

			if ( ! preg_match( '#<script\b[^>]*>#i', $buffer, $matches, PREG_OFFSET_CAPTURE ) ) {
				$output .= $buffer;
				break;
			}

			$opening_tag = $matches[0][0];
			$offset      = $matches[0][1];
			$output     .= substr( $buffer, 0, $offset );
			$buffer      = substr( $buffer, $offset + strlen( $opening_tag ) );
			$in_script   = true;
		}

		return $output;
	}

	/**
	 * Builds a fallback title for prepared documents.
	 *
	 * @param ImportSourceItem $item    Source item.
	 * @param string           $content Source content.
	 * @return string
	 */
	private function title_for_item( ImportSourceItem $item, $content ) {
		if ( preg_match( '/^\s*#\s+(.+)$/m', $content, $matches ) ) {
			return trim( $matches[1] );
		}

		foreach ( preg_split( '/\R{2,}/', trim( (string) $content ) ) as $chunk ) {
			$setext_heading = $this->markdown_setext_heading( $chunk );
			if ( null !== $setext_heading ) {
				return $setext_heading['text'];
			}
		}

		if ( preg_match( '#<h1\b[^>]*>(.*?)</h1>#is', $content, $matches ) ) {
			$title = $this->html_text( $matches[1] );

			if ( '' !== $title ) {
				return $title;
			}
		}

		if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', $content, $matches ) ) {
			$title = $this->html_text( $matches[1] );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$relative_path = trim( $item->get_relative_path() );

		if ( '' !== $relative_path ) {
			return pathinfo( $relative_path, PATHINFO_FILENAME );
		}

		return pathinfo( $item->get_source_uri(), PATHINFO_FILENAME );
	}

	/**
	 * Builds a title for an incrementally prepared text chunk.
	 *
	 * @param ImportSourceItem $item       Source item.
	 * @param string           $content    Chunk content.
	 * @param int              $index      Chunk index.
	 * @param bool             $is_complete Whether this chunk completed the file.
	 * @return string
	 */
	private function title_for_text_chunk( ImportSourceItem $item, $content, $index, $is_complete ) {
		$title = $this->title_for_item( $item, $content );

		if ( 0 === (int) $index && $is_complete ) {
			return $title;
		}

		return $title . ' part ' . ( (int) $index + 1 );
	}

	/**
	 * Extracts normalized hostnames from absolute HTTP(S) URLs in a document.
	 *
	 * @param string $content Source content after script stripping.
	 * @return array<string,array<int,string>> Domains keyed to a few example URLs.
	 */
	private function extract_absolute_url_domains( $content ) {
		$domains = array();

		if ( ! preg_match_all( '#https?://([^/\s<>"\'\)\]\}:]+)(?::\d+)?[^\s<>"\'\)\]\}]*#i', (string) $content, $matches, PREG_SET_ORDER ) ) {
			return $domains;
		}

		foreach ( $matches as $match ) {
			$url            = $match[0];
			$normalized_url = rtrim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), '.,;:' );
			$host           = $this->normalize_domain( $match[1] );

			if ( '' === $host ) {
				continue;
			}

			if ( ! isset( $domains[ $host ] ) ) {
				$domains[ $host ] = array();
			}

			if ( count( $domains[ $host ] ) < 3 && ! in_array( $normalized_url, $domains[ $host ], true ) ) {
				$domains[ $host ][] = $normalized_url;
			}
		}

		ksort( $domains );

		return $domains;
	}

	/**
	 * Merges newly discovered absolute URL domains into existing metadata.
	 *
	 * @param array<string,mixed>             $metadata Existing document metadata.
	 * @param array<string,array<int,string>> $domains  Newly discovered examples keyed by domain.
	 * @return array<string,mixed>
	 */
	private function merge_absolute_url_domain_metadata( array $metadata, array $domains ) {
		if ( empty( $domains ) ) {
			return $metadata;
		}

		$examples = isset( $metadata['absolute_url_examples'] ) && is_array( $metadata['absolute_url_examples'] )
			? $metadata['absolute_url_examples']
			: array();

		$examples = $this->merge_absolute_url_domain_examples( $examples, $domains );

		$metadata['absolute_url_examples'] = $examples;
		$metadata['absolute_url_domains']  = array_keys( $examples );

		return $metadata;
	}

	/**
	 * Merges absolute URL domain example collections.
	 *
	 * @param array<string,array<int,string>> $examples Existing examples.
	 * @param array<string,array<int,string>> $domains  Newly discovered examples keyed by domain.
	 * @return array<string,array<int,string>>
	 */
	private function merge_absolute_url_domain_examples( array $examples, array $domains ) {
		foreach ( $domains as $domain => $urls ) {
			$domain = $this->normalize_domain( $domain );

			if ( '' === $domain ) {
				continue;
			}

			if ( ! isset( $examples[ $domain ] ) || ! is_array( $examples[ $domain ] ) ) {
				$examples[ $domain ] = array();
			}

			foreach ( $urls as $url ) {
				$url = trim( (string) $url );

				if ( '' !== $url && count( $examples[ $domain ] ) < 3 && ! in_array( $url, $examples[ $domain ], true ) ) {
					$examples[ $domain ][] = $url;
				}
			}
		}

		ksort( $examples );

		return $examples;
	}

	/**
	 * Normalizes a URL host for first-party confirmation.
	 *
	 * @param string $domain Raw domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}

	/**
	 * Counts block comments in prepared markup.
	 *
	 * @param string $markup Block markup.
	 * @return int
	 */
	private function count_blocks( $markup ) {
		preg_match_all( '/<!--\s+wp:/', $markup, $matches );

		return count( $matches[0] );
	}

	/**
	 * Escapes text for simple generated HTML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Extracts normalized text from a small HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function html_text( $html ) {
		$text = preg_replace( '#<[^>]*>#', ' ', (string) $html );
		$text = html_entity_decode( is_string( $text ) ? $text : '', ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Records a source item processor event.
	 *
	 * @param ImportSession       $session Session.
	 * @param string              $type    Event type.
	 * @param string              $message Event message.
	 * @param ImportSourceItem    $item    Source item.
	 * @param array<string,mixed> $context Extra context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $type, $message, ImportSourceItem $item, array $context ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$this->event_level_for_type( $type ),
				$type,
				$message,
				array_merge(
					array(
						'item_key'      => $item->get_key(),
						'relative_path' => $item->get_relative_path(),
					),
					$context
				)
			)
		);
	}

	/**
	 * Chooses an event level from the processor event type.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private function event_level_for_type( $type ) {
		if ( false !== strpos( $type, '.failed' ) ) {
			return ImportProgressEvent::LEVEL_ERROR;
		}

		if ( false !== strpos( $type, '.skipped' ) ) {
			return ImportProgressEvent::LEVEL_WARNING;
		}

		return ImportProgressEvent::LEVEL_INFO;
	}
}
