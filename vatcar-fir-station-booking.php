<?php
/**
 * Plugin Name: VATCAR FIR Station Booking
 * Description: ATC booking system for WordPress, integrating with VATSIM ATC Bookings API.
 * Version: 1.4.3
 * Author: Sav Monzac
 * GitHub Plugin URI: savmon120/curacao-controller-bookings-plugin
 * Primary Branch: dev
 */

if (!defined('ABSPATH')) exit;

if (!defined('VATCAR_VATSIM_API_BASE')) {
    define('VATCAR_VATSIM_API_BASE', 'https://atc-bookings.vatsim.net');
}
if (!defined('VATCAR_VATSIM_API_KEY')) {
    // Load API key from WP options (admin settings page)
    define('VATCAR_VATSIM_API_KEY', get_option('vatcar_vatsim_api_key', ''));
}

// Debug helper function - checks WP option instead of constant
function vatcar_atc_is_debug_enabled() {
    return (bool) get_option('vatcar_atc_debug_mode', false);
}

function vatcar_vatsim_headers() {
    return [
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . VATCAR_VATSIM_API_KEY,
    ];
}

// Core classes
require_once plugin_dir_path(__FILE__) . 'includes/class-booking.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-schedule.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-validation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-controller-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-controller-widget.php';
// require_once plugin_dir_path(__FILE__) . 'includes/class-live-traffic.php'; // TODO: Being developed in separate branch

// Add custom cron schedule for 15-minute intervals
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['every_15_minutes'])) {
        $schedules['every_15_minutes'] = [
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes')
        ];
    }
    return $schedules;
});

// Automatic compliance checking function
function vatcar_check_booking_compliance() {
    global $wpdb;
    $table = $wpdb->prefix . 'atc_bookings';
    $now = current_time('mysql');
    
    // Get bookings that started within last 30 minutes or start within next 30 minutes
    // This ensures we catch bookings at their start time
    $start_window_past = gmdate('Y-m-d H:i:s', strtotime($now) - (30 * 60));
    $start_window_future = gmdate('Y-m-d H:i:s', strtotime($now) + (30 * 60));
    
    $bookings = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE start >= %s AND start <= %s AND cid IS NOT NULL AND cid != ''",
        $start_window_past,
        $start_window_future
    ));
    
    if (empty($bookings)) {
        return; // No bookings to check
    }
    
    foreach ($bookings as $booking) {
        // Check if we've already recorded status for this booking in the last 15 minutes
        $history_table = $wpdb->prefix . 'atc_booking_compliance';
        $recent_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $history_table WHERE booking_id = %d AND checked_at > %s",
            $booking->id,
            gmdate('Y-m-d H:i:s', strtotime($now) - (15 * 60))
        ));
        
        if ((int)$recent_check > 0) {
            continue; // Already checked recently, skip
        }
        
        // Check compliance status
        $status = VatCar_ATC_Booking::is_controller_logged_in_on_time(
            $booking->cid,
            $booking->callsign,
            $booking->start
        );
        
        // Only record meaningful statuses (not 'not_logged_in' before booking time)
        $booking_time = strtotime($booking->start);
        $current_time = time();
        
        // Record if:
        // 1. Booking time has passed (to catch no_shows, late, on_time)
        // 2. Controller is already logged in early
        if ($current_time >= $booking_time || $status === 'early' || $status === 'on_time') {
            VatCar_ATC_Booking::record_compliance_check(
                $booking->id,
                $booking->cid,
                $booking->callsign,
                $status
            );
        }
    }
}

// Cleanup expired bookings function
function vatcar_cleanup_expired_bookings() {
    // Bookings are no longer deleted to preserve compliance history
    // Past bookings are filtered from the frontend schedule display instead
    // This function is kept for backward compatibility with WP Cron
}

// Schedule cron jobs on activation
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('vatcar_cleanup_expired_bookings')) {
        wp_schedule_event(time(), 'daily', 'vatcar_cleanup_expired_bookings');
    }
    if (!wp_next_scheduled('vatcar_check_booking_compliance')) {
        wp_schedule_event(time(), 'every_15_minutes', 'vatcar_check_booking_compliance');
    }
});

// Unschedule on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('vatcar_cleanup_expired_bookings');
    wp_clear_scheduled_hook('vatcar_check_booking_compliance');
});

add_action('vatcar_cleanup_expired_bookings', 'vatcar_cleanup_expired_bookings');
add_action('vatcar_check_booking_compliance', 'vatcar_check_booking_compliance');

// Add custom cron schedule for testing
add_filter('cron_schedules', function($schedules) {
    $schedules['every_5_minutes'] = [
        'interval' => 300, // 5 minutes
        'display' => __('Every 5 Minutes')
    ];
    return $schedules;
});

// Shortcodes
add_shortcode('vatcar_atc_booking', ['VatCar_ATC_Booking', 'render_form']);
add_shortcode('vatcar_atc_schedule', ['VatCar_ATC_Schedule', 'render_table']);
add_shortcode('vatcar_my_bookings', ['VatCar_Controller_Dashboard', 'render_dashboard']);

function vatcar_detect_subdivision() {
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    if (strpos($host, 'curacao.vatcar.net') !== false || strpos($host, 'curacao.vatcar.local') !== false) {
        return 'CUR';
    }
    if (strpos($host, 'piarco.vatcar.net') !== false) {
        return 'PIA';
    }
    if (strpos($host, 'curacao-fir-vatcar.local') !== false) {
        return 'CUR';
    }

    // Add more mappings as needed
    return ''; // Default
}

function vatcar_get_subdivision_name($code) {
    $names = [
        'CUR' => 'Curaçao',
        'PIA' => 'Piarco',
        // Add more as needed
    ];
    return $names[$code] ?? $code; // Fallback to code if not found
}

function vatcar_unrecognised_site_error($as_html = true) {
    $message = 'This site is not configured or recognised within the plugin. Please create a';
    
    if ($as_html) {
        return '<p><strong>Error:</strong> ' . esc_html($message) . ' <a href="https://github.com/savmon120/curacao-controller-bookings-plugin/issues" target="_blank">GitHub issue</a>.</p>';
    }
    return $message;
}

