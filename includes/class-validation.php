<?php
class Curacao_ATC_Validation {

    /**
     * Validate callsign format.
     * Accepts suffixes: DEL, GND, TWR, APP, DEP, CTR, FSS.
     */
    public static function valid_callsign($callsign) {
        return (bool) preg_match('/_(RMP|DEL|GND|TWR|APP|DEP|CTR|FSS)$/', $callsign);
    }

    /**
     * Check if a booking overlaps existing ones for the same callsign.
     */
    public static function has_overlap($callsign, $start, $end) {
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';

        // Overlap if any existing interval intersects [start, end]
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE callsign = %s
             AND NOT (end <= %s OR start >= %s)",
            $callsign, $start, $end
        );

        $count = (int) $wpdb->get_var($query);
        return $count > 0;
    }
    /**
     * Generate <option> tags for 15‑minute time increments.
     * Marks the $selected value as selected.
     */
    public static function time_options($selected = null) {
        $html = '';
        for ($h = 0; $h < 24; $h++) {
            for ($m = 0; $m < 60; $m += 15) {
                $time = sprintf('%02d:%02d', $h, $m);
                $sel = ($time === $selected) ? 'selected' : '';
                $html .= "<option value='$time' $sel>$time</option>";
            }
        }
        return $html;
    }
}
