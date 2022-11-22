<?php
/**
 * Our "API client," responsible for turning recent toots into WordPress posts.
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

/**
 * Import handler.
 */
class Import_Handler {
	/**
	 * This plugin's settings.
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @param Options_Handler $options_handler The plugin's Options Handler.
	 */
	public function __construct( $options_handler ) {
		$this->options = $options_handler->get_options();
	}

	/**
	 * Registers hook callbacks.
	 */
	public function register() {
		add_action( 'import_from_mastodon_get_statuses', array( $this, 'get_statuses' ) );
		add_action( 'import_from_mastodon_get_favourites', array( $this, 'get_favourites' ) );
	}

	/**
	 * Grabs statuses off Mastodon and adds 'em as WordPress posts.
	 */
	public function get_statuses() {
		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		$account_id = $this->get_account_id();

		if ( null === $account_id ) {
			error_log( '[Import From Mastodon] Could not get account ID; token invalid?' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$args = array(
			'exclude_reblogs' => empty( $this->options['include_reblogs'] ),
			'exclude_replies' => empty( $this->options['include_replies'] ),
			'limit'           => 1, // Initially, import only one status.
		);

		if ( isset( $this->options['latest_toot'] ) ) {
			$args['since_id'] = $this->options['latest_toot'];
			$args['limit']    = apply_filters( 'import_from_mastodon_limit', 40 ); // Lowering this number might prevent timeouts but could lead to skipped toots.
		}

		$query_string = http_build_query( $args );

		if ( $this->options['tags'] ) {
			$tags = explode( ',', (string) $this->options['tags'] );

			foreach ( $tags as $tag ) {
				$query_string .= '&tagged[]=' . rawurlencode( $tag );
			}
		}

		$headers = array(
			'Accept' => 'application/json',
		);

		if ( empty( $this->options['public_only'] ) ) {
			// Importing non-public toots requires an auth token.
			$headers['Authorization'] = 'Bearer ' . $this->options['mastodon_access_token'];
		}

		$response = wp_remote_get(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/accounts/' . $account_id . '/statuses?' . $query_string ),
			array(
				'headers' => $headers,
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( '[Import From Mastodon] Failed to get toots: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$body     = wp_remote_retrieve_body( $response );
		$statuses = json_decode( $body );

		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			error_log( '[Import From Mastodon] No new toots found.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		if ( ! empty( $this->options['denylist'] ) ) {
			// Prep our denylist; doing this only once.
			$denylist = explode( "\n", (string) $this->options['denylist'] );
			$denylist = array_map( 'trim', $denylist );
		}

		// Reverse the array, so that the most recent status is inserted last.
		$statuses = array_reverse( $statuses );

		foreach ( $statuses as $status ) {
			if ( isset( $status->visibility ) && 'direct' === $status->visibility ) {
				// Direct message. Skip. Followers-only and unlisted toots _can_
				// be imported, depending on the "public only" setting.
				continue;
			}

			if ( ! empty( $denylist ) && isset( $status->content ) && str_ireplace( $denylist, '', $status->content ) !== $status->content ) {
				// Denylisted.
				continue;
			}

			// ID (on our instance).
			if ( empty( $status->id ) ) {
				// This should never happen.
				continue;
			}

			if ( ! empty( $status->reblog->url ) ) {
				$url = $status->reblog->url;
			} else {
				$url = $status->url;
			}

			if ( self::already_exists( $url ) ) {
				// Use URL rather than ID, to avoid clashes after switching
				// instances, etc.
				continue;
			}

			$content = trim(
				wp_kses(
					$status->content,
					array(
						'a'  => array(
							'href' => array(),
						),
						'br' => array(),
						'p'  => array(),
					)
				)
			);

			if ( isset( $status->reblog->url ) && isset( $status->reblog->account->username ) ) {
				$content = trim(
					wp_kses(
						isset( $status->reblog->content ) ? $status->reblog->content : '',
						array(
							'a'  => array(
								'href' => array(),
							),
							'br' => array(),
							'p'  => array(),
						)
					)
				);

				// Add a little bit of context to boosts.
				if ( ! empty( $content ) ) {
					$content  = '<div class="h-cite u-repost-of"><blockquote>' . PHP_EOL . '<div class="e-content">' . $content . '</div>' . PHP_EOL;
					$content .= '&mdash;<a class="u-url" href="' . esc_url( $status->reblog->url ) . '" rel="nofollow">@' . esc_html( $status->reblog->account->username ) . '</a>';
					$content .= PHP_EOL . '</blockquote></div>';
				}

				/* @todo: Also include reblogs' attachments. */
			} elseif ( isset( $status->in_reply_to_id ) ) {
				// We could eventually add some kind of threading, but let's
				// stick with a tiny bit of context for now.
				$parent = wp_remote_get(
					esc_url_raw( $this->options['mastodon_host'] . '/api/v1/statuses/' . $status->in_reply_to_id ),
					array(
						'headers' => array( 'Accept' => 'application/json' ),
						'timeout' => 11,
					)
				);

				if ( is_wp_error( $parent ) ) {
					// An error occurred.
					error_log( '[Import From Mastodon] Failed to get parent: ' . $parent->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					$parent = wp_remote_retrieve_body( $parent );
					$parent = json_decode( $parent );

					if ( isset( $parent->url ) && isset( $parent->account->username ) ) {
						/* translators: %s: reply link */
						$content = sprintf( esc_html__( 'In reply to %s.', 'import-from-mastodon' ), '<a class="u-in-reply-to" href="' . esc_url( $parent->url ) . '" rel="nofollow">@' . esc_html( $parent->account->username ) . '</a>' ) . PHP_EOL . PHP_EOL . $content;
						$content = trim( $content ); // Trim off trailing whitespace, in case `$content` was originally empty.
					}
				}
			}

			if ( empty( $content ) && empty( $status->media_attachments ) ) {
				// Skip.
				continue;
			}

			$title = '';

			if ( ! empty( $content ) ) {
				// Generate title from content.
				$title = wp_trim_words( $content, 10 );
			} elseif ( ! empty( $status->media_attachments[0]->description ) ) {
				// Generate title from first image's alt text.
				$title = wp_trim_words( $content, 10 );
			}

			// Allow the autogenerated content and title to be overriden.
			$content = apply_filters( 'import_from_mastodon_post_content', $content, $status );
			$title   = apply_filters( 'import_from_mastodon_post_title', $title, $status );

			$args = array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => isset( $this->options['post_status'] ) ? $this->options['post_status'] : 'publish',
				'post_type'     => isset( $this->options['post_type'] ) ? $this->options['post_type'] : 'post', // There used to be a `post_type` setting.
				'post_date_gmt' => ! empty( $status->created_at ) ? date( 'Y-m-d H:i:s', strtotime( $status->created_at ) ) : '', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'meta_input'    => array(),
			);

			if ( ! empty( $this->options['post_author'] ) && false !== get_userdata( $this->options['post_author'] ) ) {
				$args['post_author'] = $this->options['post_author'];
			}

			if ( ! empty( $this->options['post_category'] ) && term_exists( $this->options['post_category'], 'category' ) ) {
				$args['post_category'] = array( $this->options['post_category'] );
			}

			$args['meta_input']['_import_from_mastodon_id']  = $status->id;
			$args['meta_input']['_import_from_mastodon_url'] = esc_url_raw( $url );

			if ( empty( $title ) && ! empty( $args['meta_input']['_import_from_mastodon_url'] ) ) {
				$args['post_title'] = $args['meta_input']['_import_from_mastodon_url'];
			}

			$post_id = wp_insert_post( apply_filters( 'import_from_mastodon_args', $args, $status ) );

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				// Skip.
				continue;
			}

			// We use this hook to save the most recently imported toot's ID.
			do_action( 'import_from_mastodon_after_import', $post_id, $status );

			if ( ! empty( $status->media_attachments ) ) {
				$i = 0;

				foreach ( $status->media_attachments as $attachment ) {
					if ( empty( $attachment->type ) || 'image' !== $attachment->type ) {
						// For now, only images are supported.
						continue;
					}

					if ( empty( $attachment->url ) || ! wp_http_validate_url( $attachment->url ) ) {
						// Invalid URL.
						continue;
					}

					// Download the image into WordPress's uploads folder, and
					// attach it to the newly created post.
					$attachment_id = $this->create_attachment(
						$attachment->url,
						$post_id,
						! empty( $attachment->description ) ? $attachment->description : ''
					);

					if ( 0 === $i && 0 !== $attachment_id && apply_filters( 'import_from_mastodon_featured_image', true ) ) {
						// Set the first successfully uploaded attachment as
						// featured image.
						set_post_thumbnail( $post_id, $attachment_id );
					}

					$i++;
				}

				do_action( 'import_from_mastodon_after_attachments', $post_id, $status );
			}
		}
	}

	/**
	 * Grabs favourites off Mastodon and adds 'em as WordPress posts.
	 */
	public function get_favourites() {
		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		$account_id = $this->get_account_id();

		if ( null === $account_id ) {
			error_log( '[Import From Mastodon] Could not get account ID; token invalid?' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$args = array(
			'limit'           => 1, // Initially, import only one fave.
		);
		
		/* @todo: Figure out pagination. */
		// if ( isset( $this->options['latest_favourite'] ) ) {
		// 	$args['since_id'] = $this->options['latest_favourite'];
		// 	$args['limit']    = apply_filters( 'import_from_mastodon_limit', 40 ); // Lowering this number might prevent timeouts but could lead to skipped faves.
		// }

		$query_string = http_build_query( $args );

		$headers = array(
			'Accept' => 'application/json',
			'Authorization' => 'Bearer ' . $this->options['mastodon_access_token']
		);

		$response = wp_remote_get(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/favourites?' . $query_string ),
			array(
				'headers' => $headers,
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( '[Import From Mastodon] Failed to get favourites: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$body     = wp_remote_retrieve_body( $response );
		$favourites = json_decode( $body );

		if ( empty( $favourites ) || ! is_array( $favourites ) ) {
			error_log( '[Import From Mastodon] No new favourites found.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Reverse the array, so that the most recent status is inserted last.
		$favourites = array_reverse( $favourites );

		foreach ( $favourites as $favourite ) {
			if ( isset( $favourite->visibility ) && 'direct' === $favourite->visibility ) {
				// Direct message. Skip. Followers-only and unlisted toots _can_
				// be imported, depending on the "public only" setting.
				//
				// Is this check really necessary for favourites? :shrug_emoji:
				continue;
			}

			// ID (on our instance).
			if ( empty( $favourite->id ) ) {
				// This should never happen.
				continue;
			}

			$url = $favourite->url;

			if ( self::already_exists( $url ) ) {
				// Use URL rather than ID, to avoid clashes after switching
				// instances, etc.
				continue;
			}

			$content = trim(
				wp_kses(
					$favourite->content,
					array(
						'a'  => array(
							'href' => array(),
						),
						'br' => array(),
						'p'  => array(),
					)
				)
			);

			// Add a little bit of context.
			if ( ! empty( $content ) ) {
				$content  = '<div class="h-cite u-favorite-of"><blockquote>' . PHP_EOL . '<div class="e-content">' . $content . '</div>' . PHP_EOL;
				$content .= '&mdash;<a class="u-url" href="' . esc_url( $favourite->url ) . '" rel="nofollow">@' . esc_html( $favourite->account->username ) . '</a>';
				$content .= PHP_EOL . '</blockquote></div>';
			}

			if ( empty( $content ) && empty( $favourite->media_attachments ) ) {
				// Skip.
				continue;
			}

			$title = '';

			if ( ! empty( $content ) ) {
				// Generate title from content.
				$title = wp_trim_words( $content, 10 );
			} elseif ( ! empty( $favourite->media_attachments[0]->description ) ) {
				// Generate title from first image's alt text.
				$title = wp_trim_words( $content, 10 );
			}

			// Allow the autogenerated content and title to be overriden.
			$content = apply_filters( 'import_from_mastodon_post_content', $content, $favourite );
			$title   = apply_filters( 'import_from_mastodon_post_title', $title, $favourite );

			$args = array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => isset( $this->options['post_status'] ) ? $this->options['post_status'] : 'publish',
				'post_type'     => isset( $this->options['post_type'] ) ? $this->options['post_type'] : 'post', // There used to be a `post_type` setting.
				'post_date_gmt' => '', // There's no timestamp for favourites on Mastodon's API, so the best we can do it use the current time – it's a terrible idea for importing old faves, but works better for new faves than using the date of the faved toot I think
				'meta_input'    => array(),
			);

			if ( ! empty( $this->options['post_author'] ) && false !== get_userdata( $this->options['post_author'] ) ) {
				$args['post_author'] = $this->options['post_author'];
			}

			if ( ! empty( $this->options['post_category'] ) && term_exists( $this->options['post_category'], 'category' ) ) {
				$args['post_category'] = array( $this->options['post_category'] );
			}

			$args['meta_input']['_import_from_mastodon_id']  = $favourite->id;
			$args['meta_input']['_import_from_mastodon_url'] = esc_url_raw( $url );

			if ( empty( $title ) && ! empty( $args['meta_input']['_import_from_mastodon_url'] ) ) {
				$args['post_title'] = $args['meta_input']['_import_from_mastodon_url'];
			}

			$post_id = wp_insert_post( apply_filters( 'import_from_mastodon_args', $args, $favourite ) );

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				// Skip.
				continue;
			}

			// We use this hook to save the most recently imported toot's ID.
			do_action( 'import_from_mastodon_after_import', $post_id, $favourite );

			if ( ! empty( $favourite->media_attachments ) ) {
				$i = 0;

				foreach ( $favourite->media_attachments as $attachment ) {
					if ( empty( $attachment->type ) || 'image' !== $attachment->type ) {
						// For now, only images are supported.
						continue;
					}

					if ( empty( $attachment->url ) || ! wp_http_validate_url( $attachment->url ) ) {
						// Invalid URL.
						continue;
					}

					// Download the image into WordPress's uploads folder, and
					// attach it to the newly created post.
					$attachment_id = $this->create_attachment(
						$attachment->url,
						$post_id,
						! empty( $attachment->description ) ? $attachment->description : ''
					);

					if ( 0 === $i && 0 !== $attachment_id && apply_filters( 'import_from_mastodon_featured_image', true ) ) {
						// Set the first successfully uploaded attachment as
						// featured image.
						set_post_thumbnail( $post_id, $attachment_id );
					}

					$i++;
				}

				do_action( 'import_from_mastodon_after_attachments', $post_id, $favourite );
			}
		}
	}

	/**
	 * Uploads an image to a certain post.
	 *
	 * @param  string $attachment_url Image URL.
	 * @param  int    $post_id        Post ID.
	 * @param  string $description    Image `alt` text.
	 * @return int                    Attachment ID, and 0 on failure.
	 */
	private function create_attachment( $attachment_url, $post_id, $description ) {
		if ( ! function_exists( 'wp_crop_image' ) ) {
			// Load image functions.
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Get the "current" WordPress upload dir.
		$wp_upload_dir = wp_upload_dir();

		// *Assuming* unique filenames, here.
		$filename  = pathinfo( $attachment_url, PATHINFO_FILENAME ) . '.' . pathinfo( $attachment_url, PATHINFO_EXTENSION );
		$file_path = trailingslashit( $wp_upload_dir['path'] ) . $filename;

		if ( file_exists( $file_path ) ) {
			error_log( '[Import From Mastodon] File already exists: ' . esc_url_raw( $attachment_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// File already exists, somehow. So either we've got a different
			// file with the exact same name or we're trying to re-import the
			// exact same file. Assuming the latter, also because Mastodon's
			// random file names aren't something we would easily come up with.

			/* @todo: Create our own filenames based on complete URL hashes? */
			$file_url      = str_replace( $wp_upload_dir['basedir'], $wp_upload_dir['baseurl'], $file_path );
			$attachment_id = attachment_url_to_postid( $file_url ); // Attachment ID or 0.
		} else {
			// Download attachment.
			$response = wp_remote_get(
				esc_url_raw( $attachment_url ),
				array(
					'headers' => array( 'Accept' => 'image/*' ),
					'timeout' => 11,
				)
			);

			if ( is_wp_error( $response ) ) {
				return 0;
			}

			if ( empty( $response['body'] ) ) {
				return 0;
			}

			global $wp_filesystem;

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			// Write image data.
			if ( ! $wp_filesystem->put_contents( $file_path, $response['body'], 0644 ) ) {
				error_log( '[Import From Mastodon] Could not save image file: ' . $file_path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return 0;
			}

			if ( ! file_is_valid_image( $file_path ) || ! file_is_displayable_image( $file_path ) ) {
				unlink( $file_path );

				error_log( '[Import From Mastodon] Invalid image file: ' . esc_url_raw( $attachment_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return 0;
			}

			// Import the image into WordPress' media library.
			$attachment = array(
				'guid'           => $file_path,
				'post_mime_type' => wp_check_filetype( $filename, null )['type'],
				'post_title'     => $filename,
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, trailingslashit( $wp_upload_dir['path'] ) . $filename, $post_id );
		}

		if ( empty( $attachment_id ) ) {
			// Something went wrong.
			error_log( '[Import From Mastodon] Invalid image file: ' . esc_url_raw( $attachment_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}

		// Generate metadata. Generates thumbnails, too.
		$metadata = wp_generate_attachment_metadata(
			$attachment_id,
			trailingslashit( $wp_upload_dir['path'] ) . $filename
		);

		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Explicitly set image `alt` text.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_textarea_field( $description ) );

		return $attachment_id;
	}

	/**
	 * Get the authenticated user's account ID.
	 *
	 * @return int|null Account ID, or null on failure.
	 */
	private function get_account_id() {
		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return null;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return null;
		}

		$response = wp_remote_get(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/accounts/verify_credentials' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
					'Accept'        => 'application/json',
				),
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Import From Mastodon] Could not get account ID: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		if ( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) ) {
			error_log( '[Import From Mastodon] Invalid token?' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// The current access token has somehow become invalid. Forget it.
			unset( $this->options['mastodon_access_token'] );
			update_option( 'import_from_mastodon_settings', $this->options );
		}

		$body    = wp_remote_retrieve_body( $response );
		$account = json_decode( $body );

		if ( empty( $account->id ) ) {
			error_log( '[Import From Mastodon] Despite the seemingly valid response, could not get account ID' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		return (int) $account->id;
	}

	/**
	 * Checks for the existence of a similar post.
	 *
	 * We normally try to avoid double posts by including a `since_id API param,
	 * but that one gets reset when switching instances.
	 *
	 * @param  string $toot_url Toot URL, because IDs aren't unique per se.
	 * @return int|bool         The corresponding post ID, or false.
	 */
	public static function already_exists( $toot_url ) {
		// Fetch the most recent toot's post ID.
		$query = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'fields'      => 'ids',
				'limit'       => 1,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_import_from_mastodon_url',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_import_from_mastodon_url',
						'compare' => '=',
						'value'   => esc_url_raw( $toot_url ),
					),
				),
			)
		);

		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return false;
		}

		if ( ! is_array( $posts ) ) {
			return false;
		}

		return reset( $posts );
	}

	/**
	 * Returns the most recent toot's Mastodon ID.
	 *
	 * @return string|null Mastodon ID, or `null`.
	 */
	private function get_latest_status() {
		// Fetch the most recent toot's post ID.
		$query = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				// Ordering by ID would not necessarily give us the most recent
				// status, like, in the case of re-imports, etc.
				'meta_key'    => '_import_from_mastodon_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'     => 'meta_value_num', // Assume a numerical ID.
				'order'       => 'DESC',
				'fields'      => 'ids',
				'limit'       => 1,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_import_from_mastodon_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_import_from_mastodon_id',
						'compare' => '!=',
						'value'   => '',
					),
				),
			)
		);

		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return null;
		}

		if ( ! is_array( $posts ) ) {
			return null;
		}

		// Return Mastodon ID of most recent post with a Mastodon ID.
		return get_post_meta( reset( $posts ), '_import_from_mastodon_id', true ); // A (numeric, most likely) string.
	}
}
