<?php
class VatCar_ATC_Booking {

    /**
     * Render the booking form (shortcode handler).
     */
    public static function render_form() {
        ob_start();

        // Require login (except local dev)
        if (strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') === true) {
            if (!is_user_logged_in()) {
                echo '<p>You must be logged in to book a station.</p>';
                return ob_get_clean();
            }
            $user = wp_get_current_user();
            if (!in_array('controller', (array) $user->roles, true)) {
                echo '<p>You do not have permission to book ATC stations.</p>';
                return ob_get_clean();
            }
        }

        // Handle form submission (non-AJAX create)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['callsign'])) {

            // Nonce verification
            if (!isset($_POST['vatcar_booking_nonce'])
                || !wp_verify_nonce($_POST['vatcar_booking_nonce'], 'vatcar_new_booking')) {
                wp_die('Security check failed');
            }

            // SAFETY: Force UTC parsing/storage.
            // (Your form JS may convert local->UTC before submit; this ensures we store in UTC regardless of server TZ.)
            $start_str = trim((string)($_POST['start_date'] ?? '')) . ' ' . trim((string)($_POST['start_time'] ?? ''));
            $end_str   = trim((string)($_POST['end_date'] ?? ''))   . ' ' . trim((string)($_POST['end_time'] ?? ''));

            $start_ts = strtotime($start_str . ' UTC');
            $end_ts   = strtotime($end_str . ' UTC');

            if (!$start_ts || !$end_ts) {
                echo '<p style="color:red;">Error: Invalid start/end date or time.</p>';
            } else {
                $start = gmdate('Y-m-d H:i:s', $start_ts);
                $end   = gmdate('Y-m-d H:i:s', $end_ts);

                $result = self::save_booking([
                    // Always use the authenticated controller's CID, do not trust POST
                    'cid'         => self::vatcar_get_cid(),
                    'callsign'    => sanitize_text_field($_POST['callsign'] ?? ''),
                    'start'       => $start,
                    'end'         => $end,
                    'division'    => 'CAR',
                    'subdivision' => 'CUR',
                    'type'        => 'booking',
                ]);

                if (is_wp_error($result)) {
                    echo '<p style="color:red;">Error: ' . esc_html($result->get_error_message()) . '</p>';
                } else {
                    // Show success briefly, then redirect (2s)
                    $target = home_url('/controller-schedule/');

                    echo '<p style="color:green; font-weight:600;">Booking saved successfully!</p>';
                    echo '<p style="color:#666; font-size:13px;">Redirecting to controller schedule…</p>';

                    // 2-second redirect (safe even if headers already sent)
                    echo '<meta http-equiv="refresh" content="2;url=' . esc_url($target) . '">';

                    // JS fallback (also 2s)
                    echo '<script>
                        setTimeout(function () {
                            window.location.href = ' . json_encode($target) . ';
                        }, 2000);
                    </script>';

                    return ob_get_clean(); // IMPORTANT: stop before rendering the form again
                }
            }
        }

        include plugin_dir_path(__FILE__) . '../templates/booking-form.php';
        return ob_get_clean();
    }

    /**
     * Create booking via VATSIM API, then cache locally.
     */
    public static function save_booking($data) {
        // Local validation
        if (empty($data['callsign'])) return new WP_Error('missing_callsign', 'You must select a station.');
        if (empty($data['start']))    return new WP_Error('missing_start', 'You must select a start date and time.');
        if (empty($data['end']))      return new WP_Error('missing_end', 'You must select an end date and time.');
        if (!VatCar_ATC_Validation::valid_callsign($data['callsign'])) {
            return new WP_Error('invalid_callsign', 'Invalid callsign format.');
        }

        $now_plus_6h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_6h)) {
            return new WP_Error('invalid_start', 'Start time must be at least 2 hours from now.');
        }
        if (strtotime($data['end']) <= strtotime($data['start'])) {
            return new WP_Error('invalid_end', 'End time must be after start time.');
        }
        if (VatCar_ATC_Validation::has_overlap($data['callsign'], $data['start'], $data['end'])) {
            return new WP_Error('overlap', 'Booking overlaps with existing one.');
        }

        // Confirm caller identity
        $current_cid = self::vatcar_get_cid();
        if ((string)$data['cid'] !== (string)$current_cid) {
            return new WP_Error('unauthorized', 'You can only book for your own CID.');
        }

        // Validate controller division and rating
        $controller_data = self::get_controller_data($current_cid);
        if (is_wp_error($controller_data)) {
            return $controller_data;
        }
        if (empty($controller_data['division_id']) || $controller_data['division_id'] !== 'CAR') {
            return new WP_Error('invalid_division', 'You must be in the VATCAR division to book a position.');
        }
        $required_subdivision = get_option('vatcar_fir_subdivision', 'CUR');
        if (empty($controller_data['subdivision_id']) || $controller_data['subdivision_id'] !== $required_subdivision) {
            $sub_name = vatcar_get_subdivision_name($required_subdivision);
            return new WP_Error('invalid_subdivision', 'You must be in the ' . $sub_name . ' subdivision to book a position.');
        }
        if (isset($controller_data['rating']) && intval($controller_data['rating']) < 2) {
            return new WP_Error('insufficient_rating', 'You must have at least S1 rating to book.');
        }

        // Service account CID for API calls
        $api_cid = defined('VATCAR_VATSIM_API_CID') ? (string)VATCAR_VATSIM_API_CID : (string)$current_cid;

        // Remote API call: POST /booking
        $endpoint = VATCAR_VATSIM_API_BASE . '/api/booking';
        $payload  = [
            'callsign'    => $data['callsign'],
            'cid'         => (int)$api_cid, // service account CID
            'type'        => 'booking',
            'start'       => $data['start'],
            'end'         => $data['end'],
            'division'    => $data['division'],
            'subdivision' => $data['subdivision'],
        ];
        $response = wp_remote_post($endpoint, [
            'headers' => vatcar_vatsim_headers(),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to reach VATSIM API.');
        }

        $code    = wp_remote_retrieve_response_code($response);
        $bodyRaw = wp_remote_retrieve_body($response);
        $body    = json_decode($bodyRaw, true);

        if ($code !== 201 || !is_array($body) || empty($body['id'])) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Unexpected response (' . $code . '): ' . $bodyRaw;
            return new WP_Error('api_error', $msg);
        }

        // Cache locally with external_id. Preserve the real controller's cid, store api_cid separately.
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $wpdb->insert($table, [
            'cid'         => (string)$current_cid,   // real controller
            'api_cid'     => (string)$api_cid,       // service account
            'callsign'    => (string)$data['callsign'],
            'type'        => 'booking',
            'start'       => (string)$data['start'],
            'end'         => (string)$data['end'],
            'division'    => (string)$data['division'],
            'subdivision' => (string)$data['subdivision'],
            'external_id' => (int)$body['id'],
        ]);

        return true;
    }

    /**
     * Update booking via VATSIM API (PUT /booking/{id}), then update local cache.
     */
    public static function update_booking($id, $data) {
        global $wpdb;
        $table   = $wpdb->prefix . 'atc_bookings';
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$booking) return new WP_Error('not_found', 'Booking not found.');

        $current_cid = self::vatcar_get_cid();
        if ((string)$booking->cid !== (string)$current_cid) {
            return new WP_Error('unauthorized', 'You can only edit your own bookings.');
        }

        // Validate controller division and rating
        $controller_data = self::get_controller_data($current_cid);
        if (is_wp_error($controller_data)) {
            return $controller_data;
        }
        if (empty($controller_data['division_id']) || $controller_data['division_id'] !== 'CAR') {
            return new WP_Error('invalid_division', 'You must be in the VATCAR division to book ATC positions.');
        }
        $required_subdivision = get_option('vatcar_fir_subdivision', 'CUR');
        if (empty($controller_data['subdivision_id']) || $controller_data['subdivision_id'] !== $required_subdivision) {
            $sub_name = vatcar_get_subdivision_name($required_subdivision);
            return new WP_Error('invalid_subdivision', 'You must be in the ' . $sub_name . ' subdivision to book a position.');
        }
        if (isset($controller_data['rating']) && intval($controller_data['rating']) < 2) {
            return new WP_Error('insufficient_rating', 'You must have at least S1 rating to book.');
        }

        if (empty($data['callsign'])) return new WP_Error('missing_callsign', 'You must select a station.');
        if (empty($data['start']) || empty($data['end'])) return new WP_Error('missing_times', 'Start and end times are required.');
        if (!VatCar_ATC_Validation::valid_callsign($data['callsign'])) {
            return new WP_Error('invalid_callsign', 'Invalid callsign format.');
        }

        $now_plus_6h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_6h)) return new WP_Error('invalid_start', 'Start time must be at least 2 hours from now.');
        if (strtotime($data['end']) <= strtotime($data['start'])) return new WP_Error('invalid_end', 'End time must be after start time.');

        if (VatCar_ATC_Validation::has_overlap($data['callsign'], $data['start'], $data['end'])) {
            return new WP_Error('overlap', 'Booking overlaps with existing one.');
        }

        if (empty($booking->external_id)) {
            return new WP_Error('api_error', 'Missing external booking ID for VATSIM update.');
        }

        // Remote API call: PUT /booking/{id}
        $endpoint = VATCAR_VATSIM_API_BASE . '/api/booking/' . (int)$booking->external_id;
        $payload  = [
            'callsign'    => $data['callsign'],
            'cid'         => (int)$booking->api_cid, // service account CID
            'type'        => 'booking',
            'start'       => $data['start'],
            'end'         => $data['end'],
            'division'    => $booking->division,
            'subdivision' => $booking->subdivision,
        ];
        $response = wp_remote_request($endpoint, [
            'method'  => 'PUT',
            'headers' => vatcar_vatsim_headers(),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to reach VATSIM API.');
        }

        $code    = wp_remote_retrieve_response_code($response);
        $bodyRaw = wp_remote_retrieve_body($response);
        $body    = json_decode($bodyRaw, true);

        if ($code !== 200) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Update failed (' . $code . '): ' . $bodyRaw;
            return new WP_Error('api_error', $msg);
        }

        // Update local cache: keep cid intact, update other fields
        $wpdb->update($table, [
            'callsign' => $data['callsign'],
            'start'    => $data['start'],
            'end'      => $data['end'],
            'api_cid'  => $booking->api_cid,
        ], ['id' => $id]);

        return true;
    }

    /**
     * Delete booking via VATSIM API (DELETE /booking/{id}), then remove local cache.
     */
    public static function delete_booking($id) {
        global $wpdb;
        $table   = $wpdb->prefix . 'atc_bookings';
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$booking) return new WP_Error('not_found', 'Booking not found.');

        $current_cid = self::vatcar_get_cid();
        $super_cid   = '140'; // Danny CID (change if needed)

        if ((string)$booking->cid !== (string)$current_cid && (string)$current_cid !== (string)$super_cid) {
        return new WP_Error('unauthorized', 'You can only delete your own bookings.');
        }


        if (empty($booking->external_id)) {
            return new WP_Error('api_error', 'Missing external booking ID for VATSIM delete.');
        }

        // Remote API call: DELETE /booking/{id}
        $endpoint = VATCAR_VATSIM_API_BASE . '/api/booking/' . (int)$booking->external_id;
        $response = wp_remote_request($endpoint, [
            'method'  => 'DELETE',
            'headers' => vatcar_vatsim_headers(),
            'body'    => wp_json_encode(['cid' => (int)$booking->api_cid]),
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to reach VATSIM API.');
        }

        $code    = wp_remote_retrieve_response_code($response);
        $bodyRaw = wp_remote_retrieve_body($response);
        $body    = json_decode($bodyRaw, true);

        if ($code !== 204 && $code !== 200) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Delete failed (' . $code . '): ' . $bodyRaw;
            return new WP_Error('api_error', $msg);
        }

        // Remove local cache
        $wpdb->delete($table, ['id' => $id]);
        return true;
    }

    /**
     * Fetch live VATSIM data feed.
     */
    public static function get_vatsim_live_data() {
        $url = 'https://data.vatsim.net/v3/vatsim-data.json';
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to fetch VATSIM live data.');
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'VATSIM API returned error ' . $code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body || !isset($body['controllers'])) {
            return new WP_Error('api_error', 'Invalid response from VATSIM live data');
        }
        
        return $body['controllers'];
    }

    /**
     * Check if a controller (CID) is logged in on a specific callsign.
     */
    public static function is_controller_logged_in($cid, $callsign) {
        $controllers = self::get_vatsim_live_data();
        
        if (is_wp_error($controllers)) {
            return false; // Can't verify, so assume offline
        }
        
        foreach ($controllers as $controller) {
            if ((int)$controller['cid'] === (int)$cid && $controller['callsign'] === $callsign) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check controller booking status: on_time, early, late, not_logged_in, or no_show.
     * Returns: 'on_time', 'early', 'late', 'not_logged_in', 'no_show', or 'unknown'
     */
    public static function is_controller_logged_in_on_time($cid, $callsign, $booked_start) {
        $controllers = self::get_vatsim_live_data();
        
        if (is_wp_error($controllers)) {
            return 'unknown'; // Can't verify
        }
        
        $booking_time = strtotime($booked_start);
        $current_time = current_time('timestamp');
        $window_start = $booking_time - (15 * 60); // 15 minutes before
        $window_end = $booking_time + (15 * 60);   // 15 minutes after
        
        // Check if controller is logged in on this callsign
        foreach ($controllers as $controller) {
            if ((int)$controller['cid'] === (int)$cid && $controller['callsign'] === $callsign) {
                $logon_time = strtotime($controller['logon_time']);
                
                if ($logon_time < $window_start) {
                    return 'early';
                } elseif ($logon_time > $window_end) {
                    return 'late';
                } else {
                    return 'on_time';
                }
            }
        }
        
        // Controller not logged in - check if it's a no-show
        if ($current_time >= $booking_time) {
            return 'no_show'; // Booking time has passed and not logged in
        }
        
        return 'not_logged_in'; // Still waiting for booking time
    }

    /**
     * Get current CID depending on environment.
     */
    public static function vatcar_get_cid() {
        if (function_exists('vatsim_connect_get_cid')) {
            return vatsim_connect_get_cid(); // production VATSIM Connect
        }
        if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') !== false) {
            return '1288763'; // static CID for local testing; adjust as needed
        }
        return (string)get_current_user_id(); // last fallback (numeric user id)
    }

    /**
     * Fetch controller data from VATSIM API.
     */
    public static function get_controller_data($cid) {
        // For local testing, return mock data
        if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') !== false) {
            return [
                'id' => (int)$cid,
                'division_id' => 'CAR',
                'subdivision_id' => 'CUR',
                'rating' => 3, // S2 for testing
            ];
        }

        $url = "https://api.vatsim.net/v2/members/{$cid}";
        $api_key = get_option('vatcar_vatsim_api_key', '');
        $response = wp_remote_get($url, [
            'headers' => ['X-API-Key' => $api_key],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to fetch controller data from VATSIM.');
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'VATSIM API returned error ' . $code);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body || !isset($body['id'])) {
            return new WP_Error('api_error', 'Invalid response from VATSIM API');
        }
        return $body;
    }

    /**
     * AJAX handler: update booking.
     */
    public static function ajax_update_booking() {
        if (!isset($_POST['vatcar_update_nonce'])
            || !wp_verify_nonce($_POST['vatcar_update_nonce'], 'vatcar_update_booking')) {
            wp_send_json_error('Security check failed');
        }

        $id = intval($_POST['booking_id'] ?? 0);
        $data = [
            'callsign' => sanitize_text_field($_POST['callsign'] ?? ''),
            'start'    => gmdate('Y-m-d H:i:s', strtotime(trim(($_POST['start_date'] ?? '') . ' ' . ($_POST['start_time'] ?? '')) . ' UTC')),
            'end'      => gmdate('Y-m-d H:i:s', strtotime(trim(($_POST['end_date'] ?? '') . ' ' . ($_POST['end_time'] ?? '')) . ' UTC')),
        ];

        $result = self::update_booking($id, $data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Booking updated successfully.');
        }
    }

    /**
     * AJAX handler: delete booking.
     */
    public static function ajax_delete_booking() {
        if (!isset($_POST['vatcar_delete_nonce'])
            || !wp_verify_nonce($_POST['vatcar_delete_nonce'], 'vatcar_delete_booking')) {
            wp_send_json_error('Security check failed');
        }

        $booking_id = intval($_POST['booking_id'] ?? 0);
        $result = self::delete_booking($booking_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Booking deleted successfully.');
        }
    }

    /**
     * Record a booking compliance status check in the history.
     * Status: 'on_time', 'early', 'late', 'not_logged_in', 'no_show', 'unknown'
     */
    public static function record_compliance_check($booking_id, $cid, $callsign, $status) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'atc_booking_compliance';
        
        $result = $wpdb->insert(
            $history_table,
            [
                'booking_id' => (int)$booking_id,
                'cid' => sanitize_text_field($cid),
                'callsign' => sanitize_text_field($callsign),
                'status' => sanitize_text_field($status),
                'checked_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        
        return $result ? true : new WP_Error('db_error', 'Failed to record compliance check');
    }

    /**
     * Get compliance history for a booking.
     * Returns array of compliance records or WP_Error.
     */
    public static function get_booking_compliance_history($booking_id) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'atc_booking_compliance';
        
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $history_table WHERE booking_id = %d ORDER BY checked_at DESC",
            (int)$booking_id
        ));
        
        return $records ?: [];
    }

    /**
     * Get compliance history for a CID across all bookings.
     * Returns array of compliance records or empty array.
     */
    public static function get_cid_compliance_history($cid, $limit = 50) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'atc_booking_compliance';
        
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $history_table WHERE cid = %s ORDER BY checked_at DESC LIMIT %d",
            sanitize_text_field($cid),
            (int)$limit
        ));
        
        return $records ?: [];
    }
}
