<?php
/**
 * Automatically observe existing site lead persistence and its established AJAX dispatch.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded exact source/CID lookups reuse existing metadata without new tables.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Track only source leads first created in this request; read their final metadata later.
 *
 * @param int      $id Native lead ID.
 * @param \WP_Post $post Source post.
 * @param bool     $update Whether this is an existing post update.
 * @return void
 */
function native_collect( $id, $post, $update ) {
	if ( 'leads' !== $post->post_type ) {
		return;
	}
	if ( ! $update ) {
		$GLOBALS['lcrm_native_new_leads'][ $id ] = true;
		capture_retry_native_mark( $id, $post );
	}
	if ( 'publish' === $post->post_status && ! empty( $GLOBALS['lcrm_native_new_leads'][ $id ] ) ) {
		$GLOBALS['lcrm_native_queue'][ $id ] = true;
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\native_collect', 20, 3 );

add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'leads' === $post->post_type && ( ! empty( $GLOBALS['lcrm_native_new_leads'][ $post->ID ] ) || is_array( capture_retry_native_read( $post->ID ) ) ) ) {
			$GLOBALS['lcrm_native_queue'][ $post->ID ] = true;
		}
	},
	20,
	3
);

/** Public source types that may change an exact recipient mapping. */
const NATIVE_CATALOG_TYPES = array( 'institutions', 'study', 'courses', 'course', 'routes', 'route', 'page' );

/**
 * Identify the current site's request-local catalog without mixing switched blogs.
 *
 * @return string Site-specific request cache key.
 */
function native_catalog_scope() {
	global $wpdb;
	return get_current_blog_id() . ':' . $wpdb->prefix;
}

/**
 * Build a bounded transient name from a catalog revision.
 *
 * @param string $revision Revision created by the server.
 * @return string Blog-scoped transient name.
 */
function native_catalog_key( $revision ) {
	return 'lcrm_catalog_v1_' . md5( $revision );
}

/**
 * Validate the compact cache shape without trusting arbitrary cached data.
 *
 * @param mixed  $cached Transient payload.
 * @param string $revision Expected source revision.
 * @return bool Whether the cached title map is usable.
 */
function native_catalog_valid( $cached, $revision ) {
	if ( ! is_array( $cached ) || array( 'revision', 'titles' ) !== array_keys( $cached ) || $revision !== $cached['revision'] || ! is_array( $cached['titles'] ) || count( $cached['titles'] ) > 20000 ) {
		return false;
	}
	$size = 0;
	foreach ( $cached['titles'] as $name => $ids ) {
		if ( ( ! is_string( $name ) && ! is_int( $name ) ) || '' === (string) $name || strlen( (string) $name ) > 4096 || ! is_array( $ids ) || count( $ids ) > 1000 || array_values( $ids ) !== $ids ) {
			return false;
		}
		$size += strlen( (string) $name ) + 32 * ( count( $ids ) + 1 );
		if ( $size > 2 * MB_IN_BYTES ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Invalidate the catalog after a relevant post has changed or been deleted.
 *
 * @param int           $id Changed post ID, or zero for explicit invalidation.
 * @param \WP_Post|null $post Supplied post object, including after deletion.
 * @return void
 */
function native_catalog_invalidate( $id = 0, $post = null ) {
	if ( $id && ! in_array( $post instanceof \WP_Post ? $post->post_type : get_post_type( $id ), NATIVE_CATALOG_TYPES, true ) ) {
		return;
	}
	$scope = native_catalog_scope();
	unset( $GLOBALS['lcrm_native_catalog_cache'][ $scope ] );
	$GLOBALS['lcrm_native_catalog_dirty'][ $scope ] = true;
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) ) {
		native_catalog_commit();
	}
}

/**
 * Rotate the revision only after catalog edits have committed.
 *
 * @return void
 */
function native_catalog_commit() {
	$scope = native_catalog_scope();
	if ( empty( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ] ) ) {
		return;
	}
	$old      = get_option( 'lcrm_catalog_revision', '0' );
	$revision = str_replace( '-', '', wp_generate_uuid4() );
	if ( is_string( $old ) ) {
		delete_transient( native_catalog_key( $old ) );
	}
	update_option( 'lcrm_catalog_revision', $revision, false );
	if ( get_option( 'lcrm_catalog_revision' ) !== $revision ) {
		return; // A cache failure must not turn an already committed financial mutation into an error.
	}
	unset( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ], $GLOBALS['lcrm_native_catalog_cache'][ $scope ] );
}
add_action( 'lcrm_committed', __NAMESPACE__ . '\\native_catalog_commit' );
add_action( 'save_post', __NAMESPACE__ . '\\native_catalog_invalidate', 20, 2 );
add_action( 'deleted_post', __NAMESPACE__ . '\\native_catalog_invalidate', 20, 2 );
add_action(
	'transition_post_status',
	static function ( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status ) {
			native_catalog_invalidate( $post->ID, $post );
		}
	},
	20,
	3
);

