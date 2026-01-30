<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Live Traffic Integration - Display live network data and booking coordination
 */
class VatCar_Live_Traffic {
    
    const CACHE_DURATION = 15; // seconds
    
    /**
     * Get live controllers for a specific FIR
     * @param string $subdivision FIR subdivision code (e.g., 'CUR')
     * @return array|WP_Error Array of controller data or WP_Error on failure
     */
    public static function get_live_controllers($subdivision = 'CUR') {
        $cache_key = 'vatcar_live_controllers_' . $subdivision . '_' . floor(time() / self::CACHE_DURATION);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch fresh data
        $all_controllers = VatCar_ATC_Booking::get_vatsim_live_data();
        if (is_wp_error($all_controllers)) {
            return $all_controllers;
        }
        
        // Filter by FIR callsign prefix
        $fir_prefix = self::get_fir_prefix($subdivision);
        $fir_controllers = array_filter($all_controllers, function($c) use ($fir_prefix) {
            return strpos($c['callsign'], $fir_prefix) === 0;
        });
        
        set_transient($cache_key, $fir_controllers, self::CACHE_DURATION);
        return $fir_controllers;
    }
    
    /**
     * Get overlapping bookings for a given time range
     * @param string $start Start datetime (Y-m-d H:i:s)
     * @param string $end End datetime (Y-m-d H:i:s)
     * @param string $subdivision FIR subdivision
     * @param string|null $exclude_callsign Optional callsign to exclude (for edit mode)
     * @return array Array of booking objects
     */
    public static function get_overlapping_bookings($start, $end, $subdivision = 'CUR', $exclude_callsign = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        
        $query = "SELECT * FROM $table 
                 WHERE subdivision = %s 
                 AND (
                     (start <= %s AND end >= %s) OR
                     (start >= %s AND start < %s)
                 )";
        $params = [$subdivision, $start, $start, $start, $end];
        
        // Exclude specific callsign if provided (for edit mode - don't show self as overlap)
        if ($exclude_callsign !== null) {
            $query .= " AND callsign != %s";
            $params[] = $exclude_callsign;
        }
        
        $query .= " ORDER BY start ASC";
        
        $bookings = $wpdb->get_results($wpdb->prepare($query, $params));
        
        return $bookings ?: [];
    }
    
