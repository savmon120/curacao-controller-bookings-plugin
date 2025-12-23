<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * FIR Staff Dashboard for managing bookings and viewing compliance status
 */

class VatCar_ATC_Dashboard {

    /**
     * Render the manage bookings dashboard
     */
    public static function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $subdivision = vatcar_detect_subdivision();
        
        if (empty($subdivision)) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> This site is not configured or recognized within the plugin. Please create a <a href="https://github.com/savmon120/curacao-controller-bookings-plugin/issues" target="_blank">GitHub issue</a>.</p></div>';
            echo '</div>';
            return;
        }

        // Get all bookings for this FIR
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE subdivision = %s ORDER BY start DESC",
            $subdivision
        ));

        ?>
        <div class="wrap">
            <h1>ATC Bookings Dashboard</h1>
            <p>Managing bookings for: <strong><?php echo esc_html(vatcar_get_subdivision_name($subdivision)); ?></strong></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col">Callsign</th>
                        <th scope="col">Controller</th>
                        <th scope="col">Booked</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No bookings found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <tr data-id="<?php echo intval($booking->id); ?>">
                                <td><strong><?php echo esc_html($booking->callsign); ?></strong></td>
                                <td>
                                    <?php 
                                    $name = isset($booking->controller_name) && !empty($booking->controller_name) 
                                        ? $booking->controller_name 
                                        : 'Unknown';
                                    echo esc_html($name); 
                                    ?><br>
                                    <small style="color: #666;"><?php echo esc_html($booking->cid); ?></small>
                                </td>
                                <td>
                                    <small>
                                        <?php echo esc_html(date('M d, H:i', strtotime($booking->start))); ?>
                                        -
                                        <?php echo esc_html(date('H:i', strtotime($booking->end))); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if (!empty($booking->cid)): ?>
                                        <span class="booking-status" 
                                              data-booking-id="<?php echo intval($booking->id); ?>" 
                                              data-cid="<?php echo esc_attr($booking->cid); ?>" 
                                              data-callsign="<?php echo esc_attr($booking->callsign); ?>" 
                                              data-start="<?php echo esc_attr($booking->start); ?>">
                                            <em>Loading...</em>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">Unknown controller</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button 
                                        class="button button-small edit-booking-btn" 
                                        data-id="<?php echo intval($booking->id); ?>"
                                        data-callsign="<?php echo esc_attr($booking->callsign); ?>"
                                        data-start="<?php echo esc_attr($booking->start); ?>"
                                        data-end="<?php echo esc_attr($booking->end); ?>">
                                        Edit
                                    </button>
                                    <button class="button button-small view-history" data-booking-id="<?php echo intval($booking->id); ?>">
                                        View History
                                    </button>
                                    <button 
                                        class="button button-small button-link-delete cancel-booking-btn" 
                                        data-id="<?php echo intval($booking->id); ?>"
                                        data-callsign="<?php echo esc_attr($booking->callsign); ?>"
                                        data-start="<?php echo esc_attr($booking->start); ?>"
                                        data-end="<?php echo esc_attr($booking->end); ?>">
                                        Cancel Booking
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Include edit and delete booking modals -->
        <?php 
        include plugin_dir_path(__FILE__) . '../templates/edit-booking-form.php';
        include plugin_dir_path(__FILE__) . '../templates/delete-booking-form.php';
        ?>

        <!-- Modal for compliance history -->
        <div id="compliance-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: white; padding: 20px; border-radius: 5px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <button type="button" style="float: right; background: none; border: none; font-size: 24px; cursor: pointer;" onclick="document.getElementById('compliance-modal').style.display='none';">&times;</button>
                <h2>Compliance History</h2>
                <div id="modal-content" style="margin-top: 20px;">
                    <!-- History will load here -->
                </div>
            </div>
        </div>

        <style>
            .booking-status.on_time { color: #008000; font-weight: bold; }
            .booking-status.early { color: #0073aa; font-weight: bold; }
            .booking-status.late { color: #ff9933; font-weight: bold; }
            .booking-status.not_logged_in { color: #999; }
            .booking-status.no_show { color: #ff0000; font-weight: bold; }
            .booking-status.unknown { color: #ccc; }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Load status for each booking
            document.querySelectorAll('.booking-status').forEach(function(el) {
                const bookingId = el.getAttribute('data-booking-id');
                const cid = el.getAttribute('data-cid');
                const callsign = el.getAttribute('data-callsign');
                const start = el.getAttribute('data-start');

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=vatcar_get_booking_status&cid=' + encodeURIComponent(cid) + '&callsign=' + encodeURIComponent(callsign) + '&start=' + encodeURIComponent(start)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        el.textContent = data.data.status.charAt(0).toUpperCase() + data.data.status.slice(1).replace('_', ' ');
                        el.classList.add(data.data.status);
                    } else {
                        el.textContent = 'Error';
                        el.classList.add('unknown');
                    }
                })
                .catch(() => {
                    el.textContent = 'Error';
                    el.classList.add('unknown');
                });
            });

            // View history button
            document.querySelectorAll('.view-history').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const modal = document.getElementById('compliance-modal');
                    const content = document.getElementById('modal-content');
                    
                    content.innerHTML = '<p>Loading...</p>';
                    modal.style.display = 'flex';

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=vatcar_get_compliance_history&booking_id=' + encodeURIComponent(bookingId)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data.length > 0) {
                            let html = '<table style="width: 100%; border-collapse: collapse;">';
                            html += '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px;">Status</th><th style="text-align: left; padding: 8px;">Checked At</th></tr>';
                            data.data.forEach(function(record) {
                                html += '<tr style="border-bottom: 1px solid #ddd;"><td style="padding: 8px;">' + record.status.charAt(0).toUpperCase() + record.status.slice(1).replace('_', ' ') + '</td><td style="padding: 8px;">' + record.checked_at + '</td></tr>';
                            });
                            html += '</table>';
                            content.innerHTML = html;
                        } else {
                            content.innerHTML = '<p>No compliance history found.</p>';
                        }
                    })
                    .catch(() => {
                        content.innerHTML = '<p>Error loading history.</p>';
                    });
                });
            });

            // Edit booking button - trigger existing edit modal
            document.querySelectorAll('.edit-booking-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (typeof window.openEditModal === 'function') {
                        window.openEditModal(this);
                    }
                });
            });

            // Cancel booking button - trigger existing delete modal
            document.querySelectorAll('.cancel-booking-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (typeof window.openDeleteModal === 'function') {
                        window.openDeleteModal(this);
                    }
                });
            });
        });
        </script>

        <?php
    }

    /**
     * AJAX endpoint to get current booking status
     */
    public static function ajax_get_booking_status() {
        $cid = isset($_POST['cid']) ? sanitize_text_field($_POST['cid']) : '';
        $callsign = isset($_POST['callsign']) ? sanitize_text_field($_POST['callsign']) : '';
        $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';

        if (!$cid || !$callsign || !$start) {
            wp_send_json_error('Missing parameters');
        }

        $status = VatCar_ATC_Booking::is_controller_logged_in_on_time($cid, $callsign, $start);
        wp_send_json_success(['status' => $status]);
    }

    /**
     * AJAX endpoint to get compliance history for a booking
     */
    public static function ajax_get_compliance_history() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

        if (!$booking_id) {
            wp_send_json_error('Invalid booking ID');
        }

        $history = VatCar_ATC_Booking::get_booking_compliance_history($booking_id);
        wp_send_json_success($history);
    }
}
