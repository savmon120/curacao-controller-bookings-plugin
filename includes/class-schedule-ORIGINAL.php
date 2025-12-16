<?php
class Curacao_ATC_Schedule {
    public static function render_table() {
        // Fetch from VATSIM API
        $query = add_query_arg([
            'division'    => 'CAR',
            'subdivision' => 'CUR',
            'sort'        => 'start',
            'sort_dir'    => 'asc',
        ], CURACAO_VATSIM_API_BASE . '/api/booking');

        $response = wp_remote_get($query, [
            'headers' => curacao_vatsim_headers(),
            'timeout' => 15,
        ]);

        $inserted = 0;
        $updated  = 0;

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true); // associative arrays

            if (is_array($data)) {
                global $wpdb;
                $table = $wpdb->prefix . 'atc_bookings';

                // Verify table exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                ));
                if (!$exists) {
                    error_log("ATC bookings table not found: {$table}");
                } else {
                    foreach ($data as $b) {
                        if (!is_array($b)) continue;

                        $api_cid     = isset($b['cid']) ? (string)$b['cid'] : ''; // service account CID
                        $callsign    = isset($b['callsign']) ? (string)$b['callsign'] : '';
                        $type        = isset($b['type']) ? (string)$b['type'] : 'booking';
                        $start       = isset($b['start']) ? (string)$b['start'] : '';
                        $end         = isset($b['end']) ? (string)$b['end'] : '';
                        $division    = isset($b['division']) ? (string)$b['division'] : 'CAR';
                        $subdivision = isset($b['subdivision']) ? (string)$b['subdivision'] : 'CUR';
                        $external_id = isset($b['id']) ? (int)$b['id'] : 0;

                        if (!$external_id) continue;

                        // Upsert by external_id to keep local edits mapped
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM `$table` WHERE `external_id` = %d",
                            $external_id
                        ));

                        if ($existing) {
                            $sql = "
                                UPDATE `$table`
                                SET `api_cid`=%s, `callsign`=%s, `type`=%s, `start`=%s, `end`=%s,
                                    `division`=%s, `subdivision`=%s
                                WHERE `external_id`=%d
                            ";
                            $res = $wpdb->query($wpdb->prepare($sql,
                                $api_cid, $callsign, $type, $start, $end, $division, $subdivision, $external_id
                            ));
                            if ($res === false) {
                                error_log('ATC update failed: ' . $wpdb->last_error);
                            } else {
                                $updated++;
                            }
                        } else {
                            $sql = "
                                INSERT INTO `$table`
                                    (`api_cid`, `callsign`, `type`, `start`, `end`, `division`, `subdivision`, `external_id`)
                                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d)
                            ";
                            $res = $wpdb->query($wpdb->prepare($sql,
                                $api_cid, $callsign, $type, $start, $end, $division, $subdivision, $external_id
                            ));
                            if ($res === false) {
                                error_log('ATC insert failed: ' . $wpdb->last_error);
                            } else {
                                $inserted++;
                            }
                        }
                    }

                    // Only log info if debug flag is enabled
                    if (defined('CURACAO_ATC_DEBUG') && CURACAO_ATC_DEBUG) {
                        error_log("ATC schedule sync: inserted={$inserted}, updated={$updated}");
                    }
                }
            } else {
                // Only log unexpected API responses if debug is enabled
                if (defined('CURACAO_ATC_DEBUG') && CURACAO_ATC_DEBUG) {
                    error_log('Unexpected VATSIM API response (not array): ' . print_r($data, true));
                }
            }
        } else {
            // Always log API fetch errors
            error_log('VATSIM schedule fetch failed: ' . $response->get_error_message());
        }

        // Read from local cache and render
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $bookings = $wpdb->get_results("SELECT * FROM `$table` ORDER BY `start` ASC");
        $current_cid = Curacao_ATC_Booking::curacao_get_cid();

        ob_start();
        echo '<table>';
        echo '<tr><th>Callsign</th><th>Type</th><th>Start</th><th>End</th><th>Actions</th></tr>';

        foreach ($bookings as $booking) {
            echo '<tr data-id="' . esc_attr($booking->id) . '">';
            echo '<td>' . esc_html($booking->callsign) . '</td>';
            echo '<td>' . esc_html($booking->type) . '</td>';
            echo '<td>' . esc_html($booking->start) . '</td>';
            echo '<td>' . esc_html($booking->end) . '</td>';

            // Only allow edit/delete if the logged-in controller's CID matches the stored cid
            if ((string)$booking->cid === (string)$current_cid) {
                echo '<td>';
                echo '<a href="#" onclick="openEditModal(this)"'
                   . ' data-id="' . esc_attr($booking->id) . '"'
                   . ' data-callsign="' . esc_attr($booking->callsign) . '"'
                   . ' data-start="' . esc_attr($booking->start) . '"'
                   . ' data-end="' . esc_attr($booking->end) . '">Edit</a> ';
                echo '<a href="#" onclick="openDeleteModal(this)"'
                   . ' data-id="' . esc_attr($booking->id) . '"'
                   . ' data-callsign="' . esc_attr($booking->callsign) . '"'
                   . ' data-start="' . esc_attr($booking->start) . '"'
                   . ' data-end="' . esc_attr($booking->end) . '">Delete</a>';
                echo '</td>';
            } else {
                echo '<td></td>';
            }

            echo '</tr>';
        }

        echo '</table>';

        include plugin_dir_path(__FILE__) . '../templates/edit-booking-form.php';
        include plugin_dir_path(__FILE__) . '../templates/delete-booking-form.php';

        return ob_get_clean();
    }
}
