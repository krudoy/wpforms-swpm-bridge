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
            
            $data[$swpmField] = $this->normalizeMappedFieldValue($field['value'] ?? '', $swpmField);
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
            
            $data[$swpmField] = $this->normalizeMappedFieldValue($postFields[$mappingKey], $swpmField);
        }
        
        // Membership level handling
        if (!empty($config['membership_level'])) {
            $data['membership_level'] = $config['membership_level'];
        } elseif (empty($data['membership_level']) && !empty($settings['default_membership_level'])) {
            $data['membership_level'] = $settings['default_membership_level'];
        }
        
        return MemberDTO::fromArray($data);
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