    /**
     * Get complementary position suggestions filtered by user's rating/authorization
     * Suggests both higher AND lower positions based on what user can book
     * @param string $cid User's CID
     * @param array $existing_bookings Already booked positions at this time
     * @return array Array of suggested callsigns with reasons
     */
    public static function get_complementary_suggestions($cid, $existing_bookings) {
        // Get user's rating and authorization
        $controller_data = VatCar_ATC_Booking::get_controller_data($cid);
        if (is_wp_error($controller_data)) {
            return [];
        }
        
        $user_rating = isset($controller_data['rating']) ? intval($controller_data['rating']) : 0;
        
        // Define bidirectional complementary position pairs
        $complementary_pairs = [
            'DEL' => ['GND', 'RMP', 'TWR'],
            'GND' => ['DEL', 'RMP', 'TWR'],
            'RMP' => ['DEL', 'GND', 'TWR'],
            'TWR' => ['GND', 'DEL', 'RMP', 'APP', 'DEP'],
            'APP' => ['TWR', 'DEP', 'CTR'],
            'DEP' => ['TWR', 'APP', 'CTR'],
            'CTR' => ['APP', 'DEP', 'FSS'],
            'FSS' => ['APP', 'DEP', 'CTR'],
        ];
        
        $suggestions = [];
        $subdivision = vatcar_detect_subdivision();
        
        // Get airports for this subdivision
        $airports = [];
        if (function_exists('vatcar_get_subdivision_airports')) {
            $airports = vatcar_get_subdivision_airports($subdivision);
        } else {
            // Fallback: extract unique airports from existing bookings
            foreach ($existing_bookings as $booking) {
                $parts = explode('_', $booking->callsign);
                if (count($parts) >= 2) {
                    $airports[] = $parts[0];
                }
            }
            $airports = array_unique($airports);
        }
        
        // For each existing booking, find complementary positions user can book
        foreach ($existing_bookings as $booking) {
            $parts = explode('_', $booking->callsign);
            if (count($parts) < 2) continue;
            
            $suffix = strtoupper(end($parts));
            
            if (!isset($complementary_pairs[$suffix])) {
                continue;
            }
            
            foreach ($complementary_pairs[$suffix] as $suggested_suffix) {
                foreach ($airports as $airport) {
                    $suggested_callsign = $airport . '_' . $suggested_suffix;
                    
                    // Check if position already booked
                    $already_booked = false;
                    foreach ($existing_bookings as $check) {
                        if ($check->callsign === $suggested_callsign) {
                            $already_booked = true;
                            break;
                        }
                    }
                    if ($already_booked) continue;
                    
                    // Check if user has required rating or authorization
                    $required_rating = VatCar_ATC_Booking::get_position_required_rating($suggested_callsign);
                    $has_rating = ($user_rating >= $required_rating);
                    $has_authorization = VatCar_ATC_Booking::is_authorized_for_position($cid, $suggested_callsign);
                    
                    // Only suggest if user is eligible
                    if ($has_rating || $has_authorization) {
                        $booked_rating = VatCar_ATC_Booking::get_position_required_rating($booking->callsign);
                        $direction = ($required_rating > $booked_rating) ? 'upward' : ($required_rating < $booked_rating ? 'downward' : 'peer');
                        
                        $suggestions[] = [
                            'callsign' => $suggested_callsign,
                            'reason' => "Complement {$booking->callsign}",
                            'booked_by' => $booking->controller_name ?? 'Unknown',
                            'booked_by_cid' => $booking->cid,
                            'direction' => $direction,
                            'start' => $booking->start,
                            'end' => $booking->end,
                        ];
                    }
                }
            }
        }
        
        // Remove duplicates (same callsign suggested multiple times)
        $unique_suggestions = [];
        $seen_callsigns = [];
        foreach ($suggestions as $suggestion) {
            if (!in_array($suggestion['callsign'], $seen_callsigns)) {
                $unique_suggestions[] = $suggestion;
                $seen_callsigns[] = $suggestion['callsign'];
            }
        }
        
        return $unique_suggestions;
    }
    
