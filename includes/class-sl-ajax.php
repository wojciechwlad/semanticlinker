<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoints consumed by the admin dashboard and settings page.
 *
 * Endpoints
 * ─────────
 *   sl_reject_link       – reject a link + add (post, URL) to the
 *                          blacklist + flush the injection cache.
 *   sl_trigger_indexing  – run the full index → match pipeline
 *                          synchronously so the admin sees results
 *                          immediately.  (For very large sites
 *                          consider replacing with Action Scheduler.)
 */
class SL_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_sl_reject_link',      [ $this, 'reject_link' ] );
		add_action( 'wp_ajax_sl_restore_link',     [ $this, 'restore_link' ] );
		add_action( 'wp_ajax_sl_trigger_indexing', [ $this, 'trigger_indexing' ] );
		add_action( 'wp_ajax_sl_start_indexing',   [ $this, 'start_indexing' ] );
		add_action( 'wp_ajax_sl_process_batch',    [ $this, 'process_batch' ] );
		add_action( 'wp_ajax_sl_delete_all_links', [ $this, 'delete_all_links' ] );
		add_action( 'wp_ajax_sl_cancel_indexing',  [ $this, 'cancel_indexing' ] );
		add_action( 'wp_ajax_sl_get_debug',        [ $this, 'get_debug' ] );
		add_action( 'wp_ajax_sl_clear_debug',      [ $this, 'clear_debug' ] );
		add_action( 'wp_ajax_sl_fix_tables',       [ $this, 'fix_tables' ] );

		// Custom URLs CRUD
		add_action( 'wp_ajax_sl_add_custom_url',    [ $this, 'add_custom_url' ] );
		add_action( 'wp_ajax_sl_update_custom_url', [ $this, 'update_custom_url' ] );
		add_action( 'wp_ajax_sl_delete_custom_url', [ $this, 'delete_custom_url' ] );
		add_action( 'wp_ajax_sl_get_custom_urls',   [ $this, 'get_custom_urls' ] );
		add_action( 'wp_ajax_sl_save_custom_url_threshold', [ $this, 'save_custom_url_threshold' ] );
	}

	/* ── Reject / blacklist ─────────────────────────────────────── */

	public function reject_link(): void {
		$this->verify();

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		if ( $link_id < 1 ) {
			wp_send_json_error( 'Nieprawidłowy identyfikator linku.' );
		}

		$link = SL_DB::get_link( $link_id );
		if ( ! $link ) {
			wp_send_json_error( 'Link nie znaleziony.' );
		}

		/* 1. Permanently blacklist this (post, target URL) pair */
		SL_DB::add_to_blacklist( $link->post_id, $link->anchor_text, $link->target_url );

		/* 2. Soft-delete: keep the row for the audit trail */
		SL_DB::update_link_status( $link_id, 'rejected' );

		/* 3. Flush cached injected HTML so the link disappears
		 *    from the frontend on next page view */
		do_action( 'sl_link_changed', (int) $link->post_id );

		wp_send_json_success( [ 'message' => 'Link odrzucony i dodany do blacklisty.' ] );
	}

	/* ── Restore link ──────────────────────────────────────────── */

	public function restore_link(): void {
		$this->verify();

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		if ( $link_id < 1 ) {
			wp_send_json_error( 'Nieprawidłowy identyfikator linku.' );
		}

		$link = SL_DB::get_link( $link_id );
		if ( ! $link ) {
			wp_send_json_error( 'Link nie znaleziony.' );
		}

		/* 1. Remove from blacklist */
		SL_DB::remove_from_blacklist( $link->post_id, $link->target_url );

		/* 2. Restore status to active */
		SL_DB::update_link_status( $link_id, 'active' );

		/* 3. Flush cache */
		do_action( 'sl_link_changed', (int) $link->post_id );

		wp_send_json_success( [ 'message' => 'Link przywrócony.' ] );
	}

	/* ── Trigger indexing (legacy synchronous) ────────────────────── */

	public function trigger_indexing(): void {
		$this->verify();

		( new SL_Indexer() )->run();

		wp_send_json_success( [ 'message' => 'Indeksacja i matching zakończone.' ] );
	}

	/* ── Batch indexing with progress ─────────────────────────────── */

	/**
	 * Start batch indexing - returns total posts to process.
	 */
	public function start_indexing(): void {
		$this->verify();
		SL_Debug::register_shutdown_handler();

		try {
			$result = SL_Indexer::init_batch();

			if ( isset( $result['error'] ) ) {
				wp_send_json_error( $result['error'] );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			SL_Debug::log( 'error', 'Exception in start_indexing: ' . $e->getMessage(), [
				'file'  => str_replace( ABSPATH, '', $e->getFile() ),
				'line'  => $e->getLine(),
			] );
			wp_send_json_error( 'Błąd PHP: ' . $e->getMessage() . ' — sprawdź Debug Logs.' );
		}
	}

	/**
	 * Process one batch of posts.
	 */
	public function process_batch(): void {
		$this->verify();
		SL_Debug::register_shutdown_handler();

		try {
			$result = SL_Indexer::process_batch();

			if ( isset( $result['error'] ) ) {
				wp_send_json_error( $result['error'] );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			SL_Debug::log( 'error', 'Exception in process_batch: ' . $e->getMessage(), [
				'file'  => str_replace( ABSPATH, '', $e->getFile() ),
				'line'  => $e->getLine(),
			] );
			wp_send_json_error( 'Błąd PHP: ' . $e->getMessage() . ' — sprawdź Debug Logs.' );
		}
	}

	/* ── Delete all links ──────────────────────────────────────── */

	public function delete_all_links(): void {
		$this->verify();

		$deleted_links     = SL_DB::delete_all_links();
		$deleted_blacklist = SL_DB::delete_all_blacklist();
		$deleted_embeddings = SL_DB::delete_all_embeddings();

		// Clear all injector caches (links were deleted, cached HTML is stale)
		SL_Injector::flush_all_caches();

		// Clear any in-progress indexing/matching sessions
		SL_Indexer::cancel();
		SL_Matcher::cancel();

		// Reschedule cron to run in 1 hour (not immediately)
		wp_clear_scheduled_hook( 'sl_run_indexing' );
		if ( SL_Settings::get( 'cron_enabled', false ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sl_run_indexing' );
		}

		wp_send_json_success( [
			'message' => sprintf(
				'Usunięto %d linków, %d wpisów blacklisty i %d embeddingów. Indeksacja zresetowana.',
				$deleted_links,
				$deleted_blacklist,
				$deleted_embeddings
			),
		] );
	}

	/* ── Debug endpoints ───────────────────────────────────────── */

	public function get_debug(): void {
		$this->verify();

		try {
			$state = SL_Debug::get_state_summary();
			$logs  = SL_Debug::get_logs();

			wp_send_json_success( [
				'logs'  => $logs,
				'state' => $state,
			] );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Błąd PHP: ' . $e->getMessage() );
		} catch ( \Error $e ) {
			wp_send_json_error( 'Błąd krytyczny PHP: ' . $e->getMessage() );
		}
	}

	public function clear_debug(): void {
		$this->verify();

		SL_Debug::clear();
		wp_send_json_success( [ 'message' => 'Logi wyczyszczone.' ] );
	}

	public function fix_tables(): void {
		$this->verify();

		SL_Debug::ensure_tables();
		$tables = SL_Debug::check_tables();

		wp_send_json_success( [
			'message' => 'Tabele utworzone/naprawione.',
			'tables'  => $tables,
		] );
	}

	/* ── Cancel indexing ────────────────────────────────────────── */

	/**
	 * Cancel ongoing indexing/matching process.
	 */
	public function cancel_indexing(): void {
		$this->verify();

		SL_Indexer::cancel( true );  // Also cancels matcher

		SL_Debug::log( 'ajax', 'Indexing cancelled by user' );

		wp_send_json_success( [ 'message' => 'Proces anulowany.' ] );
	}

	/* ── Custom URLs CRUD ──────────────────────────────────────── */

	/**
	 * Add a new custom URL.
	 */
	public function add_custom_url(): void {
		$this->verify();

		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$keywords = isset( $_POST['keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['keywords'] ) ) : '';

		if ( empty( $url ) || empty( $title ) ) {
			wp_send_json_error( 'URL i tytuł są wymagane.' );
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( 'Nieprawidłowy format URL.' );
		}

		if ( SL_DB::custom_url_exists( $url ) ) {
			wp_send_json_error( 'Ten URL już istnieje.' );
		}

		$count = SL_DB::get_custom_url_count();
		if ( $count >= SL_DB::MAX_CUSTOM_URLS ) {
			wp_send_json_error( 'Osiągnięto limit ' . SL_DB::MAX_CUSTOM_URLS . ' URL-i.' );
		}

		$id = SL_DB::insert_custom_url( [
			'url'      => $url,
			'title'    => $title,
			'keywords' => $keywords,
		] );

		if ( ! $id ) {
			wp_send_json_error( 'Nie udało się dodać URL.' );
		}

		// Generate embedding for the new custom URL
		$this->generate_custom_url_embedding( $id, $title, $keywords );

		wp_send_json_success( [
			'message' => 'URL dodany.',
			'id'      => $id,
			'count'   => SL_DB::get_custom_url_count(),
		] );
	}

	/**
	 * Update an existing custom URL.
	 */
	public function update_custom_url(): void {
		$this->verify();

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$keywords = isset( $_POST['keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['keywords'] ) ) : '';

		if ( $id < 1 ) {
			wp_send_json_error( 'Nieprawidłowy identyfikator.' );
		}

		if ( empty( $url ) || empty( $title ) ) {
			wp_send_json_error( 'URL i tytuł są wymagane.' );
		}

		$existing = SL_DB::get_custom_url( $id );
		if ( ! $existing ) {
			wp_send_json_error( 'URL nie znaleziony.' );
		}

		$ok = SL_DB::update_custom_url( $id, [
			'url'      => $url,
			'title'    => $title,
			'keywords' => $keywords,
		] );

		if ( ! $ok ) {
			wp_send_json_error( 'Nie udało się zaktualizować URL (może istnieje duplikat).' );
		}

		// Regenerate embedding if title or keywords changed
		if ( $existing->title !== $title || $existing->keywords !== $keywords ) {
			$this->generate_custom_url_embedding( $id, $title, $keywords );
		}

		wp_send_json_success( [ 'message' => 'URL zaktualizowany.' ] );
	}

	/**
	 * Delete a custom URL.
	 */
	public function delete_custom_url(): void {
		$this->verify();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( $id < 1 ) {
			wp_send_json_error( 'Nieprawidłowy identyfikator.' );
		}

		$ok = SL_DB::delete_custom_url( $id );

		if ( ! $ok ) {
			wp_send_json_error( 'Nie udało się usunąć URL.' );
		}

		wp_send_json_success( [
			'message' => 'URL usunięty.',
			'count'   => SL_DB::get_custom_url_count(),
		] );
	}

	/**
	 * Get all custom URLs (for AJAX refresh).
	 */
	public function get_custom_urls(): void {
		$this->verify();

		$urls = SL_DB::get_all_custom_urls();

		wp_send_json_success( [
			'urls'  => $urls,
			'count' => count( $urls ),
			'max'   => SL_DB::MAX_CUSTOM_URLS,
		] );
	}

	/**
	 * Save custom URL similarity threshold.
	 */
	public function save_custom_url_threshold(): void {
		$this->verify();

		$threshold = isset( $_POST['threshold'] ) ? (float) $_POST['threshold'] : 0.5;

		// Clamp to valid range [0.20 … 0.90]
		$threshold = max( 0.20, min( 0.90, $threshold ) );

		// Get current settings and update
		$settings = get_option( SL_Settings::OPTION_KEY, [] );
		$settings['custom_url_threshold'] = $threshold;
		update_option( SL_Settings::OPTION_KEY, $settings );

		wp_send_json_success( [
			'message'   => 'Próg zapisany.',
			'threshold' => $threshold,
		] );
	}

	/**
	 * Generate embedding for a custom URL.
	 *
	 * @param int    $id       Custom URL ID.
	 * @param string $title    Title text.
	 * @param string $keywords Keywords text.
	 */
	private function generate_custom_url_embedding( int $id, string $title, string $keywords ): void {
		$text = $title;
		if ( ! empty( $keywords ) ) {
			$text .= ' ' . $keywords;
		}

		$api       = new SL_Embedding_API();
		$embedding = $api->embed_single( $text );

		if ( $embedding ) {
			SL_DB::update_custom_url_embedding( $id, $embedding );
		}
	}

	/* ── Guard ──────────────────────────────────────────────────── */

	/**
	 * Verify capability + nonce.  Calls wp_send_json_error (which
	 * exits) if either check fails.
	 */
	private function verify(): void {
		if (
			! current_user_can( 'manage_options' )
			|| ! wp_verify_nonce( $_POST['nonce'] ?? '', 'sl_ajax_nonce' )
		) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
	}
}
