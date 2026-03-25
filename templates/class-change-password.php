<?php

if ( ! class_exists( 'WPForms_Template', false ) ) {
	return;
}

/**
 * Change Password template for WPForms.
 */
class WPForms_Template_SWPM_Change_Password extends WPForms_Template {

	/**
	 * Initialize template.
	 */
	public function init() {
		$this->name        = 'SWPM Change Password';
		$this->slug        = 'swpm-change-password';
		$this->description = 'Change password form template for WPForms ↔ Simple WordPress Membership.';
		$this->includes    = 'Old password and new password fields with SWPM change-password integration';
		$this->data        = [
			'meta'     => [
				'template' => $this->slug,
			],
			'fields'   => [
				'11' => [ 'id' => '11', 'type' => 'password', 'label' => 'Old Password', 'required' => '1', 'password-strength-level' => '3', 'size' => 'medium' ],
				'3'  => [ 'id' => '3', 'type' => 'password', 'label' => 'New Password', 'required' => '1', 'confirmation' => '1', 'password-strength' => '1', 'password-strength-level' => '3', 'password-visibility' => '1', 'size' => 'medium' ],
			],
			'field_id' => 12,
			'settings' => [
				'form_title'             => 'Change Password',
				'submit_text'            => 'Submit',
				'submit_text_processing' => 'Submitting...',
				'notification_enable'    => '1',
				'notifications'          => [
					'1' => [
						'enable'            => '1',
						'notification_name' => 'Default Notification',
						'email'             => '{admin_email}',
						'subject'           => 'User Password Changed',
						'message'           => '{all_fields}',
					],
				],
				'confirmations'          => [
					'1' => [
						'name'           => 'Default Confirmation',
						'type'           => 'message',
						'message'        => '<p>Password changed successfully.</p>',
						'message_scroll' => '1',
					],
				],
				'swpm_integration'       => [
					'enabled'          => '1',
					'action_type'      => 'change_password',
					'field_map'        => [
						'3'  => 'password',
						'11' => 'current_password',
					],
					'field_map_custom' => [],
					'membership_level' => '2',
					'options'          => [
						'on_duplicate'                => 'reject',
						'password_mode'               => 'require_field',
						'auto_login'                  => '1',
						'notify_admin_on_failure'     => '1',
						'current_password_mode'       => 'require_when_mapped',
						'blank_new_password_behavior' => 'ignore',
					],
				],
			],
		];
	}
}

new WPForms_Template_SWPM_Change_Password();