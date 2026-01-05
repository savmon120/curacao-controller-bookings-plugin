# roadmap.md

## Completed Features
[x] Validating against controller division and rating to allow booking
[x] Manage dashboard bookings as FIR staff (admin dashboard)
[x] Remove bookings after end date (now: preserve for compliance, filter from frontend)
[x] Use VATSIM data API to validate controller logged on for their booking
[x] Controller name on booking using WordPress name
[x] Whitelisting visitors and solo certs to enable booking of stations
[x] Position-specific authorization for whitelisted controllers
[x] Expiration dates and renewal for whitelist entries
[x] Auto-populate controller names from WordPress accounts
[x] **Position-based rating restrictions** - Graduated rating requirements per position type (S1=GND/RMP/DEL, S2=TWR, S3=APP/DEP, C1=CTR/FSS)
[x] **Controller personal dashboard** - View personal bookings, compliance statistics, authorization status ([vatcar_my_bookings] shortcode)
[x] **Controller widget** - Sidebar widget showing next booking and quick stats
[x] **Compliance history tracking** - Automatic 15-minute checks for on-time, early, late, and no-show status
[x] **Real-time login status** - Live VATSIM data feed integration (15-second refresh) for dashboard status display

## Planned Features

### High Priority
[] **Filter booking form dropdown** - Show only authorized positions for whitelisted controllers in dropdown
[] **Booking conflict warnings** - Display existing bookings when selecting time/position (real-time conflict detection)
[] **Automatic station discovery** - Set FIR in settings and fetch stations automatically (investigate VATSIM API capability)
[] **Enhanced mobile responsiveness** - Optimize booking form and dashboard layouts for mobile devices

### Medium Priority
[] **Reminder notifications** - Email/Discord pings for upcoming bookings (configurable lead time)
[] **Audit trail logging** - Track all whitelist controller entry additions, removals, and renewals
[] **Booking templates** - Save common booking patterns (e.g., "Friday evening TNCC_TWR 2000-2300z")
[] **Edit booking from dashboard** - Allow controllers to edit their own bookings directly from personal dashboard (currently redirect to form)

### UX Improvements (Post v1.3.0)
[] **Booking form as modal** - Option to open booking form in modal instead of page redirect
   - Pros: No context switching, instant updates, modern UX
   - Cons: Form complexity in modal, mobile constraints, requires AJAX conversion
   - Consider as user preference or feature flag after v1.3.0 feedback
   
[] **Post-booking redirect options** - Configurable redirect destination after creating booking
   - Current: Redirects to public schedule (operational awareness)
   - Option A: Redirect to personal dashboard (immediate confirmation)
   - Option B: Stay on public schedule with success banner + "View My Dashboard" link
   - Long-term: User preference "After booking, show me: [Public Schedule] [My Dashboard]"

### Low Priority / Future Consideration
[] **Email confirmations** - Send booking confirmation emails to controllers
[] **Discord webhook integration** - Post new bookings and compliance alerts to Discord channels
[] **FIR statistics dashboard** - Busiest times, most popular positions, booking frequency trends
[] **Controller performance reports** - Detailed on-time percentage reports, booking consistency metrics
[] **Calendar grid view** - Month/week calendar view with visual timeline for bookings

## Known Issues / Bug Fixes Needed
[x] **Dashboard status display bug** - Past bookings in ATC Bookings dashboard show "no show" even when compliance table has "on_time" records
   - Root cause: Dashboard queries real-time VATSIM status instead of using historical compliance data
   - Fix approach: Check compliance table first for past bookings, only query live data for current/upcoming bookings
   - Priority: Medium (display issue only, doesn't affect data integrity)
   - Affected: FIR staff dashboard (`includes/class-dashboard.php`)

## Feature Requests / Ideas
[] **Admin book on behalf of controller** - Allow FIR staff to create bookings for other controllers
   - Use case: Staff scheduling, coverage coordination, event planning
   - Implementation: Add CID selection dropdown for admins in booking form, validate target controller eligibility
   - Related: Admins can already edit/delete others' bookings (v1.4.0), this would complete the admin booking management suite
   
[] **Recurring bookings** - Set up repeating bookings (e.g., every Friday 1800-2200z for a month)
[] **Position availability forecast** - Show busy/quiet times based on historical data
[] **Live traffic integration** - Display current network traffic alongside scheduled bookings
[] **Achievement badges** - Recognition system for consistent, reliable controllers
[] **Advanced calendar features** - Drag-to-book interface, visual conflict detection, multi-day view