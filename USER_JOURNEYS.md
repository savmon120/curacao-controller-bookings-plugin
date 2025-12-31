# User Journeys - VATCAR FIR Station Booking Plugin

**Version:** 1.1.0  
**Date:** December 23, 2025

---

## Overview

This document outlines all possible user journeys for the VATCAR FIR Station Booking plugin, including authorization logic, rating requirements, position restrictions, and expiration handling.

---

## 🎭 User Types

### 1. **VATCAR Division Home Controllers**
- Controllers assigned to CAR division
- Assigned to the correct subdivision (CUR, PIA, etc.)
- Full booking privileges based on rating

### 2. **Visiting Controllers (Whitelisted)**
- Controllers from other divisions
- Must be added to whitelist by FIR staff
- Can have expiration dates
- Restricted to specific positions (if configured)

### 3. **Solo Certification Holders**
- Controllers with solo certifications for specific positions
- Can bypass division/subdivision requirements for certified positions
- Can bypass rating requirements for certified positions

### 4. **FIR Staff (Administrators)**
- Can manage bookings for any controller
- Can add/remove/renew authorizations
- Can view compliance history
- Full CRUD access to all bookings

---

## 📊 Rating System & Position Access

### VATSIM Rating Scale
- **0** = OBS (Observer) - Cannot book
- **1** = S1 (Student 1) - Cannot book
- **2** = S2 (Student 2) - Can book (minimum rating)
- **3** = S3 (Student 3) - Can book
- **4** = C1 (Controller 1) - Can book
- **5+** = Higher ratings - Can book

### Minimum Requirements
```
Rating < 2 (OBS, S1) → ❌ Cannot book ANY positions
Rating ≥ 2 (S2+)     → ✅ Can book ALL positions
```

### Current Implementation: **NO POSITION-SPECIFIC RATING RESTRICTIONS**

**All positions have the same requirements:**
- Minimum S2 rating (or solo certification)
- Valid callsign format
- Correct division/subdivision (or authorization)

**Available Positions:**
- `TNCA_RMP` (Aruba Ramp) - formerly GND
- `TNCA_GND` (Aruba Ground)
- `TNCA_TWR` (Aruba Tower)
- `TNCA_APP` (Aruba Approach)
- `TNCC_TWR` (Curacao Tower)
- `TNCB_TWR` (Bonaire Tower)
- `TNCF_APP` (St. Maarten Approach)
- `TNCF_CTR` (Curacao Control)
- `TNCM_DEL` (St. Maarten Delivery)
- `TNCM_TWR` (St. Maarten Tower)
- `TNCM_APP` (St. Maarten Approach)
- `TQPF_TWR` (Anguilla Tower)

---

## 🚀 User Journey 1: VATCAR Home Controller Books Position

### Prerequisites
- Must be logged in
- Must be in CAR division
- Must be in correct subdivision (CUR, PIA, etc.)
- Must have rating ≥ 2 (S2+)

### Steps
1. **Navigate to booking page** (shortcode: `[vatcar_atc_booking]`)
2. **Select position** from dropdown
3. **Choose date and time**
   - Start time must be ≥ 2 hours from now
   - End time must be after start time
4. **Submit booking**

### Validation Checks
✅ Nonce verification  
✅ User is logged in  
✅ User has 'controller' role  
✅ CID matches logged-in user  
✅ Callsign format valid (`_DEL|GND|TWR|APP|DEP|CTR|FSS|RMP`)  
✅ No time overlap with existing bookings for same callsign  
✅ Division = 'CAR'  
✅ Subdivision matches site (CUR, PIA, etc.)  
✅ Rating ≥ 2  

### Success Outcome
- Booking created in VATSIM API
- Booking cached locally in DB
- Controller name stored
- Redirect to schedule page

### Possible Errors
- `missing_callsign` - No position selected
- `invalid_callsign` - Invalid format
- `invalid_start` - Start time < 2 hours from now
- `invalid_end` - End time ≤ start time
- `overlap` - Conflicts with existing booking
- `unauthorized` - CID mismatch
- `invalid_division` - Not in CAR division
- `invalid_subdivision` - Wrong subdivision
- `insufficient_rating` - Rating < 2

---

## 🌎 User Journey 2: Visiting Controller (Whitelisted)

### Prerequisites
- Must be logged in
- Must be added to whitelist by FIR staff
- Authorization must not be expired
- Must have rating ≥ 2 (unless has position-specific authorization)

### FIR Staff Setup Process
1. **Navigate to** Settings → VATCAR ATC Bookings → Visitor Whitelist
2. **Click** "Add Visitor/Solo Authorization"
3. **Enter:**
   - CID
   - Authorization type: **Visitor**
   - Notes (e.g., "S3 visitor from ZAN")
   - Expiration date (optional, leave empty for permanent)
   - Authorized positions (optional, leave empty to allow all)
4. **Submit**

### Booking Process (Visitor)
1. **Navigate to booking page**
2. **Select position** (must be in authorized positions list if specified)
3. **Choose date and time**
4. **Submit booking**

### Validation Checks
✅ Nonce verification  
✅ User is logged in  
✅ CID matches logged-in user  
✅ **Controller is whitelisted** (checked in DB)  
✅ **Whitelist entry not expired** (`expires_at IS NULL OR expires_at > NOW()`)  
✅ **Position is authorized** (if positions specified, must match)  
⚠️ **Division check BYPASSED** (visitors can be from any division)  
⚠️ **Subdivision check BYPASSED** (visitors can be from any subdivision)  
✅ Rating ≥ 2 (unless position-specific authorization)  

### Position-Specific Authorization
If FIR staff specifies authorized positions:
- ✅ Can book ONLY those positions
- ❌ Cannot book other positions

If NO positions specified:
- ✅ Can book ANY position

### Expiration Logic
**When authorization expires:**
- `expires_at` datetime passes current time
- `is_controller_whitelisted()` returns `false`
- Controller sees error: "You must be in the VATCAR division to book a position"
- Booking attempt fails

**Testing Expiration:**
```sql
-- Check expiration status
SELECT cid, expires_at, 
       CASE 
         WHEN expires_at IS NULL THEN 'Permanent'
         WHEN expires_at > NOW() THEN 'Active'
         ELSE 'Expired'
       END as status
FROM wp_atc_controller_whitelist;
```

### Renewing Expired Authorization
1. **FIR staff** navigates to Visitor Whitelist
2. **Clicks** "Renew" or "Extend" button on expired entry
3. **Sets new** expiration date
4. **Submits**
5. Controller can now book again

---

## 🎓 User Journey 3: Solo Certification Holder

### Prerequisites
- Must be logged in
- Must have solo certification added by FIR staff
- Certification must not be expired
- No minimum rating required for certified positions

### FIR Staff Setup Process
1. **Navigate to** Settings → VATCAR ATC Bookings → Visitor Whitelist
2. **Click** "Add Visitor/Solo Authorization"
3. **Enter:**
   - CID
   - Authorization type: **Solo**
   - Notes (e.g., "Solo cert for TNCC_TWR")
   - Expiration date (required for solo certs)
   - **Select specific positions** (required for solo certs)
4. **Submit**

### Key Differences: Solo vs Visitor

| Aspect | Visitor | Solo |
|--------|---------|------|
| Authorization Type | `visitor` | `solo` |
| Expiration | Optional | Recommended |
| Positions | Optional (can allow all) | Must specify |
| Division Check | Bypassed | Bypassed for certified positions |
| Subdivision Check | Bypassed | Bypassed for certified positions |
| Rating Check | Required (S2+) | **Bypassed for certified positions** |
| Typical Use Case | Controllers from other divisions | Local controllers training on specific positions |

### Booking Process (Solo Cert)
1. **Navigate to booking page**
2. **Select certified position** (e.g., TNCC_TWR)
3. **Choose date and time**
4. **Submit booking**

### Validation Checks
✅ Nonce verification  
✅ User is logged in  
✅ CID matches logged-in user  
✅ **Position-specific authorization exists**  
⚠️ **Division check BYPASSED** (for certified position)  
⚠️ **Subdivision check BYPASSED** (for certified position)  
⚠️ **Rating check BYPASSED** (for certified position)  

### Example Scenario: S1 Controller with Solo Cert

**Setup:**
- Controller CID: 1234567
- Current rating: S1 (rating = 1)
- Solo cert for: TNCC_TWR
- Expiration: 30 days from now

**Booking TNCC_TWR (certified):**
- ✅ **Success** - All checks bypassed

**Booking TNCA_TWR (not certified):**
- ❌ **Fails** - "You must have at least S1 rating to book"
- S1 controllers cannot book non-certified positions

---

## 👨‍💼 User Journey 4: FIR Staff Managing Bookings

### Prerequisites
- Must be logged in
- Must have `manage_options` capability (admin)

### Access Points
1. **Dashboard:** WP Admin → ATC Bookings Dashboard
2. **Settings:** WP Admin → Settings → VATCAR ATC Bookings
3. **Whitelist:** Settings page → Visitor Whitelist tab

### Managing Bookings (Dashboard)
**Path:** WP Admin → ATC Bookings Dashboard

**Actions Available:**
1. **View all bookings** for the FIR
2. **Real-time status** (on time, early, late, no show)
3. **Edit booking** (change time/callsign)
4. **Cancel booking** (delete from API + local DB)
5. **View compliance history** (past status checks)

### Managing Authorizations (Whitelist)
**Path:** Settings → VATCAR ATC Bookings → Visitor Whitelist

**Actions Available:**

#### Add Authorization
1. Click "Add Visitor/Solo Authorization"
2. Fill form:
   - **CID** (required)
   - **Type** (visitor or solo)
   - **Notes** (optional)
   - **Expiration** (optional datetime-local)
   - **Positions** (checkboxes, optional)
3. Submit
4. Authorization created with:
   - `added_by` = current admin user ID
   - `date_added` = now
   - `controller_name` = fetched from WordPress if user exists

#### Remove Authorization
1. Find entry in table
2. Click "Remove" button
3. Confirm
4. Entry deleted from database
5. Controller can no longer book (unless they meet home requirements)

#### Renew/Extend Authorization
1. Find expired or soon-to-expire entry
2. Click "Renew" (expired) or "Extend" (active)
3. Set new expiration date
4. Submit
5. `expires_at` updated
6. Controller can book again

---

## 📋 User Journey 5: Viewing Schedule

### Prerequisites
- None (public access)

### Access
- **Shortcode:** `[vatcar_atc_schedule]`
- **Typical page:** `/controller-schedule/`

