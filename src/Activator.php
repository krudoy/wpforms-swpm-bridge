<?php

declare(strict_types=1);

namespace SWPMWPForms;

/**
 * Plugin activation handler.
 */
class Activator {
    
    /**
     * Run activation tasks.
     */
    public static function activate(): void {
        self::createLogTable();
        self::setDefaultOptions();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create the logs database table.
     */
    private static function createLogTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'swpm_wpforms_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            form_id bigint(20) UNSIGNED DEFAULT NULL,
            entry_id bigint(20) UNSIGNED DEFAULT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Store DB version for future migrations
        update_option('swpm_wpforms_db_version', '1.0.0');
    }
    
    /**
     * Set default plugin options.
     */
    private static function setDefaultOptions(): void {
        $defaults = [
            'enabled' => true,
            'default_membership_level' => '',
            'log_level' => 'error',
            'log_retention_days' => 30,
        ];
        
        // Only set if not already exists
        if (get_option('swpm_wpforms_settings') === false) {
            add_option('swpm_wpforms_settings', $defaults);
        }
    }
}