# Live Traffic Integration - Feature Scoping & Definition
**Date:** January 5, 2026
**Status:** Planning/Definition Phase
**Priority:** Medium
**Related Features:** Real-time status (completed v1.3.0), Compliance tracking (completed v1.3.0)

---

## Executive Summary

Enhance the booking system by displaying **live network traffic** alongside scheduled bookings. This provides operational context, helps controllers see real-time airspace activity, and enables better coordination between scheduled bookings and actual network usage.

### Current State (v1.3.0)
- ✅ Real-time controller status in dashboards (15-second VATSIM data feed refresh)
- ✅ Compliance checking for booked controllers (on-time/late/no-show tracking)
- ✅ `get_vatsim_live_data()` method fetches live controller data
- ✅ `is_controller_logged_in()` checks if specific controller is online

### Proposed Enhancement
Display live traffic data (pilots, active controllers, ATIS) within the booking interface to provide operational awareness and context.

---

## Problem Statement

### Current Limitations
1. **No operational context:** Controllers see bookings but not current airspace activity
2. **Limited coordination visibility:** Can't easily see what other positions are booked at the same time
3. **Blind to current coverage:** No view of which positions are currently staffed
4. **No live/scheduled comparison:** Can't see if scheduled bookings actually materialized

### User Pain Points
- **Booking coordination:** Controllers can't see adjacent positions booked (e.g., TWR booked but no APP/DEP)
- **Operational awareness:** Staff unable to see real-time FIR coverage status at a glance
- **Walk-up sessions invisible:** No visibility into unscheduled (walk-up) controller sessions
- **Compliance verification:** Can't quickly verify if booked controllers actually logged in
- **Visitor experience:** Pilots/users can't see both future bookings and current coverage in one place

**Note:** With +2 hour minimum booking time, live traffic won't predict future traffic patterns, but helps with:
- Seeing coordination opportunities (other positions booked at same time)
- Operational awareness (current vs. scheduled coverage)
- Compliance monitoring (did booked controllers show up?)
Coordination Visibility:** Show what other positions are booked at the same time to enable better coordination
2. **Operational Awareness:** Display current FIR coverage status (live controllers vs. scheduled bookings)
3. **Unified View:** Single interface showing scheduled bookings AND current live state
4. **Compliance Monitoring:** Quick verification that booked controllers actually logged in

**Clarification on +2 Hour Minimum:**
Since bookings must be made ≥2 hours in advance, live traffic data serves operational awareness rather than booking-time traffic prediction. The key value is seeing **scheduled booking coordination** (e.g., "TWR is booked 20:00-23:00z, should I book APP/DEP for the same time?") rather than "is there traffic right now to justify booking."

### Primary Goals
1. **Operational Awareness:** Show live airspace activity alongside bookings
2. **Informed Scheduling:** Help controllers book based on current traffic patterns
3. **Unified View:** Single interface showing past/future bookings AND current state
4. **Traffic Insights:** Display pilot count, active controllers, coverage gaps

### Success Metrics
- Controllers report better booking decisions based on live data
- Reduced "empty bookings" (bookings when no traffic present)
- Increased user engagement with schedule page
- Positive feedback on operational awareness

---

## Functional Requirements

### FR-1: Live Controller Display
**Description:** Show currently online ATC positions within the FIR

**Components:**
- List of active controllers with callsign, CID, rating, online time
- Visual distinction between scheduled (booked) and unscheduled (walk-up) sessions
- Real-time updates (15-second refresh, matching existing compliance checks)
- Link online controller to their booking (if one exists)

**Data Source:** `https://data.vatsim.net/v3/vatsim-data.json` → `controllers[]` array

**Display Location Options:**
1. **Dedicated "Live Coverage" section** on schedule page (above or below booking table)
2. **Integrated column** in booking table showing "Currently Online" badge
3. **Separate shortcode** `[vatcar_live_traffic]` for flexible placement
4. **Dashboard widget** for FIR staff admin page

**Sample Data Structure:**
```json
{
  "cid": 1288763,
  "name": "John Doe",
  "callsign": "TNCC_TWR",
  "frequency": "118.700",
  "rating": 3,
  "logon_time": "2026-01-05T14:30:00Z",
  "server": "USA-EAST"
}Booking Coordination View (Priority)
**Description:** Show what other positions are booked at overlapping times

**Components:**
- When viewing/creating a booking for a specific time, highlight other positions booked during that period
- Visual grouping of complementary positions (e.g., GND+TWR+APP booked together)
- **Rating-filtered suggestions** - recommend booking complementary positions based on user's rating/authorization
- Timeline view showing booking density across day

**Use Case Example:**
- **Scenario A (User can book higher):**
  - S3 controller viewing booking form for 20:00-23:00z slot
  - System shows: "TNCC_TWR also booked 20:00-23:00z by John Doe"
  - System suggests: "Consider booking TNCC_APP for coordinated coverage" (upward suggestion)

- **Scenario B (User can only book lower):**
  - S1 controller viewing booking form for 20:00-23:00z slot
  - System shows: "TNCC_TWR also booked 20:00-23:00z by John Doe"
  - System suggests: "Consider booking TNCC_GND for coordinated coverage" (downward suggestion)
  - No APP/DEP suggestions shown (insufficient rating)

**Suggestion Filtering Logic:**
```
1. Check current user's rating via `get_controller_data($cid)['rating']`
2. Check if user is whitelisted with position-specific authorization
3. For each existing booking at selected time:
   a. Identify complementary positions (bidirectional: higher AND lower)
   b. Filter by: user_rating >= required_rating OR is_authorized_for_position()
   c. Exclude positions already booked
