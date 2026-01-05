<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * FIR Staff Dashboard for managing bookings and viewing compliance status
 */

class VatCar_ATC_Dashboard {

    /**
     * Get the most recent compliance status recorded for a booking.
     * Returns a string status or null if none exists.
     */
    private static function get_latest_booking_compliance_status($booking_id) {
        global $wpdb;

        $history_table = $wpdb->prefix . 'atc_booking_compliance';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $history_table WHERE booking_id = %d ORDER BY checked_at DESC LIMIT 1",
            (int)$booking_id
        ));

        if (!$row || empty($row->status)) {
            return null;
        }

        return (string)$row->status;
    }

    /**
     * Render the manage bookings dashboard
     */
    public static function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Prevent access if site not recognised
        if (!vatcar_admin_subdivision_check()) {
            echo '<div class="wrap"><h1>ATC Bookings Dashboard</h1></div>';
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $subdivision = vatcar_detect_subdivision();

        // Get all bookings for this FIR
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE subdivision = %s ORDER BY start DESC",
            $subdivision
        ));

        ?>
        <div class="wrap">
            <h1>ATC Bookings Dashboard</h1>
            <p>Managing bookings for: <strong><?php echo esc_html(vatcar_get_subdivision_name($subdivision)); ?></strong></p>

            <p>
                <button type="button" id="new-booking-btn" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> New Booking
                </button>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col">Callsign</th>
                        <th scope="col">Controller</th>
                        <th scope="col">Created By</th>
                        <th scope="col">Booked</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">No bookings found</td>
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
                                    <?php 
                                    if (isset($booking->created_by_cid) && !empty($booking->created_by_cid)) {
                                        $creator_cid = $booking->created_by_cid;
                                        $creator_name = VatCar_ATC_Booking::get_controller_name_for_sync($creator_cid);
                                        if ($creator_name === 'Unknown') {
                                            $creator_name = 'Unknown';
                                        }
                                        echo esc_html($creator_name);
                                        echo '<br><small style="color: #666;">' . esc_html($creator_cid) . '</small>';
                                    } else {
                                        echo '<small style="color: #999;">—</small>';
                                    }
                                    ?>
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
                                    <button 
                                        class="button button-small record-status-btn" 
                                        data-booking-id="<?php echo intval($booking->id); ?>"
                                        data-cid="<?php echo esc_attr($booking->cid); ?>"
                                        data-callsign="<?php echo esc_attr($booking->callsign); ?>"
                                        data-start="<?php echo esc_attr($booking->start); ?>"
                                        title="Record current status to compliance history">
                                        Record Status
                                    </button>
                                    <button class="button button-small view-history" 
                                            data-cid="<?php echo esc_attr($booking->cid); ?>" 
                                            data-controller-name="<?php echo esc_attr($name); ?>">
                                        View Compliance
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

        <!-- New Booking Modal -->
        <div id="new-booking-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; overflow-y: auto;">
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%; margin: 40px auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;">Create New Booking</h2>
                    <button type="button" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #666; line-height: 1;" onclick="document.getElementById('new-booking-modal').style.display='none';">&times;</button>
                </div>
                
                <div id="new-booking-message" style="display: none; padding: 12px; border-radius: 5px; margin-bottom: 15px;"></div>

                <form id="dashboard-booking-form">
                    <?php wp_nonce_field('vatcar_new_booking','vatcar_booking_nonce'); ?>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">Controller CID:</label>
                        <input type="text" name="controller_cid" id="dashboard-cid" 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                               placeholder="e.g. 1234567" required pattern="\d+" inputmode="numeric">
                        <small style="color: #666;">Enter the VATSIM CID of the controller</small>
                        <div id="dashboard-cid-lookup" style="margin-top: 8px;"></div>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">Station:</label>
                        <select name="callsign" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="" disabled selected>-- Select --</option>
                            <?php
                            $stations = vatcar_generate_station_list();
                            foreach($stations as $station){
                                $display = ($station === 'TNCA_GND') ? 'TNCA_RMP' : $station;
                                echo '<option value="'.esc_attr($station).'">'.esc_html($display).'</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">Start Date (UTC):</label>
                        <input type="date" name="start_date" required 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                               min="<?php echo gmdate('Y-m-d'); ?>">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">Start Time (UTC):</label>
                        <select name="start_time" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="" disabled selected>-- Select Time --</option>
                            <?php
                            foreach(range(0,23) as $h){
                                foreach(['00','15','30','45'] as $q){
                                    $t = sprintf('%02d:%s', $h, $q);
                                    echo "<option value='{$t}'>{$t}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">End Date (UTC):</label>
                        <input type="date" name="end_date" required 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                               min="<?php echo gmdate('Y-m-d'); ?>">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">End Time (UTC):</label>
                        <select name="end_time" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="" disabled selected>-- Select Time --</option>
                            <?php
                            foreach(range(0,23) as $h){
                                foreach(['00','15','30','45'] as $q){
                                    $t = sprintf('%02d:%s', $h, $q);
                                    echo "<option value='{$t}'>{$t}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="button" onclick="document.getElementById('new-booking-modal').style.display='none';">Cancel</button>
                        <button type="submit" class="button button-primary">Create Booking</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal for compliance history -->
        <div id="compliance-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: white; padding: 20px; border-radius: 5px; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <button type="button" style="float: right; background: none; border: none; font-size: 24px; cursor: pointer;" onclick="document.getElementById('compliance-modal').style.display='none';">&times;</button>
                <h2 id="modal-title">Controller Compliance Record</h2>
                <p style="color: #666; margin-top: 5px;" id="modal-subtitle">Complete booking history and punctuality tracking</p>
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
            // New Booking Modal
            const newBookingBtn = document.getElementById('new-booking-btn');
            const newBookingModal = document.getElementById('new-booking-modal');
            const newBookingForm = document.getElementById('dashboard-booking-form');
            const dashboardCid = document.getElementById('dashboard-cid');
            const cidLookupDiv = document.getElementById('dashboard-cid-lookup');
            const messageDiv = document.getElementById('new-booking-message');

            if (newBookingBtn) {
                newBookingBtn.addEventListener('click', function() {
                    newBookingModal.style.display = 'flex';
                    newBookingForm.reset();
                    messageDiv.style.display = 'none';
                    cidLookupDiv.innerHTML = '';
                });
            }

            // CID Lookup for dashboard modal
            let cidLookupTimer = null;
            if (dashboardCid) {
                dashboardCid.addEventListener('input', function() {
                    clearTimeout(cidLookupTimer);
                    const cid = this.value.trim();
                    
                    if (!cid || !/^\d{1,10}$/.test(cid)) {
                        cidLookupDiv.innerHTML = '';
                        return;
                    }

                    cidLookupDiv.innerHTML = '<div style="padding:8px; color:#666;">🔍 Looking up controller...</div>';

                    cidLookupTimer = setTimeout(function() {
                        const formData = new FormData();
                        formData.append('action', 'lookup_controller');
                        formData.append('cid', cid);

                        fetch(ajaxurl, {
                            method: 'POST',
                            body: formData,
                        })
                        .then(r => r.json())
                        .then(resp => {
                            if (resp.success) {
                                const data = resp.data;
                                let html = '<div style="padding:10px; background:#efe; border:1px solid #3c3; border-radius:5px; color:#060;">';
                                html += '<strong>✓ Controller Found</strong><br>';
                                html += 'Division: ' + (data.division || 'Unknown') + '<br>';
                                html += 'Subdivision: ' + (data.subdivision || 'Unknown') + '<br>';
                                html += 'Rating: ' + (data.rating_name || 'Unknown') + ' (' + (data.rating || 0) + ')';
                                
                                if (data.whitelist_type) {
                                    html += '<br><span style="color:#f80; font-weight:600;">✦ ' + data.whitelist_type.toUpperCase() + '</span>';
                                    if (data.authorized_positions && data.authorized_positions.length > 0) {
                                        html += '<br><small>Authorized: ' + data.authorized_positions.join(', ') + '</small>';
                                    }
                                }

                                const requiredSubdiv = '<?php echo esc_js(vatcar_detect_subdivision()); ?>';
                                if (data.division !== 'CAR' && !data.whitelist_type) {
                                    html += '<br><span style="color:#c33; font-weight:600;">⚠ Not in VATCAR division</span>';
                                }
                                if (data.subdivision !== requiredSubdiv && !data.whitelist_type) {
                                    html += '<br><span style="color:#c33; font-weight:600;">⚠ Wrong subdivision</span>';
                                }

                                html += '</div>';
                                cidLookupDiv.innerHTML = html;
                            } else {
                                cidLookupDiv.innerHTML = '<div style="padding:8px; background:#fee; border:1px solid #c33; border-radius:5px; color:#c33;">⚠️ ' + (resp.data || 'Failed to lookup controller') + '</div>';
                            }
                        })
                        .catch(err => {
                            cidLookupDiv.innerHTML = '<div style="padding:8px; background:#fee; border:1px solid #c33; border-radius:5px; color:#c33;">⚠️ Network error</div>';
                        });
                    }, 600);
                });
            }

            // Handle new booking form submission
            if (newBookingForm) {
                newBookingForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.textContent;
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Creating...';
                    messageDiv.style.display = 'none';

                    const formData = new FormData(this);
                    formData.append('action', 'create_booking_from_dashboard');

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData,
                    })
                    .then(r => r.json())
                    .then(resp => {
                        if (resp.success) {
                            messageDiv.style.display = 'block';
                            messageDiv.style.background = '#d4edda';
                            messageDiv.style.border = '1px solid #c3e6cb';
                            messageDiv.style.color = '#155724';
                            messageDiv.textContent = '✓ Booking created successfully! Reloading...';
                            
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            messageDiv.style.display = 'block';
                            messageDiv.style.background = '#f8d7da';
                            messageDiv.style.border = '1px solid #f5c6cb';
                            messageDiv.style.color = '#721c24';
                            messageDiv.textContent = '⚠️ Error: ' + (resp.data || 'Failed to create booking');
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }
                    })
                    .catch(err => {
                        messageDiv.style.display = 'block';
                        messageDiv.style.background = '#f8d7da';
                        messageDiv.style.border = '1px solid #f5c6cb';
                        messageDiv.style.color = '#721c24';
                        messageDiv.textContent = '⚠️ Network error. Please try again.';
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    });
                });
            }

            // Load status for each booking
            document.querySelectorAll('.booking-status').forEach(function(el) {
                const bookingId = el.getAttribute('data-booking-id');
                const cid = el.getAttribute('data-cid');
                const callsign = el.getAttribute('data-callsign');
                const start = el.getAttribute('data-start');

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=vatcar_get_booking_status&cid=' + encodeURIComponent(cid) + '&callsign=' + encodeURIComponent(callsign) + '&start=' + encodeURIComponent(start) + '&booking_id=' + encodeURIComponent(bookingId) + '&record=false'
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

            // Record status button
            document.querySelectorAll('.record-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const cid = this.getAttribute('data-cid');
                    const callsign = this.getAttribute('data-callsign');
                    const start = this.getAttribute('data-start');
                    const originalText = this.textContent;
                    
                    this.textContent = 'Recording...';
                    this.disabled = true;

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=vatcar_get_booking_status&cid=' + encodeURIComponent(cid) + '&callsign=' + encodeURIComponent(callsign) + '&start=' + encodeURIComponent(start) + '&booking_id=' + encodeURIComponent(bookingId) + '&record=true'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.textContent = '✓ Recorded';
                            setTimeout(() => {
                                this.textContent = originalText;
                                this.disabled = false;
                            }, 2000);
                        } else {
                            alert('Failed to record status');
                            this.textContent = originalText;
                            this.disabled = false;
                        }
                    })
                    .catch(() => {
                        alert('Error recording status');
                        this.textContent = originalText;
                        this.disabled = false;
                    });
                });
            });

            // View compliance history button
            document.querySelectorAll('.view-history').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const cid = this.getAttribute('data-cid');
                    const controllerName = this.getAttribute('data-controller-name');
                    const modal = document.getElementById('compliance-modal');
                    const content = document.getElementById('modal-content');
                    const title = document.getElementById('modal-title');
                    const subtitle = document.getElementById('modal-subtitle');
                    
                    title.textContent = controllerName + ' (CID: ' + cid + ')';
                    subtitle.textContent = 'Complete booking history and punctuality tracking';
                    content.innerHTML = '<p>Loading...</p>';
                    modal.style.display = 'flex';

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=vatcar_get_cid_compliance&cid=' + encodeURIComponent(cid)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data.records && data.data.records.length > 0) {
                            const stats = data.data.stats;
                            let html = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin-bottom: 25px; padding: 15px; background: #f9f9f9; border-radius: 5px;">';
                            html += '<div style="text-align: center;"><div style="font-size: 24px; font-weight: bold; color: #008000;">' + stats.on_time + '</div><div style="font-size: 12px; color: #666; text-transform: uppercase;">On Time</div></div>';
                            html += '<div style="text-align: center;"><div style="font-size: 24px; font-weight: bold; color: #0073aa;">' + stats.early + '</div><div style="font-size: 12px; color: #666; text-transform: uppercase;">Early</div></div>';
                            html += '<div style="text-align: center;"><div style="font-size: 24px; font-weight: bold; color: #ff9933;">' + stats.late + '</div><div style="font-size: 12px; color: #666; text-transform: uppercase;">Late</div></div>';
                            html += '<div style="text-align: center;"><div style="font-size: 24px; font-weight: bold; color: #ff0000;">' + stats.no_show + '</div><div style="font-size: 12px; color: #666; text-transform: uppercase;">No Shows</div></div>';
                            html += '<div style="text-align: center;"><div style="font-size: 24px; font-weight: bold; color: #111;">' + stats.total + '</div><div style="font-size: 12px; color: #666; text-transform: uppercase;">Total Checks</div></div>';
                            html += '</div>';
                            html += '<h3 style="margin-top: 20px; margin-bottom: 10px;">Recent History</h3>';
                            html += '<table style="width: 100%; border-collapse: collapse;">';
                            html += '<tr style="border-bottom: 2px solid #ddd; background: #f5f5f5;"><th style="text-align: left; padding: 10px;">Callsign</th><th style="text-align: left; padding: 10px;">Status</th><th style="text-align: left; padding: 10px;">Checked At</th></tr>';
                            data.data.records.forEach(function(record) {
                                const statusColors = {on_time: '#008000', early: '#0073aa', late: '#ff9933', no_show: '#ff0000', not_logged_in: '#999', unknown: '#ccc'};
                                const statusColor = statusColors[record.status] || '#666';
                                html += '<tr style="border-bottom: 1px solid #eee;">';
                                html += '<td style="padding: 10px; font-weight: 600;">' + record.callsign + '</td>';
                                html += '<td style="padding: 10px; color: ' + statusColor + '; font-weight: 600;">' + record.status.charAt(0).toUpperCase() + record.status.slice(1).replace(/_/g, ' ') + '</td>';
                                html += '<td style="padding: 10px; color: #666;">' + new Date(record.checked_at).toLocaleString() + '</td>';
                                html += '</tr>';
                            });
                            html += '</table>';
                            content.innerHTML = html;
                        } else {
                            content.innerHTML = '<p style="text-align: center; color: #999; padding: 40px;">No compliance history found for this controller.<br><small>History will be recorded automatically as bookings are checked.</small></p>';
                        }
                    })
                    .catch(() => {
                        content.innerHTML = '<p style="color: red;">Error loading compliance history.</p>';
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
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $record = isset($_POST['record']) && $_POST['record'] === 'true';

        if (!$cid || !$callsign || !$start) {
            wp_send_json_error('Missing parameters');
        }

        // If the booking is in the past and we already have compliance history, prefer that
        // over live VATSIM checks (which will frequently report past bookings as no_show).
        if ($booking_id > 0) {
            global $wpdb;
            $bookings_table = $wpdb->prefix . 'atc_bookings';
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT start, end FROM $bookings_table WHERE id = %d",
                (int)$booking_id
            ));

            if ($booking && !empty($booking->end)) {
                $now_gmt = (int) current_time('timestamp', true);
                $end_ts  = strtotime((string)$booking->end . ' UTC');

                if ($end_ts && $end_ts < $now_gmt) {
                    $latest = self::get_latest_booking_compliance_status($booking_id);
                    if ($latest !== null) {
                        // For past bookings, do not record a new "current" status.
                        wp_send_json_success(['status' => $latest]);
                    }
                }
            }
        }

        $status = VatCar_ATC_Booking::is_controller_logged_in_on_time($cid, $callsign, $start);
        
        // Record compliance check to history if requested and booking_id provided
        if ($record && $booking_id && current_user_can('manage_options')) {
            VatCar_ATC_Booking::record_compliance_check($booking_id, $cid, $callsign, $status);
        }
        
        wp_send_json_success(['status' => $status]);
    }

    /**
     * AJAX endpoint to get compliance history for a CID
     */
    public static function ajax_get_cid_compliance() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $cid = isset($_POST['cid']) ? sanitize_text_field($_POST['cid']) : '';

        if (!$cid) {
            wp_send_json_error('Invalid CID');
        }

        $history = VatCar_ATC_Booking::get_cid_compliance_history($cid, 50);
        
        // Calculate statistics
        $stats = [
            'total' => count($history),
            'on_time' => 0,
            'early' => 0,
            'late' => 0,
            'no_show' => 0,
            'not_logged_in' => 0,
            'unknown' => 0
        ];
        
        foreach ($history as $record) {
            if (isset($stats[$record->status])) {
                $stats[$record->status]++;
            }
        }
        
        wp_send_json_success([
            'records' => $history,
            'stats' => $stats
        ]);
    }
    
    /**
     * AJAX endpoint to get compliance history for a booking (deprecated, kept for compatibility)
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
