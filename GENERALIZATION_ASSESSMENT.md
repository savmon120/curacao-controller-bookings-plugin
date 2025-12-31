# Plugin Generalization Assessment
**Plugin:** VATCAR FIR Station Booking  
**Date:** December 23, 2025  
**Assessment for:** Use by other VATCAR subdivisions (PIA, etc.)

---

## 🎯 Executive Summary

**Current State:** ⚠️ **Partially Generalizable**  
**Division Support:** ✅ CAR (VATCAR) only  
**Subdivision Support:** ⚠️ CUR hardcoded in critical places  

**Can another FIR use it?** Yes, but requires **manual code changes** to station lists and default subdivisions.

---

## ✅ What IS Generalizable (Good!)

### 1. Subdivision Configuration
**Location:** WordPress Settings → VATCAR ATC Bookings

```php
// Stored in database
get_option('vatcar_fir_subdivision', 'CUR')
```

**Used in:**
- ✅ Booking validation (checks controller subdivision matches)
- ✅ Dashboard filtering (shows only subdivision's bookings)
- ✅ Authorization logic (enforces subdivision requirements)

**Status:** ✅ Fully configurable via admin panel

---

### 2. Division Logic
**Hardcoded but acceptable:**
```php
'division' => 'CAR'
```

**Locations:**
- `class-booking.php` line 57
- `class-schedule.php` line 10, 47

**Assessment:** ✅ Acceptable - Plugin is VATCAR-specific by design. All VATCAR subdivisions use `CAR` division.

---

### 3. Core Functionality
**These work for any subdivision:**
- ✅ Whitelist/authorization system
- ✅ Solo certification logic
- ✅ Rating validation
- ✅ Expiration handling
- ✅ VATSIM API integration
- ✅ Compliance tracking
- ✅ Real-time status checks

---

## ❌ What is NOT Generalizable (Issues)

### 1. **CRITICAL: Hardcoded Station Lists**

#### Location 1: Booking Form Template
**File:** `templates/booking-form.php` lines 82-86

```php
$stations = [
    'TNCA_RMP','TNCA_TWR','TNCA_APP',  // Aruba
    'TNCC_TWR','TNCB_TWR',              // Curaçao, Bonaire
    'TNCF_APP','TNCF_CTR',              // St. Maarten, Curaçao Control
    'TNCM_DEL','TNCM_TWR','TNCM_APP',  // St. Maarten
    'TQPF_TWR'                          // Anguilla
];
```

**Problem:** These are all Curaçao FIR stations. Piarco FIR would need different stations (TTPP, TAPA, etc.)

---

#### Location 2: Whitelist Admin Page
**File:** `vatcar-fir-station-booking.php` lines 490-501

```php
$positions = [
    'TNCA_GND' => 'Aruba Ramp (Queen Beatrix)',
    'TNCA_TWR' => 'Aruba Tower (Queen Beatrix)',
    // ... all Curaçao FIR stations
];
```

**Problem:** Used for position authorization checkboxes. Piarco staff would see Curaçao stations.

---

### 2. **MODERATE: Hardcoded Subdivision in Booking Creation**

**File:** `includes/class-booking.php` line 58

```php
$result = self::save_booking([
    'cid'         => self::vatcar_get_cid(),
    'callsign'    => sanitize_text_field($_POST['callsign'] ?? ''),
    'start'       => $start,
    'end'         => $end,
    'division'    => 'CAR',
    'subdivision' => 'CUR',  // ❌ HARDCODED
    'type'        => 'booking',
]);
```

**Problem:** New bookings always created with `subdivision = 'CUR'` regardless of settings.

**Impact:** HIGH - Breaks subdivision filtering and validation for other FIRs.

---

### 3. **MODERATE: Hardcoded Subdivision in Schedule Sync**

**File:** `includes/class-schedule.php` lines 10-11

```php
$query = add_query_arg([
    'division'    => 'CAR',
    'subdivision' => 'CUR',  // ❌ HARDCODED
    'sort'        => 'start',
    'sort_dir'    => 'asc',
], VATCAR_VATSIM_API_BASE . '/api/booking');
```

**Problem:** Schedule always syncs CUR subdivision bookings, regardless of site.

**Impact:** HIGH - Piarco would see Curaçao bookings instead of their own.

---

### 4. **MINOR: Domain Detection Logic**

**File:** `vatcar-fir-station-booking.php` lines 76-84

```php
function vatcar_detect_subdivision() {
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    if (strpos($host, 'curacao.vatcar.net') !== false || strpos($host, 'curacao.vatcar.local') !== false) {
        return 'CUR';
    }
    if (strpos($host, 'piarco.vatcar.net') !== false) {
        return 'PIA';
    }
    return ''; // Default
}
```

**Status:** ⚠️ Partially implemented - Has PIA detection but not used everywhere.

---

### 5. **MINOR: Subdivision Names**

**File:** `vatcar-fir-station-booking.php` lines 89-93

```php
function vatcar_get_subdivision_name($code) {
    $names = [
        'CUR' => 'Curaçao',
        'PIA' => 'Piarco',
        // Add more as needed
    ];
    return $names[$code] ?? $code;
}
```

**Status:** ✅ Good structure - Easy to add more subdivisions.

---

## 🔧 Required Changes for Piarco FIR

### Change 1: Fix Hardcoded Subdivision in Booking Creation

**File:** `includes/class-booking.php` line 58

**BEFORE:**
```php
'subdivision' => 'CUR',
```

**AFTER:**
```php
'subdivision' => get_option('vatcar_fir_subdivision', 'CUR'),
```

---

### Change 2: Fix Hardcoded Subdivision in Schedule Sync

**File:** `includes/class-schedule.php` line 11

**BEFORE:**
```php
'subdivision' => 'CUR',
```

**AFTER:**
```php
'subdivision' => get_option('vatcar_fir_subdivision', 'CUR'),
```

---

### Change 3: Make Station Lists Configurable

**Option A: Database-Driven (Recommended)**

Create new DB table: `wp_atc_stations`
```sql
CREATE TABLE wp_atc_stations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subdivision VARCHAR(10),
    callsign VARCHAR(20),
    name VARCHAR(255),
    position_type VARCHAR(10), -- GND, TWR, APP, CTR
    display_order INT,
    active BOOLEAN DEFAULT 1
);
```

Add admin page to manage stations per subdivision.

**Option B: Configuration Array (Quicker)**

Create `config/stations.php`:
```php
return [
    'CUR' => [
        'TNCA_GND' => ['name' => 'Aruba Ramp', 'type' => 'GND'],
        'TNCA_TWR' => ['name' => 'Aruba Tower', 'type' => 'TWR'],
        // ... etc
    ],
    'PIA' => [
        'TTPP_GND' => ['name' => 'Piarco Ground', 'type' => 'GND'],
        'TTPP_TWR' => ['name' => 'Piarco Tower', 'type' => 'TWR'],
        // ... etc
    ],
];
```

Load based on `get_option('vatcar_fir_subdivision')`.

---

### Change 4: Update Templates to Use Configurable Stations

**File:** `templates/booking-form.php`

**BEFORE:**
```php
$stations = [
    'TNCA_RMP','TNCA_TWR','TNCA_APP',
    // ... hardcoded list
];
```

**AFTER:**
```php
$subdivision = get_option('vatcar_fir_subdivision', 'CUR');
$stations = vatcar_get_subdivision_stations($subdivision);
```

---

### Change 5: Update Whitelist Admin Page

**File:** `vatcar-fir-station-booking.php` line 490

**BEFORE:**
```php
$positions = [
    'TNCA_GND' => 'Aruba Ramp (Queen Beatrix)',
    // ... hardcoded list
];
```

**AFTER:**
```php
$subdivision = get_option('vatcar_fir_subdivision', 'CUR');
$positions = vatcar_get_subdivision_stations($subdivision);
```

---

## 📊 Generalization Checklist

### Core Logic (No Changes Needed)
- [x] Division check (CAR for all VATCAR)
- [x] Subdivision validation (uses setting)
- [x] Rating requirements (universal)
- [x] Whitelist system (universal)
- [x] Solo certifications (universal)
- [x] API integration (universal)
- [x] Compliance tracking (universal)

### Requires Code Changes
- [ ] **Change 1:** Fix subdivision in booking creation (5 min)
- [ ] **Change 2:** Fix subdivision in schedule sync (5 min)
- [ ] **Change 3:** Make station lists configurable (2-4 hours)
- [ ] **Change 4:** Update booking form template (30 min)
- [ ] **Change 5:** Update whitelist admin page (30 min)

### Configuration Required (No Code Changes)
- [ ] Set `vatcar_fir_subdivision` in WordPress settings
- [ ] Configure VATSIM API key
- [ ] Add subdivision-specific stations
- [ ] Update domain detection if needed

---

## 🚀 Deployment Scenarios

### Scenario 1: Piarco FIR Wants to Use Plugin

**Current State:**
- Plugin installed
- Settings: `vatcar_fir_subdivision = 'PIA'`

**What Works:**
- ✅ Authorization logic (checks PIA subdivision)
- ✅ Dashboard filtering (shows only PIA bookings)
- ✅ Whitelist management
- ✅ Compliance tracking

**What Breaks:**
- ❌ Booking form shows Curaçao stations
- ❌ New bookings created with `subdivision = 'CUR'`
- ❌ Schedule syncs Curaçao bookings
- ❌ Whitelist position checkboxes show Curaçao stations

**Required Actions:**
1. Apply Change 1 & 2 (fix hardcoded CUR)
2. Either:
   - Manually edit station arrays to PIA stations, OR
   - Implement station configuration system (Change 3-5)

---

### Scenario 2: New VATCAR Subdivision

**Example:** Kingston FIR (hypothetical)

**Required Steps:**
1. Add to subdivision detection:
   ```php
   if (strpos($host, 'kingston.vatcar.net') !== false) {
       return 'KIN';
   }
   ```

2. Add to subdivision names:
   ```php
   'KIN' => 'Kingston',
   ```

3. Set `vatcar_fir_subdivision = 'KIN'` in settings

4. Configure Kingston stations (if implementing station config system)

5. Apply Changes 1 & 2 if not already done

---

## 💡 Recommendations

### For Immediate Piarco Deployment

**Priority 1 (Must Fix):**
1. ✅ Apply Change 1 (booking creation)
2. ✅ Apply Change 2 (schedule sync)
3. ✅ Manually update station arrays in both files to PIA stations

**Priority 2 (Should Fix):**
4. ⚠️ Document station list locations for easy updates
5. ⚠️ Test all authorization scenarios with PIA subdivision

**Time Required:** 1-2 hours

---

### For Long-Term Multi-Subdivision Support

**Implement Station Configuration System:**
- Database-driven station management
- Admin UI to add/edit/remove stations per subdivision
- Automatic population of dropdowns and checkboxes
- Position-based rating requirements (future roadmap item)

**Benefits:**
- No code changes needed for new subdivisions
- Each subdivision manages their own stations
- Supports future station additions/removals
- Enables position-specific rating requirements

**Time Required:** 4-8 hours development + testing

---

## 🔍 Code Locations Summary

| Component | File | Lines | Status | Priority |
|-----------|------|-------|--------|----------|
| Booking creation subdivision | `includes/class-booking.php` | 58 | ❌ Hardcoded | HIGH |
| Schedule sync subdivision | `includes/class-schedule.php` | 11 | ❌ Hardcoded | HIGH |
| Booking form stations | `templates/booking-form.php` | 82-86 | ❌ Hardcoded | HIGH |
| Whitelist stations | `vatcar-fir-station-booking.php` | 490-501 | ❌ Hardcoded | HIGH |
| Subdivision validation | `includes/class-booking.php` | 427, 552 | ✅ Uses setting | OK |
| Dashboard filtering | `includes/class-dashboard.php` | 22 | ✅ Uses setting | OK |
| Subdivision detection | `vatcar-fir-station-booking.php` | 76-84 | ⚠️ Partial | MEDIUM |
| Subdivision names | `vatcar-fir-station-booking.php` | 89-93 | ✅ Configurable | OK |

---

## 📝 Testing Checklist for Piarco

After applying fixes, test:

**Booking Creation:**
- [ ] Create booking → verify `subdivision = 'PIA'` in database
- [ ] Create booking → appears on Piarco schedule, not Curaçao schedule

**Schedule Display:**
- [ ] Schedule page syncs PIA bookings from API
- [ ] Only PIA bookings displayed
- [ ] No CUR bookings visible

**Authorization:**
- [ ] PIA home controller can book
- [ ] Non-PIA controller requires whitelist
- [ ] Whitelist entry with PIA positions works

**Dashboard:**
- [ ] Shows only PIA bookings
- [ ] Real-time status works for PIA positions

**Whitelist Management:**
- [ ] Position checkboxes show PIA stations
- [ ] Can authorize specific PIA positions
- [ ] Authorization validation works

---

## 🎯 Final Recommendation

**For Piarco FIR to use this plugin:**

**Option A: Quick Deploy (1-2 hours)**
1. Apply Changes 1 & 2 (fix hardcoded subdivisions)
2. Manually replace station arrays with PIA stations in 2 files
3. Set `vatcar_fir_subdivision = 'PIA'` in settings
4. Test thoroughly

**Option B: Proper Implementation (4-8 hours)**
1. Apply Changes 1 & 2 (fix hardcoded subdivisions)
2. Implement station configuration system (database + admin UI)
3. Populate CUR and PIA stations via admin
4. Each subdivision manages independently

**Recommended:** Option A for immediate needs, then implement Option B as enhancement.

---

**Last Updated:** December 23, 2025  
**Next Review:** After implementing station configuration system