<?php
class Curacao_ATC_Booking {

    /**
     * Render the booking form (shortcode handler).
     */
    public static function render_form() {
        ob_start();

        // Require login (except local dev)
        if (strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') === false) {
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
            if (!isset($_POST['curacao_booking_nonce'])
                || !wp_verify_nonce($_POST['curacao_booking_nonce'], 'curacao_new_booking')) {
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
                    'cid'         => self::curacao_get_cid(),
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
                    $target = 'https://curacao.vatcar.net/controller-schedule/';

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
        if (!Curacao_ATC_Validation::valid_callsign($data['callsign'])) {
            return new WP_Error('invalid_callsign', 'Invalid callsign format.');
        }

        $now_plus_6h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_6h)) {
            return new WP_Error('invalid_start', 'Start time must be at least 2 hours from now.');
        }
        if (strtotime($data['end']) <= strtotime($data['start'])) {
            return new WP_Error('invalid_end', 'End time must be after start time.');
        }
        if (Curacao_ATC_Validation::has_overlap($data['callsign'], $data['start'], $data['end'])) {
            return new WP_Error('overlap', 'Booking overlaps with existing one.');
        }

        // Confirm caller identity
        $current_cid = self::curacao_get_cid();
        if ((string)$data['cid'] !== (string)$current_cid) {
            return new WP_Error('unauthorized', 'You can only book for your own CID.');
        }

        // Service account CID for API calls
        $api_cid = defined('CURACAO_VATSIM_API_CID') ? (string)CURACAO_VATSIM_API_CID : (string)$current_cid;

        // Remote API call: POST /booking
        $endpoint = CURACAO_VATSIM_API_BASE . '/api/booking';
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
            'headers' => curacao_vatsim_headers(),
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

        $current_cid = self::curacao_get_cid();
        if ((string)$booking->cid !== (string)$current_cid) {
            return new WP_Error('unauthorized', 'You can only edit your own bookings.');
        }

        if (empty($data['callsign'])) return new WP_Error('missing_callsign', 'You must select a station.');
        if (empty($data['start']) || empty($data['end'])) return new WP_Error('missing_times', 'Start and end times are required.');
        if (!Curacao_ATC_Validation::valid_callsign($data['callsign'])) {
            return new WP_Error('invalid_callsign', 'Invalid callsign format.');
        }

        $now_plus_6h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_6h)) return new WP_Error('invalid_start', 'Start time must be at least 2 hours from now.');
        if (strtotime($data['end']) <= strtotime($data['start'])) return new WP_Error('invalid_end', 'End time must be after start time.');

        if (Curacao_ATC_Validation::has_overlap($data['callsign'], $data['start'], $data['end'])) {
            return new WP_Error('overlap', 'Booking overlaps with existing one.');
        }

        if (empty($booking->external_id)) {
            return new WP_Error('api_error', 'Missing external booking ID for VATSIM update.');
        }

        // Remote API call: PUT /booking/{id}
        $endpoint = CURACAO_VATSIM_API_BASE . '/api/booking/' . (int)$booking->external_id;
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
            'headers' => curacao_vatsim_headers(),
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

        $current_cid = self::curacao_get_cid();
        $super_cid   = '140'; // Danny CID (change if needed)

        if ((string)$booking->cid !== (string)$current_cid && (string)$current_cid !== (string)$super_cid) {
        return new WP_Error('unauthorized', 'You can only delete your own bookings.');
        }


        if (empty($booking->external_id)) {
            return new WP_Error('api_error', 'Missing external booking ID for VATSIM delete.');
        }

        // Remote API call: DELETE /booking/{id}
        $endpoint = CURACAO_VATSIM_API_BASE . '/api/booking/' . (int)$booking->external_id;
        $response = wp_remote_request($endpoint, [
            'method'  => 'DELETE',
            'headers' => curacao_vatsim_headers(),
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
     * Get current CID depending on environment.
     */
    public static function curacao_get_cid() {
        if (function_exists('vatsim_connect_get_cid')) {
            return vatsim_connect_get_cid(); // production VATSIM Connect
        }
        if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'curacao.vatcar.local') !== false) {
            return '164'; // static CID for local testing; adjust as needed
        }
        return (string)get_current_user_id(); // last fallback (numeric user id)
    }

    /**
     * AJAX handler: update booking.
     */
    public static function ajax_update_booking() {
        if (!isset($_POST['curacao_update_nonce'])
            || !wp_verify_nonce($_POST['curacao_update_nonce'], 'curacao_update_booking')) {
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
        if (!isset($_POST['curacao_delete_nonce'])
            || !wp_verify_nonce($_POST['curacao_delete_nonce'], 'curacao_delete_booking')) {
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
}
