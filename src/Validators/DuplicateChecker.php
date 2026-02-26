<?php

declare(strict_types=1);

namespace SWPMWPForms\Validators;

use SWPMWPForms\DTO\MemberDTO;
use SWPMWPForms\Services\SwpmService;

/**
 * Checks for existing members to handle duplicates.
 */
class DuplicateChecker {
    
    public const MODE_REJECT = 'reject';
    public const MODE_UPDATE = 'update';
    public const MODE_SKIP = 'skip';
    
    private SwpmService $swpmService;
    
    public function __construct(?SwpmService $swpmService = null) {
        $this->swpmService = $swpmService ?? SwpmService::instance();
    }
    
    /**
     * Check for duplicate member.
     * 
     * @return array{duplicate: bool, existing_member?: array, field?: string}
     */
    public function check(MemberDTO $dto): array {
        // Check by email first (primary identifier)
        if (!empty($dto->email)) {
            $existing = $this->swpmService->getMemberByEmail($dto->email);
            if ($existing) {
                return [
                    'duplicate' => true,
                    'existing_member' => $existing,
                    'field' => 'email',
                ];
            }
        }
        
        // Check by username
        if (!empty($dto->username)) {
            $existing = $this->swpmService->getMemberByUsername($dto->username);
            if ($existing) {
                return [
                    'duplicate' => true,
                    'existing_member' => $existing,
                    'field' => 'username',
                ];
            }
        }
        
        return ['duplicate' => false];
    }
    
    /**
     * Handle duplicate based on mode.
     * 
     * @return array{action: string, member_id?: int, error?: string}
     */
    public function handleDuplicate(array $checkResult, string $mode): array {
        if (!$checkResult['duplicate']) {
            return ['action' => 'create'];
        }
        
        $existingMember = $checkResult['existing_member'];
        $memberId = (int) ($existingMember['member_id'] ?? 0);
        
        switch ($mode) {
            case self::MODE_UPDATE:
                return [
                    'action' => 'update',
                    'member_id' => $memberId,
                ];
                
            case self::MODE_SKIP:
                return [
                    'action' => 'skip',
                    'member_id' => $memberId,
                ];
                
            case self::MODE_REJECT:
            default:
                $field = $checkResult['field'] ?? 'email';
                return [
                    'action' => 'reject',
                    'error' => sprintf(
                        /* translators: %s: field name (email or username) */
                        __('A member with this %s already exists.', 'wpforms-swpm-bridge'),
                        $field
                    ),
                ];
        }
    }
}