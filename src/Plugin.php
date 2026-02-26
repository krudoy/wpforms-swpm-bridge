<?php

declare(strict_types=1);

namespace SWPMWPForms;

use SWPMWPForms\Admin\FormIntegration;
use SWPMWPForms\Admin\SettingsPage;
use SWPMWPForms\Handlers\SubmissionHandler;

/**
 * Main plugin orchestrator.
 */
final class Plugin {
    
    private static ?Plugin $instance = null;
    
    /**
     * Get singleton instance.
     */
    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor for singleton.
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin components.
     */
    private function init(): void {
        // Load text domain
        add_action('init', [$this, 'loadTextDomain']);
        
        // Admin hooks
        if (is_admin()) {
            $this->initAdmin();
        }
        
        // Frontend/submission hooks
        $this->initHandlers();
    }
    
    /**
     * Load plugin text domain.
     */
    public function loadTextDomain(): void {
        load_plugin_textdomain(
            'wpforms-swpm-bridge',
            false,
            dirname(SWPM_WPFORMS_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Initialize admin components.
     */
    private function initAdmin(): void {
        // Settings page
        $settingsPage = new SettingsPage();
        $settingsPage->init();
        
        // WPForms builder integration
        $formIntegration = new FormIntegration();
        $formIntegration->init();
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }
    
    /**
     * Initialize submission handlers.
     */
    private function initHandlers(): void {
        $submissionHandler = new SubmissionHandler();
        $submissionHandler->init();
    }
    
    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueueAdminAssets(string $hook): void {
        // Load on settings page
        if ($hook === 'settings_page_swpm-wpforms-settings') {
            wp_enqueue_style(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                SWPM_WPFORMS_VERSION
            );
        }
        
        // Load on WPForms builder
        if (strpos($hook, 'wpforms') !== false) {
            wp_enqueue_style(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                SWPM_WPFORMS_VERSION
            );
            
            wp_enqueue_script(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                SWPM_WPFORMS_VERSION,
                true
            );
            
            // Pass settings to JS
            $settings = get_option('swpm_wpforms_settings', []);
            $debug = ($settings['log_level'] ?? 'error') === 'debug';
            
            wp_localize_script('swpm-wpforms-admin', 'swpm_wpforms', [
                'debug' => $debug,
            ]);
        }
    }
    
    /**
     * Prevent cloning.
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }
}