4. Display filtered suggestions (upward or downward as appropriate)
```

**Example Scenarios:**
- **S1 controller, TWR booked:** Suggest GND/DEL (coordinate downward - can book)
- **S1 controller, GND booked:** Suggest DEL/RMP (same tier)
- **S2 controller, GND booked:** Suggest TWR (coordinate upward - can book)
- **S2 controller, APP booked:** Suggest TWR (coordinate downward - can book)
- **S3 controller, TWR booked:** Suggest APP/DEP (coordinate upward - can book)
- **S3 controller, GND booked:** Suggest TWR or APP/DEP (coordinate upward)
- **C1 controller, APP booked:** Suggest TWR (downward), CTR/FSS (upward)
- **Visitor with TNCC_APP authorization only:** Suggest APP only if complementary, regardless of what's booked

**Display Integration:**
- Add to booking form (real-time as user selects time)
- Enhanced schedule table with "coordination group" visual indicators
- Dashboard widget showing "booking clusters" (multiple positions same time)
- Suggestions styled as actionable buttons: "Book TNCC_APP 20:00-23:00z"

### FR-3: Live Pilot Traffic Display (Lower Priority)
**Description:** Show current aircraft within FIR airspace

**Components:**
- Total pilot count within FIR boundaries
- Breakdown by airport (departures/arrivals)
- Departure/arrival/overflying counts
- Optional: Aircraft callsigns, types, origins/destinations

**Limitation:** Due to +2 hour minimum booking time, this data provides operational awareness but does NOT predict future traffic for booking decisions.

**Primary Use Cases:**
- FIR staff monitoring current operations
- Controllers checking if their session had actual traffic (post-session)
- Website visitors seeing current activity
- Breakdown by airport (departures/arrivals)
- Departure/arrival/overflying counts
- Optional: Aircraft callsigns, types, origins/destinations

**Data Source:** `vatsim-data.json` → `pilots[]` array

**Filtering Logic:**
- Filter by latitude/longitude bounds for FIR (current AND scheduled)

**Components:**
- **Current Coverage:** Visual indicator of positions currently online
- **Scheduled Coverage:** Show which positions are booked for upcoming time blocks
- **Coordination Gaps:** Highlight when complementary positions are missing (e.g., "TWR booked 20:00z but no APP")
- Timeline showing coverage progression (current → next 6 hours 5 arr, 3 overfly)"
2. **Detailed list:** Callsign, aircraft type, origin → destination, altitude, controller
3. **Traffic heatmap:** Visual representation of busy times (requires historical data)

### FR-3: ATIS Information
**Description:** Display current ATIS for FIR airports

**Components:**
- ATIS text/code for major airports
- Active runways
- Weather info (if available)
- Last update timestamp

**Data Source:** `vatsim-data.json` → `atis[]` array

**Display:** 
- Collapsible section per airport
- "No ATIS online" state when not broadcast
- Link to online ATIS controller (if separate position)

### FR-4: Coverage Status Visualization
**Description:** At-a-glance view of FIR coverage status

**Components:**
- Visual indicator: "Fully Staffed", "Partial Coverage", "Unstaffed"
- Position checklist (e.g., GND, TWR, APP, CTR) with online/offline status
- "Coverage gaps" warning (e.g., "TWR booked but APP needed")

**Logic:**
- Green: All major positions staffed
- Yellow: Some positions staffed
- Red: No coverage
- Consider: Scheduled bookings in near future (next 30 min)
Booking Patterns (Revised Scope)
**Description:** Show booking trends to inform coordination decisions

**Components:**
- "Typical coverage for this time" - which positions are usually booked together
- Historical booking clusters (e.g., "Friday evenings usually have TWR+APP")
- Booking density heatmap (day/hour showing how many positions booked)

**Data Source:** 
- Query existing `wp_atc_bookings` table (no new data storage needed)
- Aggregate by time slots (e.g., 3-hour blocks)
- Show patterns from last 30-90 days

**Display:**
- Heatmap showing "booking frequency" by position and time
- Recommendation: "Friday 20:00z typically has 2-3 positions staffed - consider booking for better coordination"
- "Complementary bookings" - controllers who frequently book together

**Note:** Removed pilot traffic prediction due to +2 hour minimum - focus on booking coordination patterns instead.ding booking"
- Or: "Low traffic expected, consider shorter session"

---

## Technical Architecture

### Component Breakdown

#### 1. Data Fetching Layer
**New Class:** `VatCar_Live_Traffic` (file: `includes/class-live-traffic.php`)

**Methods:**
```php
// Fetch live data with caching
public static function get_live_controllers($fir_subdivision = 'CUR');
public static function get_live_pilots($fir_subdivision = 'CUR');
public static function get_live_atis($airport_icao);

