# E2E Test: Change Password Flow

## Prerequisites
- WPForms form with SWPM integration enabled
- Action type set to "Change Password"
- Two password fields mapped:
  - One to `current_password`
  - One to `password` (new password)
- Email or username field mapped for member identification
- Existing SWPM member with known password

## Test Cases

### TC1: Successful Password Change
**Steps:**
1. Navigate to form page (logged in as member)
2. Enter email/username
3. Enter correct current password
4. Enter new password
5. Submit form

**Expected:**
- Success message displayed
- Member can log in with new password
- Old password no longer works

### TC2: Wrong Current Password
**Steps:**
1. Navigate to form page
2. Enter valid email/username
3. Enter incorrect current password
4. Enter new password
5. Submit form

**Expected:**
- Error: "Current password is incorrect"
- Password unchanged

### TC3: Missing Current Password
**Steps:**
1. Navigate to form page
2. Enter email/username
3. Leave current password empty
4. Enter new password
5. Submit form

**Expected:**
- Validation error: "Current password is required"

### TC4: Missing New Password
**Steps:**
1. Navigate to form page
2. Enter email/username
3. Enter current password
4. Leave new password empty
5. Submit form

**Expected:**
- Validation error: "New password is required"

### TC5: Member Not Found
**Steps:**
1. Navigate to form page
2. Enter non-existent email/username
3. Enter any passwords
4. Submit form

**Expected:**
- Error: "Member not found"

## Field Mapping Verification

### TC6: current_password in Field Mapping UI
**Steps:**
1. Go to WPForms builder
2. Open SWPM integration settings
3. Check field mapping dropdown

**Expected:**
- "Current Password (for change)" option available in SWPM field dropdown

### TC7: Profile Preview Excludes Passwords
**Steps:**
1. View profile preview shortcode/block output
2. Check displayed fields

**Expected:**
- `password` field NOT displayed
- `current_password` field NOT displayed

## Screenshots Needed
- [ ] Form builder with change_password action selected
- [ ] Field mapping showing current_password option
- [ ] Successful password change confirmation
- [ ] Error state: wrong current password
- [ ] Profile preview (passwords excluded)