### Features
1. **Auto-syncs** from VATSIM API on page load
2. **Shows all bookings** for the subdivision
3. **Filters expired bookings** (end time < now)
4. **Displays:**
   - Callsign
   - Controller name (from WordPress user)
   - Start time (user's local timezone)
   - End time (user's local timezone)
5. **Actions** (if logged in as booking owner):
   - ~~Edit~~ (disabled in current version)
   - Delete

### Timezone Handling
- All times stored in DB as UTC
- Displayed in user's local timezone via JavaScript
- Date picker converts local time to UTC on submit

---

## ⚙️ Authorization Logic Flow Chart

```
┌─────────────────────────────────────┐
│   Controller attempts to book       │
└────────────────┬────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────┐
│  Basic validation                   │
│  - Logged in?                       │
│  - Valid callsign format?           │
│  - No time overlap?                 │
│  - Start ≥ 2h from now?             │
└────────────────┬────────────────────┘
                 │ PASS
                 ▼
┌─────────────────────────────────────┐
│  Check: Is whitelisted?             │
│  (visitor authorization)            │
└────────┬────────────────────────────┘
         │
    ┌────┴──────┐
    │ YES       │ NO
    ▼           ▼
┌────────┐   ┌──────────────────────────┐
│VISITOR │   │ HOME CONTROLLER CHECK    │
│PATH    │   │ - Division = CAR?        │
└───┬────┘   │ - Subdivision correct?   │
    │        └────────┬─────────────────┘
    │                 │ PASS
    ▼                 ▼
┌────────────────────────────────────┐
│ Check: Authorized for position?    │
│ (solo cert or visitor position)    │
└────────┬───────────────────────────┘
         │
    ┌────┴──────┐
    │ YES       │ NO
    ▼           ▼
┌────────┐   ┌──────────────────┐
│BYPASS  │   │ STANDARD CHECKS  │
│RATING  │   │ - Rating ≥ 2?    │
│CHECK   │   └────────┬─────────┘
└───┬────┘            │ PASS
    │                 │
    └────────┬────────┘
             ▼
    ┌────────────────┐
    │ CREATE BOOKING │
    └────────────────┘
```

---

## 🔄 Expiration & Renewal Scenarios

### Scenario 1: Visitor Authorization Expires During Active Booking

**Setup:**
- Visitor authorized until Dec 25, 2025 23:59 UTC
- Has booking for Dec 26, 2025 00:00-02:00 UTC
- Authorization expires while booking is still in the future

**What Happens:**
1. ✅ Booking remains in system (not deleted)
2. ✅ Booking appears on schedule
3. ❌ Controller cannot create NEW bookings after expiration
4. ❌ Controller cannot EDIT existing bookings after expiration
5. ❌ Controller cannot DELETE their own bookings after expiration (must ask admin)
6. ✅ FIR staff can still edit/delete the booking

**To Fix:**
- FIR staff renews authorization before expiration
- Or extends authorization if still needed

### Scenario 2: Solo Certification Expires

**Setup:**
- S1 controller with solo cert for TNCC_TWR
- Solo cert expires Dec 25, 2025
- Has booking for Dec 26, 2025

**What Happens:**
1. ✅ Booking remains in system
2. ❌ Controller cannot create new bookings for TNCC_TWR
3. ❌ Controller cannot book any other positions (S1 = insufficient rating)
4. ✅ If they pass S2 checkride, they can book without solo cert

**To Fix:**
- Controller completes checkride → gets S2 rating → can book without solo cert
- Or FIR staff renews solo cert

### Scenario 3: Permanent Visitor (No Expiration)

**Setup:**
- `expires_at = NULL` in database
- S3 controller from ZAN division

**What Happens:**
1. ✅ Can book indefinitely
2. ✅ No expiration checks
3. ✅ Works across rating updates
4. ⚠️ FIR staff must manually remove if needed

**When to Use:**
- Long-term visiting controllers
- Staff from other VATCAR subdivisions
- Permanent inter-division agreements

---

## 🧪 Testing Checklist

### Home Controller Tests
- [ ] S1 controller attempts booking → should fail
- [ ] S2 controller attempts booking → should succeed
- [ ] S3 controller attempts booking → should succeed
- [ ] Controller from wrong division → should fail
- [ ] Controller from wrong subdivision → should fail

### Visitor Tests
- [ ] Non-whitelisted visitor → should fail
- [ ] Whitelisted visitor with no positions specified → can book any position
- [ ] Whitelisted visitor with specific positions → can only book those positions
- [ ] Whitelisted visitor attempts non-authorized position → should fail
- [ ] Expired visitor authorization → should fail
- [ ] Renewed authorization → should succeed

### Solo Certification Tests
- [ ] S1 with solo cert for TNCC_TWR → can book TNCC_TWR only
- [ ] S1 with solo cert attempts TNCA_TWR → should fail
- [ ] Solo cert expires → can no longer book certified position
- [ ] Solo cert renewed → can book again
- [ ] S2 with expired solo cert → can still book (has rating)

### Overlap Tests
- [ ] Book TNCC_TWR 00:00-02:00
- [ ] Attempt TNCC_TWR 01:00-03:00 → should fail (overlap)
- [ ] Attempt TNCC_TWR 02:00-04:00 → should succeed (no overlap)

### Time Validation Tests
- [ ] Start time < 2 hours from now → should fail
- [ ] Start time = 2 hours from now → should succeed
- [ ] End time ≤ start time → should fail
- [ ] End time > start time → should succeed

---

## 📝 Database Queries for Admins

### Check Authorization Status
```sql
SELECT 
    cid,
    controller_name,
    authorization_type,
    expires_at,
    CASE 
        WHEN expires_at IS NULL THEN 'Permanent'
        WHEN expires_at > NOW() THEN 'Active'
        ELSE 'Expired'
    END as status,
    notes
FROM wp_atc_controller_whitelist
ORDER BY date_added DESC;
```

### Find Authorized Positions for a CID
```sql
SELECT 
    w.cid,
    w.controller_name,
    w.authorization_type,
    GROUP_CONCAT(ap.callsign ORDER BY ap.callsign) as authorized_positions
FROM wp_atc_controller_whitelist w
LEFT JOIN wp_atc_authorized_positions ap ON w.id = ap.authorization_id
WHERE w.cid = '1234567'
GROUP BY w.id;
```

### Check Who Can Book a Specific Position
```sql
-- Home controllers (CAR division, correct subdivision, S2+)
-- + Visitors with authorization
-- + Solo cert holders

SELECT 
    w.cid,
    w.controller_name,
    w.authorization_type,
    ap.callsign as authorized_for,
    w.expires_at
FROM wp_atc_controller_whitelist w
LEFT JOIN wp_atc_authorized_positions ap ON w.id = ap.authorization_id
WHERE (ap.callsign = 'TNCC_TWR' OR ap.callsign IS NULL)
  AND (w.expires_at IS NULL OR w.expires_at > NOW());
```

---

## 🔧 Configuration Options

### WordPress Options
- `vatcar_vatsim_api_key` - API key for VATSIM ATC Bookings
- `vatcar_fir_subdivision` - Current FIR subdivision (CUR, PIA, etc.)

### Constants (in main plugin file)
- `VATCAR_VATSIM_API_BASE` - API endpoint (default: https://atc-bookings.vatsim.net)
- `VATCAR_VATSIM_API_CID` - Service account CID for API calls
- `VATCAR_ATC_DEBUG` - Debug mode (true/false)

---

## 💡 Common Questions

**Q: Can S1 controllers book ground positions only?**  
A: **No.** The current implementation requires S2+ rating for ALL positions. There is no position-specific rating restriction. However, S1 controllers can get solo certifications for specific positions.

**Q: What happens when a visitor authorization expires?**  
A: The controller can no longer create, edit, or delete bookings. Existing bookings remain in the system. FIR staff can renew the authorization or manage bookings on their behalf.

**Q: Can a solo cert holder book positions they're not certified for?**  
A: Only if they meet the standard requirements (S2+ rating, correct division/subdivision). The solo cert only bypasses checks for the specific certified positions.

**Q: How long do authorizations last?**  
A: It depends on the expiration date set by FIR staff. Leave empty for permanent authorization, or set a specific datetime for temporary access.

**Q: Can controllers from other divisions book without authorization?**  
A: **No.** Controllers must either be in the CAR division OR have a visitor/solo authorization added by FIR staff.

**Q: What's the difference between "visitor" and "solo" authorization types?**  
A: 
- **Visitor:** For controllers from other divisions. Rating checks still apply (S2+).
- **Solo:** For training/certification on specific positions. **Rating checks bypassed** for those positions.

---

**Last Updated:** December 23, 2025  
**Maintained by:** VATCAR FIR Staff