// Analysis methods
public static function get_coverage_status($fir_subdivision = 'CUR');
public static function get_pilot_count_by_airport($fir_subdivision = 'CUR');
public static function is_position_currently_staffed($callsign);
```

**Caching Strategy:**
- Cache live data for 15 seconds (WordPress transients)
- Key: `vatcar_live_data_v3_{timestamp_chunk}`
- Aligns with existing compliance check refresh rate
- Prevents excessive API calls

#### 2. Database Schema (Optional, for historical data)
```sql
CREATE TABLE {prefix}atc_traffic_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subdivision VARCHAR(10) NOT NULL,
  timestamp DATETIME NOT NULL,
  pilot_count INT NOT NULL,
  controller_count INT NOT NULL,
  positions_online TEXT, -- JSON array of callsigns
  INDEX idx_subdivision_time (subdivision, timestamp)
);
```

**Logging Schedule:** 
- WP Cron job every 15 minutes
- Store snapshot of pilot/controller counts
- Purge data older than 90 days

#### 3. Display Components

**Option A: Shortcode-Based**
```php
[vatcar_live_traffic type="controllers"]
[vatcar_live_traffic type="pilots"]
[vatcar_live_traffic type="coverage"]
[vatcar_live_traffic type="all"] // Combined view
```

**Option B: Integrated Into Schedule**
- Modify `VatCar_ATC_Schedule::render_table()`
- Add "Live Status" section above/below booking table
- Toggle visibility with JS expand/collapse

**Option C: Dashboard Widget**
- Add to admin dashboard (`VatCar_ATC_Dashboard`)
- FIR staff see live status at top of page
- Real-time updates via AJAX

#### 4. Frontend JavaScript
**File:** `assets/js/live-traffic.js`

**Functionality:**
- Auto-refresh every 15 seconds (AJAX call)
- Update pilot/controller counts without page reload
- Visual animations for status changes
- "Last updated X seconds ago" timestamp

#### 5. Settings Integration
**New Settings Section:** "Live Traffic Settings"

**Options:**
- Enable/disable live traffic display
- Select display location (schedule, dashboard, both)
- Configure FIR boundary coordinates (for pilot filtering)
- Enable historical traffic logging
- Set traffic update interval (default 15s)

---

## User Interface Mockups

### Mockup 1: Schedule Page with Live Traffic Section
```
┌─────────────────────────────────────────────────────────────┐
│ ATC Booking Schedule - Curaçao FIR                          │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│ ┌─── LIVE COVERAGE STATUS ───────────────────────────────┐  │
│ │ 🟢 Partial Coverage  │  Last updated: 3 seconds ago     │  │
│ │                                                           │  │
│ │ Active Controllers (2):                                   │  │
│ │  • TNCC_TWR - John Doe (S2) - Online 1h 23m              │  │
│ │    └─ Frequency: 118.700  │  Has booking until 23:00z   │  │
│ │  • TNCC_APP - Jane Smith (S3) - Online 45m               │  │
│ │    └─ Frequency: 119.300  │  Walk-up session            │  │
│ │                                                           │  │
│ │ Current Traffic: 8 aircraft in TNCC airspace             │  │
│ │  └─ 3 departures, 4 arrivals, 1 overflying              │  │
│ │                                                           │  │
│ │ [View Detailed Traffic ▼]                                │  │
│ └───────────────────────────────────────────────────────────┘  │
│                                                               │
│ Upcoming Bookings:                                           │
│ ┌──────────┬────────────┬──────────┬─────────┬─────────┐   │
│ │ Callsign │ Controller │ Start    │ End     │ Status  │   │
│ ├──────────┼────────────┼──────────┼─────────┼─────────┤   │
│ │ TNCC_TWR │ John Doe   │ 20:00z   │ 23:00z  │ 🟢 LIVE │   │
│ │ TNCC_APP │ Jane Smith │ 21:00z   │ 23:30z  │ Upcoming│   │
│ └──────────┴────────────┴──────────┴─────────┴─────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Mockup 2: Dashboard Widget (FIR Staff)
```
┌─────────────────────────────────────────────────────┐
│ ATC Bookings Dashboard                              │
├─────────────────────────────────────────────────────┤
│                                                       │
│ ┌─ LIVE FIR STATUS ─────────────┐  [New Booking]   │
│ │ 🟢 2 positions online          │                   │
│ │ 8 pilots in airspace           │                   │
│ │                                 │                   │
│ │ Position Coverage:              │                   │
│ │  GND  ⚫ Offline                │                   │
│ │  TWR  🟢 ONLINE (1h 23m)       │                   │
│ │  APP  🟢 ONLINE (45m)          │                   │
│ │  CTR  ⚫ Offline                │                   │
│ │                                 │                   │
│ │ Next booking: TNCC_GND @ 22:00z│                   │
│ └─────────────────────────────────┘                   │
│                                                       │
│ All Bookings:                                        │
│ [...booking table...]                                │
└─────────────────────────────────────────────────────┘
```

