<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VatCar_ATC_Booking {

    /**
     * Render the booking form (shortcode handler).
     */
    public static function render_form() {
        ob_start();

        // Require login (except local dev)
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if (strpos($host, 'curacao.vatcar.local') !== false || strpos($host, 'curacao-fir-vatcar.local') !== false) {
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

                // Detect subdivision from hostname
                $subdivision = vatcar_detect_subdivision();
                if (empty($subdivision)) {
                    echo '<p style="color:red;">' . vatcar_unrecognised_site_error(true) . '</p>';
                    return ob_get_clean();
                }

                $result = self::save_booking([
                    // Always use the authenticated controller's CID, do not trust POST
                    'cid'         => self::vatcar_get_cid(),
                    'callsign'    => sanitize_text_field($_POST['callsign'] ?? ''),
                    'start'       => $start,
                    'end'         => $end,
                    'division'    => 'CAR',
                    'subdivision' => $subdivision,
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
     * Get controller name from WordPress user first_name and last_name
     * In production: looks up user by CID (username)
     * In local dev: falls back to current user during booking creation
     */
    private static function get_controller_name($cid) {
        // Try to find user by CID (username) - works in production
        $user = get_user_by('login', $cid);
        
        // If not found, try current logged-in user (for local dev during booking creation)
        if (!$user) {
            $user = wp_get_current_user();
            if (!$user || $user->ID === 0) {
                return 'Unknown';
            }
        }
        
        // Get name from the found user
        $first_name = get_user_meta($user->ID, 'first_name', true);
        $last_name = get_user_meta($user->ID, 'last_name', true);
        
        if (!empty($first_name) && !empty($last_name)) {
            return trim($first_name . ' ' . $last_name);
        } elseif (!empty($first_name)) {
            return $first_name;
        } elseif (!empty($last_name)) {
            return $last_name;
        }
        
        return 'Unknown';
    }

    /**
     * Public wrapper for schedule sync to get controller names
     * Only looks up by CID, doesn't fall back to current user
     */
    public static function get_controller_name_for_sync($cid) {
        $user = get_user_by('login', $cid);
        if (!$user) {
            return 'Unknown';
        }
        
        $first_name = get_user_meta($user->ID, 'first_name', true);
        $last_name = get_user_meta($user->ID, 'last_name', true);
        
        if (!empty($first_name) && !empty($last_name)) {
            return trim($first_name . ' ' . $last_name);
        } elseif (!empty($first_name)) {
            return $first_name;
        } elseif (!empty($last_name)) {
            return $last_name;
        }
        
        return 'Unknown';
    }

    /**
     * Check if a CID is in the controller whitelist and not expired.
     */
    public static function is_controller_whitelisted($cid) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        $now = current_time('mysql');
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE cid = %s AND (expires_at IS NULL OR expires_at > %s)",
            $cid,
            $now
        ));
        
        return (int)$exists > 0;
    }

    /**
     * Add a CID to the visitor whitelist.
     * If the WordPress user already exists, populate name immediately.
     * @param string $cid The controller's CID
     * @param string $notes Optional notes
     * @param string|null $expires_at Optional expiration datetime
     * @param string $authorization_type 'visitor' or 'solo'
     * @return int|false The inserted row ID or false on failure
     */
    public static function add_to_whitelist($cid, $notes = '', $expires_at = null, $authorization_type = 'visitor') {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        
        // Check if WordPress user exists and get their name
        $controller_name = self::get_controller_name($cid);
        
        $data = [
            'cid' => sanitize_text_field($cid),
            'notes' => sanitize_textarea_field($notes),
            'added_by' => get_current_user_id(),
            'date_added' => current_time('mysql'),
            'expires_at' => $expires_at,
            'controller_name' => $controller_name,
            'authorization_type' => sanitize_text_field($authorization_type),
        ];
        
        $formats = ['%s', '%s', '%d', '%s', '%s', '%s', '%s'];
        
        $result = $wpdb->insert($table, $data, $formats);
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Remove a CID from the visitor whitelist.
     */
    public static function remove_from_whitelist($cid) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        
        $result = $wpdb->delete($table, [
            'cid' => $cid,
        ], ['%s']);
        
        return $result !== false;
    }

    /**
     * Update expiration date for a whitelisted visitor.
     */
    public static function update_whitelist_expiration($cid, $expires_at = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        
        $result = $wpdb->update(
            $table,
            ['expires_at' => $expires_at],
            ['cid' => $cid],
            ['%s'],
            ['%s']
        );
        
        return $result !== false;
    }

    /**
     * Update controller name in whitelist when they log in.
     */
    public static function update_whitelist_controller_name($cid) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        
        // Check if CID is in whitelist
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE cid = %s",
            $cid
        ));
        
        if ((int)$exists === 0) {
            return false; // Not in whitelist
        }
        
        // Get controller name from WordPress
        $controller_name = self::get_controller_name($cid);
        
        if ($controller_name === 'Unknown') {
            return false; // Can't find name
        }
        
        // Update whitelist with controller name
        $result = $wpdb->update(
            $table,
            ['controller_name' => $controller_name],
            ['cid' => $cid],
            ['%s'],
            ['%s']
        );
        
        return $result !== false;
    }

    /**
     * Add authorized positions for a controller.
     * @param int $authorization_id The whitelist entry ID
     * @param array $callsigns Array of callsign strings
     */
    public static function add_authorized_positions($authorization_id, $callsigns) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_authorized_positions';
        
        // Remove existing positions for this authorization
        $wpdb->delete($table, ['authorization_id' => $authorization_id], ['%d']);
        
        // Insert new positions
        foreach ($callsigns as $callsign) {
            $wpdb->insert(
                $table,
                [
                    'authorization_id' => $authorization_id,
                    'callsign' => sanitize_text_field($callsign),
                    'date_granted' => current_time('mysql'),
                ],
                ['%d', '%s', '%s']
            );
        }
        
        return true;
    }

    /**
     * Get authorized positions for a controller.
     * @param int $authorization_id The whitelist entry ID
     * @return array Array of callsign strings
     */
    public static function get_authorized_positions($authorization_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_authorized_positions';
        
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT callsign FROM $table WHERE authorization_id = %d",
            $authorization_id
        ));
        
        return $results ?: [];
    }

    /**
     * Check if a controller is authorized for a specific callsign.
     * @param string $cid The controller's CID
     * @param string $callsign The callsign to check
     * @return bool True if authorized, false otherwise
     */
    public static function is_authorized_for_position($cid, $callsign) {
        global $wpdb;
        $whitelist_table = $wpdb->prefix . 'atc_controller_whitelist';
        $positions_table = $wpdb->prefix . 'atc_authorized_positions';
        
        // Get authorization entry
        $auth = $wpdb->get_row($wpdb->prepare(
            "SELECT id, authorization_type FROM $whitelist_table WHERE cid = %s AND (expires_at IS NULL OR expires_at > %s)",
            $cid,
            current_time('mysql')
        ));
        
        if (!$auth) {
            return false; // Not in whitelist or expired
        }
        
        // Check if they have any positions listed
        $position_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $positions_table WHERE authorization_id = %d",
            $auth->id
        ));
        
        // If no positions listed, allow all positions (backward compatible)
        if ((int)$position_count === 0) {
            return true;
        }
        
        // Check if this specific callsign is authorized
        $has_position = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $positions_table WHERE authorization_id = %d AND callsign = %s",
            $auth->id,
            $callsign
        ));
        
        return (int)$has_position > 0;
    }

    /**
     * Get all whitelisted controllers.
     */
    public static function get_whitelist() {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_controller_whitelist';
        
        $results = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY date_added DESC"
        );
        
        return $results ?: [];
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

        $now_plus_2h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_2h)) {
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
        
        // Check if controller is whitelisted - if so, bypass division/subdivision checks
        $is_whitelisted = self::is_controller_whitelisted($current_cid);
        
        // Check position-specific authorization
        $is_authorized_for_position = self::is_authorized_for_position($current_cid, $data['callsign']);
        
        if ($is_whitelisted) {
            // Whitelisted controllers (visitors)
            if (!$is_authorized_for_position) {
                return new WP_Error('unauthorized_position', 'You are not authorized to book this position.');
            }
            // Bypass division/subdivision checks for visitors
        } else {
            // Non-whitelisted controllers must be in correct division/subdivision
            if (empty($controller_data['division_id']) || $controller_data['division_id'] !== 'CAR') {
                // Check if they have solo cert for this position
                if ($is_authorized_for_position) {
                    // Has solo cert - allow it (bypass division check)
                } else {
                    return new WP_Error('invalid_division', 'You must be in the VATCAR division to book a position.');
                }
            }
            $required_subdivision = vatcar_detect_subdivision();
            if (empty($required_subdivision)) {
                return new WP_Error('site_config_error', vatcar_unrecognised_site_error(false));
            }
            if (empty($controller_data['subdivision_id']) || $controller_data['subdivision_id'] !== $required_subdivision) {
                // Check if they have solo cert for this position
                if ($is_authorized_for_position) {
                    // Has solo cert - allow it (bypass subdivision check)
                } else {
                    $sub_name = vatcar_get_subdivision_name($required_subdivision);
                    return new WP_Error('invalid_subdivision', 'You must be in the ' . $sub_name . ' subdivision to book a position.');
                }
            }
        }
        
        // Rating check - can be bypassed by solo certification
        if ($is_authorized_for_position) {
            // Solo cert holders bypass rating check for their authorized positions
        } else {
            // Standard rating check for non-certified positions
            if (isset($controller_data['rating']) && intval($controller_data['rating']) < 2) {
                return new WP_Error('insufficient_rating', 'You must have at least S1 rating to book.');
            }
        }

        // Get controller name from WordPress
        $controller_name = self::get_controller_name($current_cid);
        
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

        $code     = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body     = json_decode($body_raw, true);

        if ($code !== 201 || !is_array($body) || empty($body['id'])) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Unexpected response (' . $code . '): ' . $body_raw;
            return new WP_Error('api_error', $msg);
        }

        // Cache locally with external_id. Preserve the real controller's cid, store api_cid separately.
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        
        $wpdb->insert($table, [
            'cid'              => (string)$current_cid,   // real controller
            'api_cid'          => (string)$api_cid,       // service account
            'callsign'         => (string)$data['callsign'],
            'type'             => 'booking',
            'start'            => (string)$data['start'],
            'end'              => (string)$data['end'],
            'division'         => (string)$data['division'],
            'subdivision'      => (string)$data['subdivision'],
            'external_id'      => (int)$body['id'],
            'controller_name'  => (string)$controller_name,
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
        $is_admin = current_user_can('manage_options');
        
        // Only admins can edit other people's bookings
        if (!$is_admin && (string)$booking->cid !== (string)$current_cid) {
            return new WP_Error('unauthorized', 'You can only edit your own bookings.');
        }

        // Validate controller division and rating (skip for admins editing on behalf)
        if (!$is_admin) {
            $controller_data = self::get_controller_data($current_cid);
            if (is_wp_error($controller_data)) {
                return $controller_data;
            }
            
            // Check if controller is whitelisted - if so, bypass division/subdivision checks
            $is_whitelisted = self::is_controller_whitelisted($current_cid);
            
            // Check position-specific authorization
            $is_authorized_for_position = self::is_authorized_for_position($current_cid, $data['callsign']);
            
            if ($is_whitelisted) {
                // Whitelisted controllers (visitors)
                if (!$is_authorized_for_position) {
                    return new WP_Error('unauthorized_position', 'You are not authorized to book this position.');
                }
                // Bypass division/subdivision checks for visitors
            } else {
                // Non-whitelisted controllers must be in correct division/subdivision
                if (empty($controller_data['division_id']) || $controller_data['division_id'] !== 'CAR') {
                    // Check if they have solo cert for this position
                    if ($is_authorized_for_position) {
                        // Has solo cert - allow it (bypass division check)
                    } else {
                        return new WP_Error('invalid_division', 'You must be in the VATCAR division to book ATC positions.');
                    }
                }
                $required_subdivision = vatcar_detect_subdivision();
                if (empty($required_subdivision)) {
                    return new WP_Error('site_config_error', vatcar_unrecognised_site_error(false));
                }
                if (empty($controller_data['subdivision_id']) || $controller_data['subdivision_id'] !== $required_subdivision) {
                    // Check if they have solo cert for this position
                    if ($is_authorized_for_position) {
                        // Has solo cert - allow it (bypass subdivision check)
                    } else {
                        $sub_name = vatcar_get_subdivision_name($required_subdivision);
                        return new WP_Error('invalid_subdivision', 'You must be in the ' . $sub_name . ' subdivision to book a position.');
                    }
                }
            }
            
            // Rating check - can be bypassed by solo certification
            if ($is_authorized_for_position) {
                // Solo cert holders bypass rating check for their authorized positions
            } else {
                // Standard rating check for non-certified positions
                if (isset($controller_data['rating']) && intval($controller_data['rating']) < 2) {
                    return new WP_Error('insufficient_rating', 'You must have at least S1 rating to book.');
                }
            }
        }

        if (empty($data['callsign'])) return new WP_Error('missing_callsign', 'You must select a station.');
        if (empty($data['start']) || empty($data['end'])) return new WP_Error('missing_times', 'Start and end times are required.');
        if (!VatCar_ATC_Validation::valid_callsign($data['callsign'])) {
            return new WP_Error('invalid_callsign', 'Invalid callsign format.');
        }

        $now_plus_2h = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
        if (strtotime($data['start']) < strtotime($now_plus_2h)) return new WP_Error('invalid_start', 'Start time must be at least 2 hours from now.');
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

        $code     = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body     = json_decode($body_raw, true);

        if ($code !== 200) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Update failed (' . $code . '): ' . $body_raw;
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
        $is_admin = current_user_can('manage_options');

        // Only admins can delete other people's bookings
        if (!$is_admin && (string)$booking->cid !== (string)$current_cid) {
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

        $code     = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body     = json_decode($body_raw, true);

        if ($code !== 204 && $code !== 200) {
            $msg = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'Delete failed (' . $code . '): ' . $body_raw;
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
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if (strpos($host, 'curacao.vatcar.local') !== false || strpos($host, 'curacao-fir-vatcar.local') !== false) {
            return '1288763'; // static CID for local testing; adjust as needed
        }
        return (string)get_current_user_id(); // last fallback (numeric user id)
    }

    /**
     * Fetch controller data from VATSIM API.
     */
    public static function get_controller_data($cid) {
        // For local testing, return mock data
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if (strpos($host, 'curacao.vatcar.local') !== false || strpos($host, 'curacao-fir-vatcar.local') !== false) {
            return [
                'id' => (int)$cid,
                'division_id' => 'CAR',
                'subdivision_id' => 'CUR', // Changed from CUR to test whitelist
                'rating' => 2, // S1 for testing
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
