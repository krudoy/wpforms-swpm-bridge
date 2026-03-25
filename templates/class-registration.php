<?php

if ( ! class_exists( 'WPForms_Template', false ) ) {
	return;
}

/**
 * Registration template for WPForms.
 */
class WPForms_Template_SWPM_Registration extends WPForms_Template {

	/**
	 * Initialize template.
	 */
	public function init() {
		$this->name        = 'SWPM Registration';
		$this->slug        = 'swpm-registration';
		$this->description = 'Registration form template for WPForms ↔ Simple WordPress Membership.';
		$this->includes    = 'Name, address, password, GDPR, username, email, bio, company, profile picture, website, checkboxes, multiple choice';
		$this->data        = [
			'meta'     => [
				'template' => $this->slug,
			],
			'fields'   => [
				'1'  => [ 'id' => '1', 'type' => 'name', 'label' => 'Name', 'format' => 'first-last', 'required' => '1', 'size' => 'medium' ],
				'2'  => [ 'id' => '2', 'type' => 'address', 'label' => 'Address', 'scheme' => 'us', 'size' => 'medium' ],
				'3'  => [ 'id' => '3', 'type' => 'password', 'label' => 'Password', 'required' => '1', 'confirmation' => '1', 'password-strength' => '1', 'password-strength-level' => '3', 'password-visibility' => '1', 'size' => 'medium' ],
				'4'  => [ 'id' => '4', 'type' => 'gdpr-checkbox', 'label' => 'GDPR Agreement', 'required' => '1', 'choices' => [ 1 => [ 'label' => 'I consent to having this website store my submitted information so they can respond to my inquiry.' ] ] ],
				'5'  => [ 'id' => '5', 'type' => 'text', 'label' => 'Username', 'required' => '1', 'size' => 'medium' ],
				'6'  => [ 'id' => '6', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'confirmation' => '1', 'size' => 'medium' ],
				'7'  => [ 'id' => '7', 'type' => 'textarea', 'label' => 'Short Bio', 'description' => 'Share a little biographical information to fill out your profile. This may be shown publicly.', 'size' => 'medium' ],
				'8'  => [ 'id' => '8', 'type' => 'text', 'label' => 'Company', 'size' => 'medium' ],
				'9'  => [ 'id' => '9', 'type' => 'file-upload', 'label' => 'Profile Picture', 'extensions' => 'png,jpg,jpeg', 'max_file_number' => '1' ],
				'10' => [ 'id' => '10', 'type' => 'url', 'label' => 'Website / URL', 'size' => 'medium' ],
				'14' => [ 'id' => '14', 'type' => 'checkbox', 'label' => 'Checkboxes', 'choices' => [ 1 => [ 'label' => 'First Choice' ], 2 => [ 'label' => 'Second Choice' ], 3 => [ 'label' => 'Third Choice' ] ] ],
				'15' => [ 'id' => '15', 'type' => 'radio', 'label' => 'Multiple Choice', 'choices' => [ 1 => [ 'label' => 'First Choice' ], 2 => [ 'label' => 'Second Choice' ], 3 => [ 'label' => 'Third Choice' ] ] ],
			],
			'field_id' => 16,
			'settings' => [
				'form_title'              => 'Registration',
				'submit_text'             => 'Submit',
				'submit_text_processing'  => 'Submitting...',
				'notification_enable'     => '1',
				'confirmations'           => [
					'1' => [
						'name'           => 'Default Confirmation',
						'type'           => 'message',
						'message'        => '<p>Registration completed successfully.</p>',
						'message_scroll' => '1',
					],
				],
				'swpm_integration'        => [
					'enabled'          => '1',
					'action_type'      => 'register_member',
					'field_map'        => [
						'4'  => 'custom_',
						'8'  => 'company',
						'10' => 'wp_user_url',
						'14' => 'custom_',
						'15' => 'custom_',
					],
					'field_map_custom' => [
						'4'  => 'gdpr_agreement',
						'14' => 'checkboxes',
						'15' => 'multiple_choice',
					],
					'membership_level' => '3',
					'options'          => [
						'on_duplicate'              => 'reject',
						'password_mode'             => 'require_field',
						'auto_login'                => '1',
						'notify_admin_on_failure'   => '1',
						'current_password_mode'     => 'require_when_mapped',
						'blank_new_password_behavior' => 'ignore',
					],
				],
			],
		];
	}
}

new WPForms_Template_SWPM_Registration();