<?php
/**
 * @package WP MTools
 * @version 1.2.0
 */
/*
Plugin Name: MTools for Wordpress
Plugin URI: https://github.com/dtdpro/wp_mtools/
Description: This is not just a plugin, it makes up for some of WordPress' lack of features.
Version: 1.3.1
Author: DtD Productions
Author URI: http://dtdpro.com/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

require_once( plugin_dir_path( __FILE__ ) . 'mtools.php' );
require_once( plugin_dir_path( __FILE__ ) . 'mtupdater.php' );

register_activation_hook( __FILE__, array( 'MTools', 'mt_activate' ) );
register_deactivation_hook( __FILE__, array( 'MTools', 'mt_deactivate' ) );


function mtools() {

	global $mtools;
	
	if( !isset($mtools) ) {
	
		$mtools = new mtools();
		
	}
	
	return $mtools;
}


// initialize
if (is_admin()) {
	mtools();
	new MTPluginUpdater( __FILE__, 'dtdpro', "wp_mtools" );
}