// AJAX handlers
add_action('wp_ajax_update_booking', ['VatCar_ATC_Booking', 'ajax_update_booking']);
add_action('wp_ajax_delete_booking', ['VatCar_ATC_Booking', 'ajax_delete_booking']);
add_action('wp_ajax_refresh_delete_nonce', 'vatcar_ajax_refresh_delete_nonce'); // Nonce refresh endpoint
add_action('wp_ajax_lookup_controller', ['VatCar_ATC_Booking', 'ajax_lookup_controller']);
add_action('wp_ajax_create_booking_from_dashboard', 'vatcar_ajax_create_booking_from_dashboard');
add_action('wp_ajax_vatcar_get_booking_status', ['VatCar_ATC_Dashboard', 'ajax_get_booking_status']);
add_action('wp_ajax_vatcar_get_compliance_history', ['VatCar_ATC_Dashboard', 'ajax_get_compliance_history']);
add_action('wp_ajax_vatcar_get_cid_compliance', ['VatCar_ATC_Dashboard', 'ajax_get_cid_compliance']);
add_action('wp_ajax_add_controller', 'vatcar_ajax_add_controller');
add_action('wp_ajax_remove_controller', 'vatcar_ajax_remove_controller');
add_action('wp_ajax_renew_controller', 'vatcar_ajax_renew_controller');
add_action('wp_ajax_get_booking_coordination', 'vatcar_ajax_get_booking_coordination');
add_action('wp_ajax_nopriv_get_booking_coordination', 'vatcar_ajax_get_booking_coordination'); // Public for booking form

/**
 * AJAX handler: Add controller to whitelist
 */