### Mockup 3: Live Traffic Detail View (Expandable)
```
┌─────────────────────────────────────────────────────┐
│ LIVE TRAFFIC DETAILS                   [Collapse ▲] │
├─────────────────────────────────────────────────────┤
│                                                       │
│ Aircraft in TNCC Airspace (8):                       │
│ ┌─────────┬────────┬───────┬───────────┬─────────┐ │
│ │ Callsign│ Type   │ From  │ To        │ Altitude│ │
│ ├─────────┼────────┼───────┼───────────┼─────────┤ │
│ │ AAL123  │ B738   │ KMIA  │ TNCC      │ 3,500ft │ │
│ │ DAL456  │ A320   │ TNCC  │ KATL      │ 8,200ft │ │
│ │ UAL789  │ B737   │ KEWR  │ TNCM      │FL350    │ │
│ │ ...     │ ...    │ ...   │ ...       │ ...     │ │
│ └─────────┴────────┴───────┴───────────┴─────────┘ │
│                                                       │
│ ATIS Information:                                    │
│ ┌─ TNCC ─────────────────────────────────────────┐ │
│ │ Code: Delta                                      │ │
│ │ Runway: 10 (Active)                              │ │
│ │ Wind: 090/12KT                                   │ │
│ │ "Curaçao Airport Information Delta..."          │ │
│ └──────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

---

## Implemenooking coordination visibility + basic live coverage display

**Priority A Tasks (Booking Coordination - High Value):**
1. Add "Other bookings at this time" display in booking form
2. Implement rating-based suggestion filtering logic
3. Generate complementary position suggestions (filtered by user rating/authorization)
4. Highlight overlapping position bookings in schedule table
5. Visual grouping of coordinated bookings (same time block)

**Priority B Tasks (Live Coverage - Operational Awareness):**
5. Create `VatCar_Live_Traffic` class with caching
6. Add `get_live_controllers()` method (filter by FIR)
7. Display active controllers with online time and frequency
8. Link online controllers to their bookings (if exist)
9. Add "LIVE" badge to booking table for currently online sessions

**Goal:** Timeline view and advanced suggestions

**Tasks:**
1. Timeline visualization showing booking density across day
2. "Coverage clusters" highlighting coordinated sessions
3. Smart suggestion algorithm: analyze typical position pairings (TWR often with APP)
4. Booking form real-time preview: "2 other positions booked at this time"
5. Coverage status indicator (green/yellow/red for scheduled bookings)
6. "Quick book" buttons from suggestions (pre-fill form with suggested position/time)ime"
5. Coverage status indicator (green/yellow/red for scheduled bookings)

**Effort:** 6-8 hours
**Complexity:** Medium (timeline visualization)
**Dependencies:** Phase 1 complete

### Phase 3: Live Pilot Traffic (v1.6.0) - Optional
**Goal:** Operational awareness for current sessions

**Tasks:**
1. Add `get_live_pilots()` with FIR boundary filtering
2. Display pilot count and departure/arrival breakdown
3. Add collapsible detailed pilot list (optional)
4. ATIS information display
5. Link pilots to their assigned controller (if applicable)

**Effort:** 4-6 hours
**Complexity:** Medium (FIR boundary logic)
**Dependencies:** Phase 1 complete
**Note:** Lower priority due to +2hr booking minimum - provides operational awareness, not booking decision data

### Phase 4: Historical Booking Patterns (v1.7.0)
**Goal:** Booking coordination insights and trends

**Tasks:**
1. Query historical bookings from existing `atc_bookings` table
2. Calculate "typical coverage patterns" by day/hour
3. Display "controllers who book together" patterns
4. Heatmap showing booking density (positions × time)
5. Recommendations: "Friday 20:00z typically has TWR+APP - consider DEP for full coverage"

**Effort:** 8-10 hours
**Complexity:** Medium-High (data analysis, charting)
**Dependencies:** Phase 1 & 2 complete
**Note:** Focuses on booking coordination patterns, not pilot traffic (due to +2hr minimum)able
2. WP Cron job for 15-minute traffic snapshots
3. Historical query methods (avg pilots by hour/day)
4. Chart/graph display for traffic patterns
5. "Busy time" recommendations in booking form

**Effort:** 10-12 hours
**Complexity:** High (database design, charting library)
**Dependencies:** Phase 1 & 2 complete

### Phase 4: Advanced Features (v1.7.0+)
**Goal:** Enhanced visualization and coordination

**Tasks:**
1. Real-time AJAX updates without page reload
2. Audio/visual notifications for coverage changes
3. "Request backup" feature (notify other controllers)
4. Traffic heatmap visualization
5. Mobile-optimized traffic view
6. Export traffic reports (CSV/PDF)

**Effort:** 15-20 hours
**Complexity:** High
**Dependencies:** All previous phases

---

## Data Sources & APIs

### VATSIM Data Feed v3
**Endpoint:** `https://data.vatsim.net/v3/vatsim-data.json`
**Update Frequency:** ~15 seconds
**Size:** ~500KB - 2MB (varies with network traffic)

