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
        add_action('admin_init', [$this, 'loadWpformsTemplates'], 20);

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
     * Load native WPForms template classes provided by this plugin.
     */
    public function loadWpformsTemplates(): void {
        if (!class_exists('WPForms_Template', false)) {
            return;
        }

        foreach ([
            SWPM_WPFORMS_PLUGIN_DIR . 'templates/class-registration.php',
            SWPM_WPFORMS_PLUGIN_DIR . 'templates/class-update-profile.php',
            SWPM_WPFORMS_PLUGIN_DIR . 'templates/class-change-password.php',
        ] as $templateFile) {
            if (file_exists($templateFile)) {
                require_once $templateFile;
            }
        }
    }
    
    /**
     * Initialize submission handlers.
     */
    private function initHandlers(): void {
        SwpmService::instance()->initAvatarHooks();
        add_filter('do_shortcode_tag', [$this, 'maybeReplaceLoggedOutShortcodeOutput'], 10, 4);

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

    public function maybeReplaceLoggedOutShortcodeOutput(string $output, string $tag, array $attr, array $matches): string {
        unset($matches);

        if ($tag === 'swpm_profile_form') {
            $needle = '<div class="swpm_profile_not_logged_in_msg">You are not logged in.</div>';
            if (strpos($output, $needle) === false) {
                return $output;
            }

            return str_replace($needle, $this->renderLoginNotice(__('Please login to see this page', 'wpforms-swpm-bridge')), $output);
        }

        if ($tag !== 'wpforms') {
            return $output;
        }

        $formId = isset($attr['id']) ? (int) $attr['id'] : 0;
        if ($formId <= 0) {
            return $output;
        }

        $config = FormIntegration::getConfig($formId);
        if (empty($config['enabled']) || ($config['action_type'] ?? '') !== 'change_password') {
            return $output;
        }

        if (!class_exists('SwpmMemberUtils') || (int) \SwpmMemberUtils::get_logged_in_members_id() > 0) {
            return $output;
        }

        return $this->renderLoginNotice(__('Please login to change your password', 'wpforms-swpm-bridge'));
    }

    private function renderLoginNotice(string $message): string {
        $this->enqueueProfileNoticeStyles();

        $redirectUrl = get_permalink() ?: home_url('/');

        return sprintf(
            '<div class="swpm_profile_not_logged_in_msg swpm-wpforms-profile-login-notice">'
            . '<p class="swpm-wpforms-profile-login-notice__message">%s</p>'
            . '<a href="%s" class="swpm-wpforms-profile-login-notice__button">%s</a>'
            . '</div>',
            esc_html($message),
            esc_url(wp_login_url($redirectUrl)),
            esc_html__('Login', 'wpforms-swpm-bridge')
        );
    }

    private function enqueueProfileNoticeStyles(): void {
        $profileCssPath = SWPM_WPFORMS_PLUGIN_DIR . 'assets/css/profile.css';
        $profileCssVersion = file_exists($profileCssPath) ? (string) filemtime($profileCssPath) : SWPM_WPFORMS_VERSION;

        wp_enqueue_style(
            'swpm-wpforms-profile',
            SWPM_WPFORMS_PLUGIN_URL . 'assets/css/profile.css',
            [],
            $profileCssVersion
        );

        if (did_action('wp_head') && wp_style_is('swpm-wpforms-profile', 'enqueued') && !wp_style_is('swpm-wpforms-profile', 'done')) {
            wp_print_styles(['swpm-wpforms-profile']);
        }
    }
    
    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueueAdminAssets(string $hook): void {
        $adminCssPath = SWPM_WPFORMS_PLUGIN_DIR . 'assets/css/admin.css';
        $adminCssVersion = file_exists($adminCssPath) ? (string) filemtime($adminCssPath) : SWPM_WPFORMS_VERSION;
        $adminJsPath = SWPM_WPFORMS_PLUGIN_DIR . 'assets/js/admin.js';
        $adminJsVersion = file_exists($adminJsPath) ? (string) filemtime($adminJsPath) : SWPM_WPFORMS_VERSION;

        // Load on settings page
        if ($hook === 'settings_page_swpm-wpforms-settings') {
            wp_enqueue_style(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                $adminCssVersion
            );
        }
        
        // Load on WPForms builder
        if (strpos($hook, 'wpforms') !== false) {
            wp_enqueue_style(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                $adminCssVersion
            );
            
            wp_enqueue_script(
                'swpm-wpforms-admin',
                SWPM_WPFORMS_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                $adminJsVersion,
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