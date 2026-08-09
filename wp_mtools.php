<?php
/**
 * @package WP MTools
 * @version 1.7.0
 */
/*
Plugin Name: MTools for WordPress
Plugin URI: https://github.com/dtdpro/wp_mtools/
Description: This adds extra features such as column headers for ACF Fields, Post ID, Featured Image, and User ID. Plus features for: duplicating a post, requiring login to view a post, listing cron events, post type info, ACf field info, and server info.
Version: 1.7.0
Author: DtD Productions
Author URI: http://dtdpro.com/
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

require_once( plugin_dir_path( __FILE__ ) . 'mtools.php' );
require_once( plugin_dir_path( __FILE__ ) . 'mtupdater.php' );

register_activation_hook( __FILE__, 'mt_activate' );
register_deactivation_hook( __FILE__, 'mt_deactivate' );


function mtools() {

	global $mtools;
	
	if( !isset($mtools) ) {
	
		$mtools = new mtools();
		
	}
	
	return $mtools;
}

function mt_activate() {
    $opts = [];
    $opts['show_column_fi']=true;
    $opts['show_column_pid']=true;
    $opts['show_column_uid']=true;
    add_option('wp_mtools', $opts);
}

function mt_deactivate() {
    delete_option('wp_mtools');
}

mtools();

// initialize
if (is_admin()) {
	new MTPluginUpdater( __FILE__, 'dtdpro', "wp_mtools" );
}