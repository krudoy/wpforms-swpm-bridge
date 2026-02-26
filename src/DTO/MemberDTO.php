<?php

declare(strict_types=1);

namespace SWPMWPForms\DTO;

/**
 * Data Transfer Object for SWPM member data.
 */
class MemberDTO {
    
    public string $email = '';
    public string $username = '';
    public ?string $password = null;
    public int|string|null $membershipLevel = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    
    // Extended SWPM fields
    public ?string $phone = null;
    public ?string $addressStreet = null;
    public ?string $addressCity = null;
    public ?string $addressState = null;
    public ?string $addressZipcode = null;
    public ?string $country = null;
    public ?string $company = null;
    public ?string $gender = null;
    
    // WordPress user fields
    public ?string $wpDisplayName = null;
    public ?string $wpNickname = null;
    public ?string $wpDescription = null;
    public ?string $wpUserUrl = null;
    public ?string $wpAvatar = null; // File path or attachment ID
    
    // Custom meta fields (key => value)
    public array $customMeta = [];
    
    /**
     * Create from array data.
     */
    public static function fromArray(array $data): self {
        $dto = new self();
        
        $dto->email = $data['email'] ?? '';
        $dto->username = $data['username'] ?? '';
        $dto->password = $data['password'] ?? null;
        $dto->membershipLevel = $data['membership_level'] ?? $data['membershipLevel'] ?? null;
        $dto->firstName = $data['first_name'] ?? $data['firstName'] ?? null;
        $dto->lastName = $data['last_name'] ?? $data['lastName'] ?? null;
        
        // Extended SWPM fields
        $dto->phone = $data['phone'] ?? null;
        $dto->addressStreet = $data['address_street'] ?? null;
        $dto->addressCity = $data['address_city'] ?? null;
        $dto->addressState = $data['address_state'] ?? null;
        $dto->addressZipcode = $data['address_zipcode'] ?? null;
        $dto->country = $data['country'] ?? null;
        $dto->company = $data['company'] ?? null;
        $dto->gender = $data['gender'] ?? null;
        
        // WordPress user fields
        $dto->wpDisplayName = $data['wp_display_name'] ?? null;
        $dto->wpNickname = $data['wp_nickname'] ?? null;
        $dto->wpDescription = $data['wp_description'] ?? null;
        $dto->wpUserUrl = $data['wp_user_url'] ?? null;
        $dto->wpAvatar = $data['wp_avatar'] ?? null;
        
        // Custom meta - collect all custom_ and swpm_ prefixed fields
        $customMeta = $data['custom_meta'] ?? $data['customMeta'] ?? [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'custom_') && $key !== 'custom_meta') {
                $metaKey = substr($key, 7); // Remove 'custom_' prefix
                $customMeta[$metaKey] = $value;
            } elseif (str_starts_with($key, 'swpm_')) {
                $metaKey = substr($key, 5); // Remove 'swpm_' prefix  
                $customMeta[$metaKey] = $value;
            }
        }
        $dto->customMeta = $customMeta;
        
        return $dto;
    }
    
    /**
     * Convert to array for SWPM operations.
     */
    public function toArray(): array {
        $data = [
            'email' => $this->email,
            'user_name' => $this->username,
            'password' => $this->password,
            'membership_level' => $this->membershipLevel,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
        ];
        
        // Add extended fields if set
        if ($this->phone !== null) $data['phone'] = $this->phone;
        if ($this->addressStreet !== null) $data['address_street'] = $this->addressStreet;
        if ($this->addressCity !== null) $data['address_city'] = $this->addressCity;
        if ($this->addressState !== null) $data['address_state'] = $this->addressState;
        if ($this->addressZipcode !== null) $data['address_zipcode'] = $this->addressZipcode;
        if ($this->country !== null) $data['country'] = $this->country;
        if ($this->company !== null) $data['company'] = $this->company;
        if ($this->gender !== null) $data['gender'] = $this->gender;
        
        return $data;
    }
    
    /**
     * Get WordPress user fields as array.
     */
    public function getWpUserFields(): array {
        $fields = [];
        
        if ($this->wpDisplayName !== null) $fields['display_name'] = $this->wpDisplayName;
        if ($this->wpNickname !== null) $fields['nickname'] = $this->wpNickname;
        if ($this->wpDescription !== null) $fields['description'] = $this->wpDescription;
        if ($this->wpUserUrl !== null) $fields['user_url'] = $this->wpUserUrl;
        
        return $fields;
    }
    
    /**
     * Get display name (first + last or username).
     */
    public function getDisplayName(): string {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
        return $name ?: $this->username;
    }
    
    /**
     * Check if password is set.
     */
    public function hasPassword(): bool {
        return !empty($this->password);
    }
}