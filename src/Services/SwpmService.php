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
     * Register WordPress avatar hooks.
     */
    public function initAvatarHooks(): void {
        add_filter('pre_get_avatar_data', [$this, 'filterAvatarData'], 20, 2);
        add_filter('get_avatar_data', [$this, 'filterAvatarData'], 20, 2);
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
            if ($dto->wpUserUrl !== null) {
                $member_data['home_page'] = esc_url_raw($dto->wpUserUrl);
            }
            
            // Calculate subscription end date based on level
            $level = $this->getMembershipLevelRow((int) $dto->membershipLevel);
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
            $requiresWpUserLink = $this->requiresWpUserLink($dto);
            
            // Create WP user if settings require it
            $wpUserResult = $this->maybeCreateWpUser($dto, $member_id);
            if ($requiresWpUserLink && !$wpUserResult['success']) {
                $deleteResult = $wpdb->delete($table, ['member_id' => $member_id], ['%d']);

                if ($deleteResult === false) {
                    $this->logger->error('Failed to remove member after WP user link failure', [
                        'member_id' => $member_id,
                        'error' => $wpdb->last_error,
                    ]);
                }

                return ['success' => false, 'error' => $wpUserResult['error'] ?? 'Failed to create or link WordPress user'];
            }
            
            // Save custom meta
            if (!empty($dto->customMeta)) {
                $this->saveCustomMeta($member_id, $dto->customMeta, true);
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
            // Username is intentionally not updated during profile edits.
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
            if ($dto->wpUserUrl !== null) {
                $update_data['home_page'] = esc_url_raw($dto->wpUserUrl);
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
                $this->saveCustomMeta($memberId, $dto->customMeta, true);
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
            $level = $this->getMembershipLevelRow((int) $newLevel);
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
     * Verify a member's password.
     *
     * @param int $memberId The member ID.
     * @param string $password The password to verify.
     * @return bool True if password matches.
     */
    public function verifyMemberPassword(int $memberId, string $password): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'swpm_members_tbl';

        $storedHash = $wpdb->get_var($wpdb->prepare(
            "SELECT password FROM {$table} WHERE member_id = %d",
            $memberId
        ));

        if (!$storedHash) {
            return false;
        }

        // SwpmUtils::check_password handles the hash verification
        if (class_exists('SwpmUtils') && method_exists('SwpmUtils', 'check_password')) {
            return \SwpmUtils::check_password($password, $storedHash);
        }

        // Fallback to WordPress password check
        return wp_check_password($password, $storedHash);
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
     * Load a membership level row using installed SWPM APIs.
     */
    private function getMembershipLevelRow(int $levelId): ?object {
        if (
            !class_exists('SwpmMembershipLevelUtils')
            || !method_exists('SwpmMembershipLevelUtils', 'check_if_membership_level_exists')
            || !\SwpmMembershipLevelUtils::check_if_membership_level_exists($levelId)
        ) {
            return null;
        }

        if (!class_exists('SwpmUtils') || !method_exists('SwpmUtils', 'get_membership_level_row_by_id')) {
            return null;
        }

        $level = \SwpmUtils::get_membership_level_row_by_id($levelId);

        return is_object($level) ? $level : null;
    }
    
    /**
     * Create or link the corresponding WordPress user via native SWPM APIs.
     */
    private function maybeCreateWpUser(MemberDTO $dto, int $memberId): array {
        if (!class_exists('SwpmUtils') || !method_exists('SwpmUtils', 'create_wp_user')) {
            $this->logger->warning('SWPM create_wp_user API unavailable', [
                'member_id' => $memberId,
            ]);
            return ['success' => false, 'error' => 'SWPM create_wp_user API unavailable'];
        }

        $level = $this->getMembershipLevelRow((int) $dto->membershipLevel);

        $wpUserData = [
            'user_nicename' => implode('-', preg_split('/\s+/', trim($dto->username)) ?: [$dto->username]),
            'display_name' => $dto->wpDisplayName ?? $dto->getDisplayName(),
            'user_email' => $dto->email,
            'nickname' => $dto->wpNickname ?? $dto->username,
            'user_login' => $dto->username,
            'password' => $dto->password,
            'role' => is_object($level) && isset($level->role) ? (string) $level->role : '',
            'user_registered' => current_time('mysql'),
        ];

        if ($dto->firstName !== null) {
            $wpUserData['first_name'] = $dto->firstName;
        }

        if ($dto->lastName !== null) {
            $wpUserData['last_name'] = $dto->lastName;
        }

        if ($dto->wpDescription !== null) {
            $wpUserData['description'] = $dto->wpDescription;
        }

        if ($dto->wpUserUrl !== null) {
            $wpUserData['user_url'] = $dto->wpUserUrl;
        }

        $wp_user_id = \SwpmUtils::create_wp_user($wpUserData);

        if (is_wp_error($wp_user_id) || !is_numeric($wp_user_id) || (int) $wp_user_id <= 0) {
            $error = is_wp_error($wp_user_id) ? $wp_user_id->get_error_message() : 'Invalid WP user ID returned';
            $this->logger->warning('Failed to create or link WP user', [
                'member_id' => $memberId,
                'error' => $error,
            ]);
            return ['success' => false, 'error' => $error];
        }

        if ($dto->wpAvatar !== null) {
            $this->setUserAvatar((int) $wp_user_id, $dto->wpAvatar);
            $this->saveMemberProfileImage($memberId, $dto->wpAvatar);
        }

        return ['success' => true, 'wp_user_id' => (int) $wp_user_id];
    }

    /**
     * Determine whether registration requires a linked WordPress user.
     */
    private function requiresWpUserLink(MemberDTO $dto): bool {
        return $dto->wpDisplayName !== null
            || $dto->wpNickname !== null
            || $dto->wpDescription !== null
            || $dto->wpUserUrl !== null
            || $dto->wpAvatar !== null;
    }
    
    /**
     * Update WordPress user fields for existing member.
     */
    private function maybeUpdateWpUser(int $memberId, MemberDTO $dto): void {
        $wpFields = $dto->getWpUserFields();
        if (empty($wpFields) && $dto->firstName === null && $dto->lastName === null && $dto->wpAvatar === null && !$dto->hasPassword()) {
            return;
        }
        
        $member = $this->getMemberById($memberId);
        if (!$member) {
            return;
        }

        $wpUser = null;

        if (!empty($member['user_name'])) {
            $wpUser = get_user_by('login', (string) $member['user_name']);
        }

        if (!$wpUser instanceof \WP_User && !empty($member['email'])) {
            $wpUser = get_user_by('email', (string) $member['email']);
        }

        if (!$wpUser instanceof \WP_User) {
            return;
        }

        $wpUserId = (int) $wpUser->ID;
        
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

        if ($dto->hasPassword()) {
            wp_set_password($dto->password, $wpUserId);
        }
        
        // Set profile picture if provided
        if ($dto->wpAvatar !== null) {
            $this->setUserAvatar($wpUserId, $dto->wpAvatar);
            $this->saveMemberProfileImage($memberId, $dto->wpAvatar);
        }
    }

    /**
     * Persist avatar URL to the SWPM member profile image field.
     */
    private function saveMemberProfileImage(int $memberId, string|int $avatar): void {
        $avatarUrl = is_numeric($avatar)
            ? (string) wp_get_attachment_url((int) $avatar)
            : (string) $avatar;

        if ($avatarUrl === '') {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'swpm_members_tbl',
            ['profile_image' => esc_url_raw($avatarUrl)],
            ['member_id' => $memberId],
            ['%s'],
            ['%d']
        );
    }
    
    /**
     * Set user avatar/profile picture.
     */
    private function setUserAvatar(int $wpUserId, string|int $avatar): void {
        $attachmentId = is_numeric($avatar) ? (int) $avatar : 0;
        $avatarUrl = '';

        if ($attachmentId > 0) {
            $avatarUrl = (string) wp_get_attachment_url($attachmentId);
        } else {
            $avatarUrl = (string) $avatar;
            $attachmentId = attachment_url_to_postid($avatarUrl);

            if ($attachmentId <= 0 && !empty($avatarUrl)) {
                if (!function_exists('download_url')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if (!function_exists('media_handle_sideload')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                }
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                $tempFile = null;
                $uploads = wp_upload_dir();

                if (!empty($uploads['baseurl']) && !empty($uploads['basedir']) && str_starts_with($avatarUrl, $uploads['baseurl'])) {
                    $localFile = wp_normalize_path(str_replace($uploads['baseurl'], $uploads['basedir'], $avatarUrl));

                    if (is_file($localFile) && is_readable($localFile)) {
                        $tempFile = wp_tempnam(wp_basename($localFile));

                        if ($tempFile && !@copy($localFile, $tempFile)) {
                            @unlink($tempFile);
                            $tempFile = null;
                        }
                    }
                }

                if ($tempFile === null) {
                    $tempFile = download_url($avatarUrl);
                }

                if (!is_wp_error($tempFile)) {
                    $fileArray = [
                        'name' => wp_basename((string) parse_url($avatarUrl, PHP_URL_PATH)),
                        'tmp_name' => $tempFile,
                    ];

                    $sideloadedId = media_handle_sideload($fileArray, 0);

                    if (is_wp_error($sideloadedId)) {
                        @unlink($tempFile);
                        $this->logger->warning('Failed to sideload avatar into media library', [
                            'wp_user_id' => $wpUserId,
                            'avatar_url' => $avatarUrl,
                            'error' => $sideloadedId->get_error_message(),
                        ]);
                    } else {
                        $attachmentId = (int) $sideloadedId;
                        $avatarUrl = (string) wp_get_attachment_url($attachmentId);
                    }
                } else {
                    $this->logger->warning('Failed to download avatar for sideload', [
                        'wp_user_id' => $wpUserId,
                        'avatar_url' => $avatarUrl,
                        'error' => $tempFile->get_error_message(),
                    ]);
                }
            }
        }

        if (!empty($avatarUrl)) {
            update_user_meta($wpUserId, 'swpm_wpforms_avatar', $avatarUrl);
        }

        if ($attachmentId > 0) {
            update_user_meta($wpUserId, 'wp_user_avatar', $attachmentId);
            update_user_meta($wpUserId, 'simple_local_avatar', [
                'media_id' => $attachmentId,
                'full' => $avatarUrl,
                'blog_id' => get_current_blog_id(),
            ]);
        }

        $this->logger->debug('User avatar set', [
            'wp_user_id' => $wpUserId,
            'avatar_url' => $avatarUrl,
            'attachment_id' => $attachmentId,
        ]);
    }

    /**
     * Override avatar data with the stored local attachment when available.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterAvatarData(array $args, $idOrEmail): array {
        $wpUserId = $this->resolveAvatarUserId($idOrEmail);
        if ($wpUserId <= 0) {
            return $args;
        }

        $avatar = $this->getUserAvatarData($wpUserId, (int) ($args['size'] ?? 96));
        if ($avatar === null) {
            return $args;
        }

        $args['url'] = $avatar['url'];
        $args['found_avatar'] = true;

        return $args;
    }

    /**
     * Resolve local avatar data for a WordPress user.
     *
     * @return array{attachment_id: int, url: string}|null
     */
    public function getUserAvatarData(int $wpUserId, int $size = 96): ?array {
        $attachmentId = (int) get_user_meta($wpUserId, 'wp_user_avatar', true);
        $avatarUrl = '';

        if ($attachmentId > 0) {
            $avatarUrl = (string) wp_get_attachment_image_url($attachmentId, [$size, $size]);

            if ($avatarUrl === '') {
                $avatarUrl = (string) wp_get_attachment_url($attachmentId);
            }
        }

        if ($avatarUrl === '') {
            $avatarUrl = (string) get_user_meta($wpUserId, 'swpm_wpforms_avatar', true);
        }

        if ($avatarUrl === '') {
            return null;
        }

        return [
            'attachment_id' => $attachmentId,
            'url' => $avatarUrl,
        ];
    }

    /**
     * Resolve a WordPress user ID from avatar hook input.
     */
    private function resolveAvatarUserId($idOrEmail): int {
        if ($idOrEmail instanceof \WP_User) {
            return (int) $idOrEmail->ID;
        }

        if ($idOrEmail instanceof \WP_Post) {
            return (int) $idOrEmail->post_author;
        }

        if ($idOrEmail instanceof \WP_Comment) {
            if (!empty($idOrEmail->user_id)) {
                return (int) $idOrEmail->user_id;
            }

            if (!empty($idOrEmail->comment_author_email)) {
                $user = get_user_by('email', (string) $idOrEmail->comment_author_email);

                return $user instanceof \WP_User ? (int) $user->ID : 0;
            }

            return 0;
        }

        if (is_numeric($idOrEmail)) {
            return (int) $idOrEmail;
        }

        if (is_object($idOrEmail) && isset($idOrEmail->user_id)) {
            return (int) $idOrEmail->user_id;
        }

        if (is_string($idOrEmail) && $idOrEmail !== '') {
            if (preg_match('/^[a-f0-9]{32}$/i', $idOrEmail) === 1) {
                $users = get_users([
                    'fields' => ['ID', 'user_email'],
                    'number' => -1,
                ]);

                foreach ($users as $user) {
                    $email = isset($user->user_email) ? trim((string) $user->user_email) : '';
                    if ($email !== '' && md5(strtolower($email)) === strtolower($idOrEmail)) {
                        return isset($user->ID) ? (int) $user->ID : 0;
                    }
                }

                return 0;
            }

            $user = get_user_by('email', $idOrEmail);

            return $user instanceof \WP_User ? (int) $user->ID : 0;
        }

        return 0;
    }
    
    /**
     * Save custom meta fields for a member.
     */
    private function saveCustomMeta(int $memberId, array $meta, bool $useWpUserMeta = false): void {
        if (empty($meta)) {
            return;
        }

        global $wpdb;
        $metaTable = $wpdb->prefix . 'swpm_members_meta';
        $wpUserId = $useWpUserMeta ? $this->resolveMemberWpUserId($this->getMemberById($memberId) ?: []) : 0;
        
        foreach ($meta as $key => $value) {
            if ($wpUserId > 0) {
                update_user_meta($wpUserId, $key, $value);
            }

            $storageTarget = $this->resolveCustomFieldStorageTarget($key);

            if ($storageTarget === 'column') {
                if (class_exists('SwpmMemberUtils') && method_exists('SwpmMemberUtils', 'update_member_field')) {
                    \SwpmMemberUtils::update_member_field($memberId, $key, $value);
                } else {
                    $wpdb->update($wpdb->prefix . 'swpm_members_tbl', [$key => $value], ['member_id' => $memberId]);
                }
                continue;
            }

            if ($storageTarget === 'meta') {
                $existingMetaId = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$metaTable} WHERE member_id = %d AND meta_key = %s LIMIT 1",
                    $memberId,
                    $key
                ));

                if ($existingMetaId) {
                    $wpdb->update(
                        $metaTable,
                        ['meta_value' => $value],
                        ['meta_id' => (int) $existingMetaId],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert(
                        $metaTable,
                        [
                            'member_id' => $memberId,
                            'meta_key' => $key,
                            'meta_value' => $value,
                        ],
                        ['%d', '%s', '%s']
                    );
                }

                continue;
            }

            $this->logger->warning('Skipping unsupported SWPM custom field update', [
                'member_id' => $memberId,
                'field' => $key,
            ]);
        }
    }

    public function getCustomFieldValue(array $member, string $metaKey, bool $preferWpUserMeta = false): ?string {
        if ($preferWpUserMeta) {
            $wpUserId = $this->resolveMemberWpUserId($member);
            if ($wpUserId > 0) {
                $value = get_user_meta($wpUserId, $metaKey, true);
                if ($value !== '') {
                    return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                }
            }
        }

        if (array_key_exists($metaKey, $member)) {
            return $member[$metaKey] !== null ? (string) $member[$metaKey] : null;
        }

        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}swpm_members_meta WHERE member_id = %d AND meta_key = %s LIMIT 1",
            (int) ($member['member_id'] ?? 0),
            $metaKey
        ));

        return $value !== null ? (string) $value : null;
    }

    private function resolveCustomFieldStorageTarget(string $key): ?string {
        global $wpdb;

        $memberTable = $wpdb->prefix . 'swpm_members_tbl';
        $metaTable = $wpdb->prefix . 'swpm_members_meta';

        $columnExists = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$memberTable} LIKE %s",
                $key
            )
        );

        if ($columnExists) {
            return 'column';
        }

        $metaTableExists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $metaTable));

        return $metaTableExists ? 'meta' : null;
    }

    private function resolveMemberWpUserId(array $member): int {
        $wpUser = null;

        if (!empty($member['user_name'])) {
            $wpUser = get_user_by('login', (string) $member['user_name']);
        }

        if (!$wpUser instanceof \WP_User && !empty($member['email'])) {
            $wpUser = get_user_by('email', (string) $member['email']);
        }

        return $wpUser instanceof \WP_User ? (int) $wpUser->ID : 0;
    }
}