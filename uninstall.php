<?php
/**
 * Uninstall script for WPForms SWPM Bridge.
 * 
 * This file runs when the plugin is deleted via WordPress admin.
 * It cleans up all plugin data from the database.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete plugin options
delete_option('swpm_wpforms_settings');
delete_option('swpm_wpforms_db_version');

// Delete all form meta related to this plugin
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} 
     WHERE meta_key LIKE '%swpm_integration%'"
);

// Drop the logs table
$table_name = $wpdb->prefix . 'swpm_wpforms_logs';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Clear any transients
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE '%_transient_swpm_wpforms_%' 
     OR option_name LIKE '%_transient_timeout_swpm_wpforms_%'"
);