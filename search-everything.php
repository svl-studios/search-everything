<?php
/**
 * Plugin Name: Search Everything
 * Plugin URI: https://github.com/svl-studios/search-everything
 * Description: Adds search functionality without modifying any template pages: Activate, Configure and Search. Options Include: search highlight, search pages, excerpts, attachments, drafts, comments, tags and custom fields (metadata). Also offers the ability to exclude specific pages and posts. Does not search password-protected content.
 * Version: 8.1.9
 * Author: Sovrn, zemanta, kprovance
 * Author URI: https://github.com/svl-studios/search-everything
 */

defined( 'ABSPATH' ) || exit;

const SE_VERSION = '8.1.9';

if ( ! defined( 'SE_PLUGIN_FILE' ) ) {

	/**
	 * Plugin base.
	 */
	define( 'SE_PLUGIN_FILE', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'SE_PLUGIN_NAME' ) ) {

	/**
	 * Plugin name.
	 */
	define( 'SE_PLUGIN_NAME', trim( dirname( plugin_basename( __FILE__ ) ), '/' ) );
}

if ( ! defined( 'SE_PLUGIN_DIR' ) ) {

	/**
	 * Plugin dir.
	 */
	define( 'SE_PLUGIN_DIR', WP_PLUGIN_DIR . '/' . SE_PLUGIN_NAME );
}

if ( ! defined( 'SE_PLUGIN_URL' ) ) {

	/**
	 * Plugin URL.
	 */
	define( 'SE_PLUGIN_URL', plugins_url() . '/' . SE_PLUGIN_NAME );
}

if ( ! defined( 'SE_ZEMANTA_API_GATEWAY' ) ) {

	/**
	 * API Gateway
	 */
	define( 'SE_ZEMANTA_API_GATEWAY', 'https://oneapi.zemanta.com/rest/v1/accounts' );
}

if ( ! defined( 'SE_ZEMANTA_DASHBOARD_URL' ) ) {

	/**
	 * Dashboard URL.
	 */
	define( 'SE_ZEMANTA_DASHBOARD_URL', 'https://blogmind.zemanta.com/' );
}

if ( ! defined( 'SE_ZEMANTA_PREFS_URL' ) ) {

	/**
	 * Prefers URL.
	 */
	define( 'SE_ZEMANTA_PREFS_URL', 'https://prefs.zemanta.com/api/get-sfid/' );
}

if ( ! defined( 'SE_ZEMANTA_LOGO_URL' ) ) {

	/**
	 * Logo URL.
	 */
	define( 'SE_ZEMANTA_LOGO_URL', 'https://www.zemanta.com' );
}

if ( ! defined( 'SE_PREFS_STATE_FOUND' ) ) {

	/**
	 * State found.
	 */
	define( 'SE_PREFS_STATE_FOUND', 1 );

	/**
	 * State failed.
	 */
	define( 'SE_PREFS_STATE_FAILED', - 2 );

	/**
	 * Stte not English.
	 */
	define( 'SE_PREFS_STATE_NOT_ENGLISH', - 4 );

	/**
	 * State empty.
	 */
	define( 'SE_PREFS_STATE_EMPTY', - 7 );
}

require_once SE_PLUGIN_DIR . '/config.php';
require_once SE_PLUGIN_DIR . '/options.php';

add_action( 'wp_loaded', 'se_initialize_plugin' );

/**
 * Init plugin.
 */
function se_initialize_plugin() {
	$se = new SearchEverything();
}

/**
 * Get view.
 *
 * @param string $view View.
 *
 * @return string
 */
function se_get_view( string $view ): string {
	return SE_PLUGIN_DIR . "/views/$view.php";
}

/**
 * Admin head.
 */
function se_admin_head() {
	$se_options = se_get_options();
	$se_meta    = se_get_meta();
	$se_metabox = $se_options['se_research_metabox'];

	include se_get_view( 'admin_head' );
}

add_action( 'admin_head', 'se_admin_head' );

/**
 * Global head.
 */
function se_global_head() {
	include se_get_view( 'global_head' );
}

add_action( 'wp_head', 'se_global_head' );

