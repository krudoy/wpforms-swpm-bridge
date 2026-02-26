<?php

declare(strict_types=1);

namespace SWPMWPForms\Validators;

use SWPMWPForms\DTO\MemberDTO;

/**
 * Validates member data based on action type.
 */
class MemberValidator {
    
    public const ACTION_REGISTER = 'register_member';
    public const ACTION_UPDATE = 'update_member';
    public const ACTION_CHANGE_LEVEL = 'change_level';
    
    /**
     * Required fields per action type.
     */
    private const REQUIRED_FIELDS = [
        self::ACTION_REGISTER => ['email', 'username', 'membershipLevel'],
        self::ACTION_UPDATE => [], // At least one identifier required
        self::ACTION_CHANGE_LEVEL => ['membershipLevel'], // Plus identifier
    ];
    
    /**
     * Validate member DTO for a specific action.
     * 
     * @return array{valid: bool, errors: array<string, string>}
     */
    public function validate(MemberDTO $dto, string $actionType, array $options = []): array {
        $errors = [];
        
        // Action-specific validation
        switch ($actionType) {
            case self::ACTION_REGISTER:
                $errors = $this->validateRegister($dto, $options);
                break;
                
            case self::ACTION_UPDATE:
                $errors = $this->validateUpdate($dto, $options);
                break;
                
            case self::ACTION_CHANGE_LEVEL:
                $errors = $this->validateChangeLevel($dto, $options);
                break;
                
            default:
                $errors['action_type'] = __('Invalid action type', 'wpforms-swpm-bridge');
        }
        
        /**
         * Filter validation errors.
         * 
         * @param array $errors Current validation errors.
         * @param MemberDTO $dto The member data being validated.
         * @param string $actionType The action type.
         * @param array $options Validation options.
         */
        $errors = apply_filters('swpm_wpforms_validation_errors', $errors, $dto, $actionType, $options);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Validate for registration.
     */
    private function validateRegister(MemberDTO $dto, array $options): array {
        $errors = [];
        
        // Required fields
        if (empty($dto->email)) {
            $errors['email'] = __('Email is required', 'wpforms-swpm-bridge');
        } elseif (!is_email($dto->email)) {
            $errors['email'] = __('Invalid email address', 'wpforms-swpm-bridge');
        }
        
        if (empty($dto->username)) {
            $errors['username'] = __('Username is required', 'wpforms-swpm-bridge');
        } elseif (!validate_username($dto->username)) {
            $errors['username'] = __('Invalid username', 'wpforms-swpm-bridge');
        }
        
        // Password validation based on mode
        $passwordMode = $options['password_mode'] ?? 'require_field';
        if ($passwordMode === 'require_field' && !$dto->hasPassword()) {
            $errors['password'] = __('Password is required', 'wpforms-swpm-bridge');
        }
        
        // Membership level
        if (empty($dto->membershipLevel)) {
            $errors['membership_level'] = __('Membership level is required', 'wpforms-swpm-bridge');
        } elseif (!$this->isValidMembershipLevel($dto->membershipLevel)) {
            $errors['membership_level'] = __('Invalid membership level', 'wpforms-swpm-bridge');
        }
        
        return $errors;
    }
    
    /**
     * Validate for update.
     */
    private function validateUpdate(MemberDTO $dto, array $options): array {
        $errors = [];
        
        // Need at least one identifier
        if (empty($dto->email) && empty($dto->username)) {
            $errors['identifier'] = __('Email or username is required to identify the member', 'wpforms-swpm-bridge');
        }
        
        // Validate email format if provided
        if (!empty($dto->email) && !is_email($dto->email)) {
            $errors['email'] = __('Invalid email address', 'wpforms-swpm-bridge');
        }
        
        return $errors;
    }
    
    /**
     * Validate for level change.
     */
    private function validateChangeLevel(MemberDTO $dto, array $options): array {
        $errors = [];
        
        // Need at least one identifier
        if (empty($dto->email) && empty($dto->username)) {
            $errors['identifier'] = __('Email or username is required to identify the member', 'wpforms-swpm-bridge');
        }
        
        // Membership level required
        if (empty($dto->membershipLevel)) {
            $errors['membership_level'] = __('New membership level is required', 'wpforms-swpm-bridge');
        } elseif (!$this->isValidMembershipLevel($dto->membershipLevel)) {
            $errors['membership_level'] = __('Invalid membership level', 'wpforms-swpm-bridge');
        }
        
        return $errors;
    }
    
    /**
     * Check if a membership level ID is valid.
     */
    private function isValidMembershipLevel(int|string $levelId): bool {
        if (!class_exists('SwpmMembershipLevelUtils')) {
            return true; // Can't validate without SWPM
        }
        
        $level = \SwpmMembershipLevelUtils::get_level_info($levelId);
        return $level !== null;
    }
    
    /**
     * Get required fields for an action type.
     */
    public function getRequiredFields(string $actionType): array {
        return self::REQUIRED_FIELDS[$actionType] ?? [];
    }
}