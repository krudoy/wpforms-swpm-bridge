<?php

declare(strict_types=1);

namespace SWPMWPForms\Admin;

use SWPMWPForms\Services\SwpmService;
use SWPMWPForms\Validators\MemberValidator;

/**
 * WPForms builder integration panel.
 */
class FormIntegration {
    
    private const META_KEY = 'swpm_integration';
    
    private SwpmService $swpmService;
    
    public function __construct() {
        $this->swpmService = SwpmService::instance();
    }
    
    /**
     * Register hooks.
     */
    public function init(): void {
        // Add settings panel to WPForms builder
        add_filter('wpforms_builder_settings_sections', [$this, 'addSettingsSection'], 20, 2);
        add_action('wpforms_form_settings_panel_content', [$this, 'renderSettingsPanel'], 20, 2);
        
        // Process custom field mappings before save
        add_filter('wpforms_save_form_args', [$this, 'processCustomFieldMappings'], 10, 3);
        
        // AJAX handlers for field mapping
        add_action('wp_ajax_swpm_wpforms_get_form_fields', [$this, 'ajaxGetFormFields']);
        add_action('wp_ajax_swpm_wpforms_refresh_mapping', [$this, 'ajaxRefreshMapping']);
    }
    
    /**
     * Add SWPM section to builder settings tabs.
     */
    public function addSettingsSection(array $sections, array $formData): array {
        $sections['swpm_integration'] = __('SWPM Integration', 'wpforms-swpm-bridge');
        return $sections;
    }
    
