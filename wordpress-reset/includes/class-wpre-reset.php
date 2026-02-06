<?php
/**
 * Main plugin class file.
 *
 * @package wordpress-reset
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The main WPRE_Reset class.
 */
class WPRE_Reset {

	/**
	 * Constructor. Contains Action/Filter Hooks.
	 *
	 * @access public
	 */
	public function __construct() {
		\add_action( 'admin_menu', array( $this, 'add_page' ) );
		\add_action( 'admin_init', array( $this, 'admin_init' ) );
		\add_action( 'wp_before_admin_bar_render', array( $this, 'admin_bar_link' ) );
		\add_filter( 'wp_mail', array( $this, 'hijack_mail' ), 1 );
	}

	/**
	 * While this plugin is active put a link to the reset page in the admin bar under the site title.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_bar_link(): void {
		// Only show to users who can reset the site.
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		global $wp_admin_bar;
		$wp_admin_bar->add_menu(
			array(
				'parent' => 'site-name',
				'id'     => 'wordpress-reset',
				'title'  => \esc_html__( 'Reset Site', 'wordpress-reset' ),
				'href'   => \admin_url( 'tools.php?page=wordpress-reset' ),
			)
		);
	}

	/**
	 * Checks for wordpress_reset post value and if there deletes all wp tables
	 * and performs an install, also populating the users previous password.
	 *
	 * @return void
	 */
	public function admin_init(): void {
		global $current_user;

		$wordpress_reset         = ( isset( $_POST['wordpress_reset'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['wordpress_reset'] ) ) );
		$wordpress_reset_confirm = ( isset( $_POST['wordpress_reset_confirm'] ) && 'reset' === sanitize_text_field( wp_unslash( $_POST['wordpress_reset_confirm'] ) ) );
		$valid_nonce             = ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wordpress_reset' ) );

		if ( $wordpress_reset && $wordpress_reset_confirm && $valid_nonce ) {
			// Verify user has permission to reset the site.
			if ( ! \current_user_can( 'activate_plugins' ) ) {
				\wp_die(
					\esc_html__( 'You do not have permission to reset this site.', 'wordpress-reset' ),
					\esc_html__( 'Permission Denied', 'wordpress-reset' ),
					array( 'response' => 403 )
				);
			}
			// Check if using SQLite.
			$is_sqlite = defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE;

			if ( $is_sqlite ) {
				// For SQLite, delete the database file and redirect to install screen.
				\wp_clear_auth_cookie();

						// Determine the database file path.
				// The SQLite integration plugin uses FQDB constant for the full database path.
				$database_file = null;
				if ( defined( 'FQDB' ) && FQDB ) {
					$database_file = FQDB;
				} elseif ( defined( 'DB_DIR' ) && defined( 'DB_FILE' ) ) {
					$database_file = \trailingslashit( DB_DIR ) . DB_FILE;
				} elseif ( defined( 'DB_FILE' ) ) {
					// Fallback: try WP_CONTENT_DIR/database/ directory.
					$database_file = \trailingslashit( WP_CONTENT_DIR ) . 'database/' . DB_FILE;
				} else {
					// Final fallback: default SQLite location.
					$database_file = \trailingslashit( WP_CONTENT_DIR ) . 'database/.ht.sqlite';
				}

				if ( $database_file && file_exists( $database_file ) ) {
					// Close the database connection before deleting the file.
					global $wpdb;
					if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) ) {
						// Close the PDO connection if it has a close method or unset it.
						if ( method_exists( $wpdb->dbh, 'close' ) ) {
							$wpdb->dbh->close();
						}
						$wpdb->dbh = null;
					}
					if ( ! \function_exists( 'WP_Filesystem' ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
					}

					// Initialize the filesystem with direct method (bypasses FTP credentials requirement).
					$filesystem_initialized = \WP_Filesystem( false, false, true );
					if ( ! $filesystem_initialized ) {
						\wp_die(
							\esc_html__( 'Could not initialize WordPress filesystem.', 'wordpress-reset' ),
							\esc_html__( 'Filesystem Error', 'wordpress-reset' ),
							array( 'back_link' => true )
						);
					}

					global $wp_filesystem;
					// Delete the database file. Third parameter 'f' specifies it's a file, not a directory.
					if ( ! $wp_filesystem->delete( $database_file, false, 'f' ) ) {
						\wp_die(
							/* translators: %s: The database file path. */
							\sprintf( \esc_html__( 'Could not delete the database file: %s', 'wordpress-reset' ), \esc_html( $database_file ) ),
							\esc_html__( 'Delete Error', 'wordpress-reset' ),
							array( 'back_link' => true )
						);
					}
				}

				// Redirect to WordPress installation screen.
				\wp_safe_redirect( \admin_url( 'install.php' ) );
				exit();
			}

			// MySQL/MariaDB reset process.
			// Load upgrade.php for wp_install() function.
			if ( ! function_exists( 'wp_install' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$blogname    = \get_option( 'blogname' );
			$blog_public = \get_option( 'blog_public' );

			// Determine which user to recreate after reset.
			$admin_user = \get_user_by( 'login', 'admin' );

			// Use admin user if it exists and has admin capabilities, otherwise use current user.
			if ( $admin_user && \user_can( $admin_user, 'activate_plugins' ) ) {
				$user = $admin_user;
			} else {
				$user = $current_user;
				// Verify current user has required capabilities.
				if ( ! \user_can( $user, 'activate_plugins' ) ) {
					\wp_die(
						\esc_html__( 'Current user lacks required administrator permissions.', 'wordpress-reset' ),
						\esc_html__( 'Permission Error', 'wordpress-reset' ),
						array( 'response' => 403 )
					);
				}
			}

			global $wpdb, $reactivate_wp_reset_additional;

			// MySQL/MariaDB: Use SHOW TABLES.
			$prefix = \str_replace( '_', '\_', $wpdb->prefix );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . '%' ) );

			// Drop all tables.
			foreach ( $tables as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
			}

			// Perform WordPress installation.
			$result = \wp_install( $blogname, $user->user_login, $user->user_email, $blog_public );

			// Get the new user ID from wp_install() result.
			// Defensive check even though wp_install() is documented to always return array with user_id.
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			/** @phpstan-ignore-next-line Defensive programming: check return type even though documented as always array */
			if ( ! is_array( $result ) || ! isset( $result['user_id'] ) ) {
				\wp_die(
					\esc_html__( 'WordPress installation failed during reset.', 'wordpress-reset' ),
					\esc_html__( 'Reset Error', 'wordpress-reset' ),
					array( 'back_link' => true )
				);
			}

			$new_user_id = (int) $result['user_id'];

			if ( ! $new_user_id ) {
				\wp_die(
					\esc_html__( 'Failed to create new user during reset.', 'wordpress-reset' ),
					\esc_html__( 'Reset Error', 'wordpress-reset' ),
					array( 'back_link' => true )
				);
			}

			// Restore the original user's password.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_pass = %s, user_activation_key = %s WHERE ID = %d', $wpdb->users, $user->user_pass, '', $new_user_id ) );

			$get_user_meta    = \function_exists( 'get_user_meta' ) ? 'get_user_meta' : 'get_usermeta';
			$update_user_meta = \function_exists( 'update_user_meta' ) ? 'update_user_meta' : 'update_usermeta';

			if ( $get_user_meta( $new_user_id, 'default_password_nag' ) ) {
				$update_user_meta( $new_user_id, 'default_password_nag', false );
			}

			if ( $get_user_meta( $new_user_id, $wpdb->prefix . 'default_password_nag' ) ) {
				$update_user_meta( $new_user_id, $wpdb->prefix . 'default_password_nag', false );
			}

			if ( defined( 'REACTIVATE_WP_RESET' ) && \REACTIVATE_WP_RESET === true ) {
				\activate_plugin( \plugin_basename( __FILE__ ) );
			}

			if ( ! empty( $reactivate_wp_reset_additional ) ) {
				foreach ( $reactivate_wp_reset_additional as $plugin ) {
					$plugin = \plugin_basename( $plugin );
					if ( ! \is_wp_error( \validate_plugin( $plugin ) ) ) {
						\activate_plugin( $plugin );
					}
				}
			}

			\wp_clear_auth_cookie();
			\wp_set_auth_cookie( $new_user_id );

			\wp_safe_redirect( \add_query_arg( 'reset', '1', \admin_url() ) );
			exit();
		}

