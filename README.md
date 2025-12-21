# VATCAR FIR Station Booking Plugin

A comprehensive WordPress plugin for managing ATC station bookings within the VATCAR division, integrated with the VATSIM ATC Bookings API.

## 📖 Overview

The VATCAR FIR Station Booking Plugin enables VATSIM controllers to reserve, edit, and manage ATC positions across the Caribbean region. Built specifically for VATCAR FIR subdivisions (currently supporting Curaçao), the plugin provides real-time synchronization with VATSIM's official ATC Bookings API, compliance tracking for on-time logins, and an administrative dashboard for FIR staff.

**Key Capabilities:**
- **Controller Self-Service**: Reserve ATC positions through user-friendly forms
- **VATSIM API Integration**: Two-way sync with official VATSIM ATC bookings database
- **Real-Time Status Tracking**: Live monitoring of controller login status via VATSIM data feed
- **Compliance History**: Automated tracking of on-time, late, early, and no-show logins
- **Admin Dashboard**: FIR staff can manage all bookings, view status, and access compliance records
- **Validation & Conflict Prevention**: Enforces callsign formats, overlap checks, and minimum booking lead time

## ✨ Features

### For Controllers
- **Reserve ATC Positions**: Simple booking form with date/time selection and callsign validation
- **Edit Bookings**: Update callsign or timeslot (minimum 2 hours before scheduled start)
- **Cancel Bookings**: Delete reservations with automatic API synchronization
- **View Schedule**: Display upcoming bookings in a public schedule table (past bookings hidden)
- **Division & Rating Validation**: Automatic checks ensure you meet requirements to book (CAR division membership, appropriate controller rating)

### For FIR Staff (Administrators)
- **Manage Bookings Dashboard**: View all bookings across the FIR with real-time login status
- **Override Permissions**: Edit or delete any booking regardless of ownership
- **Compliance Monitoring**: View detailed history of controller login compliance (on-time, late, early, no-show)
- **Controller Whitelist**: Manage visitor controllers and solo certifications with position-specific authorizations
- **Bypass Validation**: Admin edits skip division and rating checks (useful for special events)
- **Real-Time Status Updates**: AJAX-driven status checks show whether controllers are logged in

### Controller Whitelist System
- **Visitor Authorization**: Allow controllers from other divisions/subdivisions to book specific positions
- **Solo Certifications**: Grant local controllers permission to book positions above their rating (e.g., S1 booking tower)
- **Position-Specific Permissions**: Select which positions each controller can book (or allow all)
- **Expiration Dates**: Set temporary access with automatic expiry
- **Renewal/Extension**: Easily extend expired authorizations without re-creating entries
- **Auto-Populate Names**: Controller names automatically populate from WordPress accounts on login
- **Authorization Types**: 
  - **Visitor**: Bypasses division/subdivision checks, enforces position authorization
  - **Solo**: Bypasses rating checks for authorized positions only (must be CAR/CUR)
- **Backward Compatible**: No positions selected = all positions allowed (for legacy entries)

### Technical Features
- **VATSIM API Synchronization**: All bookings are created, updated, and deleted via official API
- **Local Caching**: Booking data cached in WordPress database for fast display
- **Live Data Integration**: Fetches controller status from VATSIM data feed (15-second refresh rate)
- **Compliance History Database**: Persistent record of login compliance for accountability
- **Historical Data Preservation**: Past bookings retained for compliance tracking (filtered from public view)
- **Controller Whitelist**: Database-backed authorization system for visitors and solo certifications
- **Position-Level Permissions**: Granular control over which positions each controller can book
- **Secure AJAX**: All operations use WordPress nonces and capability checks
- **Git Updater Support**: Automatic plugin updates directly from GitHub repository

## 🛠️ Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **VATSIM API Key**: Required for booking operations ([request here](https://atc-bookings.vatsim.net))
- **MySQL/MariaDB**: WordPress-compatible database with `information_schema` access
- **Git Updater** (optional): For automatic GitHub updates

## 📦 Installation

### Step 1: Install Plugin Files

**Option A: Via Git (Recommended)**
```bash
cd wp-content/plugins/
git clone https://github.com/savmon120/curacao-controller-bookings-plugin.git vatcar-fir-station-booking
```

