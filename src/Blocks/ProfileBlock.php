<?php

declare(strict_types=1);

namespace SWPMWPForms\Blocks;

/**
 * Gutenberg block for SWPM profile display.
 */
class ProfileBlock {
    
    private const SKIP_FIELD_TYPES = ['pagebreak', 'captcha', 'entry-preview', 'password', 'hidden'];
    private const STRUCTURAL_FIELD_TYPES = []; // Emptied - content fields now toggleable
    private const CONTENT_FIELD_TYPES = ['divider', 'html', 'content'];
    private const CONTAINER_FIELD_TYPES = ['layout', 'repeater'];
    
    public function init(): void {
        add_action('init', [$this, 'registerBlock']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendStyles']);
    }
    
    public function registerBlock(): void {
        register_block_type('swpm-wpforms/profile', [
            'api_version'     => 3,
            'editor_script'   => 'swpm-wpforms-profile-block',
            'render_callback' => [$this, 'renderBlock'],
            'attributes'      => [
                'formId' => ['type' => 'string', 'default' => ''],
                'memberId' => ['type' => 'string', 'default' => ''],
                'username' => ['type' => 'string', 'default' => ''],
                'layout' => ['type' => 'string', 'default' => 'wpforms'],
                'hiddenFields' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                'showEmptyFields' => ['type' => 'boolean', 'default' => false],
                'emptyText' => ['type' => 'string', 'default' => '—'],
                'passwordNotice' => ['type' => 'string', 'default' => 'auto'],
                'showBorder' => ['type' => 'boolean', 'default' => false],
                'className' => ['type' => 'string', 'default' => ''],
                'customContent' => ['type' => 'string', 'default' => ''],
                'editorPreview' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }
    
    public function enqueueEditorAssets(): void {
        $formsData = $this->getWpFormsWithFields();
        
        wp_enqueue_script(
            'swpm-wpforms-profile-block',
            SWPM_WPFORMS_PLUGIN_URL . 'assets/js/blocks/profile-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'],
            SWPM_WPFORMS_VERSION,
            true
        );
        
        wp_localize_script('swpm-wpforms-profile-block', 'swpmWpformsProfile', [
            'forms'   => $formsData,
            'layouts' => [
                ['value' => 'wpforms', 'label' => __('WPForms Style', 'wpforms-swpm-bridge')],
                ['value' => 'table',   'label' => __('Table', 'wpforms-swpm-bridge')],
                ['value' => 'list',    'label' => __('Definition List', 'wpforms-swpm-bridge')],
                ['value' => 'inline',  'label' => __('Inline', 'wpforms-swpm-bridge')],
            ],
            'labels' => [
                'blockTitle'        => __('SWPM Profile', 'wpforms-swpm-bridge'),
                'blockDescription'  => __('Display member profile data using WPForms field mapping.', 'wpforms-swpm-bridge'),
                'selectForm'        => __('Select Form', 'wpforms-swpm-bridge'),
                'selectFormHelp'    => __('Choose a WPForms form with SWPM integration enabled.', 'wpforms-swpm-bridge'),
                'layout'            => __('Layout Style', 'wpforms-swpm-bridge'),
                'layoutHelp'        => __('How to arrange labels and values.', 'wpforms-swpm-bridge'),
                'fieldVisibility'   => __('Field Visibility', 'wpforms-swpm-bridge'),
                'fieldVisibilityHelp' => __('Uncheck fields to hide them. Dividers/HTML are always shown.', 'wpforms-swpm-bridge'),
                'allFieldsHidden'   => __('All fields are hidden.', 'wpforms-swpm-bridge'),
                'showEmptyFields'   => __('Show Empty Fields', 'wpforms-swpm-bridge'),
                'showEmptyFieldsHelp' => __('Display fields even when they have no value.', 'wpforms-swpm-bridge'),
                'emptyText'         => __('Empty Field Text', 'wpforms-swpm-bridge'),
                'emptyTextHelp'     => __('Text to show for empty fields.', 'wpforms-swpm-bridge'),
                'passwordNotice'    => __('Password Notice', 'wpforms-swpm-bridge'),
                'passwordNoticeHelp'=> __('Show footnote about hidden password field.', 'wpforms-swpm-bridge'),
                'memberId'          => __('Member ID (optional)', 'wpforms-swpm-bridge'),
                'memberIdHelp'      => __('Leave empty to show current logged-in member.', 'wpforms-swpm-bridge'),
                'username'          => __('Username (optional)', 'wpforms-swpm-bridge'),
                'usernameHelp'      => __('Alternative to Member ID.', 'wpforms-swpm-bridge'),
                'customContent'     => __('Custom Content', 'wpforms-swpm-bridge'),
                'customContentHelp' => __('Use [swpm_field name="..."] shortcodes.', 'wpforms-swpm-bridge'),
                'noForms'           => __('No WPForms with SWPM integration found.', 'wpforms-swpm-bridge'),
                'livePreview'       => __('Live Preview', 'wpforms-swpm-bridge'),
                'notLoggedIn'       => __('Log in as a SWPM member to see preview.', 'wpforms-swpm-bridge'),
                'auto'              => __('Auto (detect)', 'wpforms-swpm-bridge'),
                'yes'               => __('Always show', 'wpforms-swpm-bridge'),
                'no'                => __('Never show', 'wpforms-swpm-bridge'),
            ],
        ]);
        
        wp_enqueue_style(
            'swpm-wpforms-profile-block-editor',
            SWPM_WPFORMS_PLUGIN_URL . 'assets/css/profile.css',
            $this->getWpformsFrontendStyleHandles(),
            SWPM_WPFORMS_VERSION
        );
    }
    
    public function enqueueFrontendStyles(): void {
        $dependencies = $this->getWpformsFrontendStyleHandles();

        foreach ($dependencies as $handle) {
            wp_enqueue_style($handle);
        }
        
        wp_enqueue_style(
            'swpm-wpforms-profile',
            SWPM_WPFORMS_PLUGIN_URL . 'assets/css/profile.css',
            $dependencies,
            SWPM_WPFORMS_VERSION
        );
    }

    /**
     * Resolve registered WPForms frontend stylesheet handles.
     *
     * @return string[]
     */
    private function getWpformsFrontendStyleHandles(): array {
        if (!function_exists('wpforms')) {
            return [];
        }

        $styles = wp_styles();
        $handles = [];

        foreach (array_keys($styles->registered) as $handle) {
            if (preg_match('/^wpforms(?:-[a-z0-9]+)*-full$/', $handle) === 1) {
                $handles[] = $handle;
            }
        }

        if (!empty($handles)) {
            return array_values(array_unique($handles));
        }

        foreach (['wpforms-modern-full', 'wpforms-pro-modern-full', 'wpforms-full'] as $handle) {
            if (wp_style_is($handle, 'registered')) {
                $handles[] = $handle;
            }
        }

        return array_values(array_unique($handles));
    }
    
    public function renderBlock(array $attributes): string {
        $shortcodeAtts = [];
        
        if (!empty($attributes['formId'])) {
            $shortcodeAtts[] = sprintf('form_id="%s"', esc_attr($attributes['formId']));
        }
        if (!empty($attributes['memberId'])) {
            $shortcodeAtts[] = sprintf('member_id="%s"', esc_attr($attributes['memberId']));
        }
        if (!empty($attributes['username'])) {
            $shortcodeAtts[] = sprintf('username="%s"', esc_attr($attributes['username']));
        }
        if (!empty($attributes['layout'])) {
            $shortcodeAtts[] = sprintf('layout="%s"', esc_attr($attributes['layout']));
        }
        if (!empty($attributes['hiddenFields']) && is_array($attributes['hiddenFields'])) {
            $exclude = implode(',', array_map('sanitize_key', $attributes['hiddenFields']));
            if ($exclude) {
                $shortcodeAtts[] = sprintf('exclude="%s"', esc_attr($exclude));
            }
        }
        if (!empty($attributes['showEmptyFields'])) {
            $shortcodeAtts[] = 'show_empty="yes"';
        }
        if (!empty($attributes['emptyText']) && $attributes['emptyText'] !== '—') {
            $shortcodeAtts[] = sprintf('empty_text="%s"', esc_attr($attributes['emptyText']));
        }
        if (!empty($attributes['showBorder'])) {
            $shortcodeAtts[] = 'show_border="yes"';
        }
        if (!empty($attributes['passwordNotice'])) {
            $shortcodeAtts[] = sprintf('password_notice="%s"', esc_attr($attributes['passwordNotice']));
        }
        if (!empty($attributes['className'])) {
            $shortcodeAtts[] = sprintf('class="%s"', esc_attr($attributes['className']));
        }
        if (!empty($attributes['editorPreview'])) {
            $shortcodeAtts[] = 'editor_preview="yes"';
        }
        
        $shortcode = '[swpm_profile ' . implode(' ', $shortcodeAtts) . ']';
        
        if (!empty($attributes['customContent'])) {
            $shortcode .= $attributes['customContent'] . '[/swpm_profile]';
        }
        
        return do_shortcode($shortcode);
    }
    
    /**
     * Get WPForms with SWPM integration and their fields (including nested in layouts).
     */
    private function getWpFormsWithFields(): array {
        if (!function_exists('wpforms')) {
            return [];
        }
        
        $forms = wpforms()->form->get('', ['posts_per_page' => -1]);
        if (empty($forms)) {
            return [];
        }
        
        $result = [];
        
        foreach ($forms as $form) {
            $formData = wpforms_decode($form->post_content);
            $config = $formData['settings']['swpm_integration'] ?? [];
            
            if (empty($config['enabled'])) {
                continue;
            }
            
            $formFields = $formData['fields'] ?? [];
            $displayableFields = [];
            $hasPassword = false;
            $processedIds = []; // Track to avoid duplicates from layout containers
            
            // Collect all displayable fields (including those nested in layouts)
            $this->collectDisplayableFields(
                $formFields, $displayableFields, $hasPassword, $processedIds
            );
            
            if (!empty($displayableFields)) {
                $result[] = [
                    'id'          => (string) $form->ID,
                    'title'       => $form->post_title,
                    'fields'      => $displayableFields,
                    'hasPassword' => $hasPassword,
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Recursively collect displayable fields from form structure.
     */
    private function collectDisplayableFields(
        array $formFields, 
        array &$displayableFields, 
        bool &$hasPassword,
        array &$processedIds
    ): void {
        foreach ($formFields as $fieldId => $field) {
            $fieldIdStr = (string) $fieldId;
            
            // Skip if already processed (nested in layout)
            if (in_array($fieldIdStr, $processedIds, true)) {
                continue;
            }
            $processedIds[] = $fieldIdStr;
            
            $fieldType = $field['type'] ?? '';

            if ($fieldType === 'password') {
                $hasPassword = true;
                continue;
            }
            
            // Skip non-displayable types
            if (in_array($fieldType, self::SKIP_FIELD_TYPES, true)) {
                continue;
            }
            
            // Skip structural fields (always shown)
            if (in_array($fieldType, self::STRUCTURAL_FIELD_TYPES, true)) {
                continue;
            }
            
            // Handle layout containers - process nested fields
            if ($fieldType === 'layout') {
                $columns = $field['columns'] ?? [];
                foreach ($columns as $column) {
                    $columnFieldIds = $column['fields'] ?? [];
                    foreach ($columnFieldIds as $nestedFieldId) {
                        if (isset($formFields[$nestedFieldId])) {
                            $this->collectDisplayableFields(
                                [$nestedFieldId => $formFields[$nestedFieldId]],
                                $displayableFields,
                                $hasPassword,
                                $processedIds
                            );
                        }
                    }
                }
                continue;
            }
            
            $fieldLabel = $field['label'] ?? '';
            if (empty($fieldLabel)) {
                $fieldLabel = sprintf('%s (%s)', ucfirst($fieldType), $fieldId);
            }

            $displayableFields[] = [
                'id'    => $fieldIdStr,
                'label' => $fieldLabel,
                'type'  => $fieldType,
            ];
        }
    }
}