		// Show success notice after reset.
		if ( isset( $_GET['reset'] ) ) {
			$reset_value = \sanitize_key( \wp_unslash( $_GET['reset'] ) );
			if ( '1' === $reset_value ) {
				$referer = \wp_get_referer();
				if ( $referer && false !== \strpos( $referer, 'wordpress-reset' ) ) {
					\add_action( 'admin_notices', array( $this, 'reset_notice' ) );
				}
			}
		}
	}

	/**
	 * Inform the user that WordPress has been successfully reset.
	 *
	 * @access public
	 * @return void
	 */
	public function reset_notice(): void {
		$user = \get_user_by( 'id', 1 );
		if ( $user ) {
			printf(
				/* translators: The username. */
				'<div id="message" class="updated fade"><p><strong>' . \esc_html__( 'WordPress has been reset back to defaults. The user "%s" was recreated with its previous password.', 'wordpress-reset' ) . '</strong></p></div>',
				\esc_html( $user->user_login )
			);
			\do_action( 'wordpress_reset_post', $user );
		}
	}

	/**
	 * Overwrite the password, because we actually reset it after this email goes out.
	 *
	 * @access public
	 * @param array<string, mixed> $args The email arguments.
	 * @return array<string, mixed> The modified email arguments.
	 */
	public function hijack_mail( array $args ): array {
		if ( \preg_match( '/Your new WordPress (blog|site) has been successfully set up at/i', $args['message'] ) ) {
			$args['message'] = \str_replace( 'Your new WordPress site has been successfully set up at:', 'Your WordPress site has been successfully reset, and can be accessed at:', $args['message'] );
			$args['message'] = \preg_replace( '/Password:.+/', 'Password: previously specified password', $args['message'] );
		}
		return $args;
	}

	/**
	 * Enqueue admin scripts and styles.
	 * Only loads on the plugin's admin page.
	 *
	 * @access public
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function admin_enqueue_scripts( string $hook ): void {
		// Only load on our plugin's page (Tools > Reset).
		if ( 'tools_page_wordpress-reset' !== $hook ) {
			return;
		}

		// Get plugin version from plugin headers.
		// Load required WordPress functions if not available.
		if ( ! \function_exists( 'get_file_data' ) ) {
			require_once ABSPATH . 'wp-includes/functions.php';
		}

		$plugin_data = \get_file_data(
			WPRE_PLUGIN_FILE,
			array( 'Version' => 'Version' )
		);
		$version     = $plugin_data['Version'] ?? '1.5.0';

		\wp_enqueue_style(
			'wordpress-reset-admin',
			\plugin_dir_url( __DIR__ ) . 'assets/css/wordpress-reset-admin.css',
			array(),
			$version
		);

		\wp_enqueue_script(
			'wordpress-reset',
			\plugin_dir_url( __DIR__ ) . 'assets/js/wordpress-reset.js',
			array(),
			$version,
			true
		);

		\wp_localize_script(
			'wordpress-reset',
			'wpResetData',
			array(
				'confirmMessage' => \__( 'This action is not reversible. Clicking OK will reset your database back to the defaults. Click Cancel to abort.', 'wordpress-reset' ),
				'alertMessage'   => \__( 'Invalid confirmation word. Please type the word reset in the confirmation field.', 'wordpress-reset' ),
			)
		);
	}

	/**
	 * Add the settings page.
	 *
	 * @access public
	 * @return void
	 */
	public function add_page(): void {
		if ( \current_user_can( 'activate_plugins' ) && \function_exists( 'add_management_page' ) ) {
			\add_management_page(
				\esc_html__( 'Reset', 'wordpress-reset' ),
				\esc_html__( 'Reset', 'wordpress-reset' ),
				'activate_plugins',
				'wordpress-reset',
				array( $this, 'admin_page' )
			);
			\add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		}
	}

	/**
	 * The settings page.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_page(): void {
		global $current_user, $reactivate_wp_reset_additional;

		// Check for SQLite and display warning notice.
		$is_sqlite = defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE;

		if ( $is_sqlite ) {
			echo '<div class="notice notice-warning"><p><strong>' . \esc_html__( 'SQLite Database Detected:', 'wordpress-reset' ) . '</strong> ' . \esc_html__( 'Resetting will delete the database file and redirect you to the WordPress installation screen. You will need to set up WordPress from scratch.', 'wordpress-reset' ) . '</p></div>';
		}

		if ( isset( $_POST['wordpress_reset_confirm'] ) && 'reset' !== sanitize_text_field( wp_unslash( $_POST['wordpress_reset_confirm'] ) ) ) {
			echo '<div class="error fade"><p><strong>' . \esc_html__( 'Invalid confirmation word. Please type the word "reset" in the confirmation field.', 'wordpress-reset' ) . '</strong></p></div>';
		} elseif ( isset( $_POST['_wpnonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wordpress_reset' ) ) {
			echo '<div class="error fade"><p><strong>' . \esc_html__( 'Invalid nonce. Please try again.', 'wordpress-reset' ) . '</strong></p></div>';
		}

		$missing = array();
		if ( ! empty( $reactivate_wp_reset_additional ) ) {
			foreach ( $reactivate_wp_reset_additional as $key => $plugin ) {
				if ( \is_wp_error( \validate_plugin( $plugin ) ) ) {
					unset( $reactivate_wp_reset_additional[ $key ] );
					$missing[] = $plugin;
				}
			}
		}

		$will_reactivate = ( defined( 'REACTIVATE_WP_RESET' ) && \REACTIVATE_WP_RESET === true );
		?>
		<div class="wrap">
			<div id="icon-tools" class="icon32"><br /></div>
			<h1><?php \esc_html_e( 'Reset', 'wordpress-reset' ); ?></h1>
			<h2><?php \esc_html_e( 'Details about the reset', 'wordpress-reset' ); ?></h2>
			<?php if ( $is_sqlite ) : ?>
				<p><strong><?php \esc_html_e( 'After completing this reset you will be taken to the WordPress installation screen.', 'wordpress-reset' ); ?></strong></p>
			<?php else : ?>
				<p><strong><?php \esc_html_e( 'After completing this reset you will be taken to the dashboard.', 'wordpress-reset' ); ?></strong></p>
				<?php $admin = \get_user_by( 'login', 'admin' ); ?>
				<?php if ( ! $admin || ! \user_can( $admin, 'activate_plugins' ) ) : ?>
					<?php $user = $current_user; ?>
					<?php /* translators: The username. */ ?>
					<p><?php printf( \esc_html__( 'The "admin" user does not exist or lacks administrator capabilities. The user %s will be recreated using its current password with administrator capabilities.', 'wordpress-reset' ), '<strong>' . \esc_html( $user->user_login ) . '</strong>' ); ?></p>
				<?php else : ?>
					<p><?php \esc_html_e( 'The "admin" user exists and will be recreated with its current password.', 'wordpress-reset' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! $is_sqlite ) : ?>
				<?php if ( $will_reactivate ) : ?>
					<p><?php echo \wp_kses_post( \__( 'This plugin <strong>will be automatically reactivated</strong> after the reset.', 'wordpress-reset' ) ); ?></p>
				<?php else : ?>
					<p><?php echo \wp_kses_post( \__( 'This plugin <strong>will not be automatically reactivated</strong> after the reset.', 'wordpress-reset' ) ); ?></p>
					<p>
						<?php
						echo \wp_kses_post(
							sprintf(
								/* translators: %1$s: The code to add. %2$s: wp-config.php. */
								\__( 'To have this plugin auto-reactivate, add %1$s to your %2$s file.', 'wordpress-reset' ),
								'<span class="code"><code>define( \'REACTIVATE_WP_RESET\', true );</code></span>',
								'<span class="code">wp-config.php</span>'
							)
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $reactivate_wp_reset_additional ) ) : ?>
					<?php \esc_html_e( 'The following additional plugins will be reactivated:', 'wordpress-reset' ); ?>
					<ul class="wpre-plugin-list">
						<?php foreach ( $reactivate_wp_reset_additional as $plugin ) : ?>
							<?php $plugin_data = \get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin ); ?>
							<li><strong><?php echo \esc_html( $plugin_data['Name'] ); ?></strong></li>
						<?php endforeach; ?>
						<?php unset( $reactivate_wp_reset_additional, $plugin, $plugin_data ); ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $missing ) ) : ?>
					<?php \esc_html_e( 'The following additional plugins are missing and cannot be reactivated:', 'wordpress-reset' ); ?>
					<ul class="wpre-plugin-list">
						<?php foreach ( $missing as $plugin ) : ?>
							<li><strong><?php echo \esc_html( $plugin ); ?></strong></li>
						<?php endforeach; ?>
						<?php unset( $missing, $plugin ); ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
			<h3><?php \esc_html_e( 'Reset', 'wordpress-reset' ); ?></h3>
			<?php /* translators: reset. */ ?>
			<p><?php printf( \esc_html__( 'Type %s in the confirmation field to confirm the reset and then click the reset button:', 'wordpress-reset' ), '<strong>reset</strong>' ); ?></p>
			<form id="wordpress_reset_form" action="" method="post">
				<?php \wp_nonce_field( 'wordpress_reset' ); ?>
				<input id="wordpress_reset" type="hidden" name="wordpress_reset" value="true" />
				<input id="wordpress_reset_confirm" type="text" name="wordpress_reset_confirm" value="" />
				<p class="submit">
					<input id="wordpress_reset_submit" type="submit" name="Submit" class="button-primary wpre-reset-button" value="<?php \esc_html_e( 'Reset', 'wordpress-reset' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}
}
