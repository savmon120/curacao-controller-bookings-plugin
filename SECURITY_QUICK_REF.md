# Quick Reference: Security Issues & Fixes

## 🔴 CRITICAL (Fix Now - 30 min)

### Issue #1: Unescaped Output
**File:** `vatcar-fir-station-booking.php:441`  
**Line:** `echo '<input type="text" name="vatcar_vatsim_api_key" value="' . $value . '" class="regular-text" />';`  
**Fix:** `echo '<input type="text" name="vatcar_vatsim_api_key" value="' . esc_attr($value) . '" class="regular-text" />';`

**File:** `vatcar-fir-station-booking.php:604`  
**Line:** `<td><?php echo $name_display; ?></td>`  
**Fix:** `<td><?php echo esc_html($name_display); ?></td>`

---

## 🟡 HIGH PRIORITY (Fix Today - 1 hour)

### Issue #2: Input Validation Missing
**File:** `vatcar-fir-station-booking.php:122`  
**Current:**
```php
$authorization_type = sanitize_text_field($_POST['authorization_type'] ?? 'visitor');
```
**Fix:**
```php
$auth_type_raw = sanitize_text_field($_POST['authorization_type'] ?? 'visitor');
$allowed_types = ['visitor', 'solo'];
$authorization_type = in_array($auth_type_raw, $allowed_types, true) ? $auth_type_raw : 'visitor';
```

### Issue #3: Datetime Validation Missing
**File:** `vatcar-fir-station-booking.php:121,130`  
**Add validation:**
```php
$expires = sanitize_text_field($_POST['expires'] ?? '');
$expires_at = null;
if (!empty($expires)) {
    $timestamp = strtotime($expires);
    if ($timestamp === false) {
        wp_send_json_error('Invalid date format');
    }
    $expires_at = gmdate('Y-m-d H:i:s', $timestamp);
}
```

### Issue #4: $_SERVER Not Sanitized
**File:** `vatcar-fir-station-booking.php:76`  
**Current:** `$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';`  
**Fix:** `$host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';`

**File:** `includes/class-booking.php:11`  
**Current:** `if (strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') === true) {`  
**Fix:**
```php
$host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
if (strpos($host, 'curacao.vatcar.local') !== false) {
```

---

## 🟢 MEDIUM PRIORITY (This Week - 2 hours)

### Issue #5: ABSPATH Protection Missing
**Files:** All in `includes/` and `templates/`  
**Add to top of each file:**
```php
<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
```

### Issue #6: Redundant Sanitization
**File:** `includes/class-booking.php:148,319,325,343` (multiple locations)  
**Current:**
```php
$wpdb->prepare("SELECT ... WHERE cid = %s", sanitize_text_field($cid), ...)
```
**Fix:**
```php
$wpdb->prepare("SELECT ... WHERE cid = %s", $cid, ...)
```
**Note:** Remove ALL `sanitize_*()` calls inside `$wpdb->prepare()`. The prepare method handles escaping.

### Issue #7: Variable Naming
**File:** `includes/class-booking.php:481`  
**Current:** `$bodyRaw = wp_remote_retrieve_body($response);`  
**Fix:** `$body_raw = wp_remote_retrieve_body($response);`

---

## 📋 File-by-File Checklist

### Main Plugin File
- [x] ✅ ABSPATH check present
- [ ] ⚠️ Add `esc_attr()` to line 441
- [ ] ⚠️ Add `esc_html()` to line 604
- [ ] ⚠️ Add input validation for authorization_type (line 122)
- [ ] ⚠️ Add datetime validation (lines 121, 130, 192)
- [ ] ⚠️ Sanitize $_SERVER (line 76)
- [ ] ⚠️ Fix $_GET sanitization (line 357)

### includes/class-booking.php
- [ ] ❌ Add ABSPATH check
- [ ] ⚠️ Sanitize $_SERVER (lines 11, 769, 780)
- [ ] ⚠️ Remove redundant sanitize_* in prepare() (multiple lines)
- [ ] ⚠️ Fix variable naming: bodyRaw → body_raw (line 481)
- [x] ✅ Nonce verification present in AJAX handlers
- [x] ✅ Using $wpdb->prepare() for queries

### includes/class-dashboard.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Capability check present (line 12)
- [x] ✅ Using esc_html/esc_attr for output
- [x] ✅ Using intval() for IDs

### includes/class-schedule.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Using esc_attr/esc_html for output
- [x] ✅ Using $wpdb->prepare() for queries

### includes/class-validation.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Using $wpdb->prepare() correctly
- [x] ✅ Proper regex for validation

### templates/booking-form.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Using esc_attr/esc_html for output
- [ ] ⚠️ Move inline styles to CSS (nice-to-have)

### templates/edit-booking-form.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Using esc_url() for URLs
- [ ] ⚠️ Move inline styles to CSS (nice-to-have)

### templates/delete-booking-form.php
- [ ] ❌ Add ABSPATH check
- [x] ✅ Using esc_url() for URLs
- [ ] ⚠️ Move inline styles to CSS (nice-to-have)

---

## 🧪 Testing Commands

### After Each Fix
```bash
# Test direct file access
curl http://yoursite.local/wp-content/plugins/vatcar-fir-station-booking/includes/class-booking.php
# Should return blank or exit message

# Check for unescaped output
curl -s http://yoursite.local/wp-admin/admin.php?page=vatcar-atc-bookings | grep -i "script\|<input.*value=\"[^\"]*\"" 

# Test SQL injection (safe test)
curl -X POST http://yoursite.local/wp-admin/admin-ajax.php \
  -d "action=add_controller&cid=1234'; DROP--&vatcar_add_controller_nonce=VALID_NONCE"
```

### Run PHPCS
```bash
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs
./vendor/bin/phpcs --standard=WordPress --extensions=php .
```

---

## ⏱️ Time Estimates

| Priority | Time | Tasks |
|----------|------|-------|
| Critical | 30 min | Output escaping (2 locations) |
| High | 1 hour | Input validation + $_SERVER sanitization |
| Medium | 2 hours | ABSPATH + clean prepared statements + naming |
| Testing | 1 hour | Manual + automated testing |
| **Total** | **4.5 hours** | Complete security fixes |

---

## 📞 Quick Wins (Do First)

1. **Line 441:** Add `esc_attr()` (2 minutes)
2. **Line 604:** Add `esc_html()` (1 minute)
3. **Line 76:** Sanitize $_SERVER (5 minutes)
4. **All includes/templates:** Add ABSPATH (20 minutes)

**Total: 28 minutes for 80% of security improvement!**

---

## ✅ Verification

After all fixes:
- [ ] No warnings from PHPCS WordPress standard
- [ ] All output uses esc_* functions
- [ ] All $_POST/$_GET/$_SERVER values sanitized
- [ ] All AJAX handlers verify nonces
- [ ] All files have ABSPATH protection
- [ ] No SQL queries without $wpdb->prepare()
- [ ] Manual security tests pass

---

**Status Updated:** December 22, 2025  
**Next Review:** After implementing fixes