**Relevant Fields:**
```json
{
  "controllers": [
    {
      "cid": 1234567,
      "name": "John Doe",
      "callsign": "TNCC_TWR",
      "frequency": "118.700",
      "facility": 4,  // 2=DEL, 3=GND, 4=TWR, 5=APP, 6=CTR
      "rating": 3,    // 1=OBS, 2=S1, 3=S2, 4=S3, 5=C1, etc.
      "server": "USA-EAST",
      "visual_range": 100,
      "text_atis": ["ATIS INFO..."],
      "last_updated": "2026-01-05T12:34:56Z",
      "logon_time": "2026-01-05T10:00:00Z"
    }
  ],
  "pilots": [
    {
      "cid": 7654321,
      "name": "Jane Pilot",
      "callsign": "AAL123",
      "server": "USA-EAST",
      "pilot_rating": 0,
      "latitude": 12.1889,
      "longitude": -68.9597,
      "altitude": 3500,
      "groundspeed": 180,
      "heading": 90,
      "flight_plan": {
        "aircraft": "B738",
        "departure": "KMIA",
        "arrival": "TNCC",
        "altitude": "9000",
        "route": "...",
        "remarks": "..."
      },
      "logon_time": "2026-01-05T11:30:00Z",
      "last_updated": "2026-01-05T12:34:56Z"
    }
  ],
  "atis": [
    {
      "cid": 1234567,
      "name": "John Doe",
      "callsign": "TNCC_ATIS",
      "frequency": "127.750",
      "facility": 1,
      "rating": 3,
      "server": "USA-EAST",
      "text_atis": [
        "ATIS INFO DELTA",
        "RWY IN USE 10",
        "WIND 090/12KT",
        "..."
      ],
      "logon_time": "2026-01-05T10:00:00Z",
      "last_updated": "2026-01-05T12:34:56Z"
    }
  ]
}
```

### FIR Boundary Filtering
**Curaçao FIR (TNCC):**
- Airports: TNCC, TNCE, TNCB, TNCM
- Approximate boundaries:
  - North: 13.5°N
  - South: 11.5°N
  - East: -67.5°W
  - West: -70.0°W

**Filtering Methods:**
1. **Airport-based:** Check `flight_plan.departure` or `flight_plan.arrival` matches FIR airports
2. **Position-based:** Check `latitude`/`longitude` within FIR boundaries
3. **Hybrid:** Include both (more accurate)

---

## Configuration & Settings

### New Plugin Settings Section
**Page:** Settings → VATCAR ATC Bookings → "Live Traffic" tab

**Settings:**

1. **Enable Live Traffic Display** (checkbox)
   - Default: Enabled
   - Description: "Show live controller and pilot traffic on schedule page"

2. **Display Location** (radio buttons)
   - Options: "Schedule Page Only", "Dashboard Only", "Both"
   - Default: Both

3. **FIR Boundary Coordinates** (text fields)
   - North Latitude: [input]
   - South Latitude: [input]
   - East Longitude: [input]
   - West Longitude: [input]
   - Default: Pre-populated based on `vatcar_detect_subdivision()`

4. **Update Interval** (select)
   - Options: 10s, 15s, 30s, 60s
   - Default: 15s
   - Warning: "Lower intervals increase server load"

5. **Show Pilot Details** (checkbox)
   - Default: Disabled
   - Description: "Display full pilot list with callsigns and routes (may slow page)"

6. **Enable Historical Traffic Logging** (checkbox - Phase 3)
   - Default: Disabled
   - Description: "Store traffic snapshots for pattern analysis (requires database space)"

7. **Historical Data Retention** (select - Phase 3)
   - Options: 30 days, 60 days, 90 days, 180 days
   - Default: 90 days

---

## Performance Considerations

### Caching Strategy
- **15-second cache** using WordPress transients
- **Transient key:** `vatcar_live_traffic_{subdivision}_{chunk}` where chunk = `floor(time() / 15)`
- **Cache bypass:** Optional URL param `?nocache=1` for debugging
- **Cache invalidation:** Automatic after 15 seconds

### API Call Optimization
- **Single API call** per 15-second window (shared across all page loads)
- **Fallback:** Show cached data with "stale data" warning if API fails
- **Timeout:** 10 seconds (match existing VATSIM API calls)

### Database Impact (Phase 3)
- **Traffic log inserts:** Every 15 minutes via WP Cron (~96 rows/day)
- **Storage estimate:** ~50 bytes/row × 96/day × 90 days = ~430 KB per FIR
- **Index strategy:** Composite index on `(subdivision, timestamp)` for fast queries
- **Auto-cleanup:** WP Cron daily job to purge old records

### Frontend Performance
- **AJAX updates:** Lightweight JSON responses (~5-10 KB)
- **Conditional rendering:** Only fetch pilot details if section expanded
- **Lazy loading:** Charts/graphs loaded on-demand (Phase 3)
- **Mobile optimization:** Hide detailed lists on small screens, show summary only
- **"I want to see what other positions are booked at the same time I'm booking"** → Coordination (PRIMARY USE CASE)
- **"If TWR is booked, I want to book APP/DEP for the same time to provide full coverage"** → Complementary booking
- "I want to know which positions are currently staffed right now" → Operational awareness
- "I want to see if controllers who booked actually logged in" → Compliance/coordination
- ~~"I want to see if there's current traffic before booking"~~ → NOT APPLICABLE (due to +2hr minimum booking time)
## Security & Privacy

