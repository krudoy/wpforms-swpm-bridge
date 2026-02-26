<?php

declare(strict_types=1);

namespace SWPMWPForms\Handlers;

use SWPMWPForms\DTO\MemberDTO;
use SWPMWPForms\Services\Logger;
use SWPMWPForms\Services\SwpmService;
use SWPMWPForms\Validators\DuplicateChecker;
use SWPMWPForms\Validators\MemberValidator;

/**
 * Routes SWPM actions based on form configuration.
 */
class ActionRouter {
    
    private SwpmService $swpmService;
    private DuplicateChecker $duplicateChecker;
    private Logger $logger;
    
    public function __construct(SwpmService $swpmService) {
        $this->swpmService = $swpmService;
        $this->duplicateChecker = new DuplicateChecker($swpmService);
        $this->logger = Logger::instance();
    }
    
    /**
     * Route to appropriate action based on config.
     * 
     * @return array{success: bool, member_id?: int, error?: string}
     */
    public function route(MemberDTO $dto, array $config): array {
        $actionType = $config['action_type'] ?? 'register_member';
        $options = $config['options'] ?? [];
        
        return match ($actionType) {
            'register_member' => $this->handleRegister($dto, $options),
            'update_member' => $this->handleUpdate($dto, $options),
            'change_level' => $this->handleChangeLevel($dto, $options),
            default => ['success' => false, 'error' => 'Unknown action type: ' . $actionType],
        };
    }
    
    /**
     * Handle member registration.
     */
    private function handleRegister(MemberDTO $dto, array $options): array {
        // Check for duplicates
        $duplicateCheck = $this->duplicateChecker->check($dto);
        $handleResult = $this->duplicateChecker->handleDuplicate(
            $duplicateCheck,
            $options['on_duplicate'] ?? 'reject'
        );
        
        switch ($handleResult['action']) {
            case 'reject':
                return [
                    'success' => false,
                    'error' => $handleResult['error'] ?? __('Member already exists', 'wpforms-swpm-bridge'),
                ];
                
            case 'update':
                // Update existing member instead
                $memberId = $handleResult['member_id'];
                $result = $this->swpmService->updateMember($memberId, $dto);
                if ($result['success']) {
                    return ['success' => true, 'member_id' => $memberId];
                }
                return $result;
                
            case 'skip':
                // Skip SWPM action, just return success
                return [
                    'success' => true,
                    'member_id' => $handleResult['member_id'] ?? null,
                    'skipped' => true,
                ];
                
            case 'create':
            default:
                // Proceed with registration
                return $this->swpmService->registerMember($dto);
        }
    }
    
    /**
     * Handle member update.
     */
    private function handleUpdate(MemberDTO $dto, array $options): array {
        // Find existing member
        $member = null;
        
        if (!empty($dto->email)) {
            $member = $this->swpmService->getMemberByEmail($dto->email);
        }
        
        if (!$member && !empty($dto->username)) {
            $member = $this->swpmService->getMemberByUsername($dto->username);
        }
        
        if (!$member) {
            return [
                'success' => false,
                'error' => __('Member not found', 'wpforms-swpm-bridge'),
            ];
        }
        
        $memberId = (int) $member['member_id'];
        $result = $this->swpmService->updateMember($memberId, $dto);
        
        if ($result['success']) {
            return ['success' => true, 'member_id' => $memberId];
        }
        
        return $result;
    }
    
    /**
     * Handle membership level change.
     */
    private function handleChangeLevel(MemberDTO $dto, array $options): array {
        // Find existing member
        $member = null;
        
        if (!empty($dto->email)) {
            $member = $this->swpmService->getMemberByEmail($dto->email);
        }
        
        if (!$member && !empty($dto->username)) {
            $member = $this->swpmService->getMemberByUsername($dto->username);
        }
        
        if (!$member) {
            return [
                'success' => false,
                'error' => __('Member not found', 'wpforms-swpm-bridge'),
            ];
        }
        
        if (empty($dto->membershipLevel)) {
            return [
                'success' => false,
                'error' => __('New membership level is required', 'wpforms-swpm-bridge'),
            ];
        }
        
        $memberId = (int) $member['member_id'];
        $result = $this->swpmService->changeLevel($memberId, $dto->membershipLevel);
        
        if ($result['success']) {
            return ['success' => true, 'member_id' => $memberId];
        }
        
        return $result;
    }
}