<?php

declare(strict_types=1);

namespace SWPMWPForms\Services;

use SWPMWPForms\DTO\MemberDTO;

/**
 * SWPM operations facade.
 * Wraps Simple WordPress Membership internal functions.
 */
class SwpmService {
    
    private static ?SwpmService $instance = null;
    private Logger $logger;
    
    public static function instance(): SwpmService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->logger = Logger::instance();
    }
    
    /**
     * Register a new member.
     * 
     * @return array{success: bool, member_id?: int, error?: string}
     */
    public function registerMember(MemberDTO $dto): array {
        try {
            // Check if SwpmMemberUtils exists
            if (!class_exists('SwpmMemberUtils')) {
                return ['success' => false, 'error' => 'SWPM not available'];
            }
            
            // Check for existing member
            if ($this->getMemberByEmail($dto->email)) {
                return ['success' => false, 'error' => 'A member with this email already exists'];
            }
            
            if ($this->getMemberByUsername($dto->username)) {
                return ['success' => false, 'error' => 'A member with this username already exists'];
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'swpm_members_tbl';
            
            // Prepare member data
            $member_data = [
                'user_name' => sanitize_user($dto->username),
                'email' => sanitize_email($dto->email),
                'password' => \SwpmUtils::encrypt_password($dto->password),
                'membership_level' => (int) $dto->membershipLevel,
                'first_name' => sanitize_text_field($dto->firstName ?? ''),
                'last_name' => sanitize_text_field($dto->lastName ?? ''),
                'member_since' => current_time('mysql'),
                'subscription_starts' => current_time('mysql'),
                'account_state' => 'active',
            ];
            
            // Add extended SWPM fields if set
            if ($dto->phone !== null) {
                $member_data['phone'] = sanitize_text_field($dto->phone);
            }
            if ($dto->addressStreet !== null) {
                $member_data['address_street'] = sanitize_text_field($dto->addressStreet);
            }
            if ($dto->addressCity !== null) {
                $member_data['address_city'] = sanitize_text_field($dto->addressCity);
            }
            if ($dto->addressState !== null) {
                $member_data['address_state'] = sanitize_text_field($dto->addressState);
            }
            if ($dto->addressZipcode !== null) {
                $member_data['address_zipcode'] = sanitize_text_field($dto->addressZipcode);
            }
            if ($dto->country !== null) {
                $member_data['country'] = sanitize_text_field($dto->country);
            }
            if ($dto->company !== null) {
                $member_data['company_name'] = sanitize_text_field($dto->company);
            }
            if ($dto->gender !== null) {
                $member_data['gender'] = sanitize_text_field($dto->gender);
            }
            
            // Calculate subscription end date based on level
            $level = \SwpmMembershipLevelUtils::get_level_info($dto->membershipLevel);
            if ($level) {
                $duration = $level->subscription_duration_type;
                if ($duration == \SwpmMembershipLevel::FIXED_DATE) {
                    $member_data['subscription_starts'] = $level->subscription_period;
                } elseif ($duration == \SwpmMembershipLevel::DAYS) {
                    $days = $level->subscription_period;
                    $member_data['subscription_starts'] = gmdate('Y-m-d', strtotime("+{$days} days"));
                }
            }
            
            $result = $wpdb->insert($table, $member_data);
            
            if ($result === false) {
                $this->logger->error('Failed to insert member', [
                    'email' => $dto->email,
                    'error' => $wpdb->last_error,
                ]);
                return ['success' => false, 'error' => 'Database error creating member'];
            }
            
            $member_id = $wpdb->insert_id;
            
            // Create WP user if settings require it
            $this->maybeCreateWpUser($dto, $member_id);
            
            // Save custom meta
            if (!empty($dto->customMeta)) {
                $this->saveCustomMeta($member_id, $dto->customMeta);
            }
            
            $this->logger->info('Member registered', [
                'member_id' => $member_id,
                'email' => $dto->email,
            ]);
            
            /**
             * Fires after a member is registered via WPForms.
             * 
             * @param int $member_id The new member ID.
             * @param MemberDTO $dto The member data.
             */
            do_action('swpm_wpforms_after_action', 'register_member', $member_id, $dto);
            
            return ['success' => true, 'member_id' => $member_id];
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception registering member', [
                'email' => $dto->email,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update an existing member.
     * 
     * @return array{success: bool, error?: string}
     */
    public function updateMember(int $memberId, MemberDTO $dto): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'swpm_members_tbl';
            
            $update_data = [];
            
            if (!empty($dto->email)) {
                $update_data['email'] = sanitize_email($dto->email);
            }
            if (!empty($dto->firstName)) {
                $update_data['first_name'] = sanitize_text_field($dto->firstName);
            }
            if (!empty($dto->lastName)) {
                $update_data['last_name'] = sanitize_text_field($dto->lastName);
            }
            if ($dto->hasPassword()) {
                $update_data['password'] = \SwpmUtils::encrypt_password($dto->password);
            }
            
            // Extended SWPM fields
            if ($dto->phone !== null) {
                $update_data['phone'] = sanitize_text_field($dto->phone);
            }
            if ($dto->addressStreet !== null) {
                $update_data['address_street'] = sanitize_text_field($dto->addressStreet);
            }
            if ($dto->addressCity !== null) {
                $update_data['address_city'] = sanitize_text_field($dto->addressCity);
            }
            if ($dto->addressState !== null) {
                $update_data['address_state'] = sanitize_text_field($dto->addressState);
            }
            if ($dto->addressZipcode !== null) {
                $update_data['address_zipcode'] = sanitize_text_field($dto->addressZipcode);
            }
            if ($dto->country !== null) {
                $update_data['country'] = sanitize_text_field($dto->country);
            }
            if ($dto->company !== null) {
                $update_data['company_name'] = sanitize_text_field($dto->company);
            }
            if ($dto->gender !== null) {
                $update_data['gender'] = sanitize_text_field($dto->gender);
            }
            
            if (empty($update_data)) {
                // Check if there's custom meta or WP fields to update
                if (empty($dto->customMeta) && empty($dto->getWpUserFields())) {
                    return ['success' => true]; // Nothing to update
                }
            }
            
            $result = $wpdb->update($table, $update_data, ['member_id' => $memberId]);
            
            if ($result === false) {
                $this->logger->error('Failed to update member', [
                    'member_id' => $memberId,
                    'error' => $wpdb->last_error,
                ]);
                return ['success' => false, 'error' => 'Database error updating member'];
            }
            
            // Update custom meta
            if (!empty($dto->customMeta)) {
                $this->saveCustomMeta($memberId, $dto->customMeta);
            }
            
            // Update WP user fields if member has WP user
            $this->maybeUpdateWpUser($memberId, $dto);
            
            $this->logger->info('Member updated', ['member_id' => $memberId]);
            
            do_action('swpm_wpforms_after_action', 'update_member', $memberId, $dto);
            
            return ['success' => true];
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception updating member', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Change a member's membership level.
     * 
     * @return array{success: bool, error?: string}
     */
    public function changeLevel(int $memberId, int|string $newLevel): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'swpm_members_tbl';
            
            // Verify level exists
            $level = \SwpmMembershipLevelUtils::get_level_info($newLevel);
            if (!$level) {
                return ['success' => false, 'error' => 'Invalid membership level'];
            }
            
            $update_data = [
                'membership_level' => (int) $newLevel,
            ];
            
            // Recalculate subscription dates
            if ($level->subscription_duration_type == \SwpmMembershipLevel::FIXED_DATE) {
                $update_data['subscription_starts'] = $level->subscription_period;
            } elseif ($level->subscription_duration_type == \SwpmMembershipLevel::DAYS) {
                $days = $level->subscription_period;
                $update_data['subscription_starts'] = gmdate('Y-m-d', strtotime("+{$days} days"));
            }
            
            $result = $wpdb->update($table, $update_data, ['member_id' => $memberId]);
            
            if ($result === false) {
                return ['success' => false, 'error' => 'Database error changing level'];
            }
            
            $this->logger->info('Member level changed', [
                'member_id' => $memberId,
                'new_level' => $newLevel,
            ]);
            
            do_action('swpm_wpforms_after_action', 'change_level', $memberId, $newLevel);
            
            return ['success' => true];
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception changing level', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get member by email.
     */
    public function getMemberByEmail(string $email): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'swpm_members_tbl';
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $email),
            ARRAY_A
        );
        
        return $result ?: null;
    }
    
    /**
     * Get member by username.
     */
    public function getMemberByUsername(string $username): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'swpm_members_tbl';
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_name = %s", $username),
            ARRAY_A
        );
        
        return $result ?: null;
    }
    
    /**
     * Get member by ID.
     */
    public function getMemberById(int $memberId): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'swpm_members_tbl';
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE member_id = %d", $memberId),
            ARRAY_A
        );
        
        return $result ?: null;
    }
    
    /**
     * Get all membership levels.
     */
    public function getMembershipLevels(): array {
        if (!class_exists('SwpmMembershipLevelUtils')) {
            return [];
        }
        
        return \SwpmMembershipLevelUtils::get_all_membership_levels_in_array() ?: [];
    }
    
    /**
     * Create WordPress user if SWPM settings require it.
     */
    private function maybeCreateWpUser(MemberDTO $dto, int $memberId): void {
        // Check SWPM settings for auto WP user creation
        $settings = \SwpmSettings::get_instance();
        if (!$settings->get_value('enable-auto-create-swpm-use-wp-user')) {
            return;
        }
        
        // Create WP user
        $wp_user_id = wp_create_user(
            $dto->username,
            $dto->password,
            $dto->email
        );
        
        if (is_wp_error($wp_user_id)) {
            $this->logger->warning('Failed to create WP user', [
                'member_id' => $memberId,
                'error' => $wp_user_id->get_error_message(),
            ]);
            return;
        }
        
        // Update member with WP user ID
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'swpm_members_tbl',
            ['wp_user_id' => $wp_user_id],
            ['member_id' => $memberId]
        );
        
        // Update WP user profile
        $wpUserData = [
            'ID' => $wp_user_id,
            'first_name' => $dto->firstName,
            'last_name' => $dto->lastName,
            'display_name' => $dto->wpDisplayName ?? $dto->getDisplayName(),
        ];
        
        // Add additional WP user fields
        if ($dto->wpNickname !== null) {
            $wpUserData['nickname'] = $dto->wpNickname;
        }
        if ($dto->wpDescription !== null) {
            $wpUserData['description'] = $dto->wpDescription;
        }
        if ($dto->wpUserUrl !== null) {
            $wpUserData['user_url'] = $dto->wpUserUrl;
        }
        
        wp_update_user($wpUserData);
        
        // Set profile picture if provided
        if ($dto->wpAvatar !== null) {
            $this->setUserAvatar($wp_user_id, $dto->wpAvatar);
        }
    }
    
    /**
     * Update WordPress user fields for existing member.
     */
    private function maybeUpdateWpUser(int $memberId, MemberDTO $dto): void {
        $wpFields = $dto->getWpUserFields();
        if (empty($wpFields) && $dto->firstName === null && $dto->lastName === null && $dto->wpAvatar === null) {
            return;
        }
        
        // Get the member's WP user ID
        $member = $this->getMemberById($memberId);
        if (!$member || empty($member['wp_user_id'])) {
            return;
        }
        
        $wpUserId = (int) $member['wp_user_id'];
        
        $wpUserData = ['ID' => $wpUserId];
        
        if ($dto->firstName !== null) {
            $wpUserData['first_name'] = $dto->firstName;
        }
        if ($dto->lastName !== null) {
            $wpUserData['last_name'] = $dto->lastName;
        }
        if ($dto->wpDisplayName !== null) {
            $wpUserData['display_name'] = $dto->wpDisplayName;
        }
        if ($dto->wpNickname !== null) {
            $wpUserData['nickname'] = $dto->wpNickname;
        }
        if ($dto->wpDescription !== null) {
            $wpUserData['description'] = $dto->wpDescription;
        }
        if ($dto->wpUserUrl !== null) {
            $wpUserData['user_url'] = $dto->wpUserUrl;
        }
        
        if (count($wpUserData) > 1) {
            wp_update_user($wpUserData);
        }
        
        // Set profile picture if provided
        if ($dto->wpAvatar !== null) {
            $this->setUserAvatar($wpUserId, $dto->wpAvatar);
        }
    }
    
    /**
     * Set user avatar/profile picture.
     */
    private function setUserAvatar(int $wpUserId, string|int $avatar): void {
        $avatarUrl = (string) $avatar;
        
        // Store avatar URL in user meta
        update_user_meta($wpUserId, 'swpm_wpforms_avatar', $avatarUrl);
        
        // Also try attachment ID for plugin compatibility
        $attachmentId = attachment_url_to_postid($avatarUrl);
        if ($attachmentId) {
            update_user_meta($wpUserId, 'wp_user_avatar', $attachmentId);
        }
        
        $this->logger->debug('User avatar set', [
            'wp_user_id' => $wpUserId,
            'avatar_url' => $avatarUrl,
        ]);
    }
    
    /**
     * Save custom meta fields for a member.
     */
    private function saveCustomMeta(int $memberId, array $meta): void {
        if (!class_exists('SwpmMemberUtils')) {
            return;
        }
        
        foreach ($meta as $key => $value) {
            \SwpmMemberUtils::update_member_field($memberId, $key, $value);
        }
    }
}