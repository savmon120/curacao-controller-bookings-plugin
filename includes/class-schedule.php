<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VatCar_ATC_Schedule {
    public static function render_table() {
        // Detect subdivision from hostname
        $subdivision = vatcar_detect_subdivision();
        if (empty($subdivision)) {
            return '<p style="color:red; font-weight:bold;">Error: This site is not configured or recognized within the plugin. Please create a <a href="https://github.com/savmon120/curacao-controller-bookings-plugin/issues" target="_blank">GitHub issue</a>.</p>';
        }

        // Fetch from VATSIM API
        $query = add_query_arg([
            'division'    => 'CAR',
            'subdivision' => $subdivision,
            'sort'        => 'start',
            'sort_dir'    => 'asc',
        ], VATCAR_VATSIM_API_BASE . '/api/booking');

        $response = wp_remote_get($query, [
            'headers' => vatcar_vatsim_headers(),
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
                        $existing = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, cid FROM `$table` WHERE `external_id` = %d",
                            $external_id
                        ));

                        // Get controller name - use real CID if we have it, otherwise try api_cid
                        $real_cid = $existing && !empty($existing->cid) ? $existing->cid : $api_cid;
                        $controller_name = VatCar_ATC_Booking::get_controller_name_for_sync($real_cid);

                        if ($existing) {
                            $sql = "
                                UPDATE `$table`
                                SET `api_cid`=%s, `callsign`=%s, `type`=%s, `start`=%s, `end`=%s,
                                    `division`=%s, `subdivision`=%s, `controller_name`=%s
                                WHERE `external_id`=%d
                            ";
                            $res = $wpdb->query($wpdb->prepare($sql,
                                $api_cid, $callsign, $type, $start, $end, $division, $subdivision, $controller_name, $external_id
                            ));
                            if ($res === false) {
                                error_log('ATC update failed: ' . $wpdb->last_error);
                            } else {
                                $updated++;
                            }
                        } else {
                            $sql = "
                                INSERT INTO `$table`
                                    (`api_cid`, `callsign`, `type`, `start`, `end`, `division`, `subdivision`, `external_id`, `controller_name`)
                                VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %s)
                            ";
                            $res = $wpdb->query($wpdb->prepare($sql,
                                $api_cid, $callsign, $type, $start, $end, $division, $subdivision, $external_id, $controller_name
                            ));
                            if ($res === false) {
                                error_log('ATC insert failed: ' . $wpdb->last_error);
                            } else {
                                $inserted++;
                            }
                        }
                    }

                    // Only log info if debug flag is enabled
                    if (defined('VATSIM_ATC_DEBUG') && VATSIM_ATC_DEBUG) {
                        error_log("ATC schedule sync: inserted={$inserted}, updated={$updated}");
                    }
                }
            } else {
                // Only log unexpected API responses if debug is enabled
                if (defined('VATCAR_ATC_DEBUG') && VATCAR_ATC_DEBUG) {
                    error_log('Unexpected VATSIM API response (not array): ' . print_r($data, true));
                }
            }
        } else {
            // Always log API fetch errors
            error_log('VATSIM schedule fetch failed: ' . $response->get_error_message());
        }

        // Read from local cache and render (exclude past bookings)
        global $wpdb;
        $table = $wpdb->prefix . 'atc_bookings';
        $now = current_time('mysql');
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table` WHERE `end` >= %s ORDER BY `start` ASC",
            $now
        ));
        $current_cid = VatCar_ATC_Booking::vatcar_get_cid();
        $super_cid = '140'; // Danny

        ob_start();

        echo '
<style>
    .ops-panel {
        border: 1px solid rgba(0,0,0,.10);
        border-radius: 10px;
        padding: 10px 12px;
        margin: 0 0 12px 0;
        background: linear-gradient(180deg, #f7f7f9 0%, #ffffff 100%);
        box-shadow: 0 2px 8px rgba(0,0,0,.06);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .ops-left {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 220px;
    }
    .ops-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #666;
        line-height: 1.1;
    }
    .ops-time {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 18px;
        font-weight: 700;
        letter-spacing: .02em;
        color: #111;
        line-height: 1.2;
        white-space: nowrap;
    }
    .ops-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #444;
    }
    .ops-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 3px rgba(34,197,94,.18);
        display: inline-block;
    }
    .ops-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .ops-toggle {
        display: inline-flex;
        border: 1px solid rgba(0,0,0,.12);
        border-radius: 999px;
        overflow: hidden;
        background: #fff;
    }
    .ops-btn {
        appearance: none;
        border: 0;
        background: transparent;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        color: #333;
        transition: background .15s ease, color .15s ease;
        white-space: nowrap;
    }
    .ops-btn:hover {
        background: rgba(0,0,0,.04);
    }
    .ops-btn.active {
        background: #111;
        color: #fff;
    }
    .ops-meta {
        font-size: 12px;
        color: #666;
        white-space: nowrap;
    }
    @media (max-width: 520px) {
        .ops-panel { flex-direction: column; align-items: stretch; }
        .ops-right { justify-content: space-between; }
        .ops-time { font-size: 16px; }
    }
</style>

<div class="ops-panel">
    <div class="ops-left">
        <div class="ops-title">Live Time Reference</div>
        <div class="ops-time" id="ops-time">--:--:--</div>
        <div class="ops-badge">
            <span class="ops-dot" aria-hidden="true"></span>
            <span id="ops-label">Zulu (UTC)</span>
        </div>
    </div>

    <div class="ops-right">
        <div class="ops-toggle" role="group" aria-label="Time zone toggle">
            <button type="button" class="ops-btn" id="btn-utc">Zulu</button>
            <button type="button" class="ops-btn" id="btn-local">Local</button>
        </div>
        <div class="ops-meta" id="ops-meta"></div>
    </div>
</div>

<script>
(function () {
    const elTime  = document.getElementById("ops-time");
    const elLabel = document.getElementById("ops-label");
    const elMeta  = document.getElementById("ops-meta");
    const btnUTC  = document.getElementById("btn-utc");
    const btnLoc  = document.getElementById("btn-local");

    const KEY = "cur_ops_time_mode";
    let mode = localStorage.getItem(KEY) || "utc"; // "utc" or "local"

    function pad(n) { return String(n).padStart(2, "0"); }

    // DB values are stored as UTC
    function parseDbUTC(raw) {
        if (!raw) return null;
        const s = String(raw).trim().replace(" ", "T");
        const m = s.match(/^(\\d{4})-(\\d{2})-(\\d{2})T(\\d{2}):(\\d{2})(?::(\\d{2}))?/);
        if (!m) return null;

        const y  = Number(m[1]);
        const mo = Number(m[2]) - 1;
        const d  = Number(m[3]);
        const hh = Number(m[4]);
        const mi = Number(m[5]);
        const ss = Number(m[6] || 0);

        return new Date(Date.UTC(y, mo, d, hh, mi, ss));
    }

    function formatDDMMYYYY_HHMM(d, useUTC) {
        if (!d) return "";
        const yyyy = useUTC ? d.getUTCFullYear() : d.getFullYear();
        const mm   = useUTC ? (d.getUTCMonth() + 1) : (d.getMonth() + 1);
        const dd   = useUTC ? d.getUTCDate() : d.getDate();
        const hh   = useUTC ? d.getUTCHours() : d.getHours();
        const mi   = useUTC ? d.getUTCMinutes() : d.getMinutes();
        
        return pad(dd) + "-" + pad(mm) + "-" + yyyy + " "
         + pad(hh) + ":" + pad(mi)
         + (useUTC ? "z" : "");
    }

    function renderTableTimes() {
        const useUTC = (mode === "utc");
        document.querySelectorAll("td.cur-dt[data-raw]").forEach(td => {
            const raw = td.getAttribute("data-raw");
            const dt = parseDbUTC(raw);
            td.textContent = dt ? formatDDMMYYYY_HHMM(dt, useUTC) : (raw || "");
        });
    }

    function renderClock() {
        const now = new Date();
        const useUTC = (mode === "utc");

        const yyyy = useUTC ? now.getUTCFullYear()  : now.getFullYear();
        const mm   = useUTC ? now.getUTCMonth()+1   : now.getMonth()+1;
        const dd   = useUTC ? now.getUTCDate()      : now.getDate();
        const hh   = useUTC ? now.getUTCHours()     : now.getHours();
        const mi   = useUTC ? now.getUTCMinutes()   : now.getMinutes();
        const ss   = useUTC ? now.getUTCSeconds()   : now.getSeconds();

        elTime.textContent = pad(dd) + "-" + pad(mm) + "-" + yyyy + " " + pad(hh) + ":" + pad(mi) + ":" + pad(ss);

        if (useUTC) {
            elLabel.textContent = "Zulu (UTC)";
            elMeta.textContent  = "Z";
        } else {
            elLabel.textContent = "Local";
            try {
                elMeta.textContent = Intl.DateTimeFormat().resolvedOptions().timeZone || "";
            } catch (e) {
                elMeta.textContent = "";
            }
        }

        btnUTC.classList.toggle("active", useUTC);
        btnLoc.classList.toggle("active", !useUTC);

        // keep table in sync with selection
        renderTableTimes();
    }

    btnUTC.addEventListener("click", function () {
        mode = "utc";
        localStorage.setItem(KEY, mode);
        renderClock();
    });

    btnLoc.addEventListener("click", function () {
        mode = "local";
        localStorage.setItem(KEY, mode);
        renderClock();
    });

    renderClock();
    setInterval(renderClock, 1000);
})();
</script>
';
        echo '
        <style>
        .atc-slot-cta{ text-align:center; margin:24px 0; }
        .atc-slot-btn{
        display:inline-block;
        padding:10px 18px;
        font-size:22px;
        font-weight:600;
        text-decoration:none;
        color:#fff !important;
        background:#2c51a2 !important;
        border-radius:6px;
        transition: background-color .2s ease, box-shadow .2s ease;
        }
        .atc-slot-btn:hover{
        background:#233f80 !important;
        box-shadow:0 2px 6px rgba(0,0,0,.15);
        }
        </style>

        <div class="atc-slot-cta">
        <a class="atc-slot-btn" href="/book-a-slot/">Book Your ATC Slot</a>
        </div>
        ';
        echo '<table>';
        echo '<tr><th>Controller</th><th>Start</th><th>End</th><th>Actions</th></tr>';

        foreach ($bookings as $booking) {
            echo '<tr data-id="' . esc_attr($booking->id) . '">';

            // Get CID for permission checks (use real controller CID, not api_cid)
            $cid_value = isset($booking->cid) && !empty($booking->cid) ? (string)$booking->cid : (string)$booking->api_cid;

            // Extract first name only from controller_name
            $first_name = 'Unknown';
            if (isset($booking->controller_name) && !empty($booking->controller_name)) {
                $name_parts = explode(' ', $booking->controller_name);
                $first_name = $name_parts[0];
            }

            echo '<td style="font-size:18px;">'
               . '<span style="font-size:15px; color:#555;">' . esc_html($first_name) . '</span><br>'
               . '<b>' . esc_html($booking->callsign) . '</b>'
               . '</td>';

            // Start/End cells (raw UTC stored, JS renders either UTC or Local)
            echo '<td class="cur-dt" data-raw="' . esc_attr((string)$booking->start) . '" style="font-size:14px;"></td>';
            echo '<td class="cur-dt" data-raw="' . esc_attr((string)$booking->end)   . '" style="font-size:14px;"></td>';

            // Allow edit/delete if:
            // 1. Admin (has manage_options capability)
            // 2. It's your own booking (CID matches)
            // 3. Super user (CID 140 - Danny)
            $is_admin = current_user_can('manage_options');
            $is_own_booking = $cid_value !== '' && (string)$cid_value === (string)$current_cid;
            $is_super = (string)$current_cid === (string)$super_cid;
            
            if ($is_admin || $is_own_booking || $is_super) {
                echo '<td style="font-size:16px;">';
                //echo '<a href="#" onclick="openEditModal(this)"'
                //   . ' data-id="' . esc_attr($booking->id) . '"'
                //  . ' data-callsign="' . esc_attr($booking->callsign) . '"'
                //   . ' data-start="' . esc_attr($booking->start) . '"'
                //   . ' data-end="' . esc_attr($booking->end) . '">Edit</a><br>';
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
