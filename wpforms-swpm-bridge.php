<?php
/**
 * Plugin Name: WPForms SWPM Bridge
 * Plugin URI: https://example.com/wpforms-swpm-bridge
 * Description: Integrates WPForms with Simple WordPress Membership for form-driven member management.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: wpforms-swpm-bridge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('SWPM_WPFORMS_VERSION', '1.0.0');
define('SWPM_WPFORMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SWPM_WPFORMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SWPM_WPFORMS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Composer autoload
if (file_exists(SWPM_WPFORMS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once SWPM_WPFORMS_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Manual PSR-4 autoloader fallback
    spl_autoload_register(function (string $class): void {
        $prefix = 'SWPMWPForms\\';
        $baseDir = SWPM_WPFORMS_PLUGIN_DIR . 'src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

/**
 * Check if required plugins are active.
 */
function swpm_wpforms_check_dependencies(): bool {
    $missing = [];
    
    // Check WPForms
    if (!class_exists('WPForms')) {
        $missing[] = 'WPForms';
    }
    
    // Check Simple WordPress Membership
    if (!class_exists('SimpleWpMembership') && !defined('SIMPLE_WP_MEMBERSHIP_VER')) {
        $missing[] = 'Simple WordPress Membership';
    }
    
    if (!empty($missing)) {
        add_action('admin_notices', function() use ($missing) {
            $message = sprintf(
                /* translators: %s: list of missing plugins */
                __('WPForms SWPM Bridge requires the following plugins to be active: %s', 'wpforms-swpm-bridge'),
                implode(', ', $missing)
            );
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
        return false;
    }
    
    return true;
}

/**
 * Initialize the plugin.
 */
function swpm_wpforms_init(): void {
    if (!swpm_wpforms_check_dependencies()) {
        return;
    }
    
    \SWPMWPForms\Plugin::instance();
}
add_action('plugins_loaded', 'swpm_wpforms_init');

/**
 * Activation hook.
 */
function swpm_wpforms_activate(): void {
    if (class_exists('\SWPMWPForms\Activator')) {
        \SWPMWPForms\Activator::activate();
    }
}
register_activation_hook(__FILE__, 'swpm_wpforms_activate');

/**
 * Deactivation hook.
 */
function swpm_wpforms_deactivate(): void {
    if (class_exists('\SWPMWPForms\Deactivator')) {
        \SWPMWPForms\Deactivator::deactivate();
    }
}
register_deactivation_hook(__FILE__, 'swpm_wpforms_deactivate');