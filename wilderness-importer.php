<?php

/**
 * Plugin Name: Wilderness Importer
 * Plugin URI: 
 * Description: Import or export subscriptions in your WooCommerce store via CSV.
 * Version: 1.0
 * Author: The Fold
 * Author URI: 
 * License: 
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once( 'includes/wilderness-functions.php' );

class Wilderness_Importer {

	public static $wilderness_importer;

	public static $version = '1.0.0';

	protected static $plugin_file = __FILE__;

    // standard plugin stuff
	public static function init() {
		add_filter( 'plugins_loaded', __CLASS__ . '::setup_importer' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );

		spl_autoload_register( __CLASS__ . '::autoload' );
	}

    // ensure woocommerce subscriptions is installed
    public static function setup_importer() {

		if ( is_admin() ) {
			if ( class_exists( 'WC_Subscriptions' ) && version_compare( WC_Subscriptions::$version, '2.0', '>=' ) ) {
				self::$wilderness_importer = new Wilderness_Import_Admin();
			}
		}
	}

    // standard plugin stuff to ensure we see the deactivate button on the plugins page
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=import_subscription' ) ) . '">' . esc_html__( 'Import', 'wilderness-import' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	public static function plugin_url() {
		return plugin_dir_url( self::$plugin_file );
	}

	public static function plugin_dir() {
		return plugin_dir_path( self::$plugin_file );
	}

	public static function autoload( $class ) {
		$class = strtolower( $class );
		$file  = 'class-' . str_replace( '_', '-', $class ) . '.php';

		if ( 0 === strpos( $class, 'wilderness_import' )) {
			require_once( self::plugin_dir() . '/includes/' . $file );
		}
	}
}

Wilderness_Importer::init();
