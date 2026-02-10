<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin data-access layer.  All SQL lives here; the rest of the plugin
 * talks to the DB exclusively through these static helpers.
 */
class SL_DB {

	/**
	 * In-memory cache for active links count per URL.
	 * Prevents thousands of DB queries during matching.
	 * @var array|null  [ target_url => count ] or null if not loaded
	 */
	private static ?array $url_links_cache = null;

	/* ═══════════════════════════════════════════════════════════════
	 * LINKS (wp_semantic_links)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Insert a new link proposal.
	 *
	 * @param array $data  Keys: post_id, anchor_text, target_url,
	 *                            target_post_id, similarity_score
	 * @return int|false   Inserted ID, or false on failure.
	 */
	public static function insert_link( array $data ) {
		global $wpdb;

		// Validate required fields
		if ( empty( $data['post_id'] ) || empty( $data['anchor_text'] ) || empty( $data['target_url'] ) ) {
			return false;
		}

		// Sanitize inputs
		$post_id          = absint( $data['post_id'] );
		$anchor_text      = sanitize_text_field( $data['anchor_text'] );
		$target_url       = esc_url_raw( $data['target_url'] );
		$target_post_id   = absint( $data['target_post_id'] ?? 0 );
		$similarity_score = floatval( $data['similarity_score'] ?? 0 );

		// Ensure URL is valid
		if ( empty( $target_url ) ) {
			return false;
		}

		// Allow custom status (default: active)
		$status = sanitize_text_field( $data['status'] ?? 'active' );
		$allowed_statuses = [ 'active', 'rejected', 'filtered' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'active';
		}

		$ok = $wpdb->insert(
			$wpdb->prefix . 'semantic_links',
			[
				'post_id'          => $post_id,
				'anchor_text'      => $anchor_text,
				'target_url'       => $target_url,
				'target_post_id'   => $target_post_id,
				'similarity_score' => $similarity_score,
				'status'           => $status,
			],
			[ '%d', '%s', '%s', '%d', '%f', '%s' ]
		);

		if ( $ok ) {
			// Trigger cache invalidation for this post's injected content
			do_action( 'sl_link_changed', $post_id );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * All active links for a single post (used by the injector).
	 *
	 * @return object[]
	 */
	public static function get_links_for_post( int $post_id, string $status = 'active' ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND status = %s
				 ORDER BY similarity_score DESC",
				$post_id,
				$status
			)
		);
	}

	/**
	 * All links (optionally filtered by status), with source title
	 * joined from wp_posts.  Used by the admin dashboard.
	 * Excludes links where source or target post is trashed/deleted.
	 *
	 * @return object[]
	 */
	public static function get_all_links( string $status = '' ): array {
		global $wpdb;
		// Join source post (required), target post is optional (may be external URL)
		// Show links where source is published AND (target is published OR target_post_id is 0)
		$q = "SELECT sl.*, p.post_title AS source_title
		      FROM {$wpdb->prefix}semantic_links sl
		      LEFT JOIN {$wpdb->prefix}posts p ON sl.post_id = p.ID
		      LEFT JOIN {$wpdb->prefix}posts p2 ON sl.target_post_id = p2.ID
		      WHERE p.post_status = 'publish'
		        AND (sl.target_post_id = 0 OR p2.post_status = 'publish')";
		if ( $status !== '' ) {
			$q .= $wpdb->prepare( " AND sl.status = %s", $status );
		}
		$q .= " ORDER BY sl.created_at DESC";
		return $wpdb->get_results( $q );
	}

	/**
	 * Single link row by ID.
	 *
	 * @return object|null
	 */
	public static function get_link( int $link_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_links WHERE ID = %d",
				$link_id
			)
		);
	}

	/**
	 * Soft status change (active → rejected/filtered or vice versa).
	 * Only allows 'active', 'rejected', and 'filtered' statuses for security.
	 */
	public static function update_link_status( int $link_id, string $status ): bool {
		// Whitelist allowed statuses
		$allowed = [ 'active', 'rejected', 'filtered' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'semantic_links',
			[ 'status' => $status ],
			[ 'ID'     => $link_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Per-post deduplication check: does an *active* link to this URL
	 * already exist in this post?
	 */
	public static function link_exists_for_post( int $post_id, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND target_url = %s AND status = 'active'
				 LIMIT 1",
				$post_id,
				$target_url
			)
		);
	}

	/**
	 * Check if this anchor text is already used for a DIFFERENT URL in this post.
	 * Ensures one anchor context maps to exactly one URL.
	 */
	public static function anchor_used_for_different_url( int $post_id, string $anchor_text, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND anchor_text = %s AND target_url != %s AND status = 'active'
				 LIMIT 1",
				$post_id,
				$anchor_text,
				$target_url
			)
		);
	}

	/**
	 * Check if this anchor text is already used GLOBALLY (across all posts) for a DIFFERENT URL.
	 * Ensures one anchor = one URL across the entire site.
	 */
	public static function anchor_used_globally_for_different_url( string $anchor_text, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE anchor_text = %s AND target_url != %s AND status = 'active'
				 LIMIT 1",
				$anchor_text,
				$target_url
			)
		);
	}

	/**
	 * Get all unique active anchors with their target URLs (for global deduplication).
	 * Returns array of objects with anchor_text and target_url.
	 *
	 * @return object[]
	 */
	public static function get_all_active_anchors(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT DISTINCT anchor_text, target_url FROM {$wpdb->prefix}semantic_links
			 WHERE status = 'active'"
		);
	}

	/** How many active links does this post currently have? */
	public static function get_active_link_count( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND status = 'active'",
				$post_id
			)
		);
	}

	/**
	 * Preload URL links counts into cache (one query instead of thousands).
	 * Call this at the start of matching to avoid per-candidate DB queries.
	 */
	public static function preload_url_links_cache(): void {
		global $wpdb;

		self::$url_links_cache = [];

		$results = $wpdb->get_results(
			"SELECT target_url, COUNT(*) as cnt
			 FROM {$wpdb->prefix}semantic_links
			 WHERE status = 'active'
			 GROUP BY target_url"
		);

		foreach ( $results as $row ) {
			self::$url_links_cache[ $row->target_url ] = (int) $row->cnt;
		}
	}

	/**
	 * Reset the URL links cache (call when links are modified).
	 */
	public static function reset_url_links_cache(): void {
		self::$url_links_cache = null;
	}

	/**
	 * How many active links point to a specific target URL (cluster)?
	 * Uses cache if available for performance.
	 *
	 * @param string $target_url  The target URL to check.
	 * @return int  Count of active links to this URL.
	 */
	public static function get_active_links_to_url( string $target_url ): int {
		// Use cache if loaded
		if ( self::$url_links_cache !== null ) {
			return self::$url_links_cache[ $target_url ] ?? 0;
		}

		// Fallback to direct query
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links
				 WHERE target_url = %s AND status = 'active'",
				$target_url
			)
		);
	}

	/**
	 * Increment the cached count for a URL (after inserting a link).
	 * Only works if cache is loaded.
	 *
	 * @param string $target_url  The target URL.
	 */
	public static function increment_url_links_cache( string $target_url ): void {
		if ( self::$url_links_cache !== null ) {
			self::$url_links_cache[ $target_url ] = ( self::$url_links_cache[ $target_url ] ?? 0 ) + 1;
		}
	}

	/**
	 * Delete all links where this post is the SOURCE.
	 */
	public static function delete_links_by_source( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete all links where this post is the TARGET.
	 */
	public static function delete_links_by_target( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links WHERE target_post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete ALL links (used by "Delete all" admin action).
	 * Returns number of deleted rows.
	 */
	public static function delete_all_links(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_links" );
	}

	/**
	 * Delete blacklist entries for a post (source).
	 */
	public static function delete_blacklist_by_post( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links_blacklist WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete ALL blacklist entries.
	 */
	public static function delete_all_blacklist(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_links_blacklist" );
	}

	/* ═══════════════════════════════════════════════════════════════
	 * BLACKLIST (wp_semantic_links_blacklist)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Add a (post, target URL) pair to the permanent blacklist.
	 * Silently ignores duplicates.
	 *
	 * Note: The blacklist works at URL level, not anchor level.
	 * This means if you reject "kredyt hipoteczny" → URL A,
	 * then "kredyty hipoteczne" → URL A is also blocked.
	 * The anchor_text is stored for debugging/reference only.
	 *
	 * @param int    $post_id     Source post ID
	 * @param string $anchor_text Anchor text (stored as metadata, not used in check)
	 * @param string $target_url  Target URL to blacklist
	 */
	public static function add_to_blacklist( int $post_id, string $anchor_text, string $target_url ): void {
		global $wpdb;

		// Sanitize inputs
		$post_id     = absint( $post_id );
		$anchor_text = sanitize_text_field( $anchor_text );
		$target_url  = esc_url_raw( $target_url );

		if ( $post_id < 1 || empty( $target_url ) ) {
			return;
		}

		// Check at URL level - anchor is ignored for deduplication
		$already = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s LIMIT 1",
				$post_id,
				$target_url
			)
		);
		if ( $already ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'semantic_links_blacklist',
			[
				'post_id'     => $post_id,
				'anchor_text' => $anchor_text,  // Stored for reference/debugging
				'target_url'  => $target_url,
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Check if a (post, URL) pair is blacklisted.
	 *
	 * Note: Check is at URL level only. Anchor text is not considered.
	 * This means rejecting ANY anchor to a URL blocks ALL anchors to that URL.
	 *
	 * @param int    $post_id    Source post ID
	 * @param string $target_url Target URL to check
	 * @return bool  True if blacklisted
	 */
	public static function is_blacklisted( int $post_id, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s LIMIT 1",
				$post_id,
				$target_url
			)
		);
	}

	/**
	 * Remove a specific entry from the blacklist (for restoring links).
	 */
	public static function remove_from_blacklist( int $post_id, string $target_url ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s",
				$post_id,
				$target_url
			)
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	 * EMBEDDINGS (wp_semantic_embeddings)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Write one embedding row.  Removes any previous row with the
	 * same (post_id, chunk_index) first.
	 *
	 * @param int    $post_id
	 * @param int    $chunk_index
	 * @param string $chunk_text
	 * @param array  $embedding
	 * @param string $content_hash
	 * @return bool  True on success, false on failure.
	 */
	public static function upsert_embedding( int $post_id, int $chunk_index, string $chunk_text, array $embedding, string $content_hash ): bool {
		global $wpdb;

		// Delete existing row first (if any)
		$delete_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d AND chunk_index = %d",
				$post_id,
				$chunk_index
			)
		);

		// Check for query error (false = error, 0 = no rows deleted is OK)
		if ( $delete_result === false ) {
			SL_Debug::log( 'db', 'ERROR: Failed to delete existing embedding', [
				'post_id'     => $post_id,
				'chunk_index' => $chunk_index,
				'db_error'    => $wpdb->last_error,
			] );
			return false;
		}

		// Insert new row
		$insert_result = $wpdb->insert(
			$wpdb->prefix . 'semantic_embeddings',
			[
				'post_id'      => $post_id,
				'chunk_index'  => $chunk_index,
				'chunk_text'   => $chunk_text,
				'embedding'    => json_encode( $embedding ),
				'content_hash' => $content_hash,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( $insert_result === false ) {
			SL_Debug::log( 'db', 'ERROR: Failed to insert embedding', [
				'post_id'     => $post_id,
				'chunk_index' => $chunk_index,
				'db_error'    => $wpdb->last_error,
			] );
			return false;
		}

		return true;
	}

	/** Delete every embedding row for a post (before re-indexing). */
	public static function delete_embeddings( int $post_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'semantic_embeddings',
			[ 'post_id' => $post_id ],
			[ '%d' ]
		);
	}

	/**
	 * All embedding rows for one post, ordered by chunk_index.
	 * JSON `embedding` column is decoded into a PHP float array.
	 *
	 * @return object[]
	 */
	public static function get_embeddings( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d ORDER BY chunk_index ASC",
				$post_id
			)
		);
		foreach ( $rows as $row ) {
			$row->embedding = json_decode( $row->embedding, true );
		}
		return $rows;
	}

	/**
	 * Title embeddings across ALL posts (chunk_index = 0).
	 * This is the "target set" for the matcher.
	 *
	 * @return object[]
	 */
	public static function get_title_embeddings(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}semantic_embeddings WHERE chunk_index = 0"
		);
		foreach ( $rows as $row ) {
			$row->embedding = json_decode( $row->embedding, true );
		}
		return $rows;
	}

	/**
	 * Quick staleness check: does a row exist for this post with the
	 * expected content_hash?  If yes the post has not changed since
	 * the last embedding run.
	 */
	public static function embeddings_are_current( int $post_id, string $content_hash ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d AND content_hash = %s LIMIT 1",
				$post_id,
				$content_hash
			)
		);
	}

	/**
	 * Delete ALL embeddings (used by "Delete all" admin action for full reset).
	 * Returns number of deleted rows.
	 */
	public static function delete_all_embeddings(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_embeddings" );
	}

	/* ═══════════════════════════════════════════════════════════════
	 * CUSTOM URLS (wp_semantic_custom_urls)
	 * ═══════════════════════════════════════════════════════════════ */

	/** Maximum number of custom URLs allowed. */
	public const MAX_CUSTOM_URLS = 100;

	/**
	 * Get the max custom URLs limit.
	 */
	public static function get_max_custom_urls(): int {
		return self::MAX_CUSTOM_URLS;
	}

	/**
	 * Insert a new custom URL.
	 *
	 * @param array $data Keys: url, title, keywords (optional)
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert_custom_url( array $data ) {
		global $wpdb;

		// Check limit
		if ( self::get_custom_url_count() >= self::MAX_CUSTOM_URLS ) {
			return false;
		}

		// Validate required fields
		if ( empty( $data['url'] ) || empty( $data['title'] ) ) {
			return false;
		}

		$url      = esc_url_raw( $data['url'] );
		$title    = sanitize_text_field( $data['title'] );
		$keywords = sanitize_textarea_field( $data['keywords'] ?? '' );

		// Validate URL format
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Check for duplicate URL
		if ( self::custom_url_exists( $url ) ) {
			return false;
		}

		$ok = $wpdb->insert(
			$wpdb->prefix . 'semantic_custom_urls',
			[
				'url'      => $url,
				'title'    => $title,
				'keywords' => $keywords,
				'status'   => 'active',
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return $ok ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing custom URL.
	 *
	 * @param int   $id   Custom URL ID.
	 * @param array $data Keys: url, title, keywords.
	 * @return bool True on success.
	 */
	public static function update_custom_url( int $id, array $data ): bool {
		global $wpdb;

		$update = [];
		$format = [];

		if ( isset( $data['url'] ) ) {
			$url = esc_url_raw( $data['url'] );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return false;
			}
			// Check if URL is used by another entry
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->prefix}semantic_custom_urls WHERE url = %s AND ID != %d",
					$url,
					$id
				)
			);
			if ( $existing ) {
				return false;
			}
			$update['url'] = $url;
			$format[] = '%s';
		}

		if ( isset( $data['title'] ) ) {
			$update['title'] = sanitize_text_field( $data['title'] );
			$format[] = '%s';
		}

		if ( isset( $data['keywords'] ) ) {
			$update['keywords'] = sanitize_textarea_field( $data['keywords'] );
			$format[] = '%s';
		}

		// Clear embedding if content changed (will be regenerated)
		if ( isset( $data['title'] ) || isset( $data['keywords'] ) ) {
			$update['embedding'] = null;
			$format[] = '%s';
		}

		if ( empty( $update ) ) {
			return true;
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'semantic_custom_urls',
			$update,
			[ 'ID' => $id ],
			$format,
			[ '%d' ]
		);
	}

	/**
	 * Delete a custom URL.
	 *
	 * @param int $id Custom URL ID.
	 * @return bool True on success.
	 */
	public static function delete_custom_url( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'semantic_custom_urls',
			[ 'ID' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Get a single custom URL by ID.
	 *
	 * @param int $id Custom URL ID.
	 * @return object|null
	 */
	public static function get_custom_url( int $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_custom_urls WHERE ID = %d",
				$id
			)
		);
	}

	/**
	 * Get all custom URLs.
	 *
	 * @param string $status Optional status filter.
	 * @return object[]
	 */
	public static function get_all_custom_urls( string $status = '' ): array {
		global $wpdb;
		$q = "SELECT * FROM {$wpdb->prefix}semantic_custom_urls";
		if ( $status !== '' ) {
			$q .= $wpdb->prepare( " WHERE status = %s", $status );
		}
		$q .= " ORDER BY created_at DESC";
		return $wpdb->get_results( $q );
	}

	/**
	 * Count custom URLs.
	 *
	 * @return int
	 */
	public static function get_custom_url_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_custom_urls"
		);
	}

	/**
	 * Check if a URL already exists.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function custom_url_exists( string $url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_custom_urls WHERE url = %s LIMIT 1",
				$url
			)
		);
	}

	/**
	 * Get custom URLs that need embedding generation.
	 *
	 * @return object[]
	 */
	public static function get_custom_urls_needing_embedding(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}semantic_custom_urls
			 WHERE embedding IS NULL AND status = 'active'"
		);
	}

	/**
	 * Update embedding for a custom URL.
	 *
	 * @param int   $id        Custom URL ID.
	 * @param array $embedding Embedding vector.
	 * @return bool
	 */
	public static function update_custom_url_embedding( int $id, array $embedding ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'semantic_custom_urls',
			[ 'embedding' => json_encode( $embedding ) ],
			[ 'ID' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Get all custom URL embeddings (for matcher).
	 *
	 * @return object[] Objects with ID, url, title, keywords, embedding (decoded)
	 */
	public static function get_custom_url_embeddings(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ID, url, title, keywords, embedding
			 FROM {$wpdb->prefix}semantic_custom_urls
			 WHERE status = 'active' AND embedding IS NOT NULL"
		);
		foreach ( $rows as $row ) {
			$row->embedding = json_decode( $row->embedding, true );
		}
		return $rows;
	}
}
