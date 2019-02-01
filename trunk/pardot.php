<?php

/*
* Plugin Name: Pardot
* Description: Connect your WordPress to Pardot with shortcode and widgets for campaign tracking, quick form access, and dynamic content.
* Author: cliffseal
* Author URI: https://cliffseal.com
* Plugin URI: https://wordpress.org/plugins/pardot/
* Developer: Cliff Seal
* Developer URI: https://cliffseal.com
* Version: 1.5
* License: GPLv2
*
* Copyright 2012 Pardot LLC
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License, version 2, as
* published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301 USA
*
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// define( 'PARDOT_API_EMAIL', 'cliff@logoscreative.com' );
// define( 'PARDOT_API_PASSWORD', 'VA+.t#^,#6M887xU;9T}93746^sDg(' );
// define( 'PARDOT_API_USER_KEY', 'a62f85417366631a533fa57db26cb447' );

/**
 * @deprecated Deprecated since version 1.5
 */
if ( ! defined( 'PARDOT_FORM_INCLUDE_TYPE' ) ) {
	define( 'PARDOT_FORM_INCLUDE_TYPE', 'iframe' );	// iframe or inline
}

if ( ! defined( 'PARDOT_API_CACHE_TIMEOUT' ) ) {
	define( 'PARDOT_API_CACHE_TIMEOUT', MONTH_IN_SECONDS );
}

if ( ! defined( 'PARDOT_WIDGET_FORM_CACHE_TIMEOUT' ) ) {
	define( 'PARDOT_WIDGET_FORM_CACHE_TIMEOUT', MONTH_IN_SECONDS );
}

if ( ! defined( 'PARDOT_JS_CACHE_TIMEOUT' ) ) {
	define( 'PARDOT_JS_CACHE_TIMEOUT', MONTH_IN_SECONDS );
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-pardot.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_plugin_name() {
	$plugin = new Pardot_WordPress();
	$plugin->get_instance();
}
run_plugin_name();