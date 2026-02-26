# WPForms SWPM Bridge

Integrates WPForms with Simple WordPress Membership for form-driven member management.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WPForms Pro
- Simple WordPress Membership

## Installation

1. Upload the `wpforms-swpm-bridge` folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin directory (or upload the included `vendor` folder)
3. Activate the plugin in WordPress admin
4. Ensure both WPForms and Simple WordPress Membership are active

## Configuration

### Global Settings

Go to **Settings → WPForms SWPM** to configure:

- **Enable Integration**: Master switch for all form integrations
- **Default Membership Level**: Fallback level when not specified in form
- **Log Level**: Controls logging verbosity (Debug, Info, Warning, Error)
- **Log Retention**: Days to keep logs before auto-cleanup

### Per-Form Setup

1. Edit any WPForms form
2. Go to **Settings → SWPM Integration**
3. Enable the integration
4. Select an action type:
   - **Register New Member**: Creates a new SWPM member
   - **Update Existing Member**: Updates member data by email/username
   - **Change Membership Level**: Changes a member's level
5. Map your form fields to SWPM member fields
6. Configure behavior options

## Field Mapping

Map WPForms fields to these SWPM fields:

| SWPM Field | Description | Required For |
|------------|-------------|--------------|
| `email` | Member email address | Register, Update, Change Level |
| `username` | Member username | Register |
| `password` | Account password | Register (unless auto-generate) |
| `first_name` | First name | Optional |
| `last_name` | Last name | Optional |
| `membership_level` | Level ID | Register, Change Level |

## Behavior Options

### On Duplicate

- **Reject**: Block submission if member exists
- **Update**: Update the existing member instead
- **Skip**: Process form normally, skip SWPM action

### Password Mode

- **Require field**: Password must be provided via form
- **Auto-generate**: Generate secure password, email to user

### Other Options

- **Auto-login**: Log user in after successful registration
- **Send welcome email**: Trigger SWPM's welcome email
- **Redirect URL**: Override form redirect on success

## Filters & Actions

### Filters

```php
// Modify member data before action
add_filter('swpm_wpforms_member_dto', function($dto, $fields, $config) {
    // Modify $dto properties
    return $dto;
}, 10, 3);

// Modify field mapping
add_filter('swpm_wpforms_field_map', function($map, $form_id) {
    return $map;
}, 10, 2);

// Add custom validation errors
add_filter('swpm_wpforms_validation_errors', function($errors, $dto, $action, $options) {
    if ($dto->email && !str_ends_with($dto->email, '@company.com')) {
        $errors['email'] = 'Must use company email';
    }
    return $errors;
}, 10, 4);

// Customize password email
add_filter('swpm_wpforms_password_email_subject', function($subject, $email, $context) {
    return $subject;
}, 10, 3);

add_filter('swpm_wpforms_password_email_message', function($message, $email, $password, $context) {
    return $message;
}, 10, 4);
```

### Actions

```php
// Before SWPM action executes
add_action('swpm_wpforms_before_action', function($action_type, $dto, $config) {
    // Log, validate, or modify
}, 10, 3);

// After SWPM action completes
add_action('swpm_wpforms_after_action', function($action_type, $member_id, $dto) {
    // Trigger integrations, send notifications
}, 10, 3);

// After password is auto-generated
add_action('swpm_wpforms_password_generated', function($email, $context) {
    // Additional notifications
}, 10, 2);
```

## Logging

Logs are stored in the `{prefix}swpm_wpforms_logs` database table.

Log levels:
- **Debug**: All operations (verbose)
- **Info**: Successful actions
- **Warning**: Non-critical issues
- **Error**: Failures only

When `WP_DEBUG_LOG` is enabled, logs also write to `debug.log`.

## Troubleshooting

### Form not creating members

1. Check global integration is enabled in Settings → WPForms SWPM
2. Verify form-level integration is enabled
3. Check field mapping includes required fields (email, username)
4. Review logs for specific errors

### Duplicate member errors

Configure "On Duplicate" behavior in form settings, or ensure users aren't submitting multiple times.

### Password emails not sending

1. Verify WordPress email is working (`wp_mail`)
2. Check spam folder
3. Review logs for email failures

## Uninstall

Deactivating the plugin preserves all data. To completely remove:

1. Deactivate the plugin
2. Delete the plugin from WordPress admin

This removes:
- Plugin options
- Log database table
- Form meta related to SWPM integration

## License

GPL v2 or later