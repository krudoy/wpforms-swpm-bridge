<?php

declare(strict_types=1);

namespace SWPMWPForms;

/**
 * Plugin deactivation handler.
 */
class Deactivator {
    
    /**
     * Run deactivation tasks.
     */
    public static function deactivate(): void {
        // Clear any scheduled events
        wp_clear_scheduled_hook('swpm_wpforms_cleanup_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}