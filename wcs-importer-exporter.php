<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once( 'includes/wcsi-functions.php' );
require_once( 'includes/wilderness-functions.php' );

class WCS_Importer_Exporter {

	public static $wcs_importer;

	public static $version = '1.0.0';

	protected static $plugin_file = __FILE__;

	/**
	 * Initialise filters for the Subscriptions CSV Importer
	 *
	 * @since 1.0
	 */
	public static function init() {
		add_filter( 'plugins_loaded', __CLASS__ . '::setup_importer' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );

		spl_autoload_register( __CLASS__ . '::autoload' );
	}

	/**
	 * Create an instance of the importer on admin pages and check for WooCommerce Subscriptions dependency.
	 *
	 * @since 1.0
	 */
	public static function setup_importer() {

		if ( is_admin() ) {
			if ( class_exists( 'WC_Subscriptions' ) && version_compare( WC_Subscriptions::$version, '2.0', '>=' ) ) {
				self::$wcs_importer = new WCS_Import_Admin();
			}
		}
	}

	/**
	 * Include Docs & Settings links on the Plugins administration screen
	 *
	 * @since 1.0
	 * @param mixed $links
	 */
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=import_subscription' ) ) . '">' . esc_html__( 'Import', 'wcs-import-export' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Get the plugin's URL path for loading assets
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function plugin_url() {
		return plugin_dir_url( self::$plugin_file );
	}

	/**
	 * Get the plugin's path for loading files
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function plugin_dir() {
		return plugin_dir_path( self::$plugin_file );
	}

	/**
	 * Get the plugin's path for loading files
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function autoload( $class ) {
		$class = strtolower( $class );
		$file  = 'class-' . str_replace( '_', '-', $class ) . '.php';

		if ( 0 === strpos( $class, 'wcs_import' )) {
			require_once( self::plugin_dir() . '/includes/' . $file );
		}
	}
}

WCS_Importer_Exporter::init();