/**
 * JetEngine/ACF may persist course relationships after save_post has fired.
 *
 * @param int|array $meta_id Changed metadata ID(s).
 * @param int       $id Source post ID.
 * @param string    $key Changed metadata key.
 * @return void
 */
function native_catalog_meta_changed( $meta_id, $id, $key ) {
	if ( ! in_array( $key, array( 'mosad', 'institution', 'institution_acf' ), true ) ) {
		return;
	}
	$type = get_post_type( $id );
	if ( ( 'study' === $type && 'mosad' === $key ) || ( in_array( $type, array( 'course', 'courses' ), true ) && in_array( $key, array( 'institution', 'institution_acf' ), true ) ) ) {
		native_catalog_invalidate( $id );
	}
}
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $catalog_meta_hook ) {
	add_action( $catalog_meta_hook, __NAMESPACE__ . '\\native_catalog_meta_changed', 20, 3 );
}

/**
 * Read only relationship metadata instead of hydrating every public post's metadata.
 *
 * @param array $posts Source catalog posts.
 * @return array Parent values grouped by source ID and metadata key.
 * @throws \RuntimeException When a relationship query fails.
 */
function native_catalog_relationships( $posts ) {
	global $wpdb;
	$ids = array();
	foreach ( $posts as $post ) {
		if ( in_array( $post->post_type, array( 'study', 'courses', 'course' ), true ) ) {
			$ids[] = (int) $post->ID;
		}
	}
	$relationships = array();
	foreach ( array_chunk( $ids, 500 ) as $batch ) {
		$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
		$queries      = $wpdb->num_queries;
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key IN (%s, %s, %s)", array_merge( $batch, array( 'mosad', 'institution_acf', 'institution' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Cached catalog read; all IN values are prepared, with a bounded generated placeholder list.
		if ( $wpdb->num_queries <= $queries || ! is_array( $rows ) || $wpdb->last_error ) {
			throw new \RuntimeException( 'Source relationship could not be read.' );
		}
		foreach ( $rows as $row ) {
			if ( ! isset( $row['post_id'], $row['meta_key'], $row['meta_value'] ) || ! in_array( (int) $row['post_id'], $batch, true ) || ! in_array( $row['meta_key'], array( 'mosad', 'institution_acf', 'institution' ), true ) ) {
				throw new \RuntimeException( 'Invalid source relationship.' );
			}
			$relationships[ (int) $row['post_id'] ][ $row['meta_key'] ][] = maybe_unserialize( $row['meta_value'] );
		}
	}
	return $relationships;
}

/**
 * Reuse an exact catalog across requests and keep contact data out of its cache.
 *
 * @return array Unique and ambiguous title mappings.
 */
