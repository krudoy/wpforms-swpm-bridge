<?php

declare(strict_types=1);

namespace SWPMWPForms;

use SWPMWPForms\Admin\FormIntegration;
use SWPMWPForms\Admin\SettingsPage;
use SWPMWPForms\Handlers\SubmissionHandler;
use SWPMWPForms\Handlers\ShortcodeDisplayHandler;
use SWPMWPForms\Services\SwpmService;
use SWPMWPForms\Shortcodes\ProfileShortcode;
use SWPMWPForms\Blocks\ProfileBlock;

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
        SwpmService::instance()->initAvatarHooks();
        add_filter('do_shortcode_tag', [$this, 'maybeReplaceSwpmProfileFormLoggedOutOutput'], 10, 4);

        $submissionHandler = new SubmissionHandler();
        $submissionHandler->init();

        $shortcodeHandler = new ShortcodeDisplayHandler();
        $shortcodeHandler->init();
        
        // Profile display shortcodes
        $profileShortcode = new ProfileShortcode();
        $profileShortcode->init();
        
        // Gutenberg block
        $profileBlock = new ProfileBlock();
        $profileBlock->init();
    }

    public function maybeReplaceSwpmProfileFormLoggedOutOutput(string $output, string $tag, array $attr, array $matches): string {
        unset($attr, $matches);

        if ($tag !== 'swpm_profile_form') {
            return $output;
        }

        $needle = '<div class="swpm_profile_not_logged_in_msg">You are not logged in.</div>';
        if (strpos($output, $needle) === false) {
            return $output;
        }

        return str_replace($needle, $this->renderSwpmProfileLoginNotice(), $output);
    }

    private function renderSwpmProfileLoginNotice(): string {
        $redirectUrl = get_permalink() ?: home_url('/');

        return sprintf(
            '<div class="swpm_profile_not_logged_in_msg swpm-wpforms-profile-login-notice" style="margin:16px 0;padding:18px 20px;border:1px solid #b3d4fc;border-radius:8px;background:#f0f6fc;color:#0f3d66;text-align:center;font-weight:600;">'
            . '<p style="margin:0 0 12px;font-size:18px;line-height:1.4;">%s</p>'
            . '<a href="%s" style="display:inline-block;padding:10px 16px;border-radius:6px;background:#2271b1;color:#ffffff;text-decoration:none;font-weight:700;">%s</a>'
            . '</div>',
            esc_html__('Please login to see this page', 'wpforms-swpm-bridge'),
            esc_url(wp_login_url($redirectUrl)),
            esc_html__('Login', 'wpforms-swpm-bridge')
        );
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