/**
 * Global notice.
 */
function se_global_notice() {
	global $pagenow, $se_global_notice_pages;

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$se_meta = se_get_meta();

	$close_url = admin_url( 'options-general.php' );

	$close_url = add_query_arg(
		array(
			'page'             => 'extend_search',
			'se_global_notice' => 0,
		),
		$close_url
	);

	$notice = $se_meta['se_global_notice'];

	if ( $notice && in_array( $pagenow, $se_global_notice_pages, true ) ) {
		include se_get_view( 'global_notice' );
	}
}

add_action( 'all_admin_notices', 'se_global_notice' );

/**
 * Class SearchEverything
 */
class SearchEverything {

	/**
	 * Enable logging.
	 *
	 * @var bool
	 */
	private $logging = false;

	/**
	 * Options.
	 *
	 * @var ArrayObject
	 */
	private $options;

	/**
	 * AJAX request.
	 *
	 * @var bool
	 */
	private $ajax_request;

	/**
	 * Query instance.
	 *
	 * @var null
	 */
	private $query_instance;

	/**
	 * SearchEverything constructor.
	 *
	 * @param false $ajax_query Is AJAX query.
	 */
	public function __construct( $ajax_query = false ) {
		$this->ajax_request = (bool) $ajax_query;
		$this->options      = se_get_options();

		if ( $this->ajax_request ) {
			$this->init_ajax( $ajax_query );
		} else {
			$this->init();
		}
	}

	/**
	 * Init AJAX.
	 *
	 * @param mixed $query Unused.
	 */
	private function init_ajax( $query ) {
		$this->search_hooks();
	}

	/**
	 * Init.
	 *
	 * @return void
	 */
	private function init(): void {

		if ( current_user_can( 'manage_options' ) ) {
			new Se_Admin();
		}

		/**
		 * Disable Search-Everything, because posts_join is not working properly in Wordpress-backend's Ajax functions
		 * (for example in wp_link_query from compose screen (article search when inserting links)).
		 */
		if ( basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) === 'admin-ajax.php' ) {
			return;
		}

		$this->search_hooks();