    /**
     * Get complementary position suggestions WITHOUT rating filter (for non-logged-in users)
     * @param array $existing_bookings Already booked positions at this time
     * @return array Array of suggested callsigns with reasons
     */
    public static function get_complementary_suggestions_unfiltered($existing_bookings) {
        // Define bidirectional complementary position pairs
        $complementary_pairs = [
            'DEL' => ['GND', 'RMP', 'TWR'],
            'GND' => ['DEL', 'RMP', 'TWR'],
            'RMP' => ['DEL', 'GND', 'TWR'],
            'TWR' => ['GND', 'DEL', 'RMP', 'APP', 'DEP'],
            'APP' => ['TWR', 'DEP', 'CTR'],
            'DEP' => ['TWR', 'APP', 'CTR'],
            'CTR' => ['APP', 'DEP', 'FSS'],
            'FSS' => ['APP', 'DEP', 'CTR'],
        ];
        
        $suggestions = [];
        $subdivision = vatcar_detect_subdivision();
        
        // Get airports for this subdivision
        $airports = [];
        if (function_exists('vatcar_get_subdivision_airports')) {
            $airports = vatcar_get_subdivision_airports($subdivision);
        } else {
            // Fallback: extract unique airports from existing bookings
            foreach ($existing_bookings as $booking) {
                $parts = explode('_', $booking->callsign);
                if (count($parts) >= 2) {
                    $airports[] = $parts[0];
                }
            }
            $airports = array_unique($airports);
        }
        
        // For each existing booking, find ALL complementary positions (no rating filter)
        foreach ($existing_bookings as $booking) {
            $parts = explode('_', $booking->callsign);
            if (count($parts) < 2) continue;
            
            $suffix = strtoupper(end($parts));
            
            if (!isset($complementary_pairs[$suffix])) {
                continue;
            }
            
            foreach ($complementary_pairs[$suffix] as $suggested_suffix) {
                foreach ($airports as $airport) {
                    $suggested_callsign = $airport . '_' . $suggested_suffix;
                    
                    // Check if position already booked
                    $already_booked = false;
                    foreach ($existing_bookings as $check) {
                        if ($check->callsign === $suggested_callsign) {
                            $already_booked = true;
                            break;
                        }
                    }
                    if ($already_booked) continue;
                    
                    // Get direction indicator (but don't filter)
                    $required_rating = VatCar_ATC_Booking::get_position_required_rating($suggested_callsign);
                    $booked_rating = VatCar_ATC_Booking::get_position_required_rating($booking->callsign);
                    $direction = ($required_rating > $booked_rating) ? 'upward' : ($required_rating < $booked_rating ? 'downward' : 'peer');
                    
                    $suggestions[] = [
                        'callsign' => $suggested_callsign,
                        'reason' => "Complement {$booking->callsign}",
                        'booked_by' => $booking->controller_name ?? 'Unknown',
                        'booked_by_cid' => $booking->cid,
                        'direction' => $direction,
                        'start' => $booking->start,
                        'end' => $booking->end,
                    ];
                }
            }
        }
        
        // Remove duplicates (same callsign suggested multiple times)
        $unique_suggestions = [];
        $seen_callsigns = [];
        foreach ($suggestions as $suggestion) {
            if (!in_array($suggestion['callsign'], $seen_callsigns)) {
                $unique_suggestions[] = $suggestion;
                $seen_callsigns[] = $suggestion['callsign'];
            }
        }
        
        return $unique_suggestions;
    }
    
    /**
     * Get coverage status for current time
     * @param string $subdivision FIR subdivision
     * @return string 'full', 'partial', or 'unstaffed'
     */
    public static function get_coverage_status($subdivision = 'CUR') {
        $controllers = self::get_live_controllers($subdivision);
        if (is_wp_error($controllers) || empty($controllers)) {
            return 'unstaffed';
        }
        
        // Count unique positions
        $positions = array_unique(array_map(function($c) {
            return $c['callsign'];
        }, $controllers));
        
        if (count($positions) >= 3) {
            return 'full'; // Green
        } elseif (count($positions) >= 1) {
            return 'partial'; // Yellow
        } else {
            return 'unstaffed'; // Red
        }
    }
    
    /**
     * Link online controller to their booking (if exists)
     * @param string $cid Controller CID
     * @param string $callsign Controller callsign
     * @return object|null Booking object or null if no booking
     */
    public static function get_booking_for_controller($cid, $callsign) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $now = gmdate('Y-m-d H:i:s');
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE cid = %s AND callsign = %s AND start <= %s AND end >= %s",
            $cid, $callsign, $now, $now
        ));
        
        return $booking;
    }
    
    /**
     * Get FIR callsign prefix for a subdivision
     * @param string $subdivision Subdivision code (e.g., 'CUR')
     * @return string Callsign prefix (e.g., 'TNCC')
     */
    private static function get_fir_prefix($subdivision) {
        $prefixes = [
            'CUR' => 'TNCC',
            'ARU' => 'TNCA',
            'BON' => 'TNCB',
            'SXM' => 'TNCM',
        ];
        
        return $prefixes[$subdivision] ?? 'TNCC';
    }
    
    /**
     * Format duration from seconds to human-readable string
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "2h 15m")
     */
    public static function format_duration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        } else {
            return sprintf('%dm', $minutes);
        }
    }
    
    /**
     * Check if a controller is currently online for their booking
     * @param object $booking Booking object
     * @return bool True if online, false otherwise
     */
    public static function is_booking_live($booking) {
        if (empty($booking->cid) || empty($booking->callsign)) {
            return false;
        }
        
        return VatCar_ATC_Booking::is_controller_logged_in($booking->cid, $booking->callsign);
    }
}