    /**
     * Render the SWPM integration settings panel.
     */
    public function renderSettingsPanel(object $panel): void {
        // Get form data from the panel object
        $formData = $panel->form_data ?? [];
        $config = $this->getIntegrationConfig($formData);
        $levels = $this->swpmService->getMembershipLevels();
        
        ?>
        <div class="wpforms-panel-content-section wpforms-panel-content-section-swpm_integration">
            <div class="wpforms-panel-content-section-title">
                <?php esc_html_e('Simple WordPress Membership Integration', 'wpforms-swpm-bridge'); ?>
            </div>
            
            <!-- Enable Toggle -->
            <div class="wpforms-setting-row">
                <label for="swpm-integration-enabled">
                    <?php esc_html_e('Enable SWPM Integration', 'wpforms-swpm-bridge'); ?>
                </label>
                <input type="checkbox" 
                       id="swpm-integration-enabled" 
                       name="settings[swpm_integration][enabled]" 
                       value="1" 
                       <?php checked($config['enabled'] ?? false); ?>>
                <p class="note">
                    <?php esc_html_e('Process form submissions as SWPM membership actions.', 'wpforms-swpm-bridge'); ?>
                </p>
            </div>
            
            <div id="swpm-integration-settings" style="<?php echo empty($config['enabled']) ? 'display:none;' : ''; ?>">
                
                <!-- Action Type -->
                <div class="wpforms-setting-row">
                    <label for="swpm-action-type">
                        <?php esc_html_e('Action Type', 'wpforms-swpm-bridge'); ?>
                    </label>
                    <select id="swpm-action-type" name="settings[swpm_integration][action_type]">
                        <option value="register_member" <?php selected($config['action_type'] ?? '', 'register_member'); ?>>
                            <?php esc_html_e('Register New Member', 'wpforms-swpm-bridge'); ?>
                        </option>
                        <option value="update_member" <?php selected($config['action_type'] ?? '', 'update_member'); ?>>
                            <?php esc_html_e('Update Existing Member', 'wpforms-swpm-bridge'); ?>
                        </option>
                        <option value="change_level" <?php selected($config['action_type'] ?? '', 'change_level'); ?>>
                            <?php esc_html_e('Change Membership Level', 'wpforms-swpm-bridge'); ?>
                        </option>
                    </select>
                </div>
                
                <!-- Field Mapping -->
                <div class="wpforms-setting-row">
                    <label><?php esc_html_e('Field Mapping', 'wpforms-swpm-bridge'); ?></label>
                    <p class="note">
                        <?php esc_html_e('Map your form fields to SWPM member fields.', 'wpforms-swpm-bridge'); ?>
                    </p>
                    
                    <div id="swpm-field-mapping" class="swpm-field-mapping-table">
                        <?php $this->renderFieldMappingRows($formData, $config); ?>
                    </div>
                </div>
                
                <!-- Membership Level (for register) -->
                <div class="wpforms-setting-row swpm-register-only">
                    <label for="swpm-membership-level">
                        <?php esc_html_e('Membership Level', 'wpforms-swpm-bridge'); ?>
                    </label>
                    <select id="swpm-membership-level" name="settings[swpm_integration][membership_level]">
                        <option value=""><?php esc_html_e('— Use field mapping —', 'wpforms-swpm-bridge'); ?></option>
                        <?php foreach ($levels as $id => $name) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($config['membership_level'] ?? '', $id); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="note">
                        <?php esc_html_e('Or map a form field to "membership_level" above.', 'wpforms-swpm-bridge'); ?>
                    </p>
                </div>
                
                <!-- Behavior Options -->
                <div class="wpforms-setting-row">
                    <label><?php esc_html_e('Options', 'wpforms-swpm-bridge'); ?></label>
                    
                    <div class="swpm-option-group">
                        <label>
                            <strong><?php esc_html_e('On Duplicate:', 'wpforms-swpm-bridge'); ?></strong>
                        </label>
                        <select name="settings[swpm_integration][options][on_duplicate]">
                            <option value="reject" <?php selected($config['options']['on_duplicate'] ?? 'reject', 'reject'); ?>>
                                <?php esc_html_e('Reject submission', 'wpforms-swpm-bridge'); ?>
                            </option>
                            <option value="update" <?php selected($config['options']['on_duplicate'] ?? '', 'update'); ?>>
                                <?php esc_html_e('Update existing member', 'wpforms-swpm-bridge'); ?>
                            </option>
                            <option value="skip" <?php selected($config['options']['on_duplicate'] ?? '', 'skip'); ?>>
                                <?php esc_html_e('Skip (process form, ignore SWPM)', 'wpforms-swpm-bridge'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="swpm-option-group">
                        <label>
                            <strong><?php esc_html_e('Password Mode:', 'wpforms-swpm-bridge'); ?></strong>
                        </label>
                        <select name="settings[swpm_integration][options][password_mode]">
                            <option value="require_field" <?php selected($config['options']['password_mode'] ?? 'require_field', 'require_field'); ?>>
                                <?php esc_html_e('Require password field', 'wpforms-swpm-bridge'); ?>
                            </option>
                            <option value="auto_generate" <?php selected($config['options']['password_mode'] ?? '', 'auto_generate'); ?>>
                                <?php esc_html_e('Auto-generate & email', 'wpforms-swpm-bridge'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="swpm-option-group">
                        <label>
                            <input type="checkbox" 
                                   name="settings[swpm_integration][options][auto_login]" 
                                   value="1" 
                                   <?php checked($config['options']['auto_login'] ?? false); ?>>
                            <?php esc_html_e('Auto-login after registration', 'wpforms-swpm-bridge'); ?>
                        </label>
                    </div>
                    
                    <div class="swpm-option-group">
                        <label>
                            <input type="checkbox" 
                                   name="settings[swpm_integration][options][send_welcome]" 
                                   value="1" 
                                   <?php checked($config['options']['send_welcome'] ?? true); ?>>
                            <?php esc_html_e('Send SWPM welcome email', 'wpforms-swpm-bridge'); ?>
                        </label>
                    </div>
                </div>
                
                <!-- Redirect URL -->
                <div class="wpforms-setting-row">
                    <label for="swpm-redirect-url">
                        <?php esc_html_e('Success Redirect URL', 'wpforms-swpm-bridge'); ?>
                    </label>
                    <input type="url" 
                           id="swpm-redirect-url" 
                           name="settings[swpm_integration][options][redirect_url]" 
                           value="<?php echo esc_attr($config['options']['redirect_url'] ?? ''); ?>" 
                           class="wpforms-setting-input"
                           placeholder="<?php esc_attr_e('Leave empty to use form default', 'wpforms-swpm-bridge'); ?>">
                </div>
                
            </div>
        </div>
        <?php
    }
    
    /**
     * Render field mapping rows.
     */
    private function renderFieldMappingRows(array $formData, array $config): void {
        $formFields = $this->getFormFields($formData);
        $fieldMap = $config['field_map'] ?? [];
        
        $swpmFields = $this->getAvailableSwpmFields();
        
        if (empty($formFields)) {
            echo '<p class="note">' . esc_html__('Add fields to your form first, then configure mapping.', 'wpforms-swpm-bridge') . '</p>';
            return;
        }
        
        // Show field IDs toggle
        echo '<p style="margin-bottom:10px;">';
        echo '<label><input type="checkbox" id="swpm-show-field-ids"> ';
        echo esc_html__('Show field IDs', 'wpforms-swpm-bridge') . '</label></p>';
        
        echo '<table class="widefat">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Form Field', 'wpforms-swpm-bridge') . '</th>';
        echo '<th class="swpm-field-id" style="display:none;width:60px;">' . esc_html__('ID', 'wpforms-swpm-bridge') . '</th>';
        echo '<th>' . esc_html__('SWPM Field', 'wpforms-swpm-bridge') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($formFields as $fieldId => $fieldLabel) {
            $currentMapping = $fieldMap[$fieldId] ?? '';
            
            echo '<tr>';
            echo '<td>' . esc_html($fieldLabel) . '</td>';
            echo '<td class="swpm-field-id" style="display:none;"><code>' . esc_html($fieldId) . '</code></td>';
            echo '<td>';
            echo '<select name="settings[swpm_integration][field_map][' . esc_attr($fieldId) . ']" class="swpm-field-select">';
            
            foreach ($swpmFields as $group => $fields) {
                if (is_array($fields)) {
                    echo '<optgroup label="' . esc_attr($group) . '">';
                    foreach ($fields as $value => $label) {
                        $selected = ($currentMapping === $value) ? ' selected' : '';
                        echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
                    }
                    echo '</optgroup>';
                } else {
                    $selected = ($currentMapping === $group) ? ' selected' : '';
                    echo '<option value="' . esc_attr($group) . '"' . $selected . '>' . esc_html($fields) . '</option>';
                }
            }
            
            echo '</select>';
            
            // Custom field input (shown when "custom_" is selected)
            $customKey = '';
            if (str_starts_with($currentMapping, 'custom_')) {
                $customKey = substr($currentMapping, 7);
            }
            $customDisplay = str_starts_with($currentMapping, 'custom_') ? '' : 'display:none;';
            echo '<input type="text" 
                         name="settings[swpm_integration][field_map_custom][' . esc_attr($fieldId) . ']" 
                         value="' . esc_attr($customKey) . '" 
                         placeholder="' . esc_attr__('meta key', 'wpforms-swpm-bridge') . '"
                         class="swpm-custom-field-input"
                         style="margin-left:5px;width:120px;' . $customDisplay . '">';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Get all available SWPM/WP fields for mapping.
     */
    private function getAvailableSwpmFields(): array {
        $fields = [
            '' => __('— Do not map —', 'wpforms-swpm-bridge'),
            
            __('SWPM Core Fields', 'wpforms-swpm-bridge') => [
                'email' => __('Email', 'wpforms-swpm-bridge'),
                'username' => __('Username', 'wpforms-swpm-bridge'),
                'password' => __('Password', 'wpforms-swpm-bridge'),
                'first_name' => __('First Name', 'wpforms-swpm-bridge'),
                'last_name' => __('Last Name', 'wpforms-swpm-bridge'),
                'membership_level' => __('Membership Level', 'wpforms-swpm-bridge'),
                'phone' => __('Phone', 'wpforms-swpm-bridge'),
                'address_street' => __('Address (Street)', 'wpforms-swpm-bridge'),
                'address_city' => __('Address (City)', 'wpforms-swpm-bridge'),
                'address_state' => __('Address (State)', 'wpforms-swpm-bridge'),
                'address_zipcode' => __('Address (Zip)', 'wpforms-swpm-bridge'),
                'country' => __('Country', 'wpforms-swpm-bridge'),
                'company' => __('Company', 'wpforms-swpm-bridge'),
                'gender' => __('Gender', 'wpforms-swpm-bridge'),
            ],
            
            __('WordPress User Fields', 'wpforms-swpm-bridge') => [
                'wp_display_name' => __('Display Name', 'wpforms-swpm-bridge'),
                'wp_nickname' => __('Nickname', 'wpforms-swpm-bridge'),
                'wp_description' => __('Bio / Description', 'wpforms-swpm-bridge'),
                'wp_user_url' => __('Website URL', 'wpforms-swpm-bridge'),
                'wp_avatar' => __('Profile Picture', 'wpforms-swpm-bridge'),
            ],
            
            __('Custom', 'wpforms-swpm-bridge') => [
                'custom_' => __('Custom Meta Field →', 'wpforms-swpm-bridge'),
            ],
        ];
        
        // Add SWPM custom form fields if Form Builder addon is active
        $customFields = $this->getSwpmCustomFields();
        if (!empty($customFields)) {
            $fields[__('SWPM Custom Fields', 'wpforms-swpm-bridge')] = $customFields;
        }
        
        /**
         * Filter available SWPM fields for mapping.
         */
        return apply_filters('swpm_wpforms_available_fields', $fields);
    }
    
    /**
     * Get SWPM custom form fields (if Form Builder addon exists).
     */
    private function getSwpmCustomFields(): array {
        $customFields = [];
        
        // Check for SWPM Form Builder custom fields
        if (class_exists('SwpmFormBuilder')) {
            global $wpdb;
            $table = $wpdb->prefix . 'swpm_form_builder_fields';
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $fields = $wpdb->get_results("SELECT field_key, field_name FROM $table WHERE field_type != 'section' ORDER BY field_order");
                foreach ($fields as $field) {
                    $customFields['swpm_' . $field->field_key] = $field->field_name;
                }
            }
        }
        
        // Also check for any existing custom meta keys in SWPM members
        global $wpdb;
        $metaTable = $wpdb->prefix . 'swpm_members_meta';
        if ($wpdb->get_var("SHOW TABLES LIKE '$metaTable'") === $metaTable) {
            $existingKeys = $wpdb->get_col("SELECT DISTINCT meta_key FROM $metaTable LIMIT 50");
            foreach ($existingKeys as $key) {
                if (!isset($customFields['swpm_' . $key])) {
                    $customFields['swpm_' . $key] = $key;
                }
            }
        }
        
        return $customFields;
    }
    
    /**
     * Get form fields for mapping.
     */
    private function getFormFields(array $formData): array {
        $fields = [];
        
        if (empty($formData['fields'])) {
            return $fields;
        }
        
        foreach ($formData['fields'] as $id => $field) {
            // Skip non-input fields
            $skipTypes = ['pagebreak', 'divider', 'html', 'content'];
            if (in_array($field['type'] ?? '', $skipTypes, true)) {
                continue;
            }
            
            $label = $field['label'] ?? sprintf(__('Field %d', 'wpforms-swpm-bridge'), $id);
            
            // Split Name fields into First/Last components
            if (($field['type'] ?? '') === 'name') {
                $format = $field['format'] ?? 'first-last';
                
                if (in_array($format, ['first-last', 'first-middle-last'], true)) {
                    $fields[$id . '_first'] = $label . ' (' . __('First', 'wpforms-swpm-bridge') . ')';
                    $fields[$id . '_last'] = $label . ' (' . __('Last', 'wpforms-swpm-bridge') . ')';
                } else {
                    // Simple format - just one field
                    $fields[$id] = $label;
                }
            // Split Address fields into components
            } elseif (($field['type'] ?? '') === 'address') {
                $scheme = $field['scheme'] ?? 'us';
                
                $fields[$id . '_address1'] = $label . ' (' . __('Address 1', 'wpforms-swpm-bridge') . ')';
                $fields[$id . '_address2'] = $label . ' (' . __('Address 2', 'wpforms-swpm-bridge') . ')';
                $fields[$id . '_city'] = $label . ' (' . __('City', 'wpforms-swpm-bridge') . ')';
                $fields[$id . '_state'] = $label . ' (' . __('State', 'wpforms-swpm-bridge') . ')';
                $fields[$id . '_postal'] = $label . ' (' . __('Zip/Postal', 'wpforms-swpm-bridge') . ')';
                $fields[$id . '_country'] = $label . ' (' . __('Country', 'wpforms-swpm-bridge') . ')';
            } else {
                $fields[$id] = $label;
            }
        }
        
        return $fields;
    }
    
    /**
     * Process custom field mappings before form save.
     */
    public function processCustomFieldMappings(array $form, $data, $args): array {
        // Decode form data
        $formData = wpforms_decode($form['post_content']);
        
        // Check if we have custom field mappings to merge
        if (isset($formData['settings']['swpm_integration']['field_map']) && 
            isset($formData['settings']['swpm_integration']['field_map_custom'])) {
            
            $fieldMap = $formData['settings']['swpm_integration']['field_map'];
            $customMap = $formData['settings']['swpm_integration']['field_map_custom'];
            
            // Merge custom_ fields with their custom key values
            foreach ($fieldMap as $fieldId => $mapping) {
                if ($mapping === 'custom_' && !empty($customMap[$fieldId])) {
                    $fieldMap[$fieldId] = 'custom_' . sanitize_key($customMap[$fieldId]);
                }
            }
            
            $formData['settings']['swpm_integration']['field_map'] = $fieldMap;
            unset($formData['settings']['swpm_integration']['field_map_custom']);
            
            // Re-encode
            $form['post_content'] = wpforms_encode($formData);
        }
        
        return $form;
    }
    
    /**
     * Get integration config for a form.
     */
    public function getIntegrationConfig(array $formData): array {
        return $formData['settings']['swpm_integration'] ?? [
            'enabled' => false,
            'action_type' => 'register_member',
            'field_map' => [],
            'membership_level' => '',
            'options' => [
                'on_duplicate' => 'reject',
                'password_mode' => 'require_field',
                'auto_login' => false,
                'send_welcome' => true,
                'redirect_url' => '',
            ],
        ];
    }
    
    /**
     * Static helper to get config by form ID.
     */
    public static function getConfig(int $formId): array {
        $form = wpforms()->form->get($formId);
        if (!$form) {
            return [];
        }
        
        $formData = wpforms_decode($form->post_content);
        
        $instance = new self();
        return $instance->getIntegrationConfig($formData);
    }
    
    /**
     * AJAX handler to get form fields.
     */
    public function ajaxGetFormFields(): void {
        check_ajax_referer('wpforms-builder', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $formId = absint($_POST['form_id'] ?? 0);
        if (!$formId) {
            wp_send_json_error('Invalid form ID');
        }
        
        $form = wpforms()->form->get($formId);
        if (!$form) {
            wp_send_json_error('Form not found');
        }
        
        $formData = wpforms_decode($form->post_content);
        $fields = $this->getFormFields($formData);
        wp_send_json_success($fields);
    }
    
    /**
     * AJAX handler to refresh field mapping HTML.
     */
    public function ajaxRefreshMapping(): void {
        // Accept nonce from multiple sources
        $nonce = $_POST['nonce'] ?? $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'wpforms-builder')) {
            // Try alternative nonce actions
            if (!wp_verify_nonce($nonce, 'wpforms-admin') && !wp_verify_nonce($nonce, 'wpforms_save_form')) {
                wp_send_json_error('Invalid nonce');
            }
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Get current form data from POST (live builder state)
        $formDataJson = stripslashes($_POST['form_data'] ?? '');
        $formData = json_decode($formDataJson, true);
        
        if (empty($formData)) {
            wp_send_json_error('Invalid form data');
        }
        
        // Get saved config from database if form_id provided
        $formId = absint($_POST['form_id'] ?? 0);
        $config = [];
        
        if ($formId) {
            $form = wpforms()->form->get($formId);
            if ($form) {
                $savedFormData = wpforms_decode($form->post_content);
                $config = $this->getIntegrationConfig($savedFormData);
            }
        }
        
        if (empty($config)) {
            $config = $this->getIntegrationConfig($formData);
        }
        
        // Render mapping rows to buffer
        ob_start();
        
        $this->renderFieldMappingRows($formData, $config);
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html]);
    }
}