**Option B: Manual Upload**
1. Download the latest release from GitHub
2. Extract the ZIP file
3. Upload the `vatcar-fir-station-booking` folder to `wp-content/plugins/`

### Step 2: Activate Plugin

1. Log in to WordPress admin dashboard
2. Navigate to **Plugins** → **Installed Plugins**
3. Find **VATCAR FIR Station Booking** and click **Activate**
4. The plugin will automatically create database tables on first activation

### Step 3: Configure VATSIM API

1. Navigate to **ATC Bookings** → **Settings** in the WordPress admin menu
2. Enter your **VATSIM API Key** (obtain from [VATSIM ATC Bookings](https://atc-bookings.vatsim.net))
3. Verify the **FIR Subdivision** is set to your region (default: `CUR` for Curaçao)
4. Click **Save Changes**

### Step 4: Run Diagnostic (Optional)

To verify API connectivity and database setup:

1. Ensure `VATCAR_ATC_DEBUG` is set to `true` (temporarily, in dev environment)
2. Visit the plugin settings page and click **Run Diagnostic**, or navigate to `?vatcar_atc_diag=1`
3. Review the output for any errors (check API connection, database tables, WP-Cron schedule)
4. **Important**: Disable debug mode in production environments

### Step 5: Add Shortcodes to Pages

**Booking Form** (allows controllers to create reservations):
```
[vatcar_atc_booking]
```

**Public Schedule** (displays upcoming bookings):
```
[vatcar_atc_schedule]
```

**Example**:
1. Create a new page: **Pages** → **Add New**
2. Title: "Book ATC Position"
3. Add the shortcode `[vatcar_atc_booking]` in the content area
4. Publish the page

## 🚀 Usage

### For Controllers

#### Creating a Booking

1. Navigate to the booking form page (e.g., `/book-atc`)
2. Fill in the required fields:
   - **Callsign**: Select from valid Curaçao FIR positions (e.g., `TNCC_TWR`, `TNCM_APP`)
   - **Start Date & Time**: Must be at least **2 hours from now** (UTC/Zulu time)
   - **End Date & Time**: Must be after start time
3. Click **Create Booking**
4. The booking will be validated (division membership, rating, overlaps) and submitted to VATSIM API
5. On success, you'll be redirected to the schedule page

**Validation Rules**:
- Must be a member of CAR division (verified via VATSIM Member API) OR be in the controller whitelist as a visitor
- Must hold at least S1 rating (visitors/solo cert holders bypass rating checks for authorized positions)
- Booking start must be minimum 2 hours in the future (UTC)
- Cannot overlap with existing bookings for the same callsign
- Cannot select past dates or times
- Whitelisted controllers must book positions they're authorized for (if positions are specified)

#### Editing a Booking

1. View the public schedule or your personal bookings
2. Click the **Edit** button next to your booking
3. Modify callsign or timeslot as needed
4. Click **Update Booking**
5. Changes are synchronized with VATSIM API immediately

**Restrictions**:
- Can only edit bookings that start more than 2 hours from now
- Can only edit your own bookings (identified by VATSIM CID)
- Same validation rules apply as when creating

#### Canceling a Booking

1. Locate your booking in the schedule
2. Click the **Cancel** button
3. Confirm deletion in the modal dialog
4. The booking is removed from both local database and VATSIM API

### For FIR Staff (Administrators)

#### Accessing the Dashboard

1. Navigate to **ATC Bookings** → **Manage Bookings** in the WordPress admin menu
2. The dashboard displays all bookings for your FIR subdivision
3. Columns include: Callsign, CID, Start/End times, and **Real-Time Status**

#### Managing Controller Whitelist

1. Navigate to **ATC Bookings** → **Controller Whitelist** in the admin menu
2. Add controllers by entering their CID, selecting authorization type, and choosing positions
3. **Authorization Types**:
   - **Visitor**: For controllers from other divisions/subdivisions (bypasses division/subdivision checks)
   - **Solo**: For local controllers with certifications for specific positions (bypasses rating checks)
4. **Position Selection**: Check specific positions they can book, or leave all unchecked for all positions
5. **Expiration**: Optionally set an expiry date for temporary access
6. **Renewal**: Click "Extend" or "Renew" to update expiration dates for existing authorizations

#### Understanding Booking Status

The **Status** column shows live controller login state:

- **On Time** 🟢: Controller logged in within ±15 minutes of scheduled start
- **Early** 🟡: Controller logged in more than 15 minutes before scheduled start
- **Late** 🟠: Controller logged in more than 15 minutes after scheduled start
- **Not Logged In** ⚪: Scheduled time has passed, controller never logged in
- **No Show** 🔴: Booking time has ended, controller never logged in (compliance violation)
- **Unknown** ❓: Unable to verify status (VATSIM data feed issue)

Status updates every 30 seconds via AJAX.

#### Viewing Compliance History

1. Click the **View History** button next to any booking
2. A modal displays all compliance checks recorded for that booking
3. Each record shows: Check time, status, and verification details
4. Use this data for accountability and feedback discussions

#### Editing Any Booking (Admin Override)

1. Click the **Edit** button next to any booking
2. Modify callsign or timeslot
3. Click **Update Booking**
4. **Admin privilege**: Division and rating checks are bypassed (useful for events, staff bookings)

#### Deleting Any Booking (Admin Override)

1. Click the **Cancel** button next to any booking
2. Confirm deletion
3. The booking is removed from VATSIM API and local database
4. The table row refreshes automatically (no page reload needed)

## ⚙️ Configuration

### Plugin Settings

Access via **ATC Bookings** → **Settings**:

| Setting | Description | Default |
|---------|-------------|---------|
| **VATSIM API Key** | Your personal API key for VATSIM ATC Bookings API | *(empty)* |
| **FIR Subdivision** | Three-letter code for your FIR (e.g., CUR, PUJ) | `CUR` |

### Constants (Advanced)

Define in `wp-config.php` or override in plugin bootstrap:

```php
// API base URL (default: https://atc-bookings.vatsim.net)
define('VATCAR_VATSIM_API_BASE', 'https://custom-api.example.com');

// Enable diagnostic endpoint (DO NOT enable in production)
define('VATCAR_ATC_DEBUG', true);
```

### Database Tables

The plugin creates three tables on activation:

**`wp_atc_bookings`** (booking cache):
- `id`, `cid`, `api_cid`, `callsign`, `type`, `start`, `end`, `division`, `subdivision`, `external_id`, `controller_name`

**`wp_atc_booking_compliance`** (compliance history):
- `id`, `booking_id`, `cid`, `callsign`, `status`, `checked_at`

**`wp_atc_controller_whitelist`** (visitor/solo authorization):
- `id`, `cid`, `notes`, `added_by`, `date_added`, `expires_at`, `controller_name`, `authorization_type`

**`wp_atc_authorized_positions`** (position-specific permissions):
- `id`, `authorization_id`, `callsign`, `date_granted`

### WP-Cron Jobs

- **`vatcar_cleanup_expired_bookings`**: Runs daily at midnight UTC (currently disabled - bookings preserved for compliance history)

## 📂 File Structure

```
vatcar-fir-station-booking/
├── vatcar-fir-station-booking.php      # Plugin bootstrap (hooks, constants, settings, activation)
├── includes/
│   ├── class-booking.php                # Booking CRUD, API sync, validation logic
│   ├── class-schedule.php               # Schedule display and API list handling
│   ├── class-validation.php             # Input validation rules (callsign, overlap, time)
│   └── class-dashboard.php              # Admin dashboard, real-time status, compliance history
├── templates/
│   ├── booking-form.php                 # Public booking creation form
│   ├── edit-booking-form.php            # Edit booking modal
│   ├── delete-booking-form.php          # Delete confirmation modal
│   └── booking-schedule.php             # Public schedule table
├── assets/
│   └── css/
│       └── VatCar-atc-bookings.css      # Plugin styles (public & admin)
└── README.md                            # This file
```

## 🔧 Troubleshooting

### API Connection Errors

**Symptom**: "VATSIM API error" message when creating bookings

**Solutions**:
1. Verify your API key in **ATC Bookings** → **Settings**
2. Test API connectivity: Run the diagnostic endpoint (`?vatcar_atc_diag=1` with debug enabled)
3. Check server firewall: Ensure outbound HTTPS to `atc-bookings.vatsim.net` is allowed
4. Review error logs: Check `wp-content/debug.log` with `WP_DEBUG` enabled

### "Invalid Division" Error

**Symptom**: Controllers see "You must be a member of division CAR" error

**Solutions**:
1. Verify the controller's VATSIM profile shows CAR division membership
2. Check VATSIM Member API response in diagnostic output
3. Temporary workaround: Admin users can create bookings on behalf of controllers (bypasses division check)

### "Insufficient Rating" Error

**Symptom**: "You need at least [X] rating to book this position"

**Solutions**:
1. Confirm the controller holds the required rating:
   - TWR/APP positions: Minimum S2 (Tower)
   - CTR positions: Minimum C1 (Enroute)
2. Verify rating via VATSIM Member API (shown in diagnostic output)
3. Temporary workaround: Admin users can override rating checks

### Booking Overlaps Not Detected

**Symptom**: System allows overlapping bookings for the same callsign

**Solutions**:
1. Verify `wp_atc_bookings` table exists: Check database via phpMyAdmin or diagnostic
2. Ensure dates are in UTC format: Check `start` and `end` columns for correct timezone
3. Test overlap detection: Use diagnostic to query existing bookings

### Real-Time Status Not Updating

**Symptom**: Dashboard shows "Unknown" status for all bookings

**Solutions**:
1. Check VATSIM data feed access: Visit `https://data.vatsim.net/v3/vatsim-data.json` directly
2. Verify browser console for AJAX errors (F12 → Console tab)
3. Ensure admin page scripts are loading: Check page source for `wp_enqueue_script` output
4. Test with a known active controller: Book a position and log in, then check dashboard

### Compliance History Empty

**Symptom**: "View History" modal shows no records

**Solutions**:
1. Verify `wp_atc_booking_compliance` table exists: Check via diagnostic
2. Ensure status checks are running: Monitor dashboard while a controller is logged in
3. Manual test: Call `VatCar_ATC_Booking::record_compliance_check()` in debug mode

### Expired Bookings Not Removed

**Symptom**: Old bookings remain in database indefinitely

**This is expected behavior**: As of recent updates, bookings are no longer automatically deleted. They are preserved for compliance history tracking but filtered from the public schedule display. This ensures the compliance tracking system continues to work by maintaining historical booking data.

**To manually clean old bookings** (if needed for database size concerns):
```sql
DELETE FROM wp_atc_bookings WHERE end < DATE_SUB(NOW(), INTERVAL 6 MONTH);
```

### Plugin Update Not Available

**Symptom**: Git Updater doesn't show updates despite new GitHub releases

**Solutions**:
1. Verify Git Updater plugin is installed and activated
2. Check plugin headers include `GitHub Plugin URI: savmon120/curacao-controller-bookings-plugin`
3. Force refresh: **Dashboard** → **Updates** → **Check Again**
4. Manual update: Pull latest from GitHub, deactivate plugin, replace files, reactivate

## 🔄 Automatic Updates with Git Updater

This plugin supports automatic updates via the [Git Updater](https://git-updater.com/) plugin.

### Setup

1. Install and activate **Git Updater** from WordPress.org
2. Ensure this plugin was installed from the GitHub repository (`savmon120/curacao-controller-bookings-plugin`)
3. Git Updater automatically detects the repository from plugin headers
4. When updates are pushed to GitHub, you'll receive notifications in **Dashboard** → **Updates**
5. Update directly from the WordPress admin (no manual file replacement needed)

### Manual Update (Without Git Updater)

```bash
cd wp-content/plugins/vatcar-fir-station-booking/
git pull origin main
```

Then refresh the WordPress admin dashboard to apply changes.

## 🛡️ Security & Best Practices

### API Key Storage
- Never commit your API key to version control
- Store in WordPress options table (`vatcar_vatsim_api_key`) via Settings page
- Use environment variables for staging/production environments if needed

### Debug Mode
- **Never enable** `VATCAR_ATC_DEBUG` in production
- Diagnostic endpoint (`?vatcar_atc_diag=1`) exposes sensitive configuration data
- Only use for local development or temporary troubleshooting

### User Permissions
- Booking creation: Any authenticated WordPress user (checks VATSIM CID from profile)
- Booking edit/delete: Only booking owner (verified by CID)
- Admin dashboard: `manage_options` capability required (typically Administrator role)
- Admin override: Only users with `manage_options` can edit/delete any booking

### Data Privacy
- CIDs stored for booking ownership verification
- Controller names populated from WordPress user accounts (first name + last name)
- No sensitive personal data stored in plugin tables
- Compliance history tracks CID and timestamps only
- GDPR compliance: Bookings retained indefinitely for compliance history (can be manually purged if needed)
- Expired whitelist entries remain visible but inactive

### Input Validation
- All user inputs sanitized via `sanitize_text_field()`, `intval()`, etc.
- Database queries use `$wpdb->prepare()` to prevent SQL injection
- AJAX requests require WordPress nonces and capability checks
- Callsign format enforced via whitelist (TWR, APP, GND, DEL, CTR, FSS)

## 🤝 Contributing

Contributions are welcome! To contribute:

1. **Fork the repository**: [savmon120/curacao-controller-bookings-plugin](https://github.com/savmon120/curacao-controller-bookings-plugin)
2. **Create a feature branch**: `git checkout -b feature/my-new-feature`
3. **Make your changes**: Follow WordPress coding standards
4. **Test thoroughly**: Verify activation, booking creation, API sync, admin dashboard
5. **Commit with clear messages**: `git commit -m "Add compliance report export feature"`
6. **Push to your fork**: `git push origin feature/my-new-feature`
7. **Submit a Pull Request**: Include description of changes and testing steps

### Development Guidelines

- **Code Style**: Follow [WordPress PHP Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/php/)
- **Database Changes**: Use `dbDelta()` for schema changes, ensure idempotency
- **API Integration**: Use `wp_remote_get()` / `wp_remote_post()`, handle `is_wp_error()`
- **Activation Hooks**: Test activation/deactivation/reactivation cycle for DB migrations
- **Documentation**: Update this README and `.github/copilot-instructions.md` for major changes

### Testing Checklist

Before submitting a PR, verify:

- [ ] Plugin activates without errors (check `wp-content/debug.log`)
- [ ] Database tables created correctly (`wp_atc_bookings`, `wp_atc_booking_compliance`)
- [ ] Booking form creates bookings and syncs to VATSIM API
- [ ] Edit/delete operations update VATSIM API and local database
- [ ] Admin dashboard displays bookings with real-time status
- [ ] Compliance history modal loads records
- [ ] Validation enforces 2-hour minimum, callsign format, overlaps
- [ ] Admin users can override ownership and validation checks
- [ ] Shortcodes render on public pages
- [ ] CSS and JavaScript assets load correctly
- [ ] WP-Cron job scheduled for cleanup

## 📜 License

This project is licensed under the **MIT License**.

## 🙏 Credits

- **Developer**: VATCAR FIR Development Team
- **Repository**: [savmon120/curacao-controller-bookings-plugin](https://github.com/savmon120/curacao-controller-bookings-plugin)
- **VATSIM API**: [VATSIM ATC Bookings](https://atc-bookings.vatsim.net)
- **VATSIM Data Feed**: [VATSIM Data](https://data.vatsim.net/)

## 📞 Support

For issues, questions, or feature requests:

- **GitHub Issues**: [Report a bug or request a feature](https://github.com/savmon120/curacao-controller-bookings-plugin/issues)
- **VATCAR Discord**: Contact FIR staff in the VATCAR Discord server
- **VATSIM Forums**: Post in the Americas region forums

## 🗺️ Roadmap

See [roadmap.md](roadmap.md) for a complete list of completed features, planned features, and future considerations.

### Recently Completed
- ✅ Controller whitelist with position-specific authorization
- ✅ Visitor and solo certification support
- ✅ Auto-populated controller names from WordPress accounts
- ✅ Expiration dates and renewal for whitelist entries
- ✅ Compliance history tracking with real-time status monitoring
- ✅ Historical booking preservation (filtered from public view)

### Next Up
- Filter booking form dropdown based on authorized positions
- Rating-based position restrictions (enforce minimum ratings per position type)
- Booking conflict warnings and templates

---

**Version**: 1.2.0  
**Last Updated**: December 2025  
**Maintained by**: VATCAR FIR Staff
