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


## Profile Display

Display member profile data on the frontend using shortcodes or a Gutenberg block.

### Shortcodes

#### `[swpm_profile]` — Display Member Profile

Renders member data using a WPForms field mapping as the template.

**Basic Usage:**

```
[swpm_profile form_id="123"]
```

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `form_id` | — | **Required.** WPForms form ID with SWPM integration enabled |
| `layout` | `wpforms` | Display layout: `wpforms`, `table`, `list`, `inline` |
| `member_id` | — | Show specific member (default: current logged-in member) |
| `username` | — | Alternative to `member_id` |
| `include` | — | Comma-separated field names to show (whitelist) |
| `exclude` | — | Comma-separated field names to hide (blacklist) |
| `password_notice` | `auto` | Password footnote: `auto`, `yes`, `no` |
| `class` | — | Additional CSS class for wrapper |

**Layout Options:**

| Layout | Description |
|--------|-------------|
| `wpforms` | Matches WPForms form styling with labels above values |
| `table` | Two-column table with labels and values |
| `list` | Definition list with left border accent |
| `inline` | All fields in one line separated by `\|` |

**Examples:**

```
// Basic profile using form template
[swpm_profile form_id="123"]

// Table layout with custom class
[swpm_profile form_id="123" layout="table" class="my-profile"]

// Show only specific fields
[swpm_profile form_id="123" include="email,first_name,last_name,membership_level"]

// Hide specific fields
[swpm_profile form_id="123" exclude="phone,address_street,address_city"]

// Show specific member's profile
[swpm_profile form_id="123" member_id="42"]

// Force password notice display
[swpm_profile form_id="123" password_notice="yes"]
```

#### `[swpm_field]` — Display Single Field

Renders a single member field value. Can be used standalone or inside `[swpm_profile]`.

**Usage:**

```
[swpm_field name="email"]
```

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `name` | — | **Required.** SWPM field name |
| `member_id` | — | Show specific member (default: current logged-in) |
| `username` | — | Alternative to `member_id` |
| `default` | — | Value to show if field is empty |
| `format` | — | Special formatting: `date`, `membership_name`, `raw` |

**Available Field Names:**

| Field Name | Description |
|------------|-------------|
| `email` | Member email |
| `username` | Member username |
| `first_name` | First name |
| `last_name` | Last name |
| `membership_level` | Level ID (use `format="membership_name"` for name) |
| `phone` | Phone number |
| `address_street` | Street address |
| `address_city` | City |
| `address_state` | State/Province |
| `address_zipcode` | Zip/Postal code |
| `country` | Country |
| `company` | Company name |
| `gender` | Gender |
| `member_since` | Registration date |
| `subscription_starts` | Subscription start date |
| `account_state` | Account status |
| `wp_display_name` | WordPress display name |
| `wp_nickname` | WordPress nickname |
| `wp_description` | WordPress bio |
| `wp_user_url` | WordPress website URL |
| `custom_*` | Custom meta field (e.g., `custom_company_id`) |

**Examples:**

```
// Basic field display
Welcome, [swpm_field name="first_name" default="Member"]!

// Membership level as name (not ID)
Your plan: [swpm_field name="membership_level" format="membership_name"]

// Custom template inside swpm_profile
[swpm_profile]
<div class="profile-card">
    <h2>[swpm_field name="first_name"] [swpm_field name="last_name"]</h2>
    <p>Email: [swpm_field name="email"]</p>
    <p>Member since: [swpm_field name="member_since"]</p>
</div>
[/swpm_profile]
```

### Gutenberg Block

The **SWPM Profile** block is available in the block inserter under the Widgets category.

Block settings include all shortcode options plus a visual preview showing:
- Selected form and layout
- Field visibility configuration  
- Password notice indicator

### Security Notes

- **Password fields are always hidden** regardless of mapping or configuration
- When a password field is mapped in the form, a security footnote is displayed automatically
- Use `password_notice="no"` to suppress the footnote if needed

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