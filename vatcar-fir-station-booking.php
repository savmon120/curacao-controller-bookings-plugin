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
add_action('wp_ajax_add_controller', 'vatcar_ajax_add_controller');
add_action('wp_ajax_remove_controller', 'vatcar_ajax_remove_controller');
add_action('wp_ajax_renew_controller', 'vatcar_ajax_renew_controller');

/**
 * AJAX handler: Add controller to whitelist
 */
function vatcar_ajax_add_controller() {
    if (!isset($_POST['vatcar_add_controller_nonce']) 
        || !wp_verify_nonce($_POST['vatcar_add_controller_nonce'], 'vatcar_add_controller')) {
        wp_send_json_error('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $cid = sanitize_text_field($_POST['cid'] ?? '');
    $notes = sanitize_textarea_field($_POST['notes'] ?? '');
    $expires = sanitize_text_field($_POST['expires'] ?? '');
    $authorization_type = sanitize_text_field($_POST['authorization_type'] ?? 'visitor');
    $positions = isset($_POST['positions']) && is_array($_POST['positions']) 
        ? array_map('sanitize_text_field', $_POST['positions']) 
        : [];

    if (empty($cid)) {
        wp_send_json_error('CID is required');
    }

    // Convert HTML5 datetime-local format to MySQL datetime
    $expires_at = null;
    if (!empty($expires)) {
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($expires));
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
 * AJAX handler: Remove controller from whitelist
 */
function vatcar_ajax_remove_controller() {
    if (!isset($_POST['vatcar_remove_controller_nonce']) 
        || !wp_verify_nonce($_POST['vatcar_remove_controller_nonce'], 'vatcar_remove_controller')) {
        wp_send_json_error('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
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
    if (!isset($_POST['vatcar_renew_controller_nonce']) 
        || !wp_verify_nonce($_POST['vatcar_renew_controller_nonce'], 'vatcar_renew_controller')) {
        wp_send_json_error('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $cid = sanitize_text_field($_POST['cid'] ?? '');
    $expires = sanitize_text_field($_POST['expires'] ?? '');

    if (empty($cid)) {
        wp_send_json_error('CID is required');
    }

    // Convert HTML5 datetime-local format to MySQL datetime, or set NULL for permanent
    $expires_at = null;
    if (!empty($expires)) {
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($expires));
    }

    $result = VatCar_ATC_Booking::update_whitelist_expiration($cid, $expires_at);

    if ($result) {
        wp_send_json_success('Visitor expiration updated successfully');
    } else {
        wp_send_json_error('Failed to update visitor');
    }
}


// Styles
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'VatCar-atc-bookings',
        plugin_dir_url(__FILE__) . 'assets/css/VatCar-atc-bookings.css',
        [],
        '1.0'
    );
}, 20);

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
    if (!in_array('controller_name', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN controller_name varchar(100) NULL");
    }

    // Create controller whitelist table
    $whitelist_table = $wpdb->prefix . 'atc_controller_whitelist';
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
    dbDelta($whitelist_sql);
    
    // Add expires_at column if it doesn't exist (for upgrades)
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

    // Create authorized positions table
    $positions_table = $wpdb->prefix . 'atc_authorized_positions';
    $positions_sql = "CREATE TABLE $positions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        authorization_id mediumint(9) NOT NULL,
        callsign varchar(20) NOT NULL,
        date_granted datetime NOT NULL,
        PRIMARY KEY (id),
        KEY authorization_id (authorization_id),
        UNIQUE KEY auth_callsign (authorization_id, callsign)
    ) $charset_collate;";
    dbDelta($positions_sql);

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
        'Controller Whitelist',
        'Controller Whitelist',
        'manage_options',
        'vatcar-atc-whitelist',
        'vatcar_atc_whitelist_page'
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
                    <th><label>Authorized Positions</label></th>
                    <td>
                        <p class="description">Select specific positions this controller can book. Leave all unchecked to allow all positions.</p>
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
                    <th><label for="controller_expires">Expires (optional)</label></th>
                    <td>
                        <input type="datetime-local" name="controller_expires" id="controller_expires" />
                        <p class="description">Leave empty for permanent access. Expired entries remain visible but don't grant access.</p>
                    </td>
                </tr>
            </table>
            <button type="submit" class="button button-primary">Add Authorization</button>
        </form>

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
                            <td><?php echo $name_display; ?></td>
                            <td><?php echo $type_display; ?></td>
                            <td style="font-size: 0.9em;"><?php echo $positions_display; ?></td>
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
