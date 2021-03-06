<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class Se_Admin
 */
class Se_Admin {

	/**
	 * Localization.
	 */
	public function se_localization() {
		load_plugin_textdomain( 'SearchEverything', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * Se_Admin constructor.
	 */
	public function __construct() {

		// Load language file.
		$locale  = get_locale();
		$meta    = se_get_meta();
		$options = se_get_options();

		if ( ! empty( $locale ) ) {
			add_action( 'admin_init', array( &$this, 'se_localization' ) );
		}

		add_action( 'admin_enqueue_scripts', array( &$this, 'se_register_plugin_scripts_and_styles' ) );
		add_action( 'admin_menu', array( &$this, 'se_add_options_panel' ) );

		if ( ! empty( $options['se_research_metabox']['visible_on_compose'] ) ) {
			add_action( 'add_meta_boxes', array( &$this, 'se_meta_box_add' ) );
		}

		if ( isset( $_GET['se_notice'] ) && 0 === $_GET['se_notice'] ) {
			$meta['show_options_page_notice'] = false;
			se_update_meta( $meta );
		}

		if ( $meta['show_options_page_notice'] ) {
			add_action( 'all_admin_notices', array( &$this, 'se_options_page_notice' ) );
		}

		if ( isset( $_GET['se_global_notice'] ) && 0 === $_GET['se_global_notice'] ) {
			$meta['se_global_notice'] = null;
			se_update_meta( $meta );
		}
	}

	/**
	 * Register style sheet.
	 */
	public function se_register_plugin_scripts_and_styles() {
		wp_register_style( 'search-everything', SE_PLUGIN_URL . '/static/css/admin.css', array(), SE_VERSION );
		wp_enqueue_style( 'search-everything' );

		add_editor_style( SE_PLUGIN_URL . '/static/css/se-styles.css', array(), SE_VERSION );

		wp_register_style( 'search-everything-compose', SE_PLUGIN_URL . '/static/css/se-compose.css', array(), SE_VERSION );
		wp_enqueue_style( 'search-everything-compose' );

		wp_register_script( 'search-everything', SE_PLUGIN_URL . '/static/js/searcheverything.js', array(), SE_VERSION, true );
		wp_enqueue_script( 'search-everything' );
	}


	/**
	 * Add metabox for search widget on editor.
	 */
	public function se_meta_box_add() {
		add_meta_box( 'se-metabox', 'Research Everything', array( &$this, 'se_meta_box_cb' ), 'post', 'side', 'high' );
	}

	/**
	 * Metabox combobox.
	 *
	 * @param WP_Post $post WP Post.
	 */
	public function se_meta_box_cb( WP_Post $post ) {
		$values = get_post_custom( $post->ID );
		$text   = isset( $values['se-meta-box-text'] ) ? esc_attr( $values['se-meta-box-text'][0] ) : '';
		wp_nonce_field( 'se-meta-box-nonce', 'meta_box_nonce' );

		?>
		<div id="se-metabox-form">
			<input data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" type="search" placeholder="Search interesting stuff" name="se-metabox-text" id="se-metabox-text" value=""/>
			<a id="se-metabox-search">Search</a>
		</div>
		<?php
	}

	/**
	 * Metabox search.
	 *
	 * @param mixed $post_id Unused.
	 */
	public function se_meta_box_search( $post_id ) {

		// Bail if we're doing an auto save.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// if our nonce isn't there, or we can't verify it, bail.
		if ( ! isset( $_POST['meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['meta_box_nonce'] ) ), 'se-meta-box-nonce' ) ) {
			return;
		}

		// if our current user can't edit this post, bail.
		if ( ! current_user_can( 'edit_post' ) ) {
			exit;
		}
	}

	/**
	 * Add options panel.
	 */
	public function se_add_options_panel() {
		add_options_page( 'Search', 'Search Everything', 'manage_options', 'extend_search', array( &$this, 'se_option_page' ) );
	}

	/**
	 * Validation.
	 *
	 * @param array $validation_rules Validation rules.
	 *
	 * @return array
	 */
	public function se_validation( array $validation_rules ): array {
		$regex = array(
			'color'         => '^(([a-z]+)|(#[0-9a-f]{2,6}))?$',
			'numeric-comma' => '^(\d+(, ?\d+)*)?$',
			'css'           => '^(([a-zA-Z-])+\ *\:[^;]+; *)*$',
		);

		$messages = array(
			// translators: %s = field name.
			'numeric-comma' => __( 'incorrect format for field <strong>%s</strong>', 'SearchEverything' ),

			// translators: %s = field name.
			'color'         => __( "field <strong>%s</strong> should be a css color ('red' or '#abc123')", 'SearchEverything' ),

			// translators: %s = field name.
			'css'           => __( "field <strong>%s</strong> doesn't contain valid css", 'SearchEverything' ),
		);

		$errors = array();
		foreach ( $validation_rules as $field => $rule_name ) {
			$rule = $regex[ $rule_name ];
			if ( ! preg_match( "/$rule/", sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ) ) ) {
				$errors[ $field ] = $messages[ $rule_name ];
			}
		}

		return $errors;
	}

	/**
	 * Build admin interface.
	 */
	public function se_option_page() {
		global $wpdb, $table_prefix, $wp_version;

		if ( $_POST ) {
			check_admin_referer( 'se-everything-nonce' );
			$errors = $this->se_validation(
				array(
					'highlight_color'         => 'color',
					'highlight_style'         => 'css',
					'exclude_categories_list' => 'numeric-comma',
					'exclude_posts_list'      => 'numeric-comma',
				)
			);

			if ( $errors ) {
				$fields = array(
					'highlight_color'         => __( 'Highlight Background Color', 'SearchEverything' ),
					'highlight_style'         => __( 'Full Highlight Style', 'SearchEverything' ),
					'exclude_categories_list' => __( 'Exclude Categories', 'SearchEverything' ),
					'exclude_posts_list'      => __( 'Exclude some post or page IDs', 'SearchEverything' ),
				);

				include se_get_view( 'options_page_errors' );

				return;
			}
		}

		$new_options = array(
			'se_exclude_categories'      => ( isset( $_POST['exclude_categories'] ) && ! empty( $_POST['exclude_categories'] ) ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories'] ) ) : '',
			'se_exclude_categories_list' => ( isset( $_POST['exclude_categories_list'] ) && ! empty( $_POST['exclude_categories_list'] ) ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_list'] ) ) : '',
			'se_exclude_posts'           => ( isset( $_POST['exclude_posts'] ) ) ? sanitize_text_field( wp_unslash( $_POST['exclude_posts'] ) ) : '',
			'se_exclude_posts_list'      => ( isset( $_POST['exclude_posts_list'] ) && ! empty( $_POST['exclude_posts_list'] ) ) ? sanitize_text_field( wp_unslash( $_POST['exclude_posts_list'] ) ) : '',
			'se_use_page_search'         => ( isset( $_POST['search_pages'] ) && sanitize_text_field( wp_unslash( $_POST['search_pages'] ) ) ),
			'se_use_comment_search'      => ( isset( $_POST['search_comments'] ) && sanitize_text_field( wp_unslash( $_POST['search_comments'] ) ) ),
			'se_use_tag_search'          => ( isset( $_POST['search_tags'] ) && sanitize_text_field( wp_unslash( $_POST['search_tags'] ) ) ),
			'se_use_tax_search'          => ( isset( $_POST['search_taxonomies'] ) && sanitize_text_field( wp_unslash( $_POST['search_taxonomies'] ) ) ),
			'se_use_category_search'     => ( isset( $_POST['search_categories'] ) && sanitize_text_field( wp_unslash( $_POST['search_categories'] ) ) ),
			'se_approved_comments_only'  => ( isset( $_POST['appvd_comments'] ) && sanitize_text_field( wp_unslash( $_POST['appvd_comments'] ) ) ),
			'se_approved_pages_only'     => ( isset( $_POST['appvd_pages'] ) && sanitize_text_field( wp_unslash( $_POST['appvd_pages'] ) ) ),
			'se_use_excerpt_search'      => ( isset( $_POST['search_excerpt'] ) && sanitize_text_field( wp_unslash( $_POST['search_excerpt'] ) ) ),
			'se_use_draft_search'        => ( isset( $_POST['search_drafts'] ) && sanitize_text_field( wp_unslash( $_POST['search_drafts'] ) ) ),
			'se_use_attachment_search'   => ( isset( $_POST['search_attachments'] ) && sanitize_text_field( wp_unslash( $_POST['search_attachments'] ) ) ),
			'se_use_authors'             => ( isset( $_POST['search_authors'] ) && sanitize_text_field( wp_unslash( $_POST['search_authors'] ) ) ),
			'se_use_cmt_authors'         => ( isset( $_POST['search_cmt_authors'] ) && sanitize_text_field( wp_unslash( $_POST['search_cmt_authors'] ) ) ),
			'se_use_metadata_search'     => ( isset( $_POST['search_metadata'] ) && sanitize_text_field( wp_unslash( $_POST['search_metadata'] ) ) ),
			'se_use_highlight'           => ( isset( $_POST['search_highlight'] ) && sanitize_text_field( wp_unslash( $_POST['search_highlight'] ) ) ),
			'se_highlight_color'         => ( isset( $_POST['highlight_color'] ) ) ? sanitize_text_field( wp_unslash( $_POST['highlight_color'] ) ) : '',
			'se_highlight_style'         => ( isset( $_POST['highlight_style'] ) ) ? sanitize_text_field( wp_unslash( $_POST['highlight_style'] ) ) : '',
			'se_research_metabox'        => array(
				'visible_on_compose'      => ( isset( $_POST['research_metabox'] ) && sanitize_text_field( wp_unslash( $_POST['research_metabox'] ) ) ),
				'external_search_enabled' => ( isset( $_POST['research_external_results'] ) && sanitize_text_field( wp_unslash( $_POST['research_external_results'] ) ) ),
			),
		);

		if ( isset( $_POST['action'] ) && 'save' === $_POST['action'] ) {
			echo '<div class="updated fade" id="limitcatsupdatenotice"><p>' . __( 'Your default search settings have been <strong>updated</strong> by Search Everything. </p><p> What are you waiting for? Go check out the new search results!', 'SearchEverything' ) . '</p></div>';
			se_update_options( $new_options );
		}

		if ( isset( $_POST['action'] ) && 'reset' === $_POST['action'] ) {
			echo '<div class="updated fade" id="limitcatsupdatenotice"><p>' . __( 'Your default search settings have been <strong>updated</strong> by Search Everything. </p><p> What are you waiting for? Go check out the new search results!', 'SearchEverything' ) . '</p></div>';
			$default_options = se_get_default_options();

			se_update_options( $default_options );
		}

		$options = se_get_options();
		$meta    = se_get_meta();

		// Get api key if it doesn't exist.
		if ( $options['se_research_metabox']['external_search_enabled'] && empty( $meta['api_key'] ) ) {
			$api_key         = fetch_api_key();
			$meta['api_key'] = $api_key;
			se_update_meta( $meta );
		}

		if ( $options['se_research_metabox']['external_search_enabled'] && ! empty( $meta['api_key'] ) ) {
			se_get_prefs();
		}

		$response_messages = se_get_response_messages();
		$external_message  = '';

		if ( ! empty( $meta['api_key'] ) && empty( $meta['sfid'] ) ) {
			$sfid_response = get_sfid();

			if ( ! empty( $sfid_response[0] ) ) {
				$meta['sfid'] = $sfid_response[1];
				se_update_meta( $meta );
			}

			$external_message = ! empty( $response_messages[ $sfid_response[1] ] ) ? $response_messages[ $sfid_response[1] ] : $response_messages[ SE_PREFS_STATE_FAILED ];
		} elseif ( ! empty( $meta['sfid'] ) ) {
			$external_message = $response_messages[ SE_PREFS_STATE_FOUND ];
		}

		include se_get_view( 'options_page' );
	}

	/**
	 * Options page notice.
	 */
	public function se_options_page_notice() {
		$screen = get_current_screen();

		if ( 'settings_page_extend_search' === $screen->id ) {
			$close_url = admin_url( $screen->parent_file );
			$close_url = add_query_arg(
				array(
					'page'      => 'extend_search',
					'se_notice' => 0,
				),
				$close_url
			);

			include se_get_view( 'options_page_notice' );
		}
	}
}

/**
 * Get API Key
 */
function fetch_api_key(): string {
	$response = se_api(
		array(
			'method'     => 'zemanta.auth.create_user',
			'partner_id' => 'wordpress-se',
		)
	);

	if ( ! is_wp_error( $response ) ) {
		if ( preg_match( '/<status>(.+?)<\/status>/', $response['body'], $matches ) ) {
			if ( 'ok' === $matches[1] && preg_match( '/<apikey>(.+?)<\/apikey>/', $response['body'], $matches ) ) {
				return $matches[1];
			}
		}
	}

	return '';
}


/**
 * API Call
 *
 * @param array $arguments Arguments to pass to the API.
 */
function se_api( array $arguments ) {
	$meta = se_get_meta();

	$api_key = $meta['api_key'] ? $meta['api_key'] : '';

	$arguments = array_merge(
		$arguments,
		array(
			'api_key' => $api_key,
		)
	);

	if ( ! isset( $arguments['format'] ) ) {
		$arguments['format'] = 'xml';
	}

	return wp_remote_post( SE_ZEMANTA_API_GATEWAY, array( 'body' => $arguments ) );
}

/**
 * Get ID.
 *
 * @return array
 */
function get_sfid(): array {
	$site_urls = array(
		get_bloginfo( 'rss2_url' ),
		get_site_url(),
	);

	$response_state;
	foreach ( $site_urls as $url ) {
		if ( empty( $url ) ) {
			continue;
		}

		$response = wp_remote_get( SE_ZEMANTA_PREFS_URL . '?url=' . rawurlencode( $url ) );

		if ( ! is_wp_error( $response ) ) {
			$response_json  = json_decode( $response['body'] );
			$response_state = $response_json->status;
			if ( 'ok' === $response_state && ! empty( $response_json->sfid ) ) {
				return array( $response_json->sfid, $response_state );
			}
		}
	}

	return array( null, $response_state );
}

/**
 * Get prefs.
 */
function se_get_prefs() {
	$meta             = se_get_meta();
	$zemanta_response = se_api(
		array(
			'method'    => 'zemanta.preferences',
			'format'    => 'json',
			'interface' => 'wordpress-se',
		)
	);
}
