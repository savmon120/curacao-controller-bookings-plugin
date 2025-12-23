# roadmap.md

## Completed Features
[x] Validating against controller division and rating to allow booking
[x] Manage dashboard bookings as FIR staff
[x] Remove bookings after end date (now: preserve for compliance, filter from frontend)
[x] Use vatsim data api to validate controller logged on for their booking
[x] Controller name on booking using wordpress name
[x] Whitelisting visitors and solo certs to enable booking of stations
[x] Position-specific authorization for whitelisted controllers
[x] Expiration dates and renewal for whitelist entries
[x] Auto-populate controller names from WordPress accounts

## Planned Features

### High Priority
[] Filter booking form dropdown to show only authorised positions for whitelisted controllers
[] Booking conflict warnings (show existing bookings when selecting time/position)
[] Set FIR in settings and fetch stations automatically (investigate VATSIM API capability)
[] **Position-based rating restrictions** - Implement graduated rating requirements per position type:
   - S1 (rating 2): GND/RMP positions only
   - S2 (rating 3): + DEL, TWR positions
   - S3 (rating 4): + APP/DEP positions  
   - C1+ (rating 5+): + CTR/FSS positions
   - Solo certifications bypass rating checks for certified positions only
   - Update validation logic in save_booking() and update_booking()
   - Add position tier mapping function (get_position_required_rating($callsign))
   - Update error messages to show required rating for attempted position

### Medium Priority
[] Reminder emails / discord pings for upcoming bookings
[] Controller dashboard (personal bookings, stats, compliance summary)
[] Log all whitelisted controller entries for audit trail
[] Booking templates (save common booking patterns, e.g., "Friday evening TNCC_TWR")

### Low Priority / Future Consideration
[] Email notifications for booking confirmations and changes
[] Discord webhook integration for new bookings and compliance alerts
[] Booking statistics dashboard (busiest times, most popular positions)
[] Controller performance reports (on-time percentage, booking frequency)
[] Mobile-responsive improvements for booking form

## Feature Requests / Ideas
[] Recurring bookings (e.g., every Friday 1800-2200z)
[] Booking delegation (allow admins to book on behalf of controllers)
[] Position availability forecast (show busy/quiet times)
[] Integration with live traffic
[] Badge/achievement system for consistent controllers
[] Display bookings as calendar view (month/week grid with visual timeline)
[] Advanced calendar features (drag-to-book, visual conflict detection)