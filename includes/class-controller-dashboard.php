<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Controller Dashboard - Personal booking view for individual controllers
 */
class VatCar_Controller_Dashboard {

    /**
     * Render the controller dashboard (shortcode handler)
     */
    public static function render_dashboard() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your bookings.</p>';
        }

        $user = wp_get_current_user();
        if (!in_array('controller', (array) $user->roles, true)) {
            return '<p>You do not have permission to view controller bookings.</p>';
        }

        $cid = VatCar_ATC_Booking::vatcar_get_cid();
        if (empty($cid)) {
            return '<p style="color:red;">Unable to determine your CID.</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $now = gmdate('Y-m-d H:i:s');

        // Get upcoming bookings (end >= now)
        $upcoming = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE cid = %s AND end >= %s ORDER BY start ASC",
            $cid,
            $now
        ));

        // Get past bookings (end < now) - limit to last 10
        $past = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE cid = %s AND end < %s ORDER BY start DESC LIMIT 10",
            $cid,
            $now
        ));

        // Get personal compliance statistics
        $stats = self::get_personal_stats($cid);

        // Get authorization status
        $whitelist_entry = VatCar_ATC_Booking::get_whitelist_entry($cid);
        $authorized_positions = [];
        if ($whitelist_entry) {
            $authorized_positions = VatCar_ATC_Booking::get_authorized_positions($whitelist_entry->id);
        }

        ob_start();
        ?>

        <div class="controller-dashboard">
            <div class="dashboard-header">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2>My Bookings</h2>
                        <p>Welcome back, <?php echo esc_html($user->first_name ?: $user->display_name); ?>! (CID: <?php echo esc_html($cid); ?>)</p>
                    </div>
                    <a href="/book-a-slot/" class="book-new-btn">+ Book New Slot</a>
                </div>
            </div>

            <?php if ($whitelist_entry): ?>
                <div class="authorization-banner <?php echo esc_attr($whitelist_entry->authorization_type); ?>">
                    <h3>
                        <?php if ($whitelist_entry->authorization_type === 'visitor'): ?>
                            🌐 Visitor Authorization
                        <?php else: ?>
                            ⭐ Solo Certification
                        <?php endif; ?>
                    </h3>
                    <p>
                        <?php if ($whitelist_entry->authorization_type === 'visitor'): ?>
                            You have visitor authorization to control in this FIR.
                        <?php else: ?>
                            You have solo certification for additional positions.
                        <?php endif; ?>
                        <?php if ($whitelist_entry->expires_at): ?>
                            <strong>Expires:</strong> <?php echo esc_html(gmdate('F j, Y G:i', strtotime($whitelist_entry->expires_at . ' UTC'))); ?>z
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($authorized_positions)): ?>
                        <p><strong>Authorized Positions:</strong></p>
                        <ul>
                            <?php foreach ($authorized_positions as $pos): ?>
                                <li><?php echo esc_html($pos === 'TNCA_GND' ? 'TNCA_RMP' : $pos); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($stats['total'] > 0): ?>
                <div class="stats-grid">
                    <div class="stat-card on-time">
                        <div class="stat-label">On Time</div>
                        <div class="stat-value"><?php echo (int)$stats['on_time']; ?></div>
                        <?php if ($stats['total'] > 0): ?>
                            <div class="stat-label"><?php echo round(($stats['on_time'] / $stats['total']) * 100); ?>%</div>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card early">
                        <div class="stat-label">Early</div>
                        <div class="stat-value"><?php echo (int)$stats['early']; ?></div>
                    </div>
                    <div class="stat-card late">
                        <div class="stat-label">Late</div>
                        <div class="stat-value"><?php echo (int)$stats['late']; ?></div>
                    </div>
                    <div class="stat-card no-show">
                        <div class="stat-label">No Shows</div>
                        <div class="stat-value"><?php echo (int)$stats['no_show']; ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dashboard-tabs">
                <button class="dashboard-tab active" data-tab="upcoming">Upcoming (<?php echo count($upcoming); ?>)</button>
                <button class="dashboard-tab" data-tab="past">Past (<?php echo count($past); ?>)</button>
            </div>

            <div class="tab-content" data-tab-content="upcoming">
                <?php if (empty($upcoming)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📅</div>
                        <h3>No Upcoming Bookings</h3>
                        <p>You don't have any upcoming ATC sessions booked.</p>
                        <a href="/book-a-slot/" class="book-now-btn">Book a Slot Now</a>
                    </div>
                <?php else: ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Callsign</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Duration</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming as $booking): ?>
                                    <tr data-id="<?php echo (int)$booking->id; ?>">
                                        <td>
                                            <div class="booking-callsign">
                                                <?php echo esc_html($booking->callsign === 'TNCA_GND' ? 'TNCA_RMP' : $booking->callsign); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-time">
                                                <?php echo esc_html(gmdate('M j, Y', strtotime($booking->start . ' UTC'))); ?><br>
                                                <?php echo esc_html(gmdate('H:i', strtotime($booking->start . ' UTC'))); ?>z
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-time">
                                                <?php echo esc_html(gmdate('M j, Y', strtotime($booking->end . ' UTC'))); ?><br>
                                                <?php echo esc_html(gmdate('H:i', strtotime($booking->end . ' UTC'))); ?>z
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-time">
                                                <?php 
                                                $duration = (strtotime($booking->end) - strtotime($booking->start)) / 3600;
                                                echo number_format($duration, 1) . ' hrs';
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-actions">
                                                <a href="#" 
                                                   class="action-btn delete delete-booking-btn"
                                                   data-id="<?php echo (int)$booking->id; ?>"
                                                   data-callsign="<?php echo esc_attr($booking->callsign); ?>"
                                                   data-start="<?php echo esc_attr($booking->start); ?>"
                                                   data-end="<?php echo esc_attr($booking->end); ?>">
                                                    Cancel
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-content" data-tab-content="past" style="display: none;">
                <?php if (empty($past)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <h3>No Past Bookings</h3>
                        <p>You haven't controlled any sessions yet.</p>
                    </div>
                <?php else: ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Callsign</th>
                                    <th>Date</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($past as $booking): ?>
                                    <tr>
                                        <td>
                                            <div class="booking-callsign">
                                                <?php echo esc_html($booking->callsign === 'TNCA_GND' ? 'TNCA_RMP' : $booking->callsign); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-time">
                                                <?php echo esc_html(gmdate('M j, Y', strtotime($booking->start . ' UTC'))); ?><br>
                                                <?php echo esc_html(gmdate('H:i', strtotime($booking->start . ' UTC'))); ?>z - <?php echo esc_html(gmdate('H:i', strtotime($booking->end . ' UTC'))); ?>z
                                            </div>
                                        </td>
                                        <td>
                                            <div class="booking-time">
                                                <?php 
                                                $duration = (strtotime($booking->end) - strtotime($booking->start)) / 3600;
                                                echo number_format($duration, 1) . ' hrs';
                                                ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Controller Resources Section -->
            <?php
            $resources_enabled = get_option('vatcar_resources_enabled', true);
            if ($resources_enabled):
                $resources_config = get_option('vatcar_resources_config', []);
                
                // Filter out empty URLs
                $active_resources = array_filter($resources_config, function($resource) {
                    return !empty($resource['url']);
                });
                
                if (!empty($active_resources)):
            ?>
            <div class="controller-resources">
                <h2>Controller Resources</h2>
                <p class="resources-intro">Quick access to essential documents, procedures, and materials for controlling in the <?php echo esc_html(vatcar_get_subdivision_name(vatcar_detect_subdivision())); ?> FIR.</p>
                
                <div class="resource-grid">
                    <?php foreach ($active_resources as $key => $resource): ?>
                        <?php
                        $resource_url = $resource['url'];
                        if (function_exists('vatcar_add_dashboard_ref_to_url')) {
                            $resource_url = vatcar_add_dashboard_ref_to_url($resource_url);
                        }
                        ?>
                        <a href="<?php echo esc_url($resource_url); ?>" class="resource-card <?php echo esc_attr($key); ?>">
                            <div class="resource-icon"><?php echo esc_html($resource['icon']); ?></div>
                            <div class="resource-content">
                                <h3><?php echo esc_html($resource['title']); ?></h3>
                                <p><?php echo esc_html($resource['description']); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php 
                endif; // active_resources
            endif; // resources_enabled
            ?>
        </div>

        <?php
        // Include delete booking modal
        include plugin_dir_path(__FILE__) . '../templates/delete-booking-form.php';
        ?>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.dashboard-tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');

                    // Update active tab
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Show corresponding content
                    tabContents.forEach(content => {
                        if (content.getAttribute('data-tab-content') === targetTab) {
                            content.style.display = 'block';
                        } else {
                            content.style.display = 'none';
                        }
                    });
                });
            });

            // Delete booking handlers
            document.querySelectorAll('.delete-booking-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (typeof window.openDeleteModal === 'function') {
                        window.openDeleteModal(this);
                    }
                });
            });

            // Listen for successful deletion to update the table
            window.addEventListener('bookingDeleted', function(event) {
                const tbody = document.querySelector('[data-tab-content="upcoming"] tbody');
                if (tbody && tbody.querySelectorAll('tr').length === 0) {
                    // Show empty state
                    const tableContainer = document.querySelector('[data-tab-content="upcoming"] .bookings-table');
                    if (tableContainer) {
                        tableContainer.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">📅</div>
                                <h3>No Upcoming Bookings</h3>
                                <p>You don't have any upcoming ATC sessions booked.</p>
                                <a href="/book-a-slot/" class="book-now-btn">Book a Slot Now</a>
                            </div>
                        `;
                    }
                    // Update tab count
                    const upcomingTab = document.querySelector('.dashboard-tab[data-tab="upcoming"]');
                    if (upcomingTab) {
                        upcomingTab.textContent = 'Upcoming (0)';
                    }
                }
            });
        });
        </script>

        <?php
        return ob_get_clean();
    }

    /**
     * Get personal compliance statistics for a controller
     */
    private static function get_personal_stats($cid) {
        $history = VatCar_ATC_Booking::get_cid_compliance_history($cid, 100);
        
        $stats = [
            'total' => count($history),
            'on_time' => 0,
            'early' => 0,
            'late' => 0,
            'no_show' => 0,
        ];
        
        foreach ($history as $record) {
            if (isset($stats[$record->status])) {
                $stats[$record->status]++;
            }
        }
        
        return $stats;
    }

    /**
     * Render compact widget view
     */
    public static function render_widget() {
        if (!is_user_logged_in()) {
            return '<p style="font-size:13px;color:#666;">Login to view your bookings.</p>';
        }

        $user = wp_get_current_user();
        if (!in_array('controller', (array) $user->roles, true)) {
            return '';
        }

        $cid = VatCar_ATC_Booking::vatcar_get_cid();
        if (empty($cid)) {
            return '<p style="font-size:13px;color:#666;">Unable to determine CID.</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $now = current_time('mysql');

        // Get next upcoming booking
        $next_booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE cid = %s AND start >= %s ORDER BY start ASC LIMIT 1",
            $cid,
            $now
        ));

        // Get quick stats
        $stats = self::get_personal_stats($cid);

        ob_start();
        ?>

        <div class="controller-widget">
            <h3 class="widget-heading">My ATC Sessions</h3>

            <?php if ($next_booking): ?>
                <div class="next-booking">
                    <div class="next-booking-callsign">
                        <?php echo esc_html($next_booking->callsign === 'TNCA_GND' ? 'TNCA_RMP' : $next_booking->callsign); ?>
                    </div>
                    <div class="next-booking-time">
                        <?php echo esc_html(date('M j, Y', strtotime($next_booking->start))); ?><br>
                        <?php echo esc_html(date('H:i', strtotime($next_booking->start))); ?>z - <?php echo esc_html(date('H:i', strtotime($next_booking->end))); ?>z
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($stats['total'] > 0): ?>
                <div class="widget-stats">
                    <div class="widget-stat on-time">
                        <div class="widget-stat-value"><?php echo (int)$stats['on_time']; ?></div>
                        <div class="widget-stat-label">On Time</div>
                    </div>
                    <div class="widget-stat rate">
                        <div class="widget-stat-value"><?php echo round(($stats['on_time'] / $stats['total']) * 100); ?>%</div>
                        <div class="widget-stat-label">Rate</div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="widget-links">
                <a href="/my-bookings/" class="widget-link">View All Bookings</a>
                <a href="/book-a-slot/" class="widget-link primary">Book a Slot</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
