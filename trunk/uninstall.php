<?php
/**
 * Remove the 'pardot_settings' entry from wp-options when plugin is uninstalled
 *
 * @author Cliff Seal
 * @since 1.5
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit();

/**
 * Delete Pardot Settings when plugin is uninstalled
 */
delete_option( 'pardot_settings' );
