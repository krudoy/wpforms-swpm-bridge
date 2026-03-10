<?php

declare(strict_types=1);

namespace SWPMWPForms\Shortcodes;

use SWPMWPForms\Admin\FormIntegration;
use SWPMWPForms\Services\SwpmService;

/**
 * Profile display shortcode.
 */
class ProfileShortcode {
    
    private SwpmService $swpmService;
    private ?array $currentMember = null;
    private array $fieldMap = [];
    private array $formFields = [];
    
    private const EXCLUDED_FIELDS = ['password'];
    private const SKIP_FIELD_TYPES = ['pagebreak', 'captcha', 'entry-preview', 'password', 'hidden'];
    private const STRUCTURAL_FIELD_TYPES = ['divider', 'html', 'content'];
    private const COMPOUND_FIELD_TYPES = ['name', 'address'];
    private const CONTAINER_FIELD_TYPES = ['layout', 'repeater'];
    private const EMPTY_RENDERABLE_UNMAPPED_FIELD_TYPES = ['checkbox', 'radio'];
    
    public function __construct() {
        $this->swpmService = SwpmService::instance();
    }
    
    public function init(): void {
        add_shortcode('swpm_profile', [$this, 'renderProfile']);
        add_shortcode('swpm_field', [$this, 'renderField']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendStyles']);
    }

    public function enqueueFrontendStyles(): void {
        if (!is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post || !has_shortcode((string) $post->post_content, 'swpm_profile')) {
            return;
        }

        $this->ensureFrontendStyles();
    }

    private function ensureFrontendStyles(): void {
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

        if (did_action('wp_head')) {
            $handles = array_filter(array_merge($dependencies, ['swpm-wpforms-profile']), static function (string $handle): bool {
                return wp_style_is($handle, 'enqueued') && !wp_style_is($handle, 'done');
            });

            if (!empty($handles)) {
                wp_print_styles($handles);
            }
        }
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
    
    public function renderProfile($atts, ?string $content = null): string {
        $this->ensureFrontendStyles();

        $atts = shortcode_atts([
            'form_id'        => '',
            'member_id'      => '',
            'username'       => '',
            'class'          => '',
            'layout'         => 'wpforms',
            'include'        => '',
            'exclude'        => '',
            'show_empty'     => 'no',
            'empty_text'     => '—',
            'show_border'    => 'no',
            'password_notice'=> 'auto',
            'editor_preview' => 'no',
        ], $atts, 'swpm_profile');
        
        $member = $this->resolveMember($atts);
        if (!$member) {
            return $this->renderNotLoggedIn();
        }
        
        $this->currentMember = $member;
        
        $includeFields = $this->parseFieldList($atts['include']);
        $excludeFields = $this->parseFieldList($atts['exclude']);
        $showEmpty = in_array(strtolower($atts['show_empty']), ['yes', 'true', '1'], true);
        $emptyText = $atts['empty_text'];
        $showBorder = in_array(strtolower($atts['show_border']), ['yes', 'true', '1'], true);
        $renderUnmappedFields = in_array(strtolower($atts['editor_preview']), ['yes', 'true', '1'], true);
        
        if (!empty($content)) {
            $output = do_shortcode($content);
            $this->currentMember = null;
            $wrapperClass = $this->getWrapperClass($atts['layout'], $atts['class']) . ($showBorder ? ' swpm-show-border' : '');
            return sprintf('<div class="%s">%s</div>', esc_attr($wrapperClass), $output);
        }
        
        $formId = (int) $atts['form_id'];
        if (!$formId) {
            $this->currentMember = null;
            return '<!-- swpm_profile: form_id required -->';
        }
        
        $output = $this->renderFromFormTemplate($formId, $member, $atts['layout'], $includeFields, $excludeFields, $showEmpty, $emptyText, $renderUnmappedFields);
        $output .= $this->maybeRenderPasswordNotice($formId, $atts['password_notice']);
        
        $this->currentMember = null;
        
        $wrapperClass = $this->getWrapperClass($atts['layout'], $atts['class']) . ($showBorder ? ' swpm-show-border' : '');
        return sprintf('<div class="%s">%s</div>', esc_attr($wrapperClass), $output);
    }
    
    public function renderField($atts): string {
        $atts = shortcode_atts([
            'name' => '', 'member_id' => '', 'username' => '', 'default' => '', 'format' => '',
        ], $atts, 'swpm_field');
        
        if (empty($atts['name']) || in_array($atts['name'], self::EXCLUDED_FIELDS, true)) return '';
        
        $member = $this->currentMember ?: $this->resolveMember($atts);
        if (!$member) return esc_html($atts['default']);
        
        $value = $this->getMemberFieldValue($member, $atts['name']);
        if ($value === null || $value === '') return esc_html($atts['default']);
        
        return esc_html($this->formatValue($value, $atts['name'], $atts['format']));
    }
    
    private function parseFieldList(string $list): array {
        return empty($list) ? [] : array_map('trim', explode(',', $list));
    }
    
    private function shouldShowField(string $fieldId, array $includeFields, array $excludeFields): bool {
        if (!empty($includeFields)) return in_array($fieldId, $includeFields, true);
        if (!empty($excludeFields)) return !in_array($fieldId, $excludeFields, true);
        return true;
    }
    
    private function getWrapperClass(string $layout, string $customClass): string {
        $classes = ['swpm-profile'];
        switch ($layout) {
            case 'wpforms': $classes[] = 'wpforms-container swpm-profile-wpforms'; break;
            case 'table':   $classes[] = 'swpm-profile-table'; break;
            case 'inline':  $classes[] = 'swpm-profile-inline'; break;
            default:        $classes[] = 'swpm-profile-list';
        }
        if (!empty($customClass)) $classes[] = $customClass;
        return implode(' ', $classes);
    }
    
    private function resolveMember(array $atts): ?array {
        if (!empty($atts['member_id'])) return $this->swpmService->getMemberById((int) $atts['member_id']);
        if (!empty($atts['username'])) return $this->swpmService->getMemberByUsername($atts['username']);
        return $this->getCurrentMember();
    }
    
    private function getCurrentMember(): ?array {
        if (!class_exists('SwpmMemberUtils')) return null;
        $memberId = \SwpmMemberUtils::get_logged_in_members_id();
        return $memberId ? $this->swpmService->getMemberById((int) $memberId) : null;
    }
    
    private function renderFromFormTemplate(
        int $formId, array $member, string $layout, 
        array $includeFields, array $excludeFields,
        bool $showEmpty, string $emptyText,
        bool $renderUnmappedFields
    ): string {
        $config = FormIntegration::getConfig($formId);
        $form = wpforms()->form->get($formId);
        if (!$form) return '<!-- swpm_profile: form not found -->';
        
        $formData = wpforms_decode($form->post_content);
        $this->formFields = $formData['fields'] ?? [];
        if (empty($this->formFields)) return '<!-- swpm_profile: no fields -->';
        
        $integration = new FormIntegration();
        $this->fieldMap = $integration->getFieldMapWithCustomKeys($config);
        
        $output = '';
        $context = [
            'member' => $member,
            'layout' => $layout,
            'includeFields' => $includeFields,
            'excludeFields' => $excludeFields,
            'showEmpty' => $showEmpty,
            'emptyText' => $emptyText,
            'renderUnmappedFields' => $renderUnmappedFields,
            'formData' => $formData,
        ];
        
        foreach ($this->formFields as $fieldId => $formField) {
            $output .= $this->renderFormField($formField, $context);
        }
        
        $output = $this->wrapFieldsOutput($output, $layout);
        return apply_filters('swpm_wpforms_profile_output', $output, $member, $formId);
    }
    
    /**
     * Render a single form field.
     */
    private function renderFormField(array $formField, array $context): string {
        $fieldType = $formField['type'] ?? '';
        $fieldIdStr = (string) ($formField['id'] ?? '');
        
        if (in_array($fieldType, self::SKIP_FIELD_TYPES, true)) return '';
        if (!$this->shouldShowField($fieldIdStr, $context['includeFields'], $context['excludeFields'])) return '';
        
        // Container fields (layout)
        if (in_array($fieldType, self::CONTAINER_FIELD_TYPES, true)) {
            return $this->renderContainerField($formField, $context);
        }
        
        // Structural fields (divider, html, content)
        if (in_array($fieldType, self::STRUCTURAL_FIELD_TYPES, true)) {
            return $this->renderStructuralField($formField, $fieldType, $context);
        }
        
        // Compound fields (name, address)
        if (in_array($fieldType, self::COMPOUND_FIELD_TYPES, true)) {
            return $this->renderCompoundField($formField, $context);
        }
        
        // Simple fields
        return $this->renderSimpleField($formField, $context);
    }
    
    /**
     * Render container fields (layout with columns).
     */
    private function renderContainerField(array $formField, array $context): string {
        $fieldType = $formField['type'] ?? '';
        $css = $formField['css'] ?? '';
        $fieldId = $formField['id'] ?? '';
        
        if ($fieldType === 'layout') {
            $columns = $formField['columns'] ?? [];
            if (empty($columns)) return '';
            
            $output = sprintf(
                '<div class="wpforms-field wpforms-field-layout swpm-field-layout %s" data-field-id="%s">',
                esc_attr($css), esc_attr($fieldId)
            );
            $output .= '<div class="wpforms-field-layout-columns">';
            
            foreach ($columns as $colIndex => $column) {
                $colWidth = $column['width_preset'] ?? '50';
                $colClass = $this->getColumnClass($colWidth);
                $isFirst = $colIndex === 0 ? ' wpforms-first' : '';
                
                $output .= sprintf(
                    '<div class="wpforms-layout-column wpforms-layout-column-%s%s">',
                    esc_attr($colClass), $isFirst
                );
                
                $columnFields = $column['fields'] ?? [];
                foreach ($columnFields as $childFieldId) {
                    if (isset($this->formFields[$childFieldId])) {
                        $output .= $this->renderFormField($this->formFields[$childFieldId], $context);
                    }
                }
                
                $output .= '</div>';
            }
            
            $output .= '</div></div>';
            return $output;
        }
        
        return '';
    }
    
    private function getColumnClass(string $width): string {
        return match($width) {
            '100' => 'full', '50' => 'one-half', '33' => 'one-third', '67' => 'two-thirds',
            '25' => 'one-quarter', '75' => 'three-quarters', '20' => 'one-fifth',
            '40' => 'two-fifths', '60' => 'three-fifths', '80' => 'four-fifths',
            default => 'one-half',
        };
    }
    
    /**
     * Render simple fields.
     */
    private function renderSimpleField(array $formField, array $context): string {
        $fieldIdStr = (string) $formField['id'];
        $fieldType = (string) ($formField['type'] ?? '');
        $swpmField = $this->fieldMap[$fieldIdStr] ?? '';
        $renderUnmappedField = empty($swpmField) && !empty($context['renderUnmappedFields']);
        $renderEmptyUnmappedChoiceField = empty($swpmField)
            && !empty($context['showEmpty'])
            && in_array($fieldType, self::EMPTY_RENDERABLE_UNMAPPED_FIELD_TYPES, true);
        
        if (in_array($swpmField, self::EXCLUDED_FIELDS, true)) return '';
        if (empty($swpmField) && !$renderUnmappedField && !$renderEmptyUnmappedChoiceField) return '';
        
        $rawValue = empty($swpmField)
            ? null
            : $this->formatValue($this->getMemberFieldValue($context['member'], $swpmField), $swpmField);
        
        $isEmpty = ($rawValue === null || $rawValue === '');
        if ($isEmpty && !$context['showEmpty'] && !$renderUnmappedField) return '';
        
        // Apply WPForms-style value filter
        $displayValue = $isEmpty 
            ? $context['emptyText'] 
            : $this->applyHtmlFieldValue($rawValue, $formField, $context['formData']);
        
        $fieldLabel = $formField['label'] ?? ucfirst(str_replace('_', ' ', $swpmField));
        
        return $this->renderDataField($fieldLabel, $displayValue, $formField, $context['layout'], $isEmpty);
    }
    
    /**
     * Render compound fields (name, address).
     */
    private function renderCompoundField(array $formField, array $context): string {
        $fieldId = (string) $formField['id'];
        $fieldType = $formField['type'];
        $fieldLabel = $formField['label'] ?? ucfirst($fieldType);
        $css = $formField['css'] ?? '';
        
        $values = [];
        $hasAnyValue = false;
        $mappedParts = [];
        
        if ($fieldType === 'name') {
            foreach (['first', 'middle', 'last'] as $part) {
                $swpmField = $this->fieldMap[$fieldId . '_' . $part] ?? '';
                if (!empty($swpmField)) {
                    $mappedParts[] = $part;
                    if (in_array($swpmField, self::EXCLUDED_FIELDS, true)) continue;
                    $val = $this->getMemberFieldValue($context['member'], $swpmField);
                    if ($val !== null && $val !== '') {
                        $values[$part] = $val;
                        $hasAnyValue = true;
                    }
                }
            }
            
        } elseif ($fieldType === 'address') {
            foreach (['street', 'city', 'state', 'postal', 'country'] as $part) {
                $swpmField = $this->fieldMap[$fieldId . '_' . $part] ?? '';
                if (!empty($swpmField)) {
                    $mappedParts[] = $part;
                    if (in_array($swpmField, self::EXCLUDED_FIELDS, true)) continue;
                    $val = $this->getMemberFieldValue($context['member'], $swpmField);
                    if ($val !== null && $val !== '') {
                        $values[$part] = $val;
                        $hasAnyValue = true;
                    }
                }
            }
        } else {
            return '';
        }

        $renderUnmappedField = empty($mappedParts) && !empty($context['renderUnmappedFields']);
        if (empty($mappedParts) && !$renderUnmappedField) return '';
        
        $isEmpty = !$hasAnyValue;
        if ($isEmpty && !$context['showEmpty'] && !$renderUnmappedField) return '';

        if ($renderUnmappedField) {
            return $this->renderDataField($fieldLabel, $context['emptyText'], $formField, $context['layout'], true, true);
        }
        
        // For wpforms layout, render with proper sub-field structure
        if ($context['layout'] === 'wpforms') {
            return $this->renderCompoundFieldWpforms($formField, $fieldType, $values, $mappedParts, $context, $isEmpty);
        }
        
        // For other layouts, combine into single value
        $displayValue = $this->combineCompoundValue($fieldType, $values, $context['emptyText'], $isEmpty);
        $displayValue = $isEmpty ? $context['emptyText'] : $this->applyHtmlFieldValue($displayValue, $formField, $context['formData']);
        
        return $this->renderDataField($fieldLabel, $displayValue, $formField, $context['layout'], $isEmpty, true);
    }
    
    /**
     * Render compound field with WPForms sub-field structure.
     */
    private function renderCompoundFieldWpforms(
        array $formField, 
        string $fieldType, 
        array $values, 
        array $mappedParts,
        array $context,
        bool $isEmpty
    ): string {
        $fieldId = $formField['id'] ?? '';
        $fieldLabel = $formField['label'] ?? ucfirst($fieldType);
        $css = $formField['css'] ?? '';
        $emptyClass = $isEmpty ? ' swpm-field-empty' : '';
        
        $output = sprintf(
            '<div id="wpforms-%s-field_%s-container" class="wpforms-field wpforms-field-%s swpm-field%s %s" data-field-type="%s" data-field-id="%s">',
            'profile', esc_attr($fieldId), esc_attr($fieldType), $emptyClass, esc_attr($css), esc_attr($fieldType), esc_attr($fieldId)
        );
        $output .= sprintf('<label class="wpforms-field-label">%s</label>', esc_html($fieldLabel));
        
        if ($fieldType === 'name') {
            $output .= $this->renderNameSubfields($values, $mappedParts, $context);
        } else {
            $output .= $this->renderAddressSubfields($values, $mappedParts, $context);
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Render name sub-fields in WPForms style.
     */
    private function renderNameSubfields(array $values, array $mappedParts, array $context): string {
        $sublabels = [
            'first' => __('First', 'wpforms-swpm-bridge'),
            'middle' => __('Middle', 'wpforms-swpm-bridge'),
            'last' => __('Last', 'wpforms-swpm-bridge'),
        ];
        
        // Filter to only mapped parts
        $parts = array_intersect(['first', 'middle', 'last'], $mappedParts);
        if (empty($parts)) return '';
        
        $count = count($parts);
        $widthClass = $count === 3 ? 'wpforms-one-third' : ($count === 2 ? 'wpforms-one-half' : '');
        
        $output = '<div class="wpforms-field-row wpforms-field-large">';
        $first = true;
        
        foreach ($parts as $part) {
            $val = $values[$part] ?? '';
            $isEmpty = ($val === '');
            
            if ($isEmpty && !$context['showEmpty']) continue;
            
            $displayVal = $isEmpty ? esc_html($context['emptyText']) : esc_html($val);
            $emptyClass = $isEmpty ? ' swpm-value-empty' : '';
            $firstClass = $first ? ' wpforms-first' : '';
            $first = false;
            
            $output .= sprintf(
                '<div class="wpforms-field-row-block%s %s"><div class="swpm-field-value%s">%s</div><span class="wpforms-field-sublabel after">%s</span></div>',
                $firstClass, $widthClass, $emptyClass, $displayVal, esc_html($sublabels[$part])
            );
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Render address sub-fields in WPForms style.
     */
    private function renderAddressSubfields(array $values, array $mappedParts, array $context): string {
        $sublabels = [
            'street' => __('Address', 'wpforms-swpm-bridge'),
            'city' => __('City', 'wpforms-swpm-bridge'),
            'state' => __('State / Province / Region', 'wpforms-swpm-bridge'),
            'postal' => __('ZIP / Postal Code', 'wpforms-swpm-bridge'),
            'country' => __('Country', 'wpforms-swpm-bridge'),
        ];
        
        $output = '';
        
        // Row 1: Street address
        if (in_array('street', $mappedParts)) {
            $val = $values['street'] ?? '';
            $isEmpty = ($val === '');
            if (!$isEmpty || $context['showEmpty']) {
                $displayVal = $isEmpty ? esc_html($context['emptyText']) : esc_html($val);
                $emptyClass = $isEmpty ? ' swpm-value-empty' : '';
                $output .= '<div class="wpforms-field-row wpforms-field-large">';
                $output .= sprintf(
                    '<div class="wpforms-field-row-block wpforms-first"><div class="swpm-field-value%s">%s</div><span class="wpforms-field-sublabel after">%s</span></div>',
                    $emptyClass, $displayVal, esc_html($sublabels['street'])
                );
                $output .= '</div>';
            }
        }
        
        // Row 2: City, State, Postal
        $row2Parts = array_intersect(['city', 'state', 'postal'], $mappedParts);
        if (!empty($row2Parts)) {
            $count = count($row2Parts);
            $widthClass = $count === 3 ? 'wpforms-one-third' : ($count === 2 ? 'wpforms-one-half' : '');
            
            $output .= '<div class="wpforms-field-row wpforms-field-large">';
            $first = true;
            
            foreach ($row2Parts as $part) {
                $val = $values[$part] ?? '';
                $isEmpty = ($val === '');
                if ($isEmpty && !$context['showEmpty']) continue;
                
                $displayVal = $isEmpty ? esc_html($context['emptyText']) : esc_html($val);
                $emptyClass = $isEmpty ? ' swpm-value-empty' : '';
                $firstClass = $first ? ' wpforms-first' : '';
                $first = false;
                
                $output .= sprintf(
                    '<div class="wpforms-field-row-block%s %s"><div class="swpm-field-value%s">%s</div><span class="wpforms-field-sublabel after">%s</span></div>',
                    $firstClass, $widthClass, $emptyClass, $displayVal, esc_html($sublabels[$part])
                );
            }
            $output .= '</div>';
        }
        
        // Row 3: Country
        if (in_array('country', $mappedParts)) {
            $val = $values['country'] ?? '';
            $isEmpty = ($val === '');
            if (!$isEmpty || $context['showEmpty']) {
                $displayVal = $isEmpty ? esc_html($context['emptyText']) : esc_html($val);
                $emptyClass = $isEmpty ? ' swpm-value-empty' : '';
                $output .= '<div class="wpforms-field-row wpforms-field-large">';
                $output .= sprintf(
                    '<div class="wpforms-field-row-block wpforms-first"><div class="swpm-field-value%s">%s</div><span class="wpforms-field-sublabel after">%s</span></div>',
                    $emptyClass, $displayVal, esc_html($sublabels['country'])
                );
                $output .= '</div>';
            }
        }
        
        return $output;
    }
    
    /**
     * Combine compound field values for non-wpforms layouts.
     */
    private function combineCompoundValue(string $fieldType, array $values, string $emptyText, bool $isEmpty): string {
        if ($isEmpty) return $emptyText;
        
        if ($fieldType === 'name') {
            return trim(implode(' ', array_filter([
                $values['first'] ?? '', $values['middle'] ?? '', $values['last'] ?? ''
            ])));
        }
        
        // Address
        $lines = [];
        if (!empty($values['street'])) $lines[] = $values['street'];
        $cityLine = trim(implode(', ', array_filter([
            $values['city'] ?? '',
            trim(($values['state'] ?? '') . ' ' . ($values['postal'] ?? '')),
        ])));
        if (!empty($cityLine)) $lines[] = $cityLine;
        if (!empty($values['country'])) $lines[] = $values['country'];
        return implode("\n", $lines);
    }
    
    /**
     * Render structural fields (divider, html, content).
     */
    private function renderStructuralField(array $formField, string $type, array $context): string {
        $fieldId = $formField['id'] ?? '';
        $css = $formField['css'] ?? '';
        
        switch ($type) {
            case 'divider':
                $label = $formField['label'] ?? '';
                $desc = $formField['description'] ?? '';
                return sprintf(
                    '<div class="wpforms-field wpforms-field-divider swpm-field-divider %s" data-field-id="%s">' .
                    '<h3 class="swpm-divider-label">%s</h3><hr class="swpm-divider-hr">%s</div>',
                    esc_attr($css), esc_attr($fieldId), esc_html($label),
                    $desc ? '<div class="swpm-divider-description">' . wp_kses_post($desc) . '</div>' : ''
                );
                
            case 'html':
                // Check for special CSS class like in your code
                $code = $formField['code'] ?? '';
                // Process shortcodes in HTML field content
                $code = do_shortcode($code);
                return sprintf(
                    '<div class="wpforms-field wpforms-field-html swpm-field-html %s" data-field-id="%s">' .
                    '<div class="swpm-html-content">%s</div></div>',
                    esc_attr($css), esc_attr($fieldId), wp_kses_post($code)
                );
                
            case 'content':
                $content = $formField['content'] ?? '';
                $content = do_shortcode($content);
                return sprintf(
                    '<div class="wpforms-field wpforms-field-content swpm-field-content %s" data-field-id="%s">' .
                    '<div class="swpm-content-content">%s</div></div>',
                    esc_attr($css), esc_attr($fieldId), wp_kses_post($content)
                );
        }
        return '';
    }
    
    /**
     * Apply wpforms_html_field_value filter for consistent formatting.
     * Also applies our own filter for SWPM-specific formatting.
     */
    private function applyHtmlFieldValue(string $value, array $formField, array $formData): string {
        // Build a pseudo entry_field structure for the filter
        $entryField = [
            'id'    => $formField['id'] ?? '',
            'type'  => $formField['type'] ?? 'text',
            'name'  => $formField['label'] ?? '',
            'value' => $value,
        ];
        
        /**
         * Filter the profile field value before display.
         * Compatible with wpforms_html_field_value signature.
         *
         * @param string $value      The field value.
         * @param array  $field      The field data.
         * @param array  $form_data  The form data.
         * @param string $context    The display context.
         */
        $value = apply_filters(
            'swpm_wpforms_profile_field_value',
            $value,
            $entryField,
            $formData,
            'swpm-profile'
        );
        
        // Also try WPForms filter if available (for compatibility with WPForms addons)
        if (has_filter('wpforms_html_field_value')) {
            $value = apply_filters(
                'wpforms_html_field_value',
                $value,
                $entryField,
                $formData,
                'swpm-profile'
            );
        }
        
        // Convert newlines to <br> for display
        $value = str_replace("\n", '<br>', $value);
        
        return $value;
    }
    
    /**
     * Render a data field (already filtered value).
     */
    private function renderDataField(string $label, string $value, array $formField, string $layout, bool $isEmpty = false, bool $isMultiline = false): string {
        $css = $formField['css'] ?? '';
        $fieldId = $formField['id'] ?? '';
        $fieldType = $formField['type'] ?? 'text';
        $emptyClass = $isEmpty ? ' swpm-field-empty' : '';
        $description = $formField['description'] ?? '';
        
        // Value is already HTML-safe from applyHtmlFieldValue, or escaped emptyText
        $displayValue = $isEmpty ? esc_html($value) : $value;
        
        switch ($layout) {
            case 'wpforms':
                $descHtml = $description ? sprintf(
                    '<div class="wpforms-field-description-below">%s</div>',
                    esc_html($description)
                ) : '';
                return sprintf(
                    '<div class="wpforms-field wpforms-field-%s swpm-field%s %s" data-field-id="%s">' .
                    '<label class="wpforms-field-label">%s</label>' .
                    '<div class="swpm-field-value">%s</div>%s</div>',
                    esc_attr($fieldType), $emptyClass, esc_attr($css), esc_attr($fieldId),
                    esc_html($label), $displayValue, $descHtml
                );
                
            case 'table':
                return sprintf(
                    '<tr class="swpm-field swpm-field-%s%s %s">' .
                    '<th class="swpm-field-label">%s</th>' .
                    '<td class="swpm-field-value">%s</td></tr>',
                    esc_attr($fieldType), $emptyClass, esc_attr($css),
                    esc_html($label), $displayValue
                );
                
            case 'inline':
                return sprintf(
                    '<span class="swpm-field swpm-field-%s%s %s">' .
                    '<span class="swpm-field-label">%s:</span> ' .
                    '<span class="swpm-field-value">%s</span></span>',
                    esc_attr($fieldType), $emptyClass, esc_attr($css),
                    esc_html($label), $displayValue
                );
                
            default: // list
                return sprintf(
                    '<dt class="swpm-field-label%s %s">%s</dt>' .
                    '<dd class="swpm-field-value">%s</dd>',
                    $emptyClass, esc_attr($css), esc_html($label), $displayValue
                );
        }
    }
    
    private function wrapFieldsOutput(string $output, string $layout): string {
        if (empty($output)) return '';
        switch ($layout) {
            case 'wpforms': return '<div class="wpforms-field-container">' . $output . '</div>';
            case 'table':   return '<table class="swpm-profile-table-inner"><tbody>' . $output . '</tbody></table>';
            case 'inline':  return preg_replace('/<\/span><span class="swpm-field/', '</span> <span class="swpm-field-separator">|</span> <span class="swpm-field', $output);
            default:        return '<dl class="swpm-profile-fields">' . $output . '</dl>';
        }
    }
    
    private function maybeRenderPasswordNotice(int $formId, string $mode): string {
        if ($mode === 'no') return '';
        $config = FormIntegration::getConfig($formId);
        $hasPassword = in_array('password', $config['field_map'] ?? [], true);
        if ($mode === 'auto' && !$hasPassword) return '';
        if ($mode === 'yes' || $hasPassword) {
            $notice = apply_filters('swpm_wpforms_profile_password_notice', 
                __('Note: Password fields are always hidden for security.', 'wpforms-swpm-bridge'));
            return sprintf('<p class="swpm-profile-password-notice"><em>%s</em></p>', esc_html($notice));
        }
        return '';
    }
    
    private function getMemberFieldValue(array $member, string $field): ?string {
        if (in_array($field, self::EXCLUDED_FIELDS, true)) return null;
        
        $columnMap = [
            'email' => 'email', 'username' => 'user_name', 'first_name' => 'first_name',
            'last_name' => 'last_name', 'membership_level' => 'membership_level',
            'phone' => 'phone', 'address_street' => 'address_street',
            'address_city' => 'address_city', 'address_state' => 'address_state',
            'address_zipcode' => 'address_zipcode', 'country' => 'country',
            'company' => 'company_name', 'gender' => 'gender',
            'member_since' => 'member_since', 'subscription_starts' => 'subscription_starts',
            'account_state' => 'account_state',
        ];
        
        if (isset($columnMap[$field])) {
            return isset($member[$columnMap[$field]]) ? (string) $member[$columnMap[$field]] : null;
        }
        if (str_starts_with($field, 'wp_')) return $this->getWpUserField($member, $field);
        if (str_starts_with($field, 'custom_')) return $this->getMemberMeta($member, substr($field, 7));
        if (str_starts_with($field, 'swpm_')) return $this->getMemberMeta($member, substr($field, 5));
        return isset($member[$field]) ? (string) $member[$field] : null;
    }
    
    private function getWpUserField(array $member, string $field): ?string {
        if (empty($member['wp_user_id'])) return null;
        $wpUser = get_userdata((int) $member['wp_user_id']);
        if (!$wpUser) return null;
        return match($field) {
            'wp_display_name' => $wpUser->display_name,
            'wp_nickname' => $wpUser->nickname,
            'wp_description' => $wpUser->description,
            'wp_user_url' => $wpUser->user_url,
            'wp_avatar' => get_avatar_url($wpUser->ID),
            default => null,
        };
    }
    
    private function getMemberMeta(array $member, string $key): ?string {
        global $wpdb;
        $memberId = $member['member_id'] ?? 0;
        if (!$memberId) return null;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}swpm_members_meta WHERE member_id = %d AND meta_key = %s LIMIT 1",
            $memberId, $key
        ));
    }
    
    private function formatValue(?string $value, string $field, string $format = ''): ?string {
        if ($value === null) return null;
        if ($format === 'membership_name' || ($field === 'membership_level' && $format !== 'raw')) {
            $levels = $this->swpmService->getMembershipLevels();
            return $levels[$value] ?? $value;
        }
        if ($format === 'date' || in_array($field, ['member_since', 'subscription_starts'], true)) {
            $ts = strtotime($value);
            return $ts ? wp_date(get_option('date_format'), $ts) : $value;
        }
        return $value;
    }
    
    private function renderNotLoggedIn(): string {
        $msg = apply_filters('swpm_wpforms_profile_not_logged_in', 
            __('Please log in to view your profile.', 'wpforms-swpm-bridge'));
        return sprintf('<div class="swpm-profile-not-logged-in">%s</div>', esc_html($msg));
    }
}
