<?php
/**
 * Uninstall script for Notification Routing Importer.
 *
 * Runs when the plugin is deleted via WordPress admin.
 *
 * @package GF_Notification_Routing_Importer
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove GitHub updater cache.
delete_transient( 'gfnri_github_release' );