function vatcar_ajax_add_controller() {
    // Verify nonce and admin capability
    if (!isset($_POST['vatcar_add_controller_nonce'])
        || !wp_verify_nonce($_POST['vatcar_add_controller_nonce'], 'vatcar_add_controller')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $cid = sanitize_text_field($_POST['cid'] ?? '');
    $notes = sanitize_textarea_field($_POST['notes'] ?? '');
    $expires = sanitize_text_field($_POST['expires'] ?? '');
    
    // Validate authorization type against whitelist
    $authorization_type_raw = sanitize_text_field($_POST['authorization_type'] ?? 'visitor');
    $allowed_types = ['visitor', 'solo'];
    $authorization_type = in_array($authorization_type_raw, $allowed_types, true) ? $authorization_type_raw : 'visitor';
    
    $positions = isset($_POST['positions']) && is_array($_POST['positions']) 
        ? array_map('sanitize_text_field', $_POST['positions']) 
        : [];

    if (empty($cid)) {
        wp_send_json_error('CID is required');
    }

    // Check if CID already has active (non-expired) authorization
    $existing_entry = VatCar_ATC_Booking::get_whitelist_entry($cid);
    if ($existing_entry) {
        wp_send_json_error('Controller already has an active authorization. Remove or wait for expiration before adding a new one.');
    }

    // Validate expires for solo certifications
    if ($authorization_type === 'solo' && empty($expires)) {
        wp_send_json_error('Expiration date is required for solo certifications');
    }

    // Convert HTML5 datetime-local format to MySQL datetime with validation
    $expires_at = null;
    if (!empty($expires)) {
        $timestamp = strtotime($expires);
        if ($timestamp === false || $timestamp < 0) {
            wp_send_json_error('Invalid expiration date format');
            return;
        }
        $expires_at = gmdate('Y-m-d H:i:s', $timestamp);
    }

    $result = VatCar_ATC_Booking::add_to_whitelist($cid, $notes, $expires_at, $authorization_type);

    if ($result) {
        // Add authorized positions if specified
        if (!empty($positions)) {
            VatCar_ATC_Booking::add_authorized_positions($result, $positions);
        }
        wp_send_json_success('Authorization added successfully');
    } else {
        wp_send_json_error('Failed to add authorization (may already exist)');
    }
}

/**
 * AJAX handler: Create booking from dashboard (admin only)
 */
function vatcar_ajax_create_booking_from_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    // Verify nonce
    if (!isset($_POST['vatcar_booking_nonce'])
        || !wp_verify_nonce($_POST['vatcar_booking_nonce'], 'vatcar_new_booking')) {
        wp_send_json_error('Security check failed');
    }

    // Parse and validate input
    $controller_cid = sanitize_text_field($_POST['controller_cid'] ?? '');
    $callsign = sanitize_text_field($_POST['callsign'] ?? '');
    $start_date = sanitize_text_field($_POST['start_date'] ?? '');
    $start_time = sanitize_text_field($_POST['start_time'] ?? '');
    $end_date = sanitize_text_field($_POST['end_date'] ?? '');
    $end_time = sanitize_text_field($_POST['end_time'] ?? '');

    if (empty($controller_cid) || !preg_match('/^\d{1,10}$/', $controller_cid)) {
        wp_send_json_error('Invalid Controller CID');
    }

    // Build UTC timestamps
    $start_str = trim($start_date) . ' ' . trim($start_time);
    $end_str = trim($end_date) . ' ' . trim($end_time);

    $start_ts = strtotime($start_str . ' UTC');
    $end_ts = strtotime($end_str . ' UTC');

    if (!$start_ts || !$end_ts) {
        wp_send_json_error('Invalid start/end date or time');
    }

    $start = gmdate('Y-m-d H:i:s', $start_ts);
    $end = gmdate('Y-m-d H:i:s', $end_ts);

    // Detect subdivision
    $subdivision = vatcar_detect_subdivision();
    if (empty($subdivision)) {
        wp_send_json_error(vatcar_unrecognised_site_error(false));
    }

    // Create booking
    $result = VatCar_ATC_Booking::save_booking([
        'cid'         => $controller_cid,
        'callsign'    => $callsign,
        'start'       => $start,
        'end'         => $end,
        'division'    => 'CAR',
        'subdivision' => $subdivision,
        'type'        => 'booking',
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('Booking created successfully');
    }
}

/**
 * AJAX handler: Remove controller from whitelist
 */
function vatcar_ajax_remove_controller() {
    // Verify nonce and admin capability
    if (!isset($_POST['vatcar_remove_controller_nonce'])
        || !wp_verify_nonce($_POST['vatcar_remove_controller_nonce'], 'vatcar_remove_controller')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $cid = sanitize_text_field($_POST['cid'] ?? '');

    if (empty($cid)) {
        wp_send_json_error('CID is required');
    }

    $result = VatCar_ATC_Booking::remove_from_whitelist($cid);

    if ($result) {
        wp_send_json_success('Visitor removed successfully');
    } else {
        wp_send_json_error('Failed to remove visitor');
    }
}

/**
 * AJAX handler: Renew/extend controller whitelist expiration
 */
function vatcar_ajax_renew_controller() {
    // Verify nonce and admin capability
    if (!isset($_POST['vatcar_renew_controller_nonce'])
        || !wp_verify_nonce($_POST['vatcar_renew_controller_nonce'], 'vatcar_renew_controller')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $cid = sanitize_text_field($_POST['cid'] ?? '');
    $expires = sanitize_text_field($_POST['expires'] ?? '');

    if (empty($cid)) {
        wp_send_json_error('CID is required');
    }

    // Convert HTML5 datetime-local format to MySQL datetime with validation, or set NULL for permanent
    $expires_at = null;
    if (!empty($expires)) {
        $timestamp = strtotime($expires);
        if ($timestamp === false || $timestamp < 0) {
            wp_send_json_error('Invalid expiration date format');
            return;
        }
        $expires_at = gmdate('Y-m-d H:i:s', $timestamp);
    }

    $result = VatCar_ATC_Booking::update_whitelist_expiration($cid, $expires_at);

    if ($result) {
        wp_send_json_success('Visitor expiration updated successfully');
    } else {
        wp_send_json_error('Failed to update visitor');
    }
}

/**
 * AJAX handler: Get booking coordination info (overlapping bookings + suggestions)
 */
function vatcar_ajax_get_booking_coordination() {
    $start = sanitize_text_field($_POST['start'] ?? '');
    $end = sanitize_text_field($_POST['end'] ?? '');
    $date = sanitize_text_field($_POST['date'] ?? '');
    
    // Require at least a date to show coordination
    if (empty($date)) {
        wp_send_json_error('Missing date');
    }
    
    $subdivision = vatcar_detect_subdivision();
    if (empty($subdivision)) {
        wp_send_json_error('Could not detect FIR subdivision');
    }
    
    // If times aren't fully specified, show all bookings for the day
    if (empty($start) || empty($end)) {
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';
    }
    
    // TODO: Live traffic feature being developed in separate branch
    // Temporarily return empty results until that branch is merged
    $overlapping = [];
    $suggestions = [];
    
    /* 
    // Get overlapping bookings
    $overlapping = VatCar_Live_Traffic::get_overlapping_bookings($start, $end, $subdivision);
    
    // Format overlapping bookings for display
    $overlapping_formatted = [];
    foreach ($overlapping as $booking) {
        $overlapping_formatted[] = [
            'callsign' => $booking->callsign,
            'controller_name' => $booking->controller_name ?? 'Unknown',
            'cid' => $booking->cid,
            'start_time' => gmdate('H:i', strtotime($booking->start)),
            'end_time' => gmdate('H:i', strtotime($booking->end)),
        ];
    }
    
    // Get suggestions - filter by rating if logged in, show all if not
    $suggestions = [];
    $debug_info = [];
    
    if (is_user_logged_in()) {
        $cid = VatCar_ATC_Booking::vatcar_get_cid();
        $controller_data = VatCar_ATC_Booking::get_controller_data($cid);
        $suggestions = VatCar_Live_Traffic::get_complementary_suggestions($cid, $overlapping);
        
        // Add debug info when debug mode is enabled
        if (vatcar_atc_is_debug_enabled()) {
            $debug_info = [
                'cid' => $cid,
                'rating' => isset($controller_data['rating']) ? $controller_data['rating'] : 'N/A',
                'overlapping_count' => count($overlapping),
                'suggestion_count' => count($suggestions),
                'is_logged_in' => true,
            ];
        }
    } else {
        // Show all complementary positions (no rating filter) if not logged in
        $suggestions = VatCar_Live_Traffic::get_complementary_suggestions_unfiltered($overlapping);
        
        if (vatcar_atc_is_debug_enabled()) {
            $debug_info = [
                'is_logged_in' => false,
                'overlapping_count' => count($overlapping),
                'suggestion_count' => count($suggestions),
            ];
        }
    }
    */
    
    $overlapping_formatted = [];
    $debug_info = [];
    
    $response = [
        'overlapping' => $overlapping_formatted,
        'suggestions' => $suggestions,
    ];
    
    if (!empty($debug_info)) {
        $response['debug'] = $debug_info;
    }
    
    wp_send_json_success($response);
}


// Styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'VatCar-atc-bookings',
        plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
        [],
        '1.3.0'
    );
}, 20);

/**
 * Get the controller dashboard URL.
 */
function vatcar_controller_dashboard_url() {
    return home_url('/my-bookings/');
}

/**
 * Append a dashboard ref flag to internal URLs so resource pages can show
 * a "Back to Controller Dashboard" button only when navigated from dashboard.
 */
function vatcar_add_dashboard_ref_to_url($url) {
    $url = trim((string)$url);
    if ($url === '' || $url === '#') {
        return $url;
    }

    $parsed = wp_parse_url($url);
    if ($parsed === false) {
        return $url;
    }

    // Only tag internal links (relative OR same host).
    $is_relative = empty($parsed['host']);
    if (!$is_relative) {
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $url_host  = $parsed['host'] ?? '';
        if ($home_host === '' || $url_host === '' || strcasecmp($home_host, $url_host) !== 0) {
            return $url;
        }
    }

    return add_query_arg('vatcar_from', 'dashboard', $url);
}

// Update whitelist controller name on user login
add_action('wp_login', function($user_login, $user) {
    // Get CID from username (VATSIM Connect uses CID as username)
    $cid = $user->user_login;
    
    // Update controller name in whitelist if they're whitelisted
    VatCar_ATC_Booking::update_whitelist_controller_name($cid);
}, 10, 2);

// Admin styles
add_action('admin_enqueue_scripts', function($hook) {
    // Only enqueue on our plugin's admin pages
    if ($hook === 'toplevel_page_vatcar-atc-dashboard') {
        wp_enqueue_style(
            'VatCar-atc-bookings-admin',
            plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
            [],
            '1.3.0'
        );
    }
});

// Database schema - version-based migrations to prevent data loss
register_activation_hook(__FILE__, function() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $current_db_version = get_option('vatcar_db_version', '0');
    $charset_collate = $wpdb->get_charset_collate();
    
    // Main bookings table
    $table = $wpdb->prefix . 'atc_bookings';
    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        cid varchar(20) NOT NULL,
        api_cid varchar(20) NULL,
        callsign varchar(20) NOT NULL,
        type varchar(20) NOT NULL,
        start datetime NOT NULL,
        end datetime NOT NULL,
        division varchar(50) NOT NULL,
        subdivision varchar(50),
        external_id int NULL,
        controller_name varchar(100) NULL,
        created_by_cid varchar(20) NULL,
        PRIMARY KEY  (id),
        KEY callsign_start_end (callsign, start, end),
        KEY external_id (external_id)
    ) $charset_collate;";
    dbDelta($sql);

    // Add created_by_cid column if missing (idempotent migration)
    $booking_columns = $wpdb->get_col($wpdb->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
        $wpdb->dbname, $table
    ));
    if (!in_array('created_by_cid', $booking_columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN created_by_cid varchar(20) NULL AFTER controller_name");
    }

    // Controller whitelist table - ONLY CREATE, never use dbDelta for updates
    $whitelist_table = $wpdb->prefix . 'atc_controller_whitelist';
    $whitelist_exists = $wpdb->get_var("SHOW TABLES LIKE '$whitelist_table'") === $whitelist_table;
    
    if (!$whitelist_exists) {
        // Table doesn't exist - create it fresh
        $whitelist_sql = "CREATE TABLE $whitelist_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cid varchar(20) NOT NULL,
            notes text NULL,
            added_by bigint(20) NOT NULL,
            date_added datetime NOT NULL,
            expires_at datetime NULL,
            controller_name varchar(100) NULL,
            authorization_type varchar(20) DEFAULT 'visitor',
            PRIMARY KEY (id),
            UNIQUE KEY cid (cid)
        ) $charset_collate;";
        $wpdb->query($whitelist_sql);
    } else {
        // Table exists - only add missing columns (never use dbDelta)
        $whitelist_columns = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            $wpdb->dbname, $whitelist_table
        ));
        if (!in_array('expires_at', $whitelist_columns)) {
            $wpdb->query("ALTER TABLE $whitelist_table ADD COLUMN expires_at datetime NULL");
        }
        if (!in_array('controller_name', $whitelist_columns)) {
            $wpdb->query("ALTER TABLE $whitelist_table ADD COLUMN controller_name varchar(100) NULL");
        }
        if (!in_array('authorization_type', $whitelist_columns)) {
            $wpdb->query("ALTER TABLE $whitelist_table ADD COLUMN authorization_type varchar(20) DEFAULT 'visitor'");
        }
    }

    // Authorized positions table
    $positions_table = $wpdb->prefix . 'atc_authorized_positions';
    $positions_exists = $wpdb->get_var("SHOW TABLES LIKE '$positions_table'") === $positions_table;
    
    if (!$positions_exists) {
        $positions_sql = "CREATE TABLE $positions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            authorization_id mediumint(9) NOT NULL,
            callsign varchar(20) NOT NULL,
            date_granted datetime NOT NULL,
            PRIMARY KEY (id),
            KEY authorization_id (authorization_id),
            UNIQUE KEY auth_callsign (authorization_id, callsign)
        ) $charset_collate;";
        $wpdb->query($positions_sql);
    }

    // Booking compliance history table
    $history_table = $wpdb->prefix . 'atc_booking_compliance';
    $history_sql = "CREATE TABLE $history_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        booking_id mediumint(9) NOT NULL,
        cid varchar(20) NOT NULL,
        callsign varchar(20) NOT NULL,
        status varchar(20) NOT NULL,
        checked_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY booking_id (booking_id),
        KEY cid (cid),
        KEY checked_at (checked_at)
    ) $charset_collate;";
    dbDelta($history_sql);

    // Update database version
    update_option('vatcar_db_version', '1.4.0');

    // Schedule daily cleanup of expired bookings
    if (!wp_next_scheduled('vatcar_cleanup_expired_bookings')) {
        wp_schedule_event(time(), 'daily', 'vatcar_cleanup_expired_bookings');
    }
});

/**
 * Get station list from settings
 * @return array Sorted array of station callsigns (ordered by position type)
 */
/**
 * Get unique airport codes from subdivision's station list
 * 
 * @param string $subdivision Subdivision code (e.g., 'CUR')
 * @return array Array of unique airport ICAO codes (e.g., ['TNCC', 'TNCA', 'TNCM'])
 */
function vatcar_get_subdivision_airports($subdivision = 'CUR') {
    // Get configured stations
    $default = "TNCA_GND\nTNCA_TWR\nTNCA_APP\nTNCC_TWR\nTNCB_TWR\nTNCF_APP\nTNCF_CTR\nTNCM_DEL\nTNCM_TWR\nTNCM_APP\nTQPF_TWR";
    $stations_setting = get_option('vatcar_stations', $default);
    
    // Parse line-separated list
    $airports = [];
    $lines = preg_split('/\r\n|\r|\n/', $stations_setting);
    
    foreach ($lines as $line) {
        $station = trim($line);
        if (!empty($station) && strpos($station, '_') !== false) {
            // Extract airport code (everything before underscore)
            $airport = substr($station, 0, strpos($station, '_'));
            $airports[$airport] = true; // Use array key for uniqueness
        }
    }
    
    return array_keys($airports);
}

function vatcar_generate_station_list() {
    $default = "TNCA_GND\nTNCA_TWR\nTNCA_APP\nTNCC_TWR\nTNCB_TWR\nTNCF_APP\nTNCF_CTR\nTNCM_DEL\nTNCM_TWR\nTNCM_APP\nTQPF_TWR";
    $stations_setting = get_option('vatcar_stations', $default);
    
    // Parse line-separated list
    $stations = [];
    $lines = preg_split('/\r\n|\r|\n/', $stations_setting);
    
    foreach ($lines as $line) {
        $station = trim($line);
        if (!empty($station)) {
            $stations[] = strtoupper($station);
        }
    }
    
    // Remove duplicates
    $stations = array_unique($stations);
    
    // Sort by airport prefix first, then by position type within each airport
    usort($stations, function($a, $b) {
        // Extract airport prefix (everything before the underscore)
        $prefix_a = strpos($a, '_') !== false ? substr($a, 0, strpos($a, '_')) : $a;
        $prefix_b = strpos($b, '_') !== false ? substr($b, 0, strpos($b, '_')) : $b;
        
        // If different airports, sort alphabetically by airport
        if ($prefix_a !== $prefix_b) {
            return strcmp($prefix_a, $prefix_b);
        }
        
        // Same airport - sort by position type
        $get_priority = function($callsign) {
            if (preg_match('/_DEL$/', $callsign)) return 1;
            if (preg_match('/_GND$/', $callsign)) return 2;
            if (preg_match('/_RMP$/', $callsign)) return 2; // Same as GND
            if (preg_match('/_TWR$/', $callsign)) return 3;
            if (preg_match('/_APP$/', $callsign)) return 4;
            if (preg_match('/_DEP$/', $callsign)) return 4; // Same as APP
            if (preg_match('/_CTR$/', $callsign)) return 5;
            if (preg_match('/_FSS$/', $callsign)) return 5; // Same as CTR
            return 99; // Unknown positions go to end
        };
        
        $priority_a = $get_priority($a);
        $priority_b = $get_priority($b);
        
        // If same priority within same airport, sort alphabetically
        if ($priority_a === $priority_b) {
            return strcmp($a, $b);
        }
        
        return $priority_a - $priority_b;
    });
    
    return $stations;
}

// Diagnostic endpoint
// Diagnostic endpoint (restricted)
add_action('init', function() {
    if (isset($_GET['vatcar_atc_diag']) && $_GET['vatcar_atc_diag'] === '1') {
        // Only allow if debug mode is enabled AND user is an admin
        if (!(vatcar_atc_is_debug_enabled() && current_user_can('manage_options'))) {
            wp_die('Unauthorized');
        }

        if (!VATCAR_VATSIM_API_KEY) {
            wp_die('No API key defined.');
        }

        $url = add_query_arg([
            'key_only' => true,
            'sort'     => 'start',
            'sort_dir' => 'asc',
        ], VATCAR_VATSIM_API_BASE . '/api/booking');

        $resp = wp_remote_get($url, [
            'headers'   => vatcar_vatsim_headers(),
            'timeout'   => 15,
        ]);

        if (is_wp_error($resp)) {
            wp_die('WP_Error: ' . $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        header('Content-Type: text/plain');
        echo "GET /booking code: {$code}\n\n";
        echo $body;
        exit;
    }
});

// Centralized subdivision detection check for admin pages
function vatcar_admin_subdivision_check() {
    $subdivision = vatcar_detect_subdivision();
    return !empty($subdivision);
}

// Display admin notice if site is not recognised
add_action('admin_notices', function() {
    // Only show on our plugin's admin pages
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'vatcar-atc') !== false) {
        if (!vatcar_admin_subdivision_check()) {
            ?>
            <div class="notice notice-error">
                <?php echo vatcar_unrecognised_site_error(true); ?>
            </div>
            <?php
        }
    }
});

// Admin settings page and dashboard
add_action('admin_menu', function() {
    add_menu_page(
        'ATC Bookings',
        'ATC Bookings',
        'manage_options',
        'vatcar-atc-dashboard',
        ['VatCar_ATC_Dashboard', 'render_dashboard'],
        'dashicons-calendar-alt',
        30
    );

    add_submenu_page(
        'vatcar-atc-dashboard',
        'Controller Whitelist',
        'Controller Whitelist',
        'manage_options',
        'vatcar-atc-whitelist',
        'vatcar_atc_whitelist_page'
    );

    add_submenu_page(
        'vatcar-atc-dashboard',
        'Dashboard Resources',
        'Dashboard Resources',
        'manage_options',
        'vatcar-atc-resources',
        'vatcar_atc_resources_page'
    );

    add_submenu_page(
        'vatcar-atc-dashboard',
        'Settings',
        'Settings',
        'manage_options',
        'vatcar-atc-settings',
        'vatcar_atc_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('vatcar_atc_settings', 'vatcar_vatsim_api_key');
    register_setting('vatcar_atc_settings', 'vatcar_fir_subdivision');
    register_setting('vatcar_atc_settings', 'vatcar_stations');
    register_setting('vatcar_atc_settings', 'vatcar_atc_debug_mode');

    add_settings_section(
        'vatcar_atc_main',
        'VATSIM API Settings',
        function() {
            echo '<p>Configure your VATSIM ATC Bookings API connection.</p>';
        },
        'vatcar-atc-settings'
    );

    add_settings_field(
        'vatcar_vatsim_api_key',
        'API Key',
        function() {
            $value = esc_attr(get_option('vatcar_vatsim_api_key', ''));
            echo '<input type="password" name="vatcar_vatsim_api_key" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . ($value ? '••••••••••••••••' : 'Enter API key') . '" />';
            echo '<p class="description">API key is masked for security.</p>';
        },
        'vatcar-atc-settings',
        'vatcar_atc_main'
    );

    add_settings_section(
        'vatcar_atc_stations',
        'Station Configuration',
        function() {
            echo '<p>Define which ATC positions are available for booking in your FIR.</p>';
        },
        'vatcar-atc-settings'
    );

    add_settings_field(
        'vatcar_stations',
        'Available Stations',
        function() {
            $default = "TNCA_GND\nTNCA_TWR\nTNCA_APP\nTNCC_TWR\nTNCB_TWR\nTNCF_APP\nTNCF_CTR\nTNCM_DEL\nTNCM_TWR\nTNCM_APP\nTQPF_TWR";
            $value = get_option('vatcar_stations', $default);
            echo '<textarea name="vatcar_stations" rows="12" class="large-text code" style="font-family: monospace;">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">One station per line (e.g., TNCC_TWR). Stations are automatically sorted by position type on the booking form. Rating requirements: GND/RMP=S1, DEL/TWR=S2, APP/DEP=S3, CTR/FSS=C1.</p>';
        },
        'vatcar-atc-settings',
        'vatcar_atc_stations'
    );

    add_settings_section(
        'vatcar_atc_advanced',
        'Advanced Settings',
        function() {
            echo '<p>Debugging and diagnostic options for troubleshooting.</p>';
        },
        'vatcar-atc-settings'
    );

    add_settings_field(
        'vatcar_atc_debug_mode',
        'Debug Mode',
        function() {
            $enabled = get_option('vatcar_atc_debug_mode', false);
            echo '<label><input type="checkbox" name="vatcar_atc_debug_mode" value="1" ' . checked(1, $enabled, false) . ' /> Enable debug mode</label>';
            echo '<p class="description">When enabled, detailed diagnostic information will be shown in error messages (authorization details, API responses, etc.). Only affects plugin errors. <strong>Disable in production.</strong></p>';
        },
        'vatcar-atc-settings',
        'vatcar_atc_advanced'
    );
    
    // Resources configuration (separate settings page)
    register_setting('vatcar_atc_resources_settings', 'vatcar_resources_enabled');
    register_setting('vatcar_atc_resources_settings', 'vatcar_resources_config');
});

/**
 * Render controller resources configuration fields
 */
function vatcar_resources_config_field() {
    $defaults = [
        'sops' => [
            'title' => 'Standard Operating Procedures',
            'description' => 'Official SOPs for all positions in the FIR',
            'icon' => '📄',
            'url' => ''
        ],
        'charts' => [
            'title' => 'Charts & Diagrams',
            'description' => 'Sector maps, airspace diagrams, and reference materials',
            'icon' => '📊',
            'url' => ''
        ],
        'training' => [
            'title' => 'Training Materials',
            'description' => 'Study guides, practical exercises, and certification resources',
            'icon' => '📚',
            'url' => ''
        ],
        'loa' => [
            'title' => 'Letters of Agreement',
            'description' => 'Coordination procedures with adjacent FIRs and facilities',
            'icon' => '📝',
            'url' => ''
        ],
        'schedule' => [
            'title' => 'Full ATC Schedule',
            'description' => 'View all upcoming controller bookings in the FIR',
            'icon' => '📅',
            'url' => '/controller-schedule/'
        ],
        'downloads' => [
            'title' => 'Downloads',
            'description' => 'Sector files, plugins, and other essential downloads',
            'icon' => '⬇️',
            'url' => ''
        ]
    ];
    
    $config = get_option('vatcar_resources_config', $defaults);
    
    // Merge with defaults to ensure all fields exist
    foreach ($defaults as $key => $default) {
        if (!isset($config[$key])) {
            $config[$key] = $default;
        }
    }
    
    echo '<table class="widefat" style="max-width: 900px;">';
    echo '<thead><tr><th style="width:15%;">Resource</th><th style="width:25%;">Title</th><th style="width:35%;">URL</th><th style="width:25%;">Description</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($config as $key => $resource) {
        $title = isset($resource['title']) ? esc_attr($resource['title']) : '';
        $url = isset($resource['url']) ? esc_attr($resource['url']) : '';
        $description = isset($resource['description']) ? esc_attr($resource['description']) : '';
        $icon = isset($resource['icon']) ? esc_html($resource['icon']) : '';
        
        echo '<tr>';
        echo '<td><strong>' . $icon . ' ' . esc_html(ucfirst($key)) . '</strong></td>';
        echo '<td><input type="text" name="vatcar_resources_config[' . esc_attr($key) . '][title]" value="' . $title . '" class="regular-text" /></td>';
        echo '<td><input type="text" name="vatcar_resources_config[' . esc_attr($key) . '][url]" value="' . $url . '" class="regular-text" placeholder="Leave empty to hide" /></td>';
        echo '<td><input type="text" name="vatcar_resources_config[' . esc_attr($key) . '][description]" value="' . $description . '" class="regular-text" /></td>';
        echo '<input type="hidden" name="vatcar_resources_config[' . esc_attr($key) . '][icon]" value="' . esc_attr($icon) . '" />';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '<p class="description">Leave URL field empty to hide a resource from the dashboard. Supports absolute URLs (/page-slug/) or full URLs (https://example.com/page).</p>';
}

function vatcar_atc_resources_page() {
    // Prevent access if site not recognised
    if (!vatcar_admin_subdivision_check()) {
        echo '<div class="wrap"><h1>Dashboard Resources</h1></div>';
        return;
    }
    ?>
    <div class="wrap">
        <h1>Controller Dashboard Resources</h1>
        <p>Configure the resource links that appear on the controller dashboard. These provide quick access to important documents and materials for controllers.</p>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('vatcar_atc_resources_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable Resources Section</th>
                    <td>
                        <?php $enabled = get_option('vatcar_resources_enabled', true); ?>
                        <label>
                            <input type="checkbox" name="vatcar_resources_enabled" value="1" <?php checked(1, $enabled); ?> />
                            Show resources section on controller dashboard
                        </label>
                        <p class="description">Uncheck to completely hide the resources section from the controller dashboard.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Resource Configuration</h2>
            <p>Configure each resource link below. Leave the URL field empty to hide that specific resource.</p>
            
            <?php vatcar_resources_config_field(); ?>
            
            <?php submit_button('Save Resource Configuration'); ?>
        </form>
    </div>
    <?php
}

function vatcar_atc_settings_page() {
    // Prevent access if site not recognised
    if (!vatcar_admin_subdivision_check()) {
        echo '<div class="wrap"><h1>FIR ATC Bookings Settings</h1></div>';
        return;
    }
    ?>
    <div class="wrap">
        <h1>FIR ATC Bookings Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('vatcar_atc_settings');
            do_settings_sections('vatcar-atc-settings');
            submit_button();
            ?>
        </form>

        <?php if (vatcar_atc_is_debug_enabled() && current_user_can('manage_options')): ?>
            <hr style="margin: 30px 0;">
            <h2>Diagnostics</h2>
            <p>Run a quick connectivity test against the VATSIM ATC Bookings API.</p>
            <a href="<?php echo esc_url(add_query_arg('vatcar_atc_diag', '1', admin_url())); ?>" 
               class="button button-secondary" target="_blank">
               Run Diagnostic
            </a>
        <?php endif; ?>
    </div>
    <?php
}

function vatcar_atc_whitelist_page() {
    // Prevent access if site not recognised
    if (!vatcar_admin_subdivision_check()) {
        echo '<div class="wrap"><h1>Controller Whitelist</h1></div>';
        return;
    }
    
    // Positions from booking form - these are the actual stations controllers can book
    $positions = [
        'TNCA_GND' => 'Aruba Ramp (Queen Beatrix)',
        'TNCA_TWR' => 'Aruba Tower (Queen Beatrix)',
        'TNCA_APP' => 'Aruba Approach (Queen Beatrix)',
        'TNCC_TWR' => 'Curacao Tower (Willemstad)',
        'TNCB_TWR' => 'Bonaire Tower (Flamingo)',
        'TNCF_APP' => 'St. Maarten Approach',
        'TNCF_CTR' => 'Curacao Control',
        'TNCM_DEL' => 'St. Maarten Delivery (Princess Juliana)',
        'TNCM_TWR' => 'St. Maarten Tower (Princess Juliana)',
        'TNCM_APP' => 'St. Maarten Approach (Princess Juliana)',
        'TQPF_TWR' => 'Anguilla Tower (Clayton J. Lloyd)',
    ];
    ?>
    <div class="wrap">
        <h1>Controller Whitelist</h1>
        <p>Manage visitor whitelist and solo certifications for controllers.</p>
        
        <form id="add-controller-form" style="margin-bottom: 20px;">
            <?php wp_nonce_field('vatcar_add_controller', 'vatcar_add_controller_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="controller_cid">Controller CID</label></th>
                    <td>
                        <input type="text" name="controller_cid" id="controller_cid" class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th><label for="authorization_type">Authorization Type</label></th>
                    <td>
                        <select name="authorization_type" id="authorization_type" class="regular-text">
                            <option value="visitor">Visitor (bypass division/subdivision checks)</option>
                            <option value="solo">Solo Certification (local controller, specific positions)</option>
                        </select>
                        <p class="description">
                            <strong>Visitor:</strong> For controllers from other divisions/subdivisions.<br>
                            <strong>Solo:</strong> For CAR/CUR controllers with certifications for specific positions.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label id="positions-label">Authorized Positions</label></th>
                    <td>
                        <p class="description" id="positions-description">Select specific positions this controller can book. Leave all unchecked to allow all positions.</p>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                            <?php foreach ($positions as $callsign => $name): ?>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" name="positions[]" value="<?php echo esc_attr($callsign); ?>" />
                                    <?php echo esc_html($callsign); ?> - <?php echo esc_html($name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label for="controller_notes">Notes (optional)</label></th>
                    <td>
                        <textarea name="controller_notes" id="controller_notes" rows="3" class="large-text"></textarea>
                        <p class="description">E.g., "S3 visitor from ZAN", "Solo cert for TNCC_TWR"</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="controller_expires">Expires <span id="expires-required" style="color:red;"></span></label></th>
                    <td>
                        <input type="datetime-local" name="controller_expires" id="controller_expires" />
                        <p class="description" id="expires-description">Leave empty for permanent access. Expired entries remain visible but don't grant access.</p>
                    </td>
                </tr>
            </table>
            <button type="submit" class="button button-primary">Add Authorization</button>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // Update label and required fields based on authorization type
            $('#authorization_type').on('change', function() {
                var type = $(this).val();
                if (type === 'solo') {
                    $('#positions-label').text('Authorized Additional Positions');
                    $('#positions-description').html('<strong>Solo Certification:</strong> Select additional positions beyond their base rating. Controller can still book positions allowed by their base rating (e.g., S1 can book GND/DEL even if not listed).');
                    $('#expires-required').text('(required)');
                    $('#controller_expires').prop('required', true);
                    $('#expires-description').text('Solo certifications must have an expiration date.');
                } else {
                    $('#positions-label').text('Authorized Positions');
                    $('#positions-description').text('Select specific positions this controller can book. Leave all unchecked to allow all positions.');
                    $('#expires-required').text('');
                    $('#controller_expires').prop('required', false);
                    $('#expires-description').text('Leave empty for permanent access. Expired entries remain visible but don\'t grant access.');
                }
            }).trigger('change');
        });
        </script>

        <h3>Current Authorizations</h3>
        <table class="wp-list-table widefat fixed striped" id="controller-whitelist-table">
            <thead>
                <tr>
                    <th style="width: 8%;">CID</th>
                    <th style="width: 12%;">Name</th>
                    <th style="width: 8%;">Type</th>
                    <th style="width: 18%;">Authorized Positions</th>
                    <th style="width: 15%;">Notes</th>
                    <th style="width: 10%;">Added By</th>
                    <th style="width: 9%;">Date Added</th>
                    <th style="width: 9%;">Expires</th>
                    <th style="width: 7%;">Status</th>
                    <th style="width: 4%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $whitelist = VatCar_ATC_Booking::get_whitelist();
                $now = current_time('timestamp');
                if (empty($whitelist)) {
                    echo '<tr><td colspan="10" style="text-align: center; color: #999;">No authorizations configured</td></tr>';
                } else {
                    foreach ($whitelist as $entry) {
                        $added_by_user = get_user_by('ID', $entry->added_by);
                        $added_by_name = $added_by_user ? $added_by_user->display_name : 'Unknown';
                        
                        // Check if expired
                        $is_expired = false;
                        $expires_display = 'Never';
                        if (!empty($entry->expires_at)) {
                            $expires_ts = strtotime($entry->expires_at);
                            $is_expired = $expires_ts <= $now;
                            $expires_display = date('Y-m-d H:i', $expires_ts);
                        }
                        
                        $row_style = $is_expired ? 'opacity: 0.5; background-color: #f9f9f9;' : '';
                        $status_text = $is_expired ? '<span style="color: #d63638;">Expired</span>' : '<span style="color: #00a32a;">Active</span>';
                        
                        // Display controller name if available
                        $name_display = !empty($entry->controller_name) 
                            ? esc_html($entry->controller_name) 
                            : '<em style="color: #999;">Not logged in yet</em>';
                        
                        // Get authorized positions
                        $positions = VatCar_ATC_Booking::get_authorized_positions($entry->id);
                        $positions_display = empty($positions) 
                            ? '<em style="color: #666;">All positions</em>' 
                            : implode(', ', array_map('esc_html', $positions));
                        
                        // Authorization type display
                        $type_display = ($entry->authorization_type ?? 'visitor') === 'solo' 
                            ? '<span style="color: #135e96;">Solo</span>' 
                            : '<span style="color: #2271b1;">Visitor</span>';
                        ?>
                        <tr data-cid="<?php echo esc_attr($entry->cid); ?>" style="<?php echo esc_attr($row_style); ?>">
                            <td><strong><?php echo esc_html($entry->cid); ?></strong></td>
                            <td><?php echo esc_html($name_display); ?></td>
                            <td><?php echo wp_kses_post($type_display); ?></td>
                            <td style="font-size: 0.9em;"><?php echo wp_kses_post($positions_display); ?></td>
                            <td><?php echo esc_html($entry->notes); ?></td>
                            <td><?php echo esc_html($added_by_name); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($entry->date_added))); ?></td>
                            <td><?php echo esc_html($expires_display); ?></td>
                            <td><?php echo $status_text; ?></td>
                            <td>
                                <button type="button" class="button button-small extend-controller" 
                                        data-cid="<?php echo esc_attr($entry->cid); ?>"
                                        style="margin-right: 5px;">
                                    <?php echo $is_expired ? 'Renew' : 'Extend'; ?>
                                </button>
                                <button type="button" class="button button-small remove-controller" 
                                        data-cid="<?php echo esc_attr($entry->cid); ?>">
                                    Remove
                                </button>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>

        <!-- Renew/Extend Modal -->
        <div id="renew-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: #fff; margin: 10% auto; padding: 20px; border-radius: 5px; width: 500px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                <h2 style="margin-top: 0;">Update Expiration</h2>
                <p>Set a new expiration date or leave empty for permanent access.</p>
                <form id="renew-form">
                    <input type="hidden" id="renew-cid" />
                    <table class="form-table">
                        <tr>
                            <th><label for="renew-expires">New Expiration</label></th>
                            <td>
                                <input type="datetime-local" id="renew-expires" style="width: 100%;" />
                                <p class="description">Leave empty for permanent access</p>
                            </td>
                        </tr>
                    </table>
                    <p style="text-align: right;">
                        <button type="button" class="button" id="renew-cancel">Cancel</button>
                        <button type="submit" class="button button-primary">Update Expiration</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Add controller
            $('#add-controller-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                $button.prop('disabled', true).text('Adding...');
                
                // Collect selected positions
                var positions = [];
                $('input[name="positions[]"]:checked').each(function() {
                    positions.push($(this).val());
                });
                
                $.post(ajaxurl, {
                    action: 'add_controller',
                    vatcar_add_controller_nonce: $('#vatcar_add_controller_nonce').val(),
                    cid: $('#controller_cid').val(),
                    notes: $('#controller_notes').val(),
                    expires: $('#controller_expires').val(),
                    authorization_type: $('#authorization_type').val(),
                    positions: positions
                }, function(response) {
                    if (response.success) {
                        alert('Authorization added successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).text('Add Authorization');
                    }
                });
            });

            // Remove controller
            $('.remove-controller').on('click', function() {
                if (!confirm('Remove this controller from the whitelist?')) return;
                
                var $button = $(this);
                var cid = $button.data('cid');
                $button.prop('disabled', true).text('Removing...');
                
                $.post(ajaxurl, {
                    action: 'remove_controller',
                    vatcar_remove_controller_nonce: '<?php echo wp_create_nonce("vatcar_remove_controller"); ?>',
                    cid: cid
                }, function(response) {
                    if (response.success) {
                        $button.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).text('Remove');
                    }
                });
            });

            // Extend/Renew visitor - open modal
            jQuery('.extend-controller').on('click', function() {
                console.log('Extend button clicked');
                var cid = jQuery(this).data('cid');
                console.log('CID:', cid);
                jQuery('#renew-cid').val(cid);
                jQuery('#renew-expires').val('');
                jQuery('#renew-modal').fadeIn(200);
            });

            // Close modal
            jQuery('#renew-cancel').on('click', function() {
                jQuery('#renew-modal').fadeOut(200);
            });

            // Submit renewal form
            $('#renew-form').on('submit', function(e) {
                e.preventDefault();
                
                var cid = $('#renew-cid').val();
                var expires = $('#renew-expires').val();
                var $submitBtn = $(this).find('button[type="submit"]');
                
                $submitBtn.prop('disabled', true).text('Updating...');
                
                $.post(ajaxurl, {
                    action: 'renew_controller',
                    vatcar_renew_controller_nonce: '<?php echo wp_create_nonce("vatcar_renew_controller"); ?>',
                    cid: cid,
                    expires: expires
                }, function(response) {
                    if (response.success) {
                        alert('Expiration updated successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $submitBtn.prop('disabled', false).text('Update Expiration');
                    }
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler to refresh delete booking nonce
 * This helps prevent stale nonce issues in long-lived page sessions
 */
function vatcar_ajax_refresh_delete_nonce() {
    // User must be logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
    }
    
    // Generate fresh nonce
    $fresh_nonce = wp_create_nonce('vatcar_delete_booking');
    
    error_log('VATCAR: Fresh delete nonce generated for user ' . get_current_user_id() . ': ' . substr($fresh_nonce, 0, 10) . '...');
    
    wp_send_json_success([
        'nonce' => $fresh_nonce,
        'user_id' => get_current_user_id(),
    ]);
}