function native_titles() {
	$scope = native_catalog_scope();
	if ( ! empty( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ] ) && ! isset( $GLOBALS['lcrm_changed_records'] ) ) {
		native_catalog_commit();
	}
	if ( empty( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ] ) && isset( $GLOBALS['lcrm_native_catalog_cache'][ $scope ] ) ) {
		return $GLOBALS['lcrm_native_catalog_cache'][ $scope ];
	}
	$revision = get_option( 'lcrm_catalog_revision', '0' );
	$revision = is_string( $revision ) && preg_match( '/^(?:0|[a-f0-9]{32})$/D', $revision ) ? $revision : '';
	if ( '' !== $revision && empty( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ] ) ) {
		$cached = get_transient( native_catalog_key( $revision ) );
		if ( native_catalog_valid( $cached, $revision ) ) {
			$GLOBALS['lcrm_native_catalog_cache'][ $scope ] = $cached['titles'];
			return $cached['titles'];
		}
	}
	$titles = native_catalog_build();
	// A transaction may contain an uncommitted catalog edit. Never publish that view.
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) && empty( $GLOBALS['lcrm_native_catalog_dirty'][ $scope ] ) && '' !== $revision ) {
		$cached = array(
			'revision' => $revision,
			'titles'   => $titles,
		);
		if ( native_catalog_valid( $cached, $revision ) ) {
			set_transient( native_catalog_key( $revision ), $cached, HOUR_IN_SECONDS );
			$GLOBALS['lcrm_native_catalog_cache'][ $scope ] = $titles;
		}
	}
	return $titles;
}

/**
 * Build exact public source names and unambiguous study-to-institution relationships.
 *
 * @return array Unique and ambiguous title mappings.
 * @throws \RuntimeException When a catalog or relationship query fails.
 */
function native_catalog_build() {
	global $wpdb;
	$titles  = array();
	$direct  = array();
	$schools = array();
	$blocked = array();
	$courses = array();
	$queries = $wpdb->num_queries;
	$posts   = get_posts(
		array(
			'cache_results'          => false,
			'post_type'              => NATIVE_CATALOG_TYPES,
			'post_status'            => array( 'publish', 'draft' ),
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		)
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $posts ) ) {
		throw new \RuntimeException( 'Source catalog could not be read.' );
	}
	$relationships = native_catalog_relationships( $posts );
	foreach ( $posts as $post ) {
		if ( 'institutions' !== $post->post_type ) {
			continue;
		}
		$schools[ $post->ID ] = true;
		$name                 = source_name( $post->post_title );
		if ( '' !== $name ) {
			$titles[ $name ][] = $post->ID;
			$direct[ $name ][] = $post->ID;
		}
		// A saved public permalink identifies a former spelling without guessing articles or similar names.
		$alias = source_name( str_replace( '-', ' ', rawurldecode( $post->post_name ) ) );
		if ( preg_match( '/\p{L}/u', $alias ) && false === strpbrk( $alias, '/\\?#' ) ) {
			$titles[ $alias ][] = $post->ID;
		}
	}
	foreach ( $posts as $post ) {
		$name = source_name( $post->post_title );
		if ( '' === $name || 'institutions' === $post->post_type ) {
			continue;
		}
		if ( in_array( $post->post_type, array( 'routes', 'route', 'page' ), true ) ) {
			$blocked[ $name ] = true;
			continue;
		}
		$keys    = 'study' === $post->post_type ? array( 'mosad' ) : array( 'institution_acf', 'institution' );
		$parents = array();
		$valid   = true;
		foreach ( $keys as $key ) {
			$values = $relationships[ $post->ID ][ $key ] ?? array();
			foreach ( $values as $value ) {
				if ( '' === $value ) {
					continue;
				}
				if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! preg_match( '/^[1-9]\d*$/D', (string) $value ) || ! isset( $schools[ (int) $value ] ) ) {
					$valid = false;
					continue;
				}
				$parents[] = (int) $value;
			}
		}
		$parents            = array_values( array_unique( $parents ) );
		$courses[ $name ][] = $valid && 1 === count( $parents ) ? $parents[0] : 0;
	}
	foreach ( $titles as &$ids ) {
		$ids = array_values( array_unique( $ids ) );
	}
	unset( $ids );
	foreach ( $direct as $name => $ids ) {
		// Explicit institution names retain their authority over inferred aliases and course names.
		$titles[ $name ] = array_values( array_unique( $ids ) );
	}
	foreach ( $blocked as $name => $unused ) {
		if ( ! isset( $direct[ $name ] ) ) {
			$titles[ $name ] = array();
		}
	}
	foreach ( $courses as $name => $parents ) {
		if ( isset( $direct[ $name ] ) || ctype_digit( (string) $name ) ) {
			continue;
		}
		$parents = array_values( array_unique( $parents ) );
		if ( isset( $blocked[ $name ] ) || in_array( 0, $parents, true ) || 1 !== count( $parents ) ) {
			$titles[ $name ] = array();
			continue;
		}
		$titles[ $name ] = array_values( array_unique( array_merge( $titles[ $name ] ?? array(), $parents ) ) );
	}
	return $titles;
}

/**
 * Notify integration failures without logging contacts or source identifiers.
 *
 * @param \WP_Error $result Failed operation.
 * @param int       $institution Recipient ID, or zero for unresolved records.
 * @param string    $source Private idempotency source shared only with trusted PHP listeners.
 * @return void
 */
function native_report_error( $result, $institution, $source ) {
	$details = $result->get_error_data();
	audit( 'native_capture_error', $institution, array( 'status' => is_array( $details ) ? ( $details['status'] ?? 500 ) : 500 ) );
	do_action( 'limu_crm_delivery_error', $result, $institution, $source );
}

/**
 * Persist one newly observed source lead; old-dated records remain historical.
 *
 * @param int $id Existing native lead post ID.
 * @return array|\WP_Error Result without copied contact records.
 */
function native_capture( $id ) {
	$automatic = capture_case_auto( $id );
	if ( is_wp_error( $automatic ) || ! $automatic ) {
		return is_wp_error( $automatic ) ? $automatic : capture_retry_failure( 'manual_review', 'הפנייה ממתינה להחלטת מנהל.' );
	}
	$post = capture_retry_source( $id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! $post || 'leads' !== $post['post_type'] || 'publish' !== $post['post_status'] ) {
		return error( 'מקור פנייה לא תקין.' );
	}
	$marker = capture_retry_native_read( $id );
	if ( is_wp_error( $marker ) ) {
		return $marker;
	}
	if ( is_array( $marker ) && ( ! isset( $marker['snapshot'], $marker['destinations'] ) || ! empty( $marker['boundary_pending'] ) ) ) {
		$marker = capture_retry_native_prepare( $id );
		if ( is_wp_error( $marker ) ) {
			return $marker;
		}
	}
	$lead = array(
		'date'      => is_array( $marker ) ? $marker['date'] : $post['post_date'],
		'contact'   => (int) $id,
		'legacy_id' => (int) $id,
	);
	foreach ( array(
		'name'  => 'full-name',
		'phone' => 'phone',
		'email' => 'email',
		'form'  => 'form-name',
	) as $field => $meta ) {
		$value          = is_array( $marker ) ? $marker['snapshot'][ $field ] : get_post_meta( $id, $meta, true );
		$lead[ $field ] = is_scalar( $value ) ? sanitize_text_field( substr( (string) $value, 0, 600 ) ) : '';
	}
	$lead['email']       = sanitize_email( $lead['email'] );
	$boundary            = (string) get_option( 'lcrm_live_capture_from', '' );
	$live                = is_array( $marker ) ? $marker['live'] : ( '' !== $boundary && $post['post_date'] >= $boundary && ! empty( $GLOBALS['lcrm_native_new_leads'][ $id ] ) );
	$lead['origin_live'] = $live;
	$lead['phone_key']   = normalize_phone( $lead['phone'] );
	$lead['email_key']   = normalize_email( $lead['email'] );
	$lead['capture_via'] = 'native';
	$source              = 'legacy:' . $id;
	$value               = is_array( $marker ) ? $marker['snapshot']['institution'] : get_post_meta( $id, 'institution', true );
	$destinations        = is_array( $marker ) ? $marker['destinations'] : ( is_scalar( $value ) ? legacy_institutions( (string) $value, native_titles() ) : array() );
	if ( ! $destinations || ( $live && ! $lead['phone_key'] && ! $lead['email_key'] ) ) {
		global $wpdb;
		$queries = $wpdb->num_queries;
		$exists  = get_posts(
			array(
				'cache_results'  => false,
				'post_type'      => 'lcrm_delivery',
				'post_status'    => 'private',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_lcrm_source',
				'meta_value'     => $source,
			)
		);
		if ( $wpdb->num_queries <= $queries || $wpdb->last_error ) {
			return error( 'לא ניתן לקרוא את קליטת הפנייה הקיימת.', 503 );
		}
		if ( $exists ) {
			$result = raw_records( $exists )[0];
			if ( is_array( $marker ) ) {
				$result = capture_retry_delivery( $result['id'], $source, 0, $lead );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$clear = is_array( $marker ) ? capture_retry_native_clear( $id ) : true;
			return is_wp_error( $clear ) ? $clear : data( $result['id'] );
		}
		$lead['institution']   = 0;
		$lead['source']        = $source;
		$lead['state']         = 'unmapped';
		$lead['month']         = substr( $lead['date'], 0, 7 );
		$lead['duplicate_of']  = 0;
		$lead['bill']          = 0;
		$lead['treatment']     = 'new';
		$lead['notes']         = array();
		$lead['capture_error'] = ! $destinations ? 'institution' : 'contact';
		$result                = save_record( 'lcrm_delivery', $lead );
		if ( ! is_wp_error( $result ) ) {
			audit( 'native_capture_exception', $result['id'], array( 'reason' => $lead['capture_error'] ) );
		}
		if ( ! is_wp_error( $result ) && is_array( $marker ) ) {
			$verified = capture_retry_delivery( $result['id'], $source, 0, $lead );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$clear = capture_retry_native_clear( $id );
			if ( is_wp_error( $clear ) ) {
				return $clear;
			}
		}
		return $result;
	}
	$results = array();
	foreach ( $destinations as $institution ) {
		$result = record_delivery( $lead, $institution, $source, ! $live );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// An overlapping import can store this newly observed source before its shutdown flush.
		$result = raw_records( array( $result['id'] ) )[0];
		if ( $live && 'historical' === $result['state'] ) {
			$bill = bill_for( $institution, $result['month'] );
			if ( $result['bill'] || ( $bill && 'approved' === $bill['state'] ) ) {
				return capture_retry_failure( 'approved_month', 'התקופה כבר אושרה. נדרשת התאמה כספית נפרדת.' );
			}
			$result['state']          = 'sent';
			$result['origin_live']    = true;
			$result['capture_via']    = 'native';
			$result['duplicate_of']   = 0;
			$result['duplicate_mode'] = $result['duplicate_mode'] ?? duplicate_policy( institution_policy( $institution ) );
			$result                   = save_record( 'lcrm_delivery', $result, $result['id'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = reconcile_duplicates( $result );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( is_array( $marker ) ) {
			$verified = capture_retry_delivery( $result['id'], $source, $institution, $lead );
			if ( is_wp_error( $verified ) || ( $live && ( 'sent' !== $verified['state'] || empty( $verified['origin_live'] ) ) ) ) {
				return is_wp_error( $verified ) ? $verified : capture_retry_failure( 'invalid_payload', 'קליטת הפנייה החיה טרם אושרה.' );
			}
		}
		$results[] = data( $result['id'] );
	}
	if ( is_array( $marker ) ) {
		$clear = capture_retry_native_clear( $id );
		if ( is_wp_error( $clear ) ) {
			return $clear;
		}
	}
	return $results;
}

/**
 * Flush final source metadata after the site's form actions have completed.
 *
 * @return array Per-source results for tests and internal integrations.
 */
function native_flush() {
	foreach ( array_keys( $GLOBALS['lcrm_native_markers'] ?? array() ) as $id ) {
		$restored = capture_retry_native_restore( $id );
		if ( is_wp_error( $restored ) ) {
			native_report_error( $restored, 0, 'legacy:' . $id );
		}
	}
	$ids                          = array_keys( $GLOBALS['lcrm_native_queue'] ?? array() );
	$GLOBALS['lcrm_native_queue'] = array();
	$results                      = array();
	$prepared                     = array();
	foreach ( $ids as $id ) {
		$automatic = capture_case_auto( $id );
		if ( is_wp_error( $automatic ) || ! $automatic ) {
			if ( is_wp_error( $automatic ) ) {
				capture_retry_schedule();
			}
			$results[ $id ] = is_wp_error( $automatic ) ? $automatic : capture_retry_failure( 'manual_review', 'הפנייה ממתינה להחלטת מנהל.' );
			continue;
		}
		$result = capture_retry_native_freeze( $id );
		if ( is_wp_error( $result ) ) {
			$results[ $id ] = capture_retry_result( $id, $result );
			native_report_error( $result, 0, 'legacy:' . $id );
		} else {
			$prepared[] = $id;
		}
	}
	foreach ( $prepared as $id ) {
		$result         = capture_retry_native_attempt( $id, 0 );
		$results[ $id ] = $result;
		if ( is_wp_error( $result ) ) {
			native_report_error( $result, 0, 'legacy:' . $id );
		}
	}
	return $results;
}
add_action( 'shutdown', __NAMESPACE__ . '\\native_flush', 5 );

/**
 * Observe successful transport in the existing institution_interested AJAX handler.
 *
 * @param array|\WP_Error $response WordPress HTTP response.
 * @param string          $context WordPress HTTP debug context.
 * @param string          $transport HTTP transport class.
 * @param array           $args Existing request arguments.
 * @param string          $url Actual outbound URL assembled by the existing handler.
 * @return array|\WP_Error|null Internal result, with no outbound request of its own.
 */
function native_http_capture( $response, $context, $transport, $args, $url ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Observe a completed existing server HTTP request; this adds no public submission endpoint.
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( ! wp_doing_ajax() || 'institution_interested' !== $action || 'response' !== $context || is_wp_error( $response ) || ! is_array( $args ) || ! isset( $args['method'] ) || ! is_string( $args['method'] ) || 'GET' !== strtoupper( $args['method'] ) || ! is_string( $url ) ) {
		return null;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'ext.bhol.co.il' !== strtolower( $parts['host'] ?? '' ) || '/lead.php' !== ( $parts['path'] ?? '' ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
		return null;
	}
	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return null;
	}
	$query = array();
	parse_str( $parts['query'] ?? '', $query );
	foreach ( array( 'cId', 'fName', 'phone', 'email' ) as $key ) {
		if ( ! isset( $query[ $key ] ) || ! is_string( $query[ $key ] ) || strlen( $query[ $key ] ) > 600 ) {
			return null;
		}
	}
	$cid = sanitize_text_field( $query['cId'] );
	if ( '' === trim( $cid ) ) {
		return null;
	}
	if ( empty( $GLOBALS['lcrm_native_ajax_source'] ) ) {
		$GLOBALS['lcrm_native_ajax_source'] = 'ajax:' . wp_generate_uuid4();
		$GLOBALS['lcrm_native_ajax_date']   = current_time( 'mysql' );
	}
	$source = $GLOBALS['lcrm_native_ajax_source'];
	$key    = hash( 'sha256', $source . ':' . $cid );
	if ( isset( $GLOBALS['lcrm_native_ajax_results'][ $key ] ) ) {
		return $GLOBALS['lcrm_native_ajax_results'][ $key ];
	}
	$lead = array(
		'name'  => sanitize_text_field( $query['fName'] ),
		'phone' => sanitize_text_field( $query['phone'] ),
		'email' => sanitize_email( $query['email'] ),
		'date'  => $GLOBALS['lcrm_native_ajax_date'],
		'form'  => 'institution_interested',
	);
	$id   = $GLOBALS['lcrm_native_ajax_queue'][ $key ] ?? capture_retry_ajax_enqueue( $lead, $cid, $source );
	if ( is_wp_error( $id ) ) {
		native_report_error( $id, 0, $source );
		return $id;
	}
	$GLOBALS['lcrm_native_ajax_queue'][ $key ] = $id;
	$result                                    = capture_retry_ajax_attempt( $id );
	if ( is_wp_error( $result ) ) {
		native_report_error( $result, 0, $source );
	} elseif ( null !== $result ) {
		$GLOBALS['lcrm_native_ajax_results'][ $key ] = $result;
	}
	return $result;
}
add_action( 'http_api_debug', __NAMESPACE__ . '\\native_http_capture', 20, 5 );
