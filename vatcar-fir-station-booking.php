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

// Shortcodes
add_shortcode('vatcar_atc_booking', ['VatCar_ATC_Booking', 'render_form']);
add_shortcode('vatcar_atc_schedule', ['VatCar_ATC_Schedule', 'render_table']);

// AJAX handlers
add_action('wp_ajax_update_booking', ['VatCar_ATC_Booking', 'ajax_update_booking']);
add_action('wp_ajax_delete_booking', ['VatCar_ATC_Booking', 'ajax_delete_booking']);

// Styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'VatCar-atc-bookings',
        plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
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


// Admin settings page
add_action('admin_menu', function() {
    add_options_page(
        'VatCar ATC Bookings',
        'Vatcar ATC Bookings',
        'manage_options',
        'vatcar-atc-bookings',
        'vatcar_atc_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('vatcar_atc_settings', 'vatcar_vatsim_api_key');

    add_settings_section(
        'vatcar_atc_main',
        'VATSIM API Settings',
        function() {
            echo '<p>Configure your VATSIM ATC Bookings API connection.</p>';
        },
        'vatcar-atc-bookings'
    );

    add_settings_field(
        'vatcar_vatsim_api_key',
        'API Key',
        function() {
            $value = esc_attr(get_option('vatcar_vatsim_api_key', ''));
            echo '<input type="text" name="vatcar_vatsim_api_key" value="' . $value . '" class="regular-text" />';
        },
        'vatcar-atc-bookings',
        'vatcar_atc_main'
    );
});

function vatcar_atc_settings_page() {
    ?>
    <div class="wrap">
        <h1>FIR ATC Bookings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('vatcar_atc_settings');
            do_settings_sections('vatcar-atc-bookings');
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
