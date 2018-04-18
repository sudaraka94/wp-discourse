<?php
/**
 * Syncs Discourse comments with WordPress posts.
 *
 * @package WPDiscourse
 */

namespace WPDiscourse\DiscourseComment;

use WPDiscourse\Shared\PluginUtilities;

/**
 * Class DiscourseComment
 */
class DiscourseComment {
	use PluginUtilities;

	/**
	 * Gives access to the plugin options.
	 *
	 * @access protected
	 * @var mixed|void
	 */
	protected $options;

	/**
	 * An instance of the DiscourseCommentFormatter class.
	 *
	 * @access protected
	 * @var \WPDiscourse\DiscourseCommentFormatter\DiscourseCommentFormatter DiscourseCommentFormatter
	 */
	protected $comment_formatter;

	/**
	 * DiscourseComment constructor.
	 *
	 * @param \WPDiscourse\DiscourseCommentFormatter\DiscourseCommentFormatter $comment_formatter An instance of DiscourseCommentFormatter.
	 */
	public function __construct( $comment_formatter ) {
		$this->comment_formatter = $comment_formatter;

		add_action( 'init', array( $this, 'setup_options' ) );
		add_filter( 'get_comments_number', array( $this, 'get_comments_number' ), 10, 2 );
		add_action( 'wpdc_sync_discourse_comments', array( $this, 'sync_comments' ) );
		add_filter( 'comments_template', array( $this, 'comments_template' ), 20, 1 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'extend_allowed_html' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'discourse_comments_js' ) );
		add_action( 'rest_api_init', array( $this, 'initialize_comment_route' ) );
	}

	/**
	 * Setup options.
	 */
	public function setup_options() {
		$this->options = $this->get_options();
	}

	/**
	 * Adds data-youtube-id to the allowable div attributes.
	 *
	 * Discourse returns the youtube video id as the value of the 'data-youtube-attribute',
	 * this function makes it possible to filter the comments with `wp_kses_post` without
	 * stripping out that attribute.
	 *
	 * @param array  $allowedposttags The array of allowed post tags.
	 * @param string $context The current context ('post', 'data', etc.).
	 *
	 * @return mixed
	 */
	public function extend_allowed_html( $allowedposttags, $context ) {
		if ( 'post' === $context ) {
			$allowedposttags['div'] = array(
				'class'           => true,
				'id'              => true,
				'style'           => true,
				'title'           => true,
				'role'            => true,
				'data-youtube-id' => array(),
			);
		}

		return $allowedposttags;
	}

	/**
	 * Enqueues the `comments.js` script.
	 *
	 * Hooks into 'wp_enqueue_scripts'.
	 */
	public function discourse_comments_js() {
		// Is the query for an existing single post of any of the allowed_post_types?
		if ( isset( $this->options['allowed_post_types'] ) && is_singular( $this->options['allowed_post_types'] ) ) {
			if ( $this->use_discourse_comments( get_the_ID() ) ) {
				wp_enqueue_script(
					'discourse-comments-js',
					WPDISCOURSE_URL . '/js/comments.js',
					array( 'jquery' ),
					get_option( 'discourse_version' ),
					true
				);
				// Localize script.
				$data = array(
					'url' => $this->options['url'],
				);
				wp_localize_script( 'discourse-comments-js', 'discourse', $data );
			}
		}

		if ( ! empty( $this->options['ajax-load'] ) ) {
			wp_register_script( 'load_comments_js', plugins_url( '../js/load-comments.js', __FILE__ ), array( 'jquery' ), WPDISCOURSE_VERSION, true );
			$data = array(
				'commentsURL' => home_url( '/wp-json/wp-discourse/v1/discourse-comments' ),
			);
			wp_enqueue_script( 'load_comments_js' );
			wp_localize_script( 'load_comments_js', 'wpdc', $data );
		}

		if ( ! empty( $this->options['load-comment-css'] ) ) {
			wp_register_style( 'comment_styles', WPDISCOURSE_URL . '/css/comments.css', array(), WPDISCOURSE_VERSION );
			wp_enqueue_style( 'comment_styles' );
		}
	}

	/**
	 * Registers a Rest API route for returning comments at /wp-json/wp-discourse/v1/discourse-comments.
	 */
	public function initialize_comment_route() {
		if ( ! empty( $this->options['ajax-load'] ) ) {
			register_rest_route(
				'wp-discourse/v1', 'discourse-comments', array(
					array(
						'methods'  => \WP_REST_Server::READABLE,
						'callback' => array( $this, 'get_discourse_comments' ),
					),
				)
			);
		}
	}

	/**
	 * Handles the REST request for Discourse comments.
	 *
	 * @param \WP_REST_Request Object $request The WP_REST_Request for Discourse comments.
	 *
	 * @return string
	 */
	public function get_discourse_comments( $request ) {
		$post_id = isset( $request['post_id'] ) ? esc_attr( wp_unslash( $request['post_id'] ) ) : null;

		if ( empty( $post_id ) ) {

			return '';
		}

		return wp_kses_post( $this->comment_formatter->format( $post_id ) );
	}

	/**
	 * Checks if a post is using Discourse comments.
	 *
	 * @param int $post_id The ID of the post.
	 *
	 * @return bool|int
	 */
	protected function use_discourse_comments( $post_id ) {
		if ( empty( $this->options['use-discourse-comments'] ) ) {

			return 0;
		}

		$discourse_post_id = get_post_meta( $post_id, 'discourse_post_id', true );

		return $discourse_post_id > 0;
	}

	protected function use_join_link( $post_id ) {
		if ( empty( $this->options['add-join-link'] ) ) {

			return 0;
		}

		$discourse_post_id = get_post_meta( $post_id, 'discourse_post_id', true );

		return $discourse_post_id > 0;
	}

	/**
	 * Syncs Discourse comments to WordPress.
	 *
	 * @param int $postid The WordPress post id.
	 *
	 * @return null
	 */
	public function sync_comments( $postid ) {
		global $wpdb;

		$discourse_options     = $this->options;
		$use_discourse_webhook = ! empty( $discourse_options['use-discourse-webhook'] );
		$time                  = date_create()->format( 'U' );

		if ( ! $use_discourse_webhook ) {
			// Every 10 minutes do a json call to sync comment count and top comments.
			$last_sync   = (int) get_post_meta( $postid, 'discourse_last_sync', true );
			$sync_period = apply_filters( 'wpdc_comment_sync_period', 600, $postid );
			$sync_post   = $last_sync + $sync_period < $time;
		} else {
			$sync_post = ( 1 === intval( get_post_meta( $postid, 'wpdc_sync_post_comments', true ) ) );
		}

		if ( $sync_post ) {
			// Avoids a double sync.
			wp_cache_set( 'discourse_comments_lock', $wpdb->get_row( "SELECT GET_LOCK( 'discourse_lock', 0 ) got_it" ) );
			if ( 1 === intval( wp_cache_get( 'discourse_comments_lock' )->got_it ) ) {

				if ( 'publish' === get_post_status( $postid ) ) {

					$comment_count            = intval( $discourse_options['max-comments'] );
					$min_trust_level          = intval( $discourse_options['min-trust-level'] );
					$min_score                = intval( $discourse_options['min-score'] );
					$min_replies              = intval( $discourse_options['min-replies'] );
					$bypass_trust_level_score = intval( $discourse_options['bypass-trust-level-score'] );

					$options = 'best=' . $comment_count . '&min_trust_level=' . $min_trust_level . '&min_score=' . $min_score;
					$options = $options . '&min_replies=' . $min_replies . '&bypass_trust_level_score=' . $bypass_trust_level_score;

					if ( isset( $discourse_options['only-show-moderator-liked'] ) && 1 === intval( $discourse_options['only-show-moderator-liked'] ) ) {
						$options = $options . '&only_moderator_liked=true';
					}
					$options = $options . '&api_key=' . $discourse_options['api-key'] . '&api_username=' . $discourse_options['publish-username'];

					$discourse_permalink = get_post_meta( $postid, 'discourse_permalink', true );
					if ( ! $discourse_permalink ) {

						return 0;
					}
					$permalink = esc_url_raw( $discourse_permalink ) . '/wordpress.json?' . $options;

					$result = wp_remote_get( $permalink );

					if ( $this->validate( $result ) ) {

						$json = json_decode( $result['body'] );

						if ( isset( $json->posts_count ) ) {
							$posts_count = $json->posts_count - 1;
							if ( $posts_count < 0 ) {
								$posts_count = 0;
							}

							update_post_meta( $postid, 'discourse_comments_count', $posts_count );
							update_post_meta( $postid, 'discourse_comments_raw', esc_sql( $result['body'] ) );
						}
					}

					update_post_meta( $postid, 'discourse_last_sync', $time );
					update_post_meta( $postid, 'wpdc_sync_post_comments', 0 );
				}// End if().
			}// End if().

			wp_cache_set( 'discourse_comments_lock', $wpdb->get_results( "SELECT RELEASE_LOCK( 'discourse_lock' )" ) );
		}// End if().

		return null;
	}

	/**
	 * Loads the comments template.
	 *
	 * Hooks into 'comments_template'.
	 *
	 * @param string $old The comments template returned by WordPress.
	 *
	 * @return string
	 */
	public function comments_template( $old ) {
		global $post;
		$post_id = $post->ID;

		if ( $this->use_discourse_comments( $post_id ) ) {
			if ( ! empty( $this->options['ajax-load'] ) ) {

				return WPDISCOURSE_PATH . 'templates/ajax-comments.php';
			}

			$discourse_comments = $this->comment_formatter->format( $post_id );

			// Use $post->comment_count because get_comments_number will return the Discourse comments
			// number for posts that are published to Discourse.
			$num_wp_comments = $post->comment_count;
			if ( empty( $this->options['show-existing-comments'] ) || 0 === intval( $num_wp_comments ) ) {
				echo wp_kses_post( $discourse_comments );

				return WPDISCOURSE_PATH . 'templates/blank.php';
			} else {
				echo wp_kses_post( $discourse_comments ) . '<div class="discourse-existing-comments-heading">' . wp_kses_post( $this->options['existing-comments-heading'] ) . '</div>';

				return $old;
			}
		}

		// Discourse comments are not being used. Return the default comments tempate.
		return $old;
	}

	/**
	 * Returns the 'discourse_comments_count' for posts that use Discourse comments.
	 *
	 * Returns the sum of the Discourse comments and the WordPress comments for posts that have both.
	 *
	 * @param int $count The comment count supplied by WordPress.
	 * @param int $post_id The ID of the post.
	 *
	 * @return mixed
	 */
	public function get_comments_number( $count, $post_id ) {
		if ( $this->use_discourse_comments( $post_id ) ) {

			$single_page = is_single( $post_id ) || is_page( $post_id );
			$single_page = apply_filters( 'wpdc_single_page_comment_number_sync', $single_page, $post_id );

			if ( empty( $this->options['use-discourse-webhook'] ) ) {
				// Only automatically sync comments for individual posts, it's too inefficient to do this with an archive page.
				if ( $single_page ) {
					$this->sync_comments( $post_id );
				} else {
					// For archive pages, check $last_sync against $archive_page_sync_period.
					$archive_page_sync_period = intval( apply_filters( 'discourse_archive_page_sync_period', DAY_IN_SECONDS, $post_id ) );
					$last_sync                = intval( get_post_meta( $post_id, 'discourse_last_sync', true ) );

					if ( $last_sync + $archive_page_sync_period < time() ) {
						$this->sync_comments( $post_id );
					}
				}
			}

			$count = intval( get_post_meta( $post_id, 'discourse_comments_count', true ) );

			// If WordPress comments are also being used, add them to the comment count.
			if ( ! empty( $this->options['show-existing-comments'] ) ) {
				$current_post     = get_post( $post_id );
				$wp_comment_count = $current_post->comment_count;
				$count            = $count + $wp_comment_count;
			}
		}

		return $count;
	}

	protected function get_discourse_comments_number( $post_id ) {
		$api_key = $this->options['api-key'];
		$api_username = $this->options['publish-username'];
		$discourse_permalink = get_post_meta( $post_id, 'discourse_permalink', true );

		if ( empty( $discourse_permalink ) ) {

			return new \WP_Error( 'wpdc_error', 'The comment number cannot be retrieved, because the discourse_permalink has not been set for the post.' );
		}

		$topic_url = esc_url_raw(
			add_query_arg(
				array(
					'api_key' => $api_key,
					'api_username' => $api_username,
				), "{$discourse_permalink}.json"
			)
		);

		$response = wp_remote_get( $topic_url );

		if ( ! $this->validate( $response ) ) {

			return new \WP_Error( 'wpdc_response_error', 'The topic posts_count could not be retrieved from Discourse.' );
		}

		$topic = json_decode( wp_remote_retrieve_body( $response ) );

		write_log( 'topic', $topic );
	}
}