		// Highlight content.
		if ( $this->options['se_use_highlight'] ) {
			add_filter( 'the_content', array( &$this, 'se_postfilter' ), 11 );
			add_filter( 'the_title', array( &$this, 'se_postfilter' ), 11 );
			add_filter( 'the_excerpt', array( &$this, 'se_postfilter' ), 11 );
		}
	}

	/**
	 * Search hooks.
	 */
	private function search_hooks() {

		// Add filters based upon option settings.
		if ( $this->options['se_use_tag_search'] || $this->options['se_use_category_search'] || $this->options['se_use_tax_search'] ) {
			add_filter( 'posts_join', array( &$this, 'se_terms_join' ) );

			if ( $this->options['se_use_tag_search'] ) {
				$this->se_log( 'searching tags' );
			}

			if ( $this->options['se_use_category_search'] ) {
				$this->se_log( 'searching categories' );
			}

			if ( $this->options['se_use_tax_search'] ) {
				$this->se_log( 'searching custom taxonomies' );
			}
		}

		if ( $this->options['se_use_page_search'] ) {
			add_filter( 'posts_where', array( &$this, 'se_search_pages' ) );
			$this->se_log( 'searching pages' );
		}

		if ( $this->options['se_use_excerpt_search'] ) {
			$this->se_log( 'searching excerpts' );
		}

		if ( $this->options['se_use_comment_search'] ) {
			add_filter( 'posts_join', array( &$this, 'se_comments_join' ) );
			$this->se_log( 'searching comments' );

			// Highlight content.
			if ( $this->options['se_use_highlight'] ) {
				add_filter( 'comment_text', array( &$this, 'se_postfilter' ) );
			}
		}

		if ( $this->options['se_use_draft_search'] ) {
			add_filter( 'posts_where', array( &$this, 'se_search_draft_posts' ) );
			$this->se_log( 'searching drafts' );
		}

		if ( $this->options['se_use_attachment_search'] ) {
			add_filter( 'posts_where', array( &$this, 'se_search_attachments' ) );
			$this->se_log( 'searching attachments' );
		}

		if ( $this->options['se_use_metadata_search'] ) {
			add_filter( 'posts_join', array( &$this, 'se_search_metadata_join' ) );
			$this->se_log( 'searching metadata' );
		}

		if ( '' !== $this->options['se_exclude_posts_list'] ) {
			$this->se_log( 'searching excluding posts' );
		}

		if ( '' !== $this->options['se_exclude_categories_list'] ) {
			add_filter( 'posts_join', array( &$this, 'se_exclude_categories_join' ) );
			$this->se_log( 'searching excluding categories' );
		}

		if ( $this->options['se_use_authors'] ) {

			add_filter( 'posts_join', array( &$this, 'se_search_authors_join' ) );
			$this->se_log( 'searching authors' );
		}

		add_filter( 'posts_search', array( &$this, 'se_search_where' ), 10, 2 );
		add_filter( 'posts_where', array( &$this, 'se_no_revisions' ) );
		add_filter( 'posts_request', array( &$this, 'se_distinct' ) );
		add_filter( 'posts_where', array( &$this, 'se_no_future' ) );
		add_filter( 'posts_request', array( &$this, 'se_log_query' ), 10, 2 );
	}

	/**
	 * Creates the list of search keywords from the 's' parameters.
	 *
	 * @return array
	 */
	private function se_get_search_terms(): array {
		global $wpdb;

		$s            = $this->query_instance->query_vars['s'] ?? '';
		$sentence     = $this->query_instance->query_vars['sentence'] ?? false;
		$search_terms = array();

		if ( ! empty( $s ) ) {

			// Added slashes screw with quote grouping when done early, so done later.
			$s = stripslashes( $s );

			if ( $sentence ) {
				$search_terms = array( $s );
			} else {
				preg_match_all( '/".*?("|$)|((?<=[\\s",+])|^)[^\\s",+]+/', $s, $matches );

				$search_terms = array_filter(
					array_map(
						function ( $a ) {
							return trim( $a, "\"\'\n\r " );
						},
						$matches[0]
					)
				);
			}
		}

		return $search_terms;
	}

	/**
	 * Add where clause to the search query.
	 *
	 * @param string   $where    Criteria.
	 * @param WP_Query $wp_query Query.
	 *
	 * @return string
	 */
	public function se_search_where( string $where, WP_Query $wp_query ): string {
		if ( ! $wp_query->is_search() && ! $this->ajax_request ) {
			return $where;
		}

		$this->query_instance = &$wp_query;

		global $wpdb;

		$search_query = $this->se_search_default();

		// Add filters based upon option settings.
		if ( $this->options['se_use_tag_search'] ) {
			$search_query .= $this->se_build_search_tag();
		}

		if ( $this->options['se_use_category_search'] || $this->options['se_use_tax_search'] ) {
			$search_query .= $this->se_build_search_categories();
		}

		if ( $this->options['se_use_metadata_search'] ) {
			$search_query .= $this->se_build_search_metadata();
		}

		if ( $this->options['se_use_excerpt_search'] ) {
			$search_query .= $this->se_build_search_excerpt();
		}

		if ( $this->options['se_use_comment_search'] ) {
			$search_query .= $this->se_build_search_comments();
		}

		if ( $this->options['se_use_authors'] ) {
			$search_query .= $this->se_search_authors();
		}

		if ( '' !== $search_query ) {
			// Lets use _OUR_ query instead of WP's, as we have posts already included in our query as well(assuming it's not empty which we check for).
			$where = ' AND ((' . $search_query . ')) ';
		}

		if ( '' !== $this->options['se_exclude_posts_list'] ) {
			$where .= $this->se_build_exclude_posts();
		}
		if ( '' !== $this->options['se_exclude_categories_list'] ) {
			$where .= $this->se_build_exclude_categories();

		}
		$this->se_log( 'global where: ' . $where );

		return $where;
	}

	/**
	 * Search for terms in default locations like title and content
	 * replacing the old search terms seems to be the best way to
	 * avoid issue with multiple terms.
	 *
	 * @return string
	 */
	private function se_search_default(): string {
		global $wpdb;

		$not_exact        = empty( $this->query_instance->query_vars['exact'] );
		$search_sql_query = '';
		$seperator        = '';
		$terms            = $this->se_get_search_terms();

		// If it's not a sentance add other terms.
		$search_sql_query .= '(';

		foreach ( $terms as $term ) {
			$search_sql_query .= $seperator;

			$esc_term = $wpdb->prepare( '%s', $not_exact ? '%' . $term . '%' : $term );

			$like_title = "($wpdb->posts.post_title LIKE $esc_term)";
			$like_post  = "($wpdb->posts.post_content LIKE $esc_term)";

			$search_sql_query .= "($like_title OR $like_post)";

			$seperator = ' AND ';
		}

		$search_sql_query .= ')';

		return $search_sql_query;
	}

	/**
	 * Exclude post revisions.
	 *
	 * @param string $where SQL string.
	 *
	 * @return string
	 */
	public function se_no_revisions( string $where ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$where = 'AND (' . substr( $where, strpos( $where, 'AND' ) + 3 ) . ") AND $wpdb->posts.post_type != 'revision'";
		}

		return $where;
	}

	/**
	 * Exclude future posts fix provided by Mx.
	 *
	 * @param string $where SQL string.
	 *
	 * @return string
	 */
	public function se_no_future( string $where ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$where = 'AND (' . substr( $where, strpos( $where, 'AND' ) + 3 ) . ") AND $wpdb->posts.post_status != 'future'";
		}

		return $where;
	}

	/**
	 * Logs search into a file.
	 *
	 * @param string $msg Message.
	 *
	 * @return void
	 */
	private function se_log( string $msg ): void {
		if ( $this->logging ) {
			// phpcs:ignore
			$fp = fopen( SE_PLUGIN_DIR . 'logfile.log', 'a+' ); // TODO: change to wp_filesystem.

			if ( ! $fp ) {
				echo 'unable to write to log file!';
			}

			$date   = gmdate( 'Y-m-d H:i:s ' );
			$source = 'search_everything plugin: ';

			fwrite( $fp, "\n\n" . $date . "\n" . $source . "\n" . $msg ); // phpcs:ignore
			fclose( $fp ); // phpcs:ignore
		}

	}

	/**
	 * Duplicate fix provided by Tiago.Pocinho.
	 *
	 * @param string $query Query.
	 *
	 * @return array|string|string[]
	 */
	public function se_distinct( string $query ) {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			if ( false === strstr( $query, 'DISTINCT' ) ) {
				$query = str_replace( 'SELECT', 'SELECT DISTINCT', $query );
			}
		}

		return $query;
	}

	/**
	 * Search pages (except password protected pages provided by loops).
	 *
	 * @param string $where SQL string.
	 *
	 * @return array|string|string[]
	 */
	private function se_search_pages( string $where ) {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$where = str_replace( '"', '\'', $where );

			if ( $this->options['se_approved_pages_only'] ) {
				$where = str_replace( "post_type = 'post'", " AND 'post_password = '' AND ", $where );
			} else { // < v 2.1
				$where = str_replace( 'post_type = \'post\' AND ', '', $where );
			}
		}
		$this->se_log( 'pages where: ' . $where );

		return $where;
	}

	/**
	 * Create the search excerpts query.
	 *
	 * @return string
	 */
	private function se_build_search_excerpt(): string {
		global $wpdb;

		$vars = $this->query_instance->query_vars;

		$s            = $vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = $vars['exact'] ?? '';
		$search       = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query.
			$n         = ( $exact ) ? '' : '%';
			$searchand = '';

			foreach ( $search_terms as $term ) {
				$term      = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search   .= "$searchand($wpdb->posts.post_excerpt LIKE $term)";
				$searchand = ' AND ';
			}
			$sentence_term = $wpdb->prepare( '%s', $exact ? $s : "%$s%" );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
				$search = "($search) OR ($wpdb->posts.post_excerpt LIKE $sentence_term)";
			}

			if ( ! empty( $search ) ) {
				$search = " OR ($search) ";
			}
		}

		return $search;
	}


	/**
	 * Search drafts.
	 *
	 * @param string $where SQL string.
	 *
	 * @return array|string|string[]
	 */
	private function se_search_draft_posts( string $where ) {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$where = str_replace( '"', '\'', $where );
			$where = str_replace( " AND (post_status = 'publish'", " AND ((post_status = 'publish' OR post_status = 'draft')", $where );
			$where = str_replace( " AND (post_status = 'publish'", " AND (post_status = 'publish' OR post_status = 'draft'", $where );
		}
		$this->se_log( 'drafts where: ' . $where );

		return $where;
	}

	/**
	 * Search attachments.
	 *
	 * @param string $where SQL string.
	 *
	 * @return array|string|string[]
	 */
	private function se_search_attachments( string $where ) {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$where = str_replace( '"', '\'', $where );
			$where = str_replace( " AND ($wpdb->posts.post_status = 'publish'", " AND ($wpdb->posts.post_status = 'publish' OR $wpdb->posts.post_type = 'attachment'", $where );
			$where = str_replace( "AND $wpdb->posts.post_type != 'attachment'", '', $where );
		}

		$this->se_log( 'attachments where: ' . $where );

		return $where;
	}

	/**
	 * Create the comments data query.
	 *
	 * @return string
	 */
	private function se_build_search_comments(): string {
		global $wpdb;

		$vars = $this->query_instance->query_vars;

		$s            = $vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = $vars['exact'] ?? '';
		$search       = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query on comments content.
			$searchand      = '';
			$search_content = '';

			foreach ( $search_terms as $term ) {
				$term            = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search_content .= "$searchand(cmt.comment_content LIKE $term)";
				$searchand       = ' AND ';
			}

			$sentense_term = $wpdb->prepare( '%s', $s );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentense_term ) {
				$search_content = "($search_content) OR (cmt.comment_content LIKE $sentense_term)";
			}

			$search = $search_content;

			// Building search query on comments author.
			if ( $this->options['se_use_cmt_authors'] ) {
				$searchand      = '';
				$comment_author = '';

				foreach ( $search_terms as $term ) {
					$term            = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
					$comment_author .= "$searchand(cmt.comment_author LIKE $term)";
					$searchand       = ' AND ';
				}

				$sentence_term = $wpdb->prepare( '%s', $s );
				if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
					$comment_author = "($comment_author) OR (cmt.comment_author LIKE $sentence_term)";
				}

				$search = "($search) OR ($comment_author)";
			}

			if ( $this->options['se_approved_comments_only'] ) {
				$comment_approved = "AND cmt.comment_approved =  '1'";
				$search           = "($search) $comment_approved";
			}

			if ( ! empty( $search ) ) {
				$search = " OR ($search) ";
			}
		}

		$this->se_log( 'comments sql: ' . $search );

		return $search;
	}

	/**
	 * Build the author search.
	 *
	 * @return string
	 */
	private function se_search_authors(): string {
		global $wpdb;

		$s            = $this->query_instance->query_vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = isset( $this->query_instance->query_vars['exact'] ) && $this->query_instance->query_vars['exact'];
		$search       = '';
		$searchand    = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query.
			foreach ( $search_terms as $term ) {
				$term      = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search   .= "$searchand(u.display_name LIKE $term)";
				$searchand = ' OR ';
			}

			$sentence_term = $wpdb->prepare( '%s', $s );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
				$search .= " OR (u.display_name LIKE $sentence_term)";
			}

			if ( ! empty( $search ) ) {
				$search = " OR ($search) ";
			}
		}

		$this->se_log( 'user where: ' . $search );

		return $search;
	}

	/**
	 * Create the search meta data query.
	 *
	 * @return string
	 */
	private function se_build_search_metadata(): string {
		global $wpdb;

		$s            = $this->query_instance->query_vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = isset( $this->query_instance->query_vars['exact'] ) && $this->query_instance->query_vars['exact'];
		$search       = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query.
			$searchand = '';
			foreach ( $search_terms as $term ) {
				$term      = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search   .= "$searchand(m.meta_value LIKE $term)";
				$searchand = ' AND ';
			}

			$sentence_term = $wpdb->prepare( '%s', $s );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
				$search = "($search) OR (m.meta_value LIKE $sentence_term)";
			}

			if ( ! empty( $search ) ) {
				$search = " OR ($search) ";
			}
		}

		$this->se_log( 'meta where: ' . $search );

		return $search;
	}

	/**
	 * Create the search tag query.
	 *
	 * @return string
	 */
	private function se_build_search_tag(): string {
		global $wpdb;

		$vars = $this->query_instance->query_vars;

		$s            = $vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = $vars['exact'] ?? '';
		$search       = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query.
			$searchand = '';
			foreach ( $search_terms as $term ) {
				$term      = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search   .= "$searchand(tter.name LIKE $term)";
				$searchand = ' OR ';
			}

			$sentence_term = $wpdb->prepare( '%s', $s );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
				$search = "($search) OR (tter.name LIKE $sentence_term)";
			}

			if ( ! empty( $search ) ) {
				$search = " OR ($search) ";
			}
		}

		$this->se_log( 'tag where: ' . $search );

		return $search;
	}

	/**
	 * Create the search categories query.
	 *
	 * @return string
	 */
	private function se_build_search_categories(): string {
		global $wpdb;

		$vars = $this->query_instance->query_vars;

		$s            = $vars['s'];
		$search_terms = $this->se_get_search_terms();
		$exact        = $vars['exact'] ?? '';
		$search       = '';

		if ( ! empty( $search_terms ) ) {

			// Building search query for categories slug.
			$searchand   = '';
			$search_slug = '';

			foreach ( $search_terms as $term ) {
				$term         = $wpdb->prepare( '%s', $exact ? $term : '%' . sanitize_title_with_dashes( $term ) . '%' );
				$search_slug .= "$searchand(tter.slug LIKE $term)";
				$searchand    = ' AND ';
			}

			$term = $wpdb->prepare( '%s', $exact ? $term : '%' . sanitize_title_with_dashes( $s ) . '%' );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $s ) {
				$search_slug = "($search_slug) OR (tter.slug LIKE $term)";
			}

			if ( ! empty( $search_slug ) ) {
				$search = " OR ($search_slug) ";
			}

			// Building search query for categories description.
			$searchand   = '';
			$search_desc = '';

			foreach ( $search_terms as $term ) {
				$term         = $wpdb->prepare( '%s', $exact ? $term : "%$term%" );
				$search_desc .= "$searchand(ttax.description LIKE $term)";
				$searchand    = ' AND ';
			}

			$sentence_term = $wpdb->prepare( '%s', $s );
			if ( count( $search_terms ) > 1 && $search_terms[0] !== $sentence_term ) {
				$search_desc = "($search_desc) OR (ttax.description LIKE $sentence_term)";
			}

			if ( ! empty( $search_desc ) ) {
				$search = $search . " OR ($search_desc) ";
			}
		}
		$this->se_log( 'categories where: ' . $search );

		return $search;
	}

	/**
	 * Create the Posts exclusion query.
	 *
	 * @return string
	 */
	private function se_build_exclude_posts(): string {
		global $wpdb;

		$exclude_query = '';
		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$excluded_post_list = trim( $this->options['se_exclude_posts_list'] );

			if ( '' !== $excluded_post_list ) {
				$excluded_post_list = array();

				foreach ( explode( ',', $excluded_post_list ) as $post_id ) {
					$excluded_post_list[] = (int) $post_id;
				}
				$excl_list     = implode( ',', $excluded_post_list );
				$exclude_query = ' AND (' . $wpdb->posts . '.ID NOT IN ( ' . $excl_list . ' ))';
			}

			$this->se_log( 'ex posts where: ' . $exclude_query );
		}

		return $exclude_query;
	}

	/**
	 * Create the Categories exclusion query.
	 *
	 * @return string
	 */
	private function se_build_exclude_categories(): string {
		global $wpdb;

		$exclude_query = '';
		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {

			$excluded_cat_list = trim( $this->options['se_exclude_categories_list'] );
			if ( '' !== $excluded_cat_list ) {
				$excluded_cat_list = array();

				foreach ( explode( ',', $excluded_cat_list ) as $cat_id ) {
					$excluded_cat_list[] = (int) $cat_id;
				}

				$excl_list     = implode( ',', $excluded_cat_list );
				$exclude_query = ' AND ( ctax.term_id NOT IN ( ' . $excl_list . " ) OR (wp_posts.post_type IN ( 'page' )))";
			}

			$this->se_log( 'ex category where: ' . $exclude_query );
		}

		return $exclude_query;
	}

	/**
	 *  Join for excluding categories
	 *
	 * @param string $join SQL string.
	 *
	 * @return string
	 * @depreacted 2.3
	 */
	private function se_exclude_categories_join( string $join ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {

			$join .= " LEFT JOIN $wpdb->term_relationships AS crel ON ($wpdb->posts.ID = crel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ctax ON (ctax.taxonomy = 'category' AND crel.term_taxonomy_id = ctax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS cter ON (ctax.term_id = cter.term_id) ";
		}

		$this->se_log( 'category join: ' . $join );

		return $join;
	}

	/**
	 * Join for searching comments.
	 *
	 * @param string $join SQL string.
	 *
	 * @return string
	 */
	public function se_comments_join( string $join ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$join .= " LEFT JOIN $wpdb->comments AS cmt ON ( cmt.comment_post_ID = $wpdb->posts.ID ) ";
		}

		$this->se_log( 'comments join: ' . $join );

		return $join;
	}

	/**
	 * Join for searching authors.
	 *
	 * @param string $join SQL string.
	 *
	 * @return string
	 */
	private function se_search_authors_join( string $join ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$join .= " LEFT JOIN $wpdb->users AS u ON ($wpdb->posts.post_author = u.ID) ";
		}

		$this->se_log( 'authors join: ' . $join );

		return $join;
	}

	/**
	 * Join for searching metadata.
	 *
	 * @param string $join SQL string.
	 *
	 * @return string
	 */
	private function se_search_metadata_join( string $join ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {
			$join .= " LEFT JOIN $wpdb->postmeta AS m ON ($wpdb->posts.ID = m.post_id) ";
		}

		$this->se_log( 'metadata join: ' . $join );

		return $join;
	}

	/**
	 * Join for searching tags.
	 *
	 * @param string $join SQL string.
	 *
	 * @return string
	 */
	public function se_terms_join( string $join ): string {
		global $wpdb;

		if ( ! empty( $this->query_instance->query_vars['s'] ) ) {

			// if we're searching for categories.
			if ( $this->options['se_use_category_search'] ) {
				$on[] = "ttax.taxonomy = 'category'";
			}

			// if we're searching for tags.
			if ( $this->options['se_use_tag_search'] ) {
				$on[] = "ttax.taxonomy = 'post_tag'";
			}

			// if we're searching custom taxonomies.
			if ( $this->options['se_use_tax_search'] ) {
				$all_taxonomies    = get_taxonomies();
				$filter_taxonomies = array( 'post_tag', 'category', 'nav_menu', 'link_category' );

				foreach ( $all_taxonomies as $taxonomy ) {
					if ( in_array( $taxonomy, $filter_taxonomies, true ) ) {
						continue;
					}

					$on[] = "ttax.taxonomy = '" . addslashes( $taxonomy ) . "'";
				}
			}

			// build our final string.
			$on    = ' ( ' . implode( ' OR ', $on ) . ' ) ';
			$join .= " LEFT JOIN $wpdb->term_relationships AS trel ON ($wpdb->posts.ID = trel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ttax ON ( " . $on . " AND trel.term_taxonomy_id = ttax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS tter ON (ttax.term_id = tter.term_id) ";
		}

		$this->se_log( 'tags join: ' . $join );

		return $join;
	}

	/**
	 * Highlight the searched terms into Title, excerpt and content
	 * in the search result page.
	 *
	 * @param mixed $postcontent Post content.
	 *
	 * @return array|mixed|string|string[]|null
	 */
	public function se_postfilter( $postcontent ) {
		global $wpdb;

		$s = $this->query_instance->query_vars['s'] ?? '';

		// highlighting.
		if ( ! is_admin() && is_search() && '' !== $s ) {
			$highlight_color = $this->options['se_highlight_color'];
			$highlight_style = $this->options['se_highlight_style'];
			$search_terms    = $this->se_get_search_terms();

			foreach ( $search_terms as $term ) {
				if ( preg_match( '/\>/', $term ) ) {
					continue;
				} //don't try to highlight this one.

				$term = preg_quote( $term );

				if ( '' !== $highlight_color ) {
					$postcontent = preg_replace( '"(?<!\<)(?<!\w)(\pL*' . $term . '\pL*)(?!\w|[^<>]*>)"iu', '<span class="search-everything-highlight-color" style="background-color:' . $highlight_color . '">$1</span>', $postcontent );
				} else {
					$postcontent = preg_replace( '"(?<!\<)(?<!\w)(\pL*' . $term . '\pL*)(?!\w|[^<>]*>)"iu', '<span class="search-everything-highlight" style="' . $highlight_style . '">$1</span>', $postcontent );
				}
			}
		}

		return $postcontent;
	}

	/**
	 * Log query.
	 *
	 * @param string   $query    Query string.
	 * @param WP_Query $wp_query WP Query.
	 *
	 * @return string
	 */
	public function se_log_query( string $query, WP_Query $wp_query ): string {
		if ( $wp_query->is_search ) {
			$this->se_log( $query );
		}

		return $query;
	}
}

add_action( 'wp_ajax_search_everything', 'search_everything_callback' );

/**
 * Search callback.
 */
function search_everything_callback() {
	global $wpdb;

	$s = $_GET['s'];

	$is_query = ! empty( $_GET['s'] );
	$result   = array();

	if ( $is_query ) {
		$result = array(
			'own'      => array(),
			'external' => array(),
		);

		$params = array(
			's' => $s,
		);

		$zemanta_response = se_api(
			array(
				'method'              => 'zemanta.suggest',
				'return_images'       => 0,
				'return_rich_objects' => 0,
				'return_articles'     => 1,
				'return_markup'       => 0,
				'return_rdf_links'    => 0,
				'return_keywords'     => 0,
				'careful_pc'          => 1,
				'interface'           => 'wordpress-se',
				'format'              => 'json',
				'emphasis'            => $_GET['s'],
				'text'                => $_GET['text'],
			)
		);

		if ( ! is_wp_error( $zemanta_response ) && 200 === $zemanta_response['response']['code'] ) {
			$result['external'] = json_decode( $zemanta_response['body'] )->articles;
		}

		new SearchEverything( true );

		if ( ! empty( $_GET['exact'] ) ) {
			$params['exact'] = true;
		}

		$params['showposts'] = 5;
		$post_query          = new WP_Query( $params );

		while ( $post_query->have_posts() ) {
			$post_query->the_post();

			$result['own'][] = get_post();
		}
		$post_query->reset_postdata();

	}

	print wp_json_encode( $result );

	die();
}


/**
 * Publish post ping.
 *
 * @param mixed $post_id Post ID.
 *
 * @return false
 */
function se_post_publish_ping( $post_id ): bool {

	// Should happen only on first publish.
	$status = false;

	if ( ! empty( $_POST['post_status'] ) && ( 'publish' === $_POST['post_status'] ) && ( 'publish' !== $_POST['original_post_status'] ) ) {
		$permalink        = get_permalink( $post_id );
		$zemanta_response = se_api(
			array(
				'method'      => 'zemanta.post_published_ping',
				'current_url' => $permalink,
				'post_url'    => $permalink,
				'post_rid'    => '',
				'interface'   => 'wordpress-se',
				'deployment'  => 'search-everything',
				'format'      => 'json',
			)
		);

		$response = json_decode( $zemanta_response['body'] );

		if ( isset( $response->status ) && ! is_wp_error( $zemanta_response ) ) {
			$status = $response->status;
		}
	}

	return $status;
}

add_action( 'publish_post', 'se_post_publish_ping' );
