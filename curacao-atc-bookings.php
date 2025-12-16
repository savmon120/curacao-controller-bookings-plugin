<?php
/**
 * Plugin Name: Curacao FIR ATC Bookings
 * Description: ATC booking system for WordPress, integrating with VATSIM ATC Bookings API.
 * Version: 1.0.0
 * Author: Sav
 * GitHub Plugin URI: savmon120/curacao-controller-bookings-plugin
 * Primary Branch: main
 */

if (!defined('ABSPATH')) exit;

if (!defined('CURACAO_VATSIM_API_BASE')) {
    define('CURACAO_VATSIM_API_BASE', 'https://atc-bookings.vatsim.net');
}
if (!defined('CURACAO_VATSIM_API_KEY')) {
    // Load API key from WP options (admin settings page)
    define('CURACAO_VATSIM_API_KEY', get_option('curacao_vatsim_api_key', ''));
}

// Debug toggle: set true in dev, false in production
if (!defined('CURACAO_ATC_DEBUG')) {
    define('CURACAO_ATC_DEBUG', true); // flip to false in production
}

function curacao_vatsim_headers() {
    return [
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . CURACAO_VATSIM_API_KEY,
    ];
}

// Core classes
require_once plugin_dir_path(__FILE__) . 'includes/class-booking.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-schedule.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-validation.php';

// Shortcodes
add_shortcode('curacao_atc_booking', ['Curacao_ATC_Booking', 'render_form']);
add_shortcode('curacao_atc_schedule', ['Curacao_ATC_Schedule', 'render_table']);

// AJAX handlers
add_action('wp_ajax_update_booking', ['Curacao_ATC_Booking', 'ajax_update_booking']);
add_action('wp_ajax_delete_booking', ['Curacao_ATC_Booking', 'ajax_delete_booking']);

// Styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'curacao-atc-bookings',
        plugin_dir_url(__FILE__) . 'assets/css/curacao-atc-bookings.css',
        [],
        '1.0'
    );
}, 20);

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
});

// Diagnostic endpoint
// Diagnostic endpoint (restricted)
add_action('init', function() {
    if (isset($_GET['curacao_atc_diag']) && $_GET['curacao_atc_diag'] === '1') {
        // Only allow if debug mode is enabled AND user is an admin
        if (!(defined('CURACAO_ATC_DEBUG') && CURACAO_ATC_DEBUG && current_user_can('manage_options'))) {
            wp_die('Unauthorized');
        }

        if (!CURACAO_VATSIM_API_KEY) {
            wp_die('No API key defined.');
        }

        $url = add_query_arg([
            'key_only' => true,
            'sort'     => 'start',
            'sort_dir' => 'asc',
        ], CURACAO_VATSIM_API_BASE . '/api/booking');

        $resp = wp_remote_get($url, [
            'headers'   => curacao_vatsim_headers(),
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


// Admin settings page
add_action('admin_menu', function() {
    add_options_page(
        'Curacao ATC Bookings',
        'Curacao ATC Bookings',
        'manage_options',
        'curacao-atc-bookings',
        'curacao_atc_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('curacao_atc_settings', 'curacao_vatsim_api_key');

    add_settings_section(
        'curacao_atc_main',
        'VATSIM API Settings',
        function() {
            echo '<p>Configure your VATSIM ATC Bookings API connection.</p>';
        },
        'curacao-atc-bookings'
    );

    add_settings_field(
        'curacao_vatsim_api_key',
        'API Key',
        function() {
            $value = esc_attr(get_option('curacao_vatsim_api_key', ''));
            echo '<input type="text" name="curacao_vatsim_api_key" value="' . $value . '" class="regular-text" />';
        },
        'curacao-atc-bookings',
        'curacao_atc_main'
    );
});

function curacao_atc_settings_page() {
    ?>
    <div class="wrap">
        <h1>Curacao FIR ATC Bookings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('curacao_atc_settings');
            do_settings_sections('curacao-atc-bookings');
            submit_button();
            ?>
        </form>
        <?php if (defined('CURACAO_ATC_DEBUG') && CURACAO_ATC_DEBUG && current_user_can('manage_options')): ?>
            <h2>Diagnostics</h2>
            <p>Run a quick connectivity test against the VATSIM ATC Bookings API.</p>
            <a href="<?php echo esc_url(add_query_arg('curacao_atc_diag', '1', admin_url())); ?>" 
               class="button button-secondary" target="_blank">
               Run Diagnostic
            </a>
        <?php endif; ?>
    </div>
    <?php
}
