# roadmap.md

## Completed Features
[x] Validating against controller division and rating to allow booking
[x] Manage dashboard bookings as FIR staff
[x] Remove bookings after end date (now: preserve for compliance, filter from frontend)
[x] Use vatsim data api to validate controller logged on for their booking
[x] Controller name on booking using either vatsim name or wordpress name
[x] Whitelisting visitors and solo certs to enable booking of stations
[x] Position-specific authorization for whitelisted controllers
[x] Expiration dates and renewal for whitelist entries
[x] Auto-populate controller names from WordPress accounts

## Planned Features

### High Priority
[] Filter booking form dropdown to show only authorized positions for whitelisted controllers
[] Controller station booking based on rating (enforce minimum ratings per position type)
[] Display bookings as calendar view (month/week grid with visual timeline)
[] Booking conflict warnings (show existing bookings when selecting time/position)

### Medium Priority
[] Reminder emails / discord pings for upcoming bookings
[] Bulk import/export whitelist entries (CSV format)
[] Controller dashboard (personal bookings, stats, compliance summary)
[] Log all whitelisted controller entries for audit trail
[] Booking templates (save common booking patterns, e.g., "Friday evening TNCC_TWR")
[] Multi-position bookings (book multiple positions at once for coverage events)

### Low Priority / Future Consideration
[] Set FIR in settings and fetch stations automatically (investigate VATSIM API capability)
[] Advanced calendar features (drag-to-book, visual conflict detection)
[] Email notifications for booking confirmations and changes
[] Discord webhook integration for new bookings and compliance alerts
[] Booking statistics dashboard (busiest times, most popular positions)
[] Controller performance reports (on-time percentage, booking frequency)
[] Public API for third-party integrations
[] Mobile-responsive improvements for booking form
[] Dark mode support for admin pages

## Feature Requests / Ideas
[] Recurring bookings (e.g., every Friday 1800-2200z)
[] Booking delegation (allow admins to book on behalf of controllers)
[] Position availability forecast (show busy/quiet times)
[] Integration with event management systems
[] Training session booking (separate from regular ATC bookings)
[] Mentoring slot coordination
[] Session notes/feedback system
[] Badge/achievement system for consistent controllers