<?php
/**
 * Plugin Name: WP Database Reset
 * Plugin URI: http://sivel.net/wordpress/wordpress-reset/
 * Description: Resets the WordPress database back to its defaults. Deletes all customizations and content. Does not modify files only resets the database.
 * Author: Aristeidis Stathopoulos, Matt Martz
 * Version: 1.5.0
 * Author URI: https://aristath.github.io
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wordpress-reset
 *
 * WordPress Reset is released under the GNU General Public License (GPL)
 * http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package wordpress-reset
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin file constant.
if ( ! defined( 'WPRE_PLUGIN_FILE' ) ) {
	define( 'WPRE_PLUGIN_FILE', __FILE__ );
}

// Include the main class.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpre-reset.php';

// Instantiate the class.
if ( \is_admin() ) {
	$wordpress_reset = new WPRE_Reset();
}