### Data Exposure
- ✅ **Public data only:** All VATSIM data is publicly available
- ✅ **No PII:** CIDs and names already public on VATSIM network
- ✅ **No authentication required:** Live data visible to all site visitors

### API Key Protection
- ✅ **Not required:** VATSIM live data feed is public, no auth needed
- ✅ **Existing booking API:** Still protected with key (separate endpoint)

### Rate Limiting
- ✅ **15-second cache:** Prevents excessive API calls
- ✅ **No user-triggered calls:** Updates automatic, not tied to user actions
- ✅ **Graceful degradation:** Show cached data if API unavailable

---

## User Stories

### As a Controller...
- "I want to see if there's current traffic before booking a session" → Informed scheduling
- "I want to know which positions are currently staffed" → Better coordination
- "I want to see if my booking time typically has traffic" → Optimize session length

### As FIR Staff...
- "I want to monitor real-time FIR coverage at a glance" → Operational oversight
- "I want to see coverage gaps and unscheduled sessions" → Resource management
- "I want traffic reports to plan events" → Event coordination

### As a Pilot/Website Visitor...
- "I want to see current ATC coverage before flying" → Flight planning
- "I want to know if my destination has ATC" → Route decisions
- "I want to see both current and upcoming coverage" → Timing decisions

---

## Open Questions & Decisions Needed

### 1. Display Location
**Question:** Where should live traffic appear by default?
**Options:** (Revised)
**Question:** Should we log historical pilot traffic or focus on booking patterns?
**Options:**
- A) Log pilot traffic (less useful due to +2hr booking minimum)
- B) Use existing booking data for coordination patterns (more useful)
- C) Both (comprehensive but more complex)

**Recommendation:** Option B (booking patterns only - pilot traffic doesn't inform future bookings
### 2. Pilot Detail Level
**Question:** How much pilot info should we show?
**Options:**
- A) Count only ("8 aircraft")
- B) Count + breakdown ("3 dep, 4 arr, 1 overfly")
- C) Full list with callsigns and routes
- D) User preference/expandable sections

**Recommendation:** Option D (progressive disclosure)

### 3. Historical Data
**Question:** Should we log historical traffic in Phase 1 or defer to Phase 3?
**Options:**
- A) Start logging immediately (Phase 1) even if not displayed yet
- B) Wait until Phase 3 when charts/analysis ready
- C) Optional: Let admins enable early if desired

**Recommendation:** Option C (add setting in Phase 1, display in Phase 3)

### 4. Mobile Experience
**Question:** How should live traffic display on mobile?
**Options:**
- A) Full display (may be cramped)
- B) Summary only (collapsible for details)
- C) Separate mobile view
- D) Hidden by default, show button to expand

**Recommendation:** Option B (responsive with summaries)

### 5. ATIS Display
**Question:** ATIS text can be long - how to display?
**Options:**
- A) Full text always visible
- B) Collapsible per-airport sections
- C) Modal popup when clicked
- D) Separate ATIS page

**Recommendation:** Option B (collapsible sections)

---

## Success Criteria

### Phase 1 (Basic Live Traffic)
- ✅ Live controller list displays correctly
- ✅ Online controllers linked to bookings (if exist)
- ✅ "LIVE" badge shows on booking table for online controllers
- ✅ Data updates every 15 seconds without page reload
- ✅ No performance degradation (page load < 2s)

### Phase 2 (Pilot Traffic)
- ✅ Pilot count accurate (matches VATSIM network stats)
- ✅ Departure/arrival breakdown correct
- ✅ ATIS information displays when available
- ✅ Coverage status indicator reflects actual staffing

### Phase 3 (Historical Patterns)
- ✅ Traffic logging runs reliably (no missed snapshots)
- ✅ Historical queries performant (< 100ms)
- ✅ Traffic charts render correctly
- ✅ Booking recommendations helpful (user feedback)

### Overall Success
- 📈 Increased schedule page engagement (time on page +20%)
- 📈 More informed booking decisions (fewer empty bookings)
- 😊 Positive user feedback (survey or comments)
- 🚀 No increase in support requests related to confusion

---

## Risks & Mitigation

### Risk 1: VATSIM API Changes
**Impact:** High
**Probability:** Low
**Mitigation:** 
- Follow VATSIM API changelog
- Implement graceful degradation (show cached data if API changes)
- Add error logging to detect breaking changes early

### Risk 2: Performance Impact
**Impact:** Medium
**Probability:** Medium
**Mitigation:**
- Aggressive caching (15-second transients)
- Optional features (pilot details, ATIS) loaded on-demand
- Database indexes for historical queries

### Risk 3: User Overwhelm
**Impact:** Medium
**Probability:** Low
**Mitigation:**
- Progressive disclosure (collapsible sections)
- Clear visual hierarchy
- Mobile-friendly summary views
- User preference to hide live traffic

### Risk 4: Stale Data
**Impact:** Low
**Probability:** Medium
**Mitigation:**
- Display "Last updated X seconds ago" timestamp
- Visual indicator if data is stale (> 30 seconds old)
- Fallback to "Unable to load live data" message

---

## Dependencies

### External Dependencies
- ✅ VATSIM Data Feed v3 (public, no auth required)
- ✅ Existing WordPress installation
- ✅ Transient API (WordPress core)
- ❓ JavaScript charting library (Phase 3 - e.g., Chart.js)

### Internal Dependencies
- ✅ Existing `VatCar_ATC_Booking::get_vatsim_live_data()` method
- ✅ Existing caching pattern (can reuse/extend)
- ✅ Existing schedule page structure
- ✅ Existing dashboard layout

### Theme Dependencies
- ❓ Sidebar support (if using widget display option)
- ✅ No theme modifications required for shortcode-based approach

---

## Alternatives Considered

### Alternative 1: External Service Integration
**Description:** Use existing VATSIM stats website (e.g., vatsim-stats.com)
**Pros:** No development needed, maintained by third party
**Cons:** No integration, users leave site, less control
**Decision:** ❌ Rejected - want integrated experience

### Alternative 2: Iframe Embed
**Description:** Embed external traffic widget via iframe
**Pros:** Quick to implement, no maintenance
**Cons:** Poor UX, no styling control, external dependency
**Decision:** ❌ Rejected - not cohesive with plugin

### Alternative 3: Real-time WebSocket
**Description:** Use WebSocket for instant updates (< 1 second)
**Pros:** True real-time updates
**Cons:** Complex infrastructure, server resources, overkill for need
**Decision:** ❌ Rejected - 15-second updates sufficient

### Alternative 4: Browser Notification API
**Description:** Push notifications when coverage changes
**Pros:** Keeps users informed even when not on page
**Cons:** Permission prompts, battery drain, potential annoyance
**Decision:** 🤔 Consider for Phase 4 (opt-in feature)

---

## Recommended Next Steps

### Immediate (Before Implementation)
1. ✅ **Review this document** with stakeholders
2. 📋 **Answer open questions** (display location, detail level, etc.)
3. 🎨 **Create UI mockups** (high-fidelity designs)
4. 📝 **Write user acceptance criteria** (define "done")

### Phase 1 Implementation (v1.5.0)
1. Create feature branch: `feature/live-traffic-integration`
2. Implement `VatCar_Live_Traffic` class with caching
3. Add basic controller list display
4. Link online controllers to bookings
5. Add "LIVE" badge to schedule table
6. Test with multiple FIR subdivisions
7. Security audit (using same process as admin book-on-behalf)
8. Create PR and deploy to dev

### Future Phases
- Phase 2: After Phase 1 user feedback (1-2 weeks)
- Phase 3: After Phase 2 validated (1 month)
- Phase 4: Evaluate need based on usage data

---

## Appendix: Code Snippets

### Sample `VatCar_Live_Traffic` Class Structure
```php
class VatCar_Live_Traffic {
    
    const CACHE_DURATION = 15; // seconds
    
    /**
     * Get live controllers for a specific FIR
     */
    public static function get_live_controllers($subdivision = 'CUR') {
        $cache_key = 'vatcar_live_controllers_' . $subdivision . '_' . floor(time() / self::CACHE_DURATION);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch fresh data
        $all_controllers = VatCar_ATC_Booking::get_vatsim_live_data();
        if (is_wp_error($all_controllers)) {
            return $all_controllers;
        }
        
        // Filter by FIR callsign prefix
        $fir_prefix = self::get_fir_prefix($subdivision);
        $fir_controllers = array_filter($all_controllers, function($c) use ($fir_prefix) {
            return strpos($c['callsign'], $fir_prefix) === 0;
        });
        
        set_transient($cache_key, $fir_controllers, self::CACHE_DURATION);
        return $fir_controllers;
    }
    
    /**
     * Get overlapping bookings for a given time range
     * @param string $start Start datetime (Y-m-d H:i:s)
     * @param string $end End datetime (Y-m-d H:i:s)
     * @param string $subdivision FIR subdivision
     * @return array Array of booking objects
     */
    public static function get_overlapping_bookings($start, $end, $subdivision = 'CUR') {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE subdivision = %s 
             AND (
                 (start <= %s AND end >= %s) OR
                 (start >= %s AND start < %s)
             )
             ORDER BY start ASC",
            $subdivision, $start, $start, $start, $end
        ));
        
        return $bookings ?: [];
    }
    Suggests both higher AND lower positions based on what user can book
     * @param string $cid User's CID
     * @param array $existing_bookings Already booked positions at this time
     * @return array Array of suggested callsigns with reasons
     */
    public static function get_complementary_suggestions($cid, $existing_bookings) {
        // Get user's rating and authorization
        $controller_data = VatCar_ATC_Booking::get_controller_data($cid);
        if (is_wp_error($controller_data)) {
            return [];
        }
        
        $user_rating = isset($controller_data['rating']) ? intval($controller_data['rating']) : 0;
        $whitelist_entry = VatCar_ATC_Booking::get_whitelist_entry($cid);
        
        // Define bidirectional complementary position pairs
        // Each position maps to positions that would complement it (higher AND lower)
        $complementary_pairs = [
            'DEL' => ['GND', 'RMP', 'TWR'],              // DEL complements GND/RMP (peer) or TWR (up)
            'GND' => ['DEL', 'RMP', 'TWR'],              // GND complements DEL/RMP (peer) or TWR (up)
            'RMP' => ['DEL', 'GND', 'TWR'],              // RMP complements DEL/GND (peer) or TWR (up)
            'TWR' => ['GND', 'DEL', 'RMP', 'APP', 'DEP'], // TWR complements GND/DEL/RMP (down) or APP/DEP (up)
            'APP' => ['TWR', 'DEP', 'CTR'],              // APP complements TWR (down), DEP (peer), CTR (up)
            'DEP' => ['TWR', 'APP', 'CTR'],              // DEP complements TWR (down), APP (peer), CTR (up)
            'CTR' => ['APP', 'DEP', 'FSS'],              // CTR complements APP/DEP (down), FSS (peer)
            'FSS' => ['APP', 'DEP', 'CTR'],              // FSS complements APP/DEP (down), CTR (peer)
        ];
        
        $suggestions = [];
        $subdivision = vatcar_detect_subdivision();
        $airports = vatcar_get_subdivision_airports($subdivision);
        
        // For each existing booking, find complementary positions user can book
        foreach ($existing_bookings as $booking) {
            $parts = explode('_', $booking->callsign);
            $suffix = strtoupper(end($parts));
            
            if (!isset($complementary_pairs[$suffix])) {
                continue;
            }
            
            foreach ($complementary_pairs[$suffix] as $suggested_suffix) {
                foreach ($airports as $airport) {
                    $suggested_callsign = $airport . '_' . $suggested_suffix;
                    
                    // Check if position already booked
                    $already_booked = false;
                    foreach ($existing_bookings as $check) {
                        if ($check->callsign === $suggested_callsign) {
                            $already_booked = true;
                            break;
                        }
                    }
                    if ($already_booked) continue;
                    
                    // KEY: Check if user has required rating or authorization
                    $required_rating = VatCar_ATC_Booking::get_position_required_rating($suggested_callsign);
                    $has_rating = ($user_rating >= $required_rating);
                    $has_authorization = VatCar_ATC_Booking::is_authorized_for_position($cid, $suggested_callsign);
                    
                    // Only suggest if user is eligible
                    if ($has_rating || $has_authorization) {
                        $direction = ($required_rating > VatCar_ATC_Booking::get_position_required_rating($booking->callsign)) 
                            ? 'upward' : 'downward';
                        
                        $suggestions[] = [
                            'callsign' => $suggested_callsign,
                            'reason' => "Complement {$booking->callsign}",
                            'booked_by' => $booking->controller_name ?? 'Unknown',
                            'direction' => $direction,
                        ];
                    }
                    // If not eligible, silently skip (don't frustrate user with unavailable suggestions)       'reason' => "Complement {$booking->callsign}",
                            'booked_by' => $booking->controller_name ?? 'Unknown',
                        ];
                    }
                }
            }
        }
        
        return array_unique($suggestions, SORT_REGULAR);
    }
    
    /**
     * Get coverage status
     */
    public static function get_coverage_status($subdivision = 'CUR') {
        $controllers = self::get_live_controllers($subdivision);
        if (is_wp_error($controllers) || empty($controllers)) {
            return 'unstaffed';
        }
        
        // Count unique positions
        $positions = array_unique(array_map(function($c) {
            return $c['callsign'];
        }, $controllers));
        
        if (count($positions) >= 3) {
            return 'full'; // Green
        } elseif (count($positions) >= 1) {
            return 'partial'; // Yellow
        } else {
            return 'unstaffed'; // Red
        }
    }
    
    /**
     * Link online controller to booking
     */
    public static function get_booking_for_controller($cid, $callsign) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $now = gmdate('Y-m-d H:i:s');
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE cid = %s AND callsign = %s AND start <= %s AND end >= %s",
            $cid, $callsign, $now, $now
        ));
        
        return $booking;
    }
}
```

### Sample Shortcode Implementation
```php
function vatcar_live_traffic_shortcode($atts) {
    $atts = shortcode_atts([
        'type' => 'all', // controllers, pilots, coverage, all
        'subdivision' => vatcar_detect_subdivision(),
    ], $atts);
    
    $controllers = VatCar_Live_Traffic::get_live_controllers($atts['subdivision']);
    $pilots = VatCar_Live_Traffic::get_live_pilots($atts['subdivision']);
    
    ob_start();
    include plugin_dir_path(__FILE__) . '../templates/live-traffic.php';
    return ob_get_clean();
}
add_shortcode('vatcar_live_traffic', 'vatcar_live_traffic_shortcode');
```

---

**End of Scoping Document**

**Next Review Date:** TBD after stakeholder review
**Document Owner:** VATCAR Development Team
**Version:** 1.0 (Initial Draft)
