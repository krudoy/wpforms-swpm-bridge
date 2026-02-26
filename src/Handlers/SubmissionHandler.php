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
                
                // Store error for display
                $this->storeError($formData['id'], $result['error'] ?? __('Membership action failed', 'wpforms-swpm-bridge'));
                return;
            }
            
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
            
            $this->storeError($formData['id'], __('An error occurred processing your membership.', 'wpforms-swpm-bridge'));
        }
    }
    
    /**
     * Add validation errors during form processing.
     */
    public function addValidationErrors(array $errors, array $formData): array {
        $config = FormIntegration::getConfig((int) $formData['id']);
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
            if (preg_match('/^(\d+)_(address1|address2|city|state|postal|country)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if (isset($fields[$fieldId][$subField])) {
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
                $fileUrl = $field['value'] ?? '';
                if (!empty($fileUrl)) {
                    $data[$swpmField] = $fileUrl;
                }
                continue;
            }
            
            $data[$swpmField] = $field['value'] ?? '';
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
            if (preg_match('/^(\d+)_(address1|address2|city|state|postal|country)$/', (string) $mappingKey, $matches)) {
                $fieldId = $matches[1];
                $subField = $matches[2];
                
                if (isset($postFields[$fieldId][$subField])) {
                    $data[$swpmField] = $postFields[$fieldId][$subField];
                }
                continue;
            }
            
            // Regular field
            if (!isset($postFields[$mappingKey])) {
                continue;
            }
            
            $value = $postFields[$mappingKey];
            $data[$swpmField] = is_array($value) ? ($value['value'] ?? '') : $value;
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
}