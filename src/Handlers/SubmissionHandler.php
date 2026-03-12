<?php

declare(strict_types=1);

namespace SWPMWPForms\Handlers;

use SWPMWPForms\Admin\FormIntegration;
use SWPMWPForms\Admin\SettingsPage;
use SWPMWPForms\DTO\MemberDTO;
use SWPMWPForms\Services\Logger;
use SWPMWPForms\Services\PasswordService;
use SWPMWPForms\Services\SwpmService;
use SWPMWPForms\Validators\DuplicateChecker;
use SWPMWPForms\Validators\MemberValidator;

/**
 * Handles WPForms submissions and routes to SWPM actions.
 */
class SubmissionHandler {
    
    private SwpmService $swpmService;
    private MemberValidator $validator;
    private DuplicateChecker $duplicateChecker;
    private PasswordService $passwordService;
    private ActionRouter $actionRouter;
    private Logger $logger;
    
    public function __construct() {
        $this->swpmService = SwpmService::instance();
        $this->validator = new MemberValidator();
        $this->duplicateChecker = new DuplicateChecker($this->swpmService);
        $this->passwordService = PasswordService::instance();
        $this->actionRouter = new ActionRouter($this->swpmService);
        $this->logger = Logger::instance();
    }
    
    /**
     * Register hooks.
     */
    public function init(): void {
        // Hook into form processing - before final save
        add_action('wpforms_process_complete', [$this, 'handleSubmission'], 10, 4);
        
        // Hook for validation errors
        add_filter('wpforms_process_initial_errors', [$this, 'addValidationErrors'], 10, 2);
        
        // Filter to block notifications on integration failure
        add_filter('wpforms_entry_email_send', [$this, 'maybeBlockNotifications'], 10, 5);

        // Replace success confirmation with integration error when post-submit SWPM action fails
        add_filter('wpforms_frontend_confirmation_message', [$this, 'maybeReplaceConfirmationMessage'], 10, 3);

        // Prepopulate logged-in member values into update forms
        add_filter('wpforms_field_properties', [$this, 'maybePrepopulateFieldProperties'], 10, 3);
    }

    /**
     * Prepopulate Update Member forms for the logged-in SWPM member.
     */
    public function maybePrepopulateFieldProperties(array $properties, array $field, array $formData): array {
        $formId = (int) ($formData['id'] ?? 0);
        if ($formId <= 0) {
            return $properties;
        }

        $config = FormIntegration::getConfig($formId);
        $config['field_map'] = FormIntegration::getFieldMapWithCustomKeys($config);
        $config['options']['current_password_mapped'] = in_array('current_password', $config['field_map'] ?? [], true);

        if (($config['action_type'] ?? '') !== 'update_member') {
            return $properties;
        }

        if (!class_exists('SwpmMemberUtils')) {
            return $properties;
        }

        $memberId = (int) \SwpmMemberUtils::get_logged_in_members_id();
        if ($memberId <= 0) {
            return $properties;
        }

        $member = $this->swpmService->getMemberById($memberId);
        if (!$member) {
            return $properties;
        }

        if (isset($properties['inputs']) && is_array($properties['inputs'])) {
            foreach ($properties['inputs'] as $inputKey => &$input) {
                if (!is_array($input)) {
                    continue;
                }

                $mappingKey = $this->resolveInputMappingKey($field, (string) $inputKey);
                $value = $this->resolvePrepopulatedValue($config['field_map'] ?? [], $mappingKey, $member);
                if ($value === null) {
                    continue;
                }

                $input['default'] = $value;
                $input['attr']['value'] = $value;
            }
            unset($input);
        }

        $mappingKey = (string) ($field['id'] ?? '');
        $value = $this->resolvePrepopulatedValue($config['field_map'] ?? [], $mappingKey, $member);
        if ($value !== null) {
            $properties['inputs']['primary']['default'] = $value;
            $properties['inputs']['primary']['attr']['value'] = $value;
        }

        return $properties;
    }
    
    /**
     * Handle form submission.
     */
    public function handleSubmission(array $fields, array $entry, array $formData, int $entryId): void {
        // Check if global integration is enabled
        $settings = SettingsPage::getSettings();
        if (empty($settings['enabled'])) {
            return;
        }
        
        // Get form integration config
        $config = FormIntegration::getConfig((int) $formData['id']);
        $config['field_map'] = FormIntegration::getFieldMapWithCustomKeys($config);
        $config['options']['current_password_mapped'] = in_array('current_password', $config['field_map'] ?? [], true);
        if (empty($config['enabled'])) {
            return;
        }
        
        $this->logger->debug('Processing submission', [
            'form_id' => $formData['id'],
            'entry_id' => $entryId,
            'action_type' => $config['action_type'] ?? 'unknown',
        ]);
        
        try {
            // Build MemberDTO from form fields
            $dto = $this->buildMemberDTO($fields, $config, $settings);
            
            /**
             * Filter the member DTO before action.
             * 
             * @param MemberDTO $dto The member data.
             * @param array $fields Form field data.
             * @param array $config Integration config.
             */
            $dto = apply_filters('swpm_wpforms_member_dto', $dto, $fields, $config);
            
            /**
             * Action before SWPM action is executed.
             * 
             * @param string $actionType The action being performed.
             * @param MemberDTO $dto The member data.
             * @param array $config Integration config.
             */
            do_action('swpm_wpforms_before_action', $config['action_type'], $dto, $config);
            
            // Route to appropriate action
            $result = $this->actionRouter->route($dto, $config);
            
            if (!$result['success']) {
                $this->logger->error('SWPM action failed', [
                    'form_id' => $formData['id'],
                    'entry_id' => $entryId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                
                // Store failure state to block standard notifications
                $this->storeIntegrationResult((int) $formData['id'], false);
                
                // Send admin failure notification if enabled
                if (!empty($config['options']['notify_admin_on_failure'])) {
                    $this->sendAdminFailureNotification($formData, $fields, $result['error'] ?? 'Unknown error');
                }
                
                // Store error for display
                $this->storeError((int) $formData['id'], $result['error'] ?? __('Membership action failed', 'wpforms-swpm-bridge'));
                return;
            }
            
            // Store success state to allow standard notifications
            $this->storeIntegrationResult((int) $formData['id'], true);
            
            $this->logger->info('SWPM action completed', [
                'form_id' => $formData['id'],
                'entry_id' => $entryId,
                'action_type' => $config['action_type'],
                'member_id' => $result['member_id'] ?? null,
            ]);
            
            // Handle auto-login for registration
            if ($config['action_type'] === 'register_member' && 
                !empty($config['options']['auto_login']) && 
                !empty($result['member_id'])) {
                $this->performAutoLogin($result['member_id'], $dto);
            }
            
            // Handle redirect override
            if (!empty($config['options']['redirect_url'])) {
                add_filter('wpforms_process_redirect_url', function() use ($config) {
                    return esc_url($config['options']['redirect_url']);
                });
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception in submission handler', [
                'form_id' => $formData['id'],
                'entry_id' => $entryId,
                'error' => $e->getMessage(),
            ]);
            
            $message = trim($e->getMessage());
            if ($message === '') {
                $message = __('An error occurred processing your membership.', 'wpforms-swpm-bridge');
            }

            $this->storeError((int) $formData['id'], $message);
        }
    }
    
    /**
     * Add validation errors during form processing.
     */
    public function addValidationErrors(array $errors, array $formData): array {
        $config = FormIntegration::getConfig((int) $formData['id']);
        $config['field_map'] = FormIntegration::getFieldMapWithCustomKeys($config);
        $config['options']['current_password_mapped'] = in_array('current_password', $config['field_map'] ?? [], true);
        if (empty($config['enabled'])) {
            return $errors;
        }
        
        // Get submitted fields from $_POST
        $fields = $_POST['wpforms']['fields'] ?? [];
        if (empty($fields)) {
            return $errors;
        }
        
        // Build DTO for validation
        $settings = SettingsPage::getSettings();
        $dto = $this->buildMemberDTOFromPost($fields, $config, $settings);
        
        // Validate
        $validationResult = $this->validator->validate($dto, $config['action_type'], $config['options'] ?? []);
        
        if (!$validationResult['valid']) {
            foreach ($validationResult['errors'] as $field => $message) {
                // Find the WPForms field ID that maps to this SWPM field
                $wpformsFieldId = $this->findFieldIdByMapping($config['field_map'] ?? [], $field);
                
                if ($wpformsFieldId !== null) {
                    $errors[$formData['id']][$wpformsFieldId] = $message;
                } else {
                    // Generic form error
                    $errors[$formData['id']]['header'] = $message;
                }
            }
        }
        
        // Check for duplicates if registering
        if ($config['action_type'] === 'register_member') {
            $duplicateResult = $this->duplicateChecker->check($dto);
            $handleResult = $this->duplicateChecker->handleDuplicate(
                $duplicateResult, 
                $config['options']['on_duplicate'] ?? 'reject'
            );
            
            if ($handleResult['action'] === 'reject' && !empty($handleResult['error'])) {
                $errors[$formData['id']]['header'] = $handleResult['error'];
            }
        }
        
        return $errors;
    }
    
    /**
     * Build MemberDTO from processed form fields.
     */
    private function buildMemberDTO(array $fields, array $config, array $settings): MemberDTO {
        $fieldMap = $config['field_map'] ?? [];
        $data = [];
        
        foreach ($fieldMap as $mappingKey => $swpmField) {
            if (empty($swpmField)) {
                continue;
            }
            
            // Handle split name fields (e.g., "1_first", "1_last")
            if (preg_match('/^(\d+)_(first|last)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if (isset($fields[$fieldId][$subField])) {
                    $data[$swpmField] = $fields[$fieldId][$subField];
                }
                continue;
            }
            
            // Handle split address fields (e.g., "1_address1", "1_city", "1_state", "1_postal", "1_country")
            if (preg_match('/^(\d+)_(street|city|state|postal|country)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if ($subField === 'street') {
                    // Combine address1 and address2
                    $address1 = $fields[$fieldId]['address1'] ?? '';
                    $address2 = $fields[$fieldId]['address2'] ?? '';
                    $combined = trim($address1);
                    if (!empty($address2)) {
                        $combined .= ', ' . trim($address2);
                    }
                    $data[$swpmField] = $combined;
                } elseif (isset($fields[$fieldId][$subField])) {
                    $data[$swpmField] = $fields[$fieldId][$subField];
                }
                continue;
            }
            
            // Regular field
            if (!isset($fields[$mappingKey])) {
                continue;
            }
            
            $field = $fields[$mappingKey];
            
            // Handle file upload fields (for profile picture)
            if (($field['type'] ?? '') === 'file-upload' && $swpmField === 'wp_avatar') {
                $fileValue = $field['value'] ?? '';
                $fileUrl = '';

                if (is_string($fileValue)) {
                    $decoded = json_decode($fileValue, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (is_array($decoded) && isset($decoded[0]['url'])) {
                            $fileUrl = (string) $decoded[0]['url'];
                        } elseif (is_array($decoded) && isset($decoded['url'])) {
                            $fileUrl = (string) $decoded['url'];
                        }
                    } else {
                        $fileUrl = $fileValue;
                    }
                } elseif (is_array($fileValue)) {
                    if (isset($fileValue[0]['url'])) {
                        $fileUrl = (string) $fileValue[0]['url'];
                    } elseif (isset($fileValue['url'])) {
                        $fileUrl = (string) $fileValue['url'];
                    }
                }

                if ($fileUrl !== '') {
                    $data[$swpmField] = $fileUrl;
                }
                continue;
            }
            
            $rawValue = $field['value'] ?? '';
            if (($field['type'] ?? '') === 'password' && is_array($rawValue)) {
                $rawValue = $rawValue['primary'] ?? '';
            }

            $data[$swpmField] = $this->normalizeMappedFieldValue($rawValue, $swpmField);
        }
        
        // Use fixed membership level if set, otherwise from field map
        if (!empty($config['membership_level'])) {
            $data['membership_level'] = $config['membership_level'];
        } elseif (empty($data['membership_level']) && !empty($settings['default_membership_level'])) {
            $data['membership_level'] = $settings['default_membership_level'];
        }
        
        // Handle password auto-generation
        if (($config['options']['password_mode'] ?? 'require_field') === 'auto_generate' && empty($data['password'])) {
            $data['password'] = $this->passwordService->generate();
            
            // Send password email
            $this->passwordService->sendPasswordEmail($data['email'] ?? '', $data['password'], [
                'username' => $data['username'] ?? '',
            ]);
        }
        
        return MemberDTO::fromArray($data);
    }
    
    /**
     * Build MemberDTO from POST data (for validation).
     */
    private function buildMemberDTOFromPost(array $postFields, array $config, array $settings): MemberDTO {
        $fieldMap = $config['field_map'] ?? [];
        $data = [];
        
        foreach ($fieldMap as $mappingKey => $swpmField) {
            if (empty($swpmField)) {
                continue;
            }
            
            // Handle split name fields (e.g., "1_first", "1_last")
            if (preg_match('/^(\d+)_(first|last)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if (isset($postFields[$fieldId][$subField])) {
                    $data[$swpmField] = $postFields[$fieldId][$subField];
                }
                continue;
            }
            
            // Handle split address fields
            if (preg_match('/^(\d+)_(street|city|state|postal|country)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if ($subField === 'street') {
                    // Combine address1 and address2
                    $address1 = $postFields[$fieldId]['address1'] ?? '';
                    $address2 = $postFields[$fieldId]['address2'] ?? '';
                    $combined = trim($address1);
                    if (!empty($address2)) {
                        $combined .= ', ' . trim($address2);
                    }
                    $data[$swpmField] = $combined;
                } elseif (isset($postFields[$fieldId][$subField])) {
                    $data[$swpmField] = $postFields[$fieldId][$subField];
                }
                continue;
            }
            
            // Regular field
            if (!isset($postFields[$mappingKey])) {
                continue;
            }
            
            $rawValue = $postFields[$mappingKey];
            if (is_array($rawValue) && isset($rawValue['primary'])) {
                $rawValue = $rawValue['primary'];
            }

            $data[$swpmField] = $this->normalizeMappedFieldValue($rawValue, $swpmField);
        }

        $this->logger->debug('Built DTO data from POST', [
            'field_map' => $fieldMap,
            'current_password_present' => array_key_exists('current_password', $data),
            'current_password_length' => isset($data['current_password']) ? strlen((string) $data['current_password']) : 0,
            'password_present' => array_key_exists('password', $data),
            'password_length' => isset($data['password']) ? strlen((string) $data['password']) : 0,
        ]);
        
        // Membership level handling
        if (!empty($config['membership_level'])) {
            $data['membership_level'] = $config['membership_level'];
        } elseif (empty($data['membership_level']) && !empty($settings['default_membership_level'])) {
            $data['membership_level'] = $settings['default_membership_level'];
        }
        
        return MemberDTO::fromArray($data);
    }

    /**
     * Resolve prepopulated field value from member data.
     */
    private function resolvePrepopulatedValue(array $fieldMap, string $mappingKey, array $member): ?string {
        $swpmField = $fieldMap[$mappingKey] ?? null;
        if (!$swpmField || in_array($swpmField, ['password', 'current_password'], true)) {
            return null;
        }

        $columnMap = [
            'email' => 'email',
            'username' => 'user_name',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'membership_level' => 'membership_level',
            'phone' => 'phone',
            'address_street' => 'address_street',
            'address_city' => 'address_city',
            'address_state' => 'address_state',
            'address_zipcode' => 'address_zipcode',
            'country' => 'country',
            'company' => 'company_name',
            'gender' => 'gender',
        ];

        if (isset($columnMap[$swpmField])) {
            return isset($member[$columnMap[$swpmField]]) ? (string) $member[$columnMap[$swpmField]] : null;
        }

        if (str_starts_with($swpmField, 'custom_') || str_starts_with($swpmField, 'swpm_')) {
            global $wpdb;
            $metaKey = str_starts_with($swpmField, 'custom_') ? substr($swpmField, 7) : substr($swpmField, 5);

            return $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}swpm_members_meta WHERE member_id = %d AND meta_key = %s LIMIT 1",
                (int) ($member['member_id'] ?? 0),
                $metaKey
            )) ?: null;
        }

        if (str_starts_with($swpmField, 'wp_')) {
            $wpUser = null;
            if (!empty($member['user_name'])) {
                $wpUser = get_user_by('login', (string) $member['user_name']);
            }
            if (!$wpUser instanceof \WP_User && !empty($member['email'])) {
                $wpUser = get_user_by('email', (string) $member['email']);
            }
            if (!$wpUser instanceof \WP_User) {
                return null;
            }

            return match ($swpmField) {
                'wp_display_name' => (string) $wpUser->display_name,
                'wp_nickname' => (string) $wpUser->nickname,
                'wp_description' => (string) $wpUser->description,
                'wp_user_url' => (string) $wpUser->user_url,
                default => null,
            };
        }

        return isset($member[$swpmField]) ? (string) $member[$swpmField] : null;
    }

    private function resolveInputMappingKey(array $field, string $inputKey): string {
        $fieldId = (string) ($field['id'] ?? '');

        if (($field['type'] ?? '') === 'name') {
            return match ($inputKey) {
                'first' => $fieldId . '_first',
                'last' => $fieldId . '_last',
                default => $fieldId,
            };
        }

        if (($field['type'] ?? '') === 'address') {
            return match ($inputKey) {
                'address1', 'address2' => $fieldId . '_street',
                'city' => $fieldId . '_city',
                'state' => $fieldId . '_state',
                'postal' => $fieldId . '_postal',
                'country' => $fieldId . '_country',
                default => $fieldId,
            };
        }

        return $fieldId;
    }

    /**
     * Normalize mapped field values from WPForms processed or raw POST payloads.
     */
    private function normalizeMappedFieldValue($value, string $swpmField = ''): string {
        if (!is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        if (array_key_exists('value', $value)) {
            return $this->normalizeMappedFieldValue($value['value'], $swpmField);
        }

        if (
            in_array($swpmField, ['email', 'password'], true)
            && array_key_exists('primary', $value)
        ) {
            return $this->normalizeMappedFieldValue($value['primary'], $swpmField);
        }

        $normalized = [];

        array_walk_recursive($value, static function ($item) use (&$normalized): void {
            if (is_scalar($item) && $item !== '') {
                $normalized[] = (string) $item;
            }
        });

        return implode(', ', $normalized);
    }
    
    /**
     * Find WPForms field ID by SWPM field mapping.
     */
    private function findFieldIdByMapping(array $fieldMap, string $swpmField): ?int {
        foreach ($fieldMap as $wpformsId => $mappedTo) {
            if ($mappedTo === $swpmField) {
                return (int) $wpformsId;
            }
        }
        return null;
    }
    
    /**
     * Perform auto-login after registration.
     */
    private function performAutoLogin(int $memberId, MemberDTO $dto): void {
        try {
            // Get the member's WP user ID
            $member = $this->swpmService->getMemberById($memberId);
            if (!$member || empty($member['wp_user_id'])) {
                $this->logger->debug('No WP user for auto-login', ['member_id' => $memberId]);
                return;
            }
            
            $wpUserId = (int) $member['wp_user_id'];
            
            // Set auth cookie
            wp_set_auth_cookie($wpUserId, false);
            wp_set_current_user($wpUserId);
            
            // Also set SWPM session if available
            if (class_exists('SwpmAuth')) {
                $auth = \SwpmAuth::get_instance();
                $auth->login_to_swpm_using_username_password($dto->username, $dto->password);
            }
            
            $this->logger->info('Auto-login completed', [
                'member_id' => $memberId,
                'wp_user_id' => $wpUserId,
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-login failed', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Store error for display on form.
     */
    private function storeError(int $formId, string $message): void {
        // Store in transient for display
        set_transient('swpm_wpforms_error_' . $formId, $message, 60);
    }
    
    /**
     * Store integration result for notification filtering.
     */
    private function storeIntegrationResult(int $formId, bool $success): void {
        set_transient(
            'swpm_wpforms_result_' . $formId . '_' . get_current_user_id(),
            $success ? 'success' : 'failure',
            60
        );
    }
    
    /**
     * Get stored integration result.
     */
    private function getIntegrationResult(int $formId): ?bool {
        $result = get_transient('swpm_wpforms_result_' . $formId . '_' . get_current_user_id());

        if ($result === false || $result === '' || $result === null) {
            return null;
        }

        if ($result === 'failure') {
            return false;
        }

        if ($result === 'success') {
            return true;
        }

        return (bool) $result;
    }

    /**
     * Get stored integration error.
     */
    private function getStoredError(int $formId): string {
        $error = get_transient('swpm_wpforms_error_' . $formId);

        return is_string($error) ? $error : '';
    }
    
    /**
     * Filter to block WPForms notifications on integration failure.
     */
    public function maybeBlockNotifications(bool $send, array $fields, array $entry, array $formData): bool {
        // Check if this form has SWPM integration
        $config = FormIntegration::getConfig($formData);
        if (empty($config['enabled'])) {
            return $send; // Not our form, don't interfere
        }
        
        // Check integration result
        $result = $this->getIntegrationResult((int) $formData['id']);
        
        // If integration failed, block notifications
        if ($result === false) {
            $this->logger->debug('Blocking notification due to integration failure', [
                'form_id' => $formData['id'],
            ]);
            return false;
        }
        
        return $send;
    }

    /**
     * Replace WPForms success confirmation with integration failure message when needed.
     */
    public function maybeReplaceConfirmationMessage(string $message, array $formData, array $fields = []): string {
        $config = FormIntegration::getConfig((int) $formData['id']);
        if (empty($config['enabled'])) {
            return $message;
        }

        $result = $this->getIntegrationResult((int) $formData['id']);
        if ($result !== false) {
            return $message;
        }

        $error = $this->getStoredError((int) $formData['id']);
        if ($error === '') {
            $error = __('Membership action failed', 'wpforms-swpm-bridge');
        }

        return sprintf('<div class="wpforms-error-container">%s</div>', esc_html($error));
    }
    
    /**
     * Send admin notification on integration failure.
     */
    private function sendAdminFailureNotification(array $formData, array $fields, string $error): void {
        $adminEmail = get_option('admin_email');
        $siteName = get_bloginfo('name');
        $formName = $formData['settings']['form_title'] ?? 'Unknown Form';
        
        $subject = sprintf(
            /* translators: %1$s: site name, %2$s: form name */
            __('[%1$s] SWPM Integration Failed: %2$s', 'wpforms-swpm-bridge'),
            $siteName,
            $formName
        );
        
        // Build field summary
        $fieldSummary = '';
        foreach ($fields as $field) {
            $label = $field['name'] ?? $field['id'];
            $value = $field['value'] ?? '';
            if (!empty($value)) {
                $fieldSummary .= sprintf("%s: %s\n", $label, $value);
            }
        }
        
        $message = sprintf(
            /* translators: %1$s: form name, %2$s: error message, %3$s: field summary, %4$s: timestamp */
            __("SWPM integration failed for form submission.\n\nForm: %1\$s\nError: %2\$s\n\nSubmitted Data:\n%3\$s\nTime: %4\$s", 'wpforms-swpm-bridge'),
            $formName,
            $error,
            $fieldSummary,
            current_time('mysql')
        );
        
        wp_mail($adminEmail, $subject, $message);
        
        $this->logger->info('Admin failure notification sent', [
            'form_id' => $formData['id'],
            'admin_email' => $adminEmail,
        ]);
    }
}