<?php
/**
 * Plugin Name: VATCAR FIR Station Booking
 * Description: ATC booking system for WordPress, integrating with VATSIM ATC Bookings API.
 * Version: 1.1.0
 * Author: Sav Monzac
 * GitHub Plugin URI: savmon120/curacao-controller-bookings-plugin
 * Primary Branch: main
 */

if (!defined('ABSPATH')) exit;

if (!defined('VATCAR_VATSIM_API_BASE')) {
    define('VATCAR_VATSIM_API_BASE', 'https://atc-bookings.vatsim.net');
}
if (!defined('VATCAR_VATSIM_API_KEY')) {
    // Load API key from WP options (admin settings page)
    define('VATCAR_VATSIM_API_KEY', get_option('vatcar_vatsim_api_key', ''));
}

// Debug toggle: set true in dev, false in production
if (!defined('VATCAR_ATC_DEBUG')) {
    define('VATCAR_ATC_DEBUG', false); // flip to false in production
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

// Cleanup expired bookings function
function vatcar_cleanup_expired_bookings() {
    global $wpdb;
    $table = $wpdb->prefix . 'atc_bookings';
    $now = current_time('mysql');
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE end < %s", $now));
}

// Schedule cleanup on activation
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('vatcar_cleanup_expired_bookings')) {
        wp_schedule_event(time(), 'daily', 'vatcar_cleanup_expired_bookings');
    }
});

// Unschedule on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('vatcar_cleanup_expired_bookings');
});

// Hook the cleanup function
add_action('vatcar_cleanup_expired_bookings', 'vatcar_cleanup_expired_bookings');

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

function vatcar_detect_subdivision() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (strpos($host, 'curacao.vatcar.net') !== false || strpos($host, 'curacao.vatcar.local') !== false) {
        return 'CUR';
    }
    if (strpos($host, 'piarco.vatcar.net') !== false) {
        return 'PIA';
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

// AJAX handlers
add_action('wp_ajax_update_booking', ['VatCar_ATC_Booking', 'ajax_update_booking']);
add_action('wp_ajax_delete_booking', ['VatCar_ATC_Booking', 'ajax_delete_booking']);
add_action('wp_ajax_vatcar_get_booking_status', ['VatCar_ATC_Dashboard', 'ajax_get_booking_status']);
add_action('wp_ajax_vatcar_get_compliance_history', ['VatCar_ATC_Dashboard', 'ajax_get_compliance_history']);

// Styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'VatCar-atc-bookings',
        plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
        [],
        '1.0'
    );
}, 20);

// Admin styles
add_action('admin_enqueue_scripts', function($hook) {
    // Only enqueue on our plugin's admin pages
    if ($hook === 'toplevel_page_vatcar-atc-dashboard') {
        wp_enqueue_style(
            'VatCar-atc-bookings-admin',
            plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
            [],
            '1.0'
        );
    }
});

// Database schema
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $table = $wpdb->prefix . 'atc_bookings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        cid varchar(20) NOT NULL,          -- real controller CID
        api_cid varchar(20) NULL,          -- service account CID used in API calls
        callsign varchar(20) NOT NULL,
        type varchar(20) NOT NULL,
        start datetime NOT NULL,
        end datetime NOT NULL,
        division varchar(50) NOT NULL,
        subdivision varchar(50),
        external_id int NULL,
        PRIMARY KEY  (id),
        KEY callsign_start_end (callsign, start, end),
        KEY external_id (external_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Idempotent column adds for upgrades
    $columns = $wpdb->get_col($wpdb->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
        $wpdb->dbname, $table
    ));
    if (!in_array('external_id', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN external_id int NULL");
    }
    if (!in_array('api_cid', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN api_cid varchar(20) NULL");
    }

    // Create booking compliance history table
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
        KEY checked_at (checked_at),
        FOREIGN KEY (booking_id) REFERENCES $table(id) ON DELETE CASCADE
    ) $charset_collate;";
    
    dbDelta($history_sql);

    // Schedule daily cleanup of expired bookings
    if (!wp_next_scheduled('vatcar_cleanup_expired_bookings')) {
        wp_schedule_event(time(), 'daily', 'vatcar_cleanup_expired_bookings');
    }
});

// Diagnostic endpoint
// Diagnostic endpoint (restricted)
add_action('init', function() {
    if (isset($_GET['vatcar_atc_diag']) && $_GET['vatcar_atc_diag'] === '1') {
        // Only allow if debug mode is enabled AND user is an admin
        if (!(defined('VATCAR_ATC_DEBUG') && VATCAR_ATC_DEBUG && current_user_can('manage_options'))) {
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
            echo '<input type="text" name="vatcar_vatsim_api_key" value="' . $value . '" class="regular-text" />';
        },
        'vatcar-atc-settings',
        'vatcar_atc_main'
    );
});

function vatcar_atc_settings_page() {
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
        <?php if (defined('VATCAR_ATC_DEBUG') && VATCAR_ATC_DEBUG && current_user_can('manage_options')): ?>
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
