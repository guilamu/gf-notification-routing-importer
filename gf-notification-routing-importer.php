<?php
/**
 * Plugin Name: Notification Routing Importer for Gravity Forms
 * Plugin URI: https://github.com/guilamu/gf-notification-routing-importer
 * Description: Bulk import notification routing rules from CSV/XLSX files into Gravity Forms' "Configure Routing" feature.
 * Version: 1.1.0
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: gf-notification-routing-importer
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/gf-notification-routing-importer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'GFNRI_VERSION', '1.1.0' );
define( 'GFNRI_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'GFNRI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GFNRI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files.
require_once GFNRI_PLUGIN_PATH . 'includes/class-github-updater.php';
require_once GFNRI_PLUGIN_PATH . 'includes/class-xlsx-parser.php';
require_once GFNRI_PLUGIN_PATH . 'includes/class-import-handler.php';
require_once GFNRI_PLUGIN_PATH . 'includes/class-cron.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function gfnri_init(): void {
    // Initialize GitHub updater (always, for update checks).
    GFNRI_GitHub_Updater::init();

    // Initialize main functionality only if Gravity Forms is active.
    if ( class_exists( 'GFForms' ) ) {
        new GFNRI_Import_Handler();
        GFNRI_Cron::init();
    }

    // Register with Guilamu Bug Reporter.
    if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
        Guilamu_Bug_Reporter::register( array(
            'slug'        => 'gf-notification-routing-importer',
            'name'        => 'Notification Routing Importer for Gravity Forms',
            'version'     => GFNRI_VERSION,
            'github_repo' => 'guilamu/gf-notification-routing-importer',
        ) );
    }
}
add_action( 'plugins_loaded', 'gfnri_init' );
register_deactivation_hook( __FILE__, array( 'GFNRI_Cron', 'unschedule' ) );

/**
 * Add plugin row meta links (View details, Report a Bug).
 *
 * @param array  $links Plugin row meta links.
 * @param string $file  Plugin file path.
 * @return array Modified links.
 */
function gfnri_plugin_row_meta( array $links, string $file ): array {
    if ( GFNRI_PLUGIN_FILE !== $file ) {
        return $links;
    }

    // "View details" thickbox link.
    $links[] = sprintf(
        '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
        esc_url( self_admin_url(
            'plugin-install.php?tab=plugin-information&plugin=gf-notification-routing-importer'
            . '&TB_iframe=true&width=772&height=926'
        ) ),
        esc_attr__( 'More information about Notification Routing Importer for Gravity Forms', 'gf-notification-routing-importer' ),
        esc_attr__( 'Notification Routing Importer for Gravity Forms', 'gf-notification-routing-importer' ),
        esc_html__( 'View details', 'gf-notification-routing-importer' )
    );

    if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
        $links[] = sprintf(
            '<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="gf-notification-routing-importer" data-plugin-name="%s">%s</a>',
            esc_attr__( 'Notification Routing Importer for Gravity Forms', 'gf-notification-routing-importer' ),
            esc_html__( '🐛 Report a Bug', 'gf-notification-routing-importer' )
        );
    } else {
        $links[] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://github.com/guilamu/guilamu-bug-reporter/releases',
            esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'gf-notification-routing-importer' )
        );
    }

    return $links;
}
add_filter( 'plugin_row_meta', 'gfnri_plugin_row_meta', 10, 2 );
