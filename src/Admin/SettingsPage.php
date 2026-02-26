<?php

declare(strict_types=1);

namespace SWPMWPForms\Admin;

use SWPMWPForms\Services\SwpmService;
use SWPMWPForms\Services\Logger;

/**
 * Global plugin settings page using WordPress Settings API.
 */
class SettingsPage {
    
    private const OPTION_GROUP = 'swpm_wpforms_settings_group';
    private const OPTION_NAME = 'swpm_wpforms_settings';
    private const PAGE_SLUG = 'swpm-wpforms-settings';
    
    private SwpmService $swpmService;
    
    public function __construct() {
        $this->swpmService = SwpmService::instance();
    }
    
    /**
     * Register hooks.
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }
    
    /**
     * Add settings page to admin menu.
     */
    public function addMenuPage(): void {
        add_options_page(
            __('WPForms SWPM Bridge', 'wpforms-swpm-bridge'),
            __('WPForms SWPM', 'wpforms-swpm-bridge'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }
    
    /**
     * Register settings and fields.
     */
    public function registerSettings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->getDefaults(),
            ]
        );
        
        // General Settings Section
        add_settings_section(
            'swpm_wpforms_general',
            __('General Settings', 'wpforms-swpm-bridge'),
            [$this, 'renderGeneralSection'],
            self::PAGE_SLUG
        );
        
        add_settings_field(
            'enabled',
            __('Enable Integration', 'wpforms-swpm-bridge'),
            [$this, 'renderEnabledField'],
            self::PAGE_SLUG,
            'swpm_wpforms_general'
        );
        
        add_settings_field(
            'default_membership_level',
            __('Default Membership Level', 'wpforms-swpm-bridge'),
            [$this, 'renderDefaultLevelField'],
            self::PAGE_SLUG,
            'swpm_wpforms_general'
        );
        
        // Logging Section
        add_settings_section(
            'swpm_wpforms_logging',
            __('Logging', 'wpforms-swpm-bridge'),
            [$this, 'renderLoggingSection'],
            self::PAGE_SLUG
        );
        
        add_settings_field(
            'log_level',
            __('Log Level', 'wpforms-swpm-bridge'),
            [$this, 'renderLogLevelField'],
            self::PAGE_SLUG,
            'swpm_wpforms_logging'
        );
        
        add_settings_field(
            'log_retention_days',
            __('Log Retention (Days)', 'wpforms-swpm-bridge'),
            [$this, 'renderRetentionField'],
            self::PAGE_SLUG,
            'swpm_wpforms_logging'
        );
    }
    
    /**
     * Get default settings.
     */
    private function getDefaults(): array {
        return [
            'enabled' => true,
            'default_membership_level' => '',
            'log_level' => 'error',
            'log_retention_days' => 30,
        ];
    }
    
    /**
     * Sanitize settings on save.
     */
    public function sanitizeSettings(array $input): array {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['default_membership_level'] = absint($input['default_membership_level'] ?? 0);
        $sanitized['log_level'] = sanitize_text_field($input['log_level'] ?? 'error');
        $sanitized['log_retention_days'] = absint($input['log_retention_days'] ?? 30);
        
        // Validate log level
        $validLevels = [Logger::LEVEL_DEBUG, Logger::LEVEL_INFO, Logger::LEVEL_WARNING, Logger::LEVEL_ERROR];
        if (!in_array($sanitized['log_level'], $validLevels, true)) {
            $sanitized['log_level'] = 'error';
        }
        
        return $sanitized;
    }
    
    /**
     * Render the settings page.
     */
    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Quick Start', 'wpforms-swpm-bridge'); ?></h2>
            <ol>
                <li><?php esc_html_e('Create or edit a form in WPForms', 'wpforms-swpm-bridge'); ?></li>
                <li><?php esc_html_e('Go to Settings → SWPM Integration in the form builder', 'wpforms-swpm-bridge'); ?></li>
                <li><?php esc_html_e('Enable integration and select an action type', 'wpforms-swpm-bridge'); ?></li>
                <li><?php esc_html_e('Map your form fields to SWPM member fields', 'wpforms-swpm-bridge'); ?></li>
                <li><?php esc_html_e('Save and test your form', 'wpforms-swpm-bridge'); ?></li>
            </ol>
        </div>
        <?php
    }
    
    /**
     * Section descriptions.
     */
    public function renderGeneralSection(): void {
        echo '<p>' . esc_html__('Configure the global settings for WPForms and SWPM integration.', 'wpforms-swpm-bridge') . '</p>';
    }
    
    public function renderLoggingSection(): void {
        echo '<p>' . esc_html__('Configure logging for debugging and auditing.', 'wpforms-swpm-bridge') . '</p>';
    }
    
    /**
     * Render enabled checkbox field.
     */
    public function renderEnabledField(): void {
        $options = get_option(self::OPTION_NAME, $this->getDefaults());
        $enabled = $options['enabled'] ?? true;
        
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]" value="1" <?php checked($enabled); ?>>
            <?php esc_html_e('Enable WPForms to SWPM integration', 'wpforms-swpm-bridge'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When disabled, no forms will process SWPM actions even if configured.', 'wpforms-swpm-bridge'); ?>
        </p>
        <?php
    }
    
    /**
     * Render default membership level dropdown.
     */
    public function renderDefaultLevelField(): void {
        $options = get_option(self::OPTION_NAME, $this->getDefaults());
        $currentLevel = $options['default_membership_level'] ?? '';
        $levels = $this->swpmService->getMembershipLevels();
        
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[default_membership_level]">
            <option value=""><?php esc_html_e('— Select Level —', 'wpforms-swpm-bridge'); ?></option>
            <?php foreach ($levels as $id => $name) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($currentLevel, $id); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Used when no level is specified in the form mapping.', 'wpforms-swpm-bridge'); ?>
        </p>
        <?php
    }
    
    /**
     * Render log level dropdown.
     */
    public function renderLogLevelField(): void {
        $options = get_option(self::OPTION_NAME, $this->getDefaults());
        $currentLevel = $options['log_level'] ?? 'error';
        
        $levels = [
            Logger::LEVEL_DEBUG => __('Debug (All)', 'wpforms-swpm-bridge'),
            Logger::LEVEL_INFO => __('Info', 'wpforms-swpm-bridge'),
            Logger::LEVEL_WARNING => __('Warning', 'wpforms-swpm-bridge'),
            Logger::LEVEL_ERROR => __('Error Only', 'wpforms-swpm-bridge'),
        ];
        
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[log_level]">
            <?php foreach ($levels as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($currentLevel, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Minimum log level to record. Debug logs everything.', 'wpforms-swpm-bridge'); ?>
        </p>
        <?php
    }
    
    /**
     * Render log retention field.
     */
    public function renderRetentionField(): void {
        $options = get_option(self::OPTION_NAME, $this->getDefaults());
        $days = $options['log_retention_days'] ?? 30;
        
        ?>
        <input type="number" 
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[log_retention_days]" 
               value="<?php echo esc_attr($days); ?>" 
               min="1" 
               max="365" 
               class="small-text">
        <p class="description">
            <?php esc_html_e('Logs older than this will be automatically deleted.', 'wpforms-swpm-bridge'); ?>
        </p>
        <?php
    }
    
    /**
     * Get current settings.
     */
    public static function getSettings(): array {
        return get_option(self::OPTION_NAME, [
            'enabled' => true,
            'default_membership_level' => '',
            'log_level' => 'error',
            'log_retention_days' => 30,
        ]);
    }
}