<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * New Booking Form Template
 * Location: wp-content/plugins/vatcar-fir-station-booking/templates/booking-form.php
 */
?>

<style>
.ops-panel{
  border:1px solid rgba(0,0,0,.10);
  border-radius:10px;
  padding:10px 12px;
  margin:0 0 12px 0;
  background:linear-gradient(180deg,#f7f7f9,#fff);
  box-shadow:0 2px 8px rgba(0,0,0,.06);
  display:flex; align-items:center; justify-content:space-between; gap:12px;
}
.ops-left{display:flex; flex-direction:column; gap:4px; min-width:220px;}
.ops-title{font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:#666; line-height:1.1;}
.ops-time{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; font-size:18px; font-weight:700; letter-spacing:.02em; color:#111; white-space:nowrap;}
.ops-badge{display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#444;}
.ops-dot{width:8px; height:8px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.18); display:inline-block;}
.ops-right{display:flex; align-items:center; gap:10px; justify-content:flex-end; flex-wrap:wrap;}
.ops-toggle{display:inline-flex; border:1px solid rgba(0,0,0,.12); border-radius:999px; overflow:hidden; background:#fff;}
.ops-btn{appearance:none; border:0; background:transparent; padding:7px 10px; font-size:12px; font-weight:600; cursor:pointer; color:#333; transition:background .15s ease,color .15s ease; white-space:nowrap;}
.ops-btn:hover{background:rgba(0,0,0,.04);}
.ops-btn.active{background:#111; color:#fff;}
.ops-meta{font-size:12px; color:#666; white-space:nowrap;}

.preview-box{
  margin:10px 0 14px 0;
  padding:10px 12px;
  border:1px dashed rgba(0,0,0,.18);
  border-radius:10px;
  background:rgba(0,0,0,.02);
  font-size:13px;
  color:#333;
}
.preview-box b{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;}
.preview-box small{color:#666}
.preview-z{font-size:11px; vertical-align:top; color:#666; margin-left:0;} /* small Z, no space */

@media (max-width:520px){
  .ops-panel{flex-direction:column; align-items:stretch;}
  .ops-right{justify-content:space-between;}
  .ops-time{font-size:16px;}
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

<form method="post" id="newBookingForm">
  <?php wp_nonce_field('vatcar_new_booking','vatcar_booking_nonce'); ?>
  <input type="hidden" id="input_mode" name="input_mode" value="utc">

  <?php if (current_user_can('manage_options')): ?>
    <div class="form-row">
      <label>Controller CID (Admin only):</label>
      <input
        type="text"
        name="controller_cid"
        inputmode="numeric"
        pattern="\d+"
        autocomplete="off"
        placeholder="e.g. 1234567"
      >
      <small>Leave blank to book for yourself (if applicable).</small>
    </div>
  <?php endif; ?>

  <div class="form-row">
    <label>Station:</label>
    <select name="callsign" required>
      <option value="" disabled selected>-- Select --</option>
      <?php
      $stations = vatcar_generate_station_list();
      foreach($stations as $station){
        // Special case: TNCA_GND displays as TNCA_RMP but stores as TNCA_GND
        $display = ($station === 'TNCA_GND') ? 'TNCA_RMP' : $station;
        echo '<option value="'.esc_attr($station).'">'.esc_html($display).'</option>';
      }
      ?>
    </select>
  </div>

  <div class="form-row">
    <label id="lbl-start-date">Start Date (Zulu / UTC):</label>
    <input type="date" name="start_date" required min="<?php echo gmdate('Y-m-d'); ?>">
    <small>Must be at least 2 hours from now.</small>
  </div>

  <div class="form-row">
    <label id="lbl-start-time">Start Time (Zulu / UTC):</label>
    <select name="start_time" required>
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

  <div class="form-row">
    <label id="lbl-end-date">End Date (Zulu / UTC):</label>
    <input type="date" name="end_date" required min="<?php echo gmdate('Y-m-d'); ?>">
  </div>

  <div class="form-row">
    <label id="lbl-end-time">End Time (Zulu / UTC):</label>
    <select name="end_time" required>
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

  <div class="form-row">
    <button type="submit" class="btn btn-primary">Book</button>
  </div>
</form>

<div class="preview-box" id="store-preview" style="display:none;">
  <div><small>Will be stored as (UTC):</small></div>
  <div>Start: <b id="prev-start">--</b><span class="preview-z"><small>Z</small></span></div>
  <div>End:&nbsp;&nbsp; <b id="prev-end">--</b><span class="preview-z"><small>Z</small></span></div>
</div>

<script>
(function () {

  // ===== Ops panel =====
  const elTime  = document.getElementById("ops-time");
  const elLabel = document.getElementById("ops-label");
  const elMeta  = document.getElementById("ops-meta");
  const btnUTC  = document.getElementById("btn-utc");
  const btnLoc  = document.getElementById("btn-local");
  const inputMode = document.getElementById("input_mode");

  const KEY = "cur_ops_time_mode";
  let mode = localStorage.getItem(KEY) || "utc"; // "utc" or "local"

  // ===== Preview elements =====
  const previewBox = document.getElementById("store-preview");
  const prevStart  = document.getElementById("prev-start");
  const prevEnd    = document.getElementById("prev-end");

  // ===== Form elements =====
  const form = document.getElementById("newBookingForm");
  const startDateInput  = form.querySelector('input[name="start_date"]');
  const startTimeSelect = form.querySelector('select[name="start_time"]');
  const endDateInput    = form.querySelector('input[name="end_date"]');
  const endTimeSelect   = form.querySelector('select[name="end_time"]');

  const lblStartDate = document.getElementById("lbl-start-date");
  const lblStartTime = document.getElementById("lbl-start-time");
  const lblEndDate   = document.getElementById("lbl-end-date");
  const lblEndTime   = document.getElementById("lbl-end-time");

  function pad(n){ return String(n).padStart(2,"0"); }

  // Snap up to next 15-min block (00/15/30/45). If already exact, stays.
  function snapToNextQuarter(d){
    const mins = d.getMinutes();
    const snapped = Math.ceil(mins / 15) * 15;
    d.setMinutes(snapped, 0, 0);
    return d; // Date handles minute=60 roll automatically
  }

  // Create "now" in the active mode (a Date representing that clock reading)
  function nowInMode(){
    const n = new Date();
    if (mode === "utc") {
      return new Date(Date.UTC(
        n.getUTCFullYear(), n.getUTCMonth(), n.getUTCDate(),
        n.getUTCHours(), n.getUTCMinutes(), n.getUTCSeconds(), 0
      ));
    }
    return new Date(
      n.getFullYear(), n.getMonth(), n.getDate(),
      n.getHours(), n.getMinutes(), n.getSeconds(), 0
    );
  }

  // Min start = (now + 2h) in active mode, snapped to next quarter
  function minStartInMode(){
    const d = nowInMode();
    if (mode === "utc") d.setUTCHours(d.getUTCHours() + 2);
    else d.setHours(d.getHours() + 2);
    return snapToNextQuarter(d);
  }

  // "today" in active mode for date min
  function todayYMDInMode(){
    const n = nowInMode();
    const y = (mode === "utc") ? n.getUTCFullYear() : n.getFullYear();
    const m = (mode === "utc") ? (n.getUTCMonth()+1) : (n.getMonth()+1);
    const d = (mode === "utc") ? n.getUTCDate() : n.getDate();
    return y + "-" + pad(m) + "-" + pad(d);
  }

  // Build a Date from date+time interpreted in ACTIVE mode (UTC or Local)
  function buildMomentInMode(dateStr, timeStr){
    if(!dateStr || !timeStr) return null;
    const y = Number(dateStr.slice(0,4));
    const mo = Number(dateStr.slice(5,7)) - 1;
    const da = Number(dateStr.slice(8,10));
    const hh = Number(timeStr.slice(0,2));
    const mi = Number(timeStr.slice(3,5));

    return (mode === "utc")
      ? new Date(Date.UTC(y, mo, da, hh, mi, 0, 0))
      : new Date(y, mo, da, hh, mi, 0, 0);
  }

  // Build a Date from date+time interpreted as LOCAL (used for conversion on submit)
  function buildMomentLocal(dateStr, timeStr){
    if(!dateStr || !timeStr) return null;
    const y = Number(dateStr.slice(0,4));
    const mo = Number(dateStr.slice(5,7)) - 1;
    const da = Number(dateStr.slice(8,10));
    const hh = Number(timeStr.slice(0,2));
    const mi = Number(timeStr.slice(3,5));
    return new Date(y, mo, da, hh, mi, 0, 0);
  }

  // Build a Date from date+time interpreted as UTC (used for conversion on submit)
  function buildMomentUTC(dateStr, timeStr){
    if(!dateStr || !timeStr) return null;
    const y = Number(dateStr.slice(0,4));
    const mo = Number(dateStr.slice(5,7)) - 1;
    const da = Number(dateStr.slice(8,10));
    const hh = Number(timeStr.slice(0,2));
    const mi = Number(timeStr.slice(3,5));
    return new Date(Date.UTC(y, mo, da, hh, mi, 0, 0));
  }

  function ymdFromMomentInMode(d){
    const y = (mode === "utc") ? d.getUTCFullYear() : d.getFullYear();
    const m = (mode === "utc") ? (d.getUTCMonth()+1) : (d.getMonth()+1);
    const da = (mode === "utc") ? d.getUTCDate() : d.getDate();
    return y + "-" + pad(m) + "-" + pad(da);
  }

  // ===== Option management: REMOVE invalid times (not greyed out) =====
  const START_OPTIONS_ALL = Array.from(startTimeSelect.options).filter(o => o.value).map(o => o.value);
  const END_OPTIONS_ALL   = Array.from(endTimeSelect.options).filter(o => o.value).map(o => o.value);

  function setSelectOptions(selectEl, values, keepValue){
    const prev = keepValue || selectEl.value;

    selectEl.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = '';
    ph.disabled = true;
    ph.selected = true;
    ph.textContent = '-- Select Time --';
    selectEl.appendChild(ph);

    values.forEach(v => {
      const o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      selectEl.appendChild(o);
    });

    if (prev && values.includes(prev)) selectEl.value = prev;
    else selectEl.value = '';
  }

  // ===== Enforcements =====
  function enforceNoPastDates(){
    const minYMD = todayYMDInMode();
    startDateInput.min = minYMD;
    endDateInput.min   = minYMD;

    if (startDateInput.value && startDateInput.value < minYMD) startDateInput.value = minYMD;
    if (endDateInput.value && endDateInput.value < minYMD) endDateInput.value = minYMD;
  }

  function enforceMinStart(){
    if(!startDateInput.value){
      setSelectOptions(startTimeSelect, START_OPTIONS_ALL, startTimeSelect.value);
      return;
    }

    const minYMD = todayYMDInMode();
    if (startDateInput.value < minYMD) startDateInput.value = minYMD;

    const minStart = minStartInMode();
    const selectedDay = startDateInput.value;

    let allowed = START_OPTIONS_ALL;

    // if selecting today in active mode, remove any times before minStart
    if (selectedDay === todayYMDInMode()) {
      allowed = START_OPTIONS_ALL.filter(t => {
        const cand = buildMomentInMode(selectedDay, t);
        return cand && cand.getTime() >= minStart.getTime();
      });
    }

    setSelectOptions(startTimeSelect, allowed, startTimeSelect.value);

    // keep end date >= start date
    endDateInput.min = startDateInput.value;
    if (endDateInput.value && endDateInput.value < startDateInput.value) {
      endDateInput.value = startDateInput.value;
    }
  }

  function enforceEndMinDate(){
    if (!startDateInput.value) return;
    if (endDateInput.value && endDateInput.value < startDateInput.value) {
      endDateInput.value = startDateInput.value;
    }
    endDateInput.min = startDateInput.value;
  }

  function enforceMinEnd(){
    enforceEndMinDate();

    if(!startDateInput.value || !startTimeSelect.value){
      setSelectOptions(endTimeSelect, END_OPTIONS_ALL, endTimeSelect.value);
      return;
    }

    if(!endDateInput.value) endDateInput.value = startDateInput.value;

    const startMoment = buildMomentInMode(startDateInput.value, startTimeSelect.value);
    if(!startMoment) return;

    const minEnd = new Date(startMoment.getTime() + 60*60*1000);
    const minEndYMD = ymdFromMomentInMode(minEnd);

    // bump end date forward if needed (crossing midnight)
    if (endDateInput.value < minEndYMD) {
      endDateInput.value = minEndYMD;
    }

    let allowed = END_OPTIONS_ALL;

    if (endDateInput.value === minEndYMD) {
      allowed = END_OPTIONS_ALL.filter(t => {
        const cand = buildMomentInMode(endDateInput.value, t);
        return cand && cand.getTime() >= minEnd.getTime();
      });
    }

    setSelectOptions(endTimeSelect, allowed, endTimeSelect.value);
  }
  // ===== Preview: show what will be STORED in UTC =====
  function utcYMDHM(d){
    return d.getUTCFullYear() + "-" + pad(d.getUTCMonth()+1) + "-" + pad(d.getUTCDate()) + " " + pad(d.getUTCHours()) + ":" + pad(d.getUTCMinutes());
  }

  function updateStorePreview(){
    // show only when we have enough data
    const hasStart = startDateInput.value && startTimeSelect.value;
    const hasEnd   = endDateInput.value && endTimeSelect.value;

    if (!hasStart && !hasEnd) {
      previewBox.style.display = "none";
      return;
    }

    previewBox.style.display = "block";

    // interpret selections based on current mode, but show as UTC
    const startMoment = buildMomentInMode(startDateInput.value, startTimeSelect.value);
    const endMoment   = buildMomentInMode(endDateInput.value, endTimeSelect.value);

    prevStart.textContent = startMoment ? utcYMDHM(startMoment) : "--";
    prevEnd.textContent   = endMoment ? utcYMDHM(endMoment) : "--";
  }

  // ===== Submit conversion: Local entry -> UTC submission =====
  function dateToYMD_UTC(d){ return d.getUTCFullYear()+"-"+pad(d.getUTCMonth()+1)+"-"+pad(d.getUTCDate()); }
  function timeToHM_UTC(d){ return pad(d.getUTCHours())+":"+pad(d.getUTCMinutes()); }

  function validateBeforeSubmit(){
    if (!startDateInput.value || !startTimeSelect.value) return true;
    if (!endDateInput.value || !endTimeSelect.value) return true;

    const startMoment = buildMomentInMode(startDateInput.value, startTimeSelect.value);
    const endMoment   = buildMomentInMode(endDateInput.value, endTimeSelect.value);
    if (!startMoment || !endMoment) return true;

    const minStart = minStartInMode();
    if (startMoment.getTime() < minStart.getTime()) {
      alert("Start time must be at least 2 hours from now (" + (mode==="utc"?"Zulu":"Local") + "), rounded up to the next 15-minute block.");
      return false;
    }

    const minEnd = new Date(startMoment.getTime() + 60*60*1000);
    if (endMoment.getTime() < minEnd.getTime()) {
      alert("End time must be at least 1 hour after start time.");
      return false;
    }

    return true;
  }

  form.addEventListener("submit", function(e){
    if (!validateBeforeSubmit()) {
      e.preventDefault();
      return;
    }

    // Convert Local-entry to UTC fields before POST
    if (mode === "local") {
      const sLocal = buildMomentLocal(startDateInput.value, startTimeSelect.value);
      const eLocal = buildMomentLocal(endDateInput.value, endTimeSelect.value);

      startDateInput.value = dateToYMD_UTC(sLocal);
      startTimeSelect.value = timeToHM_UTC(sLocal);

      endDateInput.value = dateToYMD_UTC(eLocal);
      endTimeSelect.value = timeToHM_UTC(eLocal);
    } else {
      // In Zulu mode, the user entered UTC already – still safe/clean.
      // (No conversion needed)
    }
  });

  // ===== Labels + clock =====
  function updateLabels(){
    const tag = (mode === "utc") ? "Zulu / UTC" : "Local";
    lblStartDate.textContent = "Start Date (" + tag + "):";
    lblStartTime.textContent = "Start Time (" + tag + "):";
    lblEndDate.textContent   = "End Date (" + tag + "):";
    lblEndTime.textContent   = "End Time (" + tag + "):";
    if (inputMode) inputMode.value = mode;
  }

  function tickClock(){
    const n = new Date();
    const useUTC = (mode === "utc");
    const yyyy = useUTC ? n.getUTCFullYear() : n.getFullYear();
    const mm   = useUTC ? (n.getUTCMonth()+1) : (n.getMonth()+1);
    const dd   = useUTC ? n.getUTCDate() : n.getDate();
    const hh   = useUTC ? n.getUTCHours() : n.getHours();
    const mi   = useUTC ? n.getUTCMinutes() : n.getMinutes();
    const ss   = useUTC ? n.getUTCSeconds() : n.getSeconds();

    elTime.textContent = pad(dd) + "-" + pad(mm) + "-" + yyyy + " " + pad(hh) + ":" + pad(mi) + ":" + pad(ss);

    if (useUTC) {
      elLabel.textContent = "Zulu (UTC)";
      elMeta.textContent = "Z";
    } else {
      elLabel.textContent = "Local";
      try { elMeta.textContent = Intl.DateTimeFormat().resolvedOptions().timeZone || ""; }
      catch(e){ elMeta.textContent = ""; }
    }
  }

  function applyModeUI(){
    btnUTC.classList.toggle("active", mode === "utc");
    btnLoc.classList.toggle("active", mode === "local");
    localStorage.setItem(KEY, mode);
  }

  function refreshAll(){
    updateLabels();
    applyModeUI();
    enforceNoPastDates();
    enforceMinStart();
    enforceMinEnd();
    updateStorePreview();
    tickClock();
  }

  btnUTC.addEventListener("click", function(){ mode="utc"; refreshAll(); });
  btnLoc.addEventListener("click", function(){ mode="local"; refreshAll(); });

  // Events
  startDateInput.addEventListener("change", function(){ enforceNoPastDates(); enforceMinStart(); enforceMinEnd(); updateStorePreview(); });
  startTimeSelect.addEventListener("change", function(){ enforceMinEnd(); updateStorePreview(); });
  endDateInput.addEventListener("change", function(){ enforceNoPastDates(); enforceMinEnd(); updateStorePreview(); });
  endTimeSelect.addEventListener("change", updateStorePreview);

  startTimeSelect.addEventListener("focus", enforceMinStart);
  endTimeSelect.addEventListener("focus", enforceMinEnd);

  // Init
  refreshAll();
  setInterval(tickClock, 1000);
})();

// ===== Admin CID Lookup (AJAX) =====
<?php if (current_user_can('manage_options')): ?>
(function() {
  const cidInput = document.querySelector('input[name="controller_cid"]');
  if (!cidInput) return;

  let lookupTimer = null;
  const resultDiv = document.createElement('div');
  resultDiv.style.cssText = 'margin-top:8px; padding:10px; border-radius:6px; font-size:13px; display:none;';
  cidInput.parentElement.appendChild(resultDiv);

  function showLoading() {
    resultDiv.style.display = 'block';
    resultDiv.style.background = 'rgba(0,0,0,0.04)';
    resultDiv.style.border = '1px solid rgba(0,0,0,0.1)';
    resultDiv.style.color = '#666';
    resultDiv.innerHTML = '🔍 Looking up controller...';
  }

  function showError(msg) {
    resultDiv.style.display = 'block';
    resultDiv.style.background = '#fee';
    resultDiv.style.border = '1px solid #c33';
    resultDiv.style.color = '#c33';
    resultDiv.innerHTML = '⚠️ ' + msg;
  }

  function showSuccess(data) {
    resultDiv.style.display = 'block';
    resultDiv.style.background = '#efe';
    resultDiv.style.border = '1px solid #3c3';
    resultDiv.style.color = '#060';

    let html = '<strong>✓ Controller Found</strong><br>';
    html += 'Division: ' + (data.division || 'Unknown') + '<br>';
    html += 'Subdivision: ' + (data.subdivision || 'Unknown') + '<br>';
    html += 'Rating: ' + (data.rating_name || 'Unknown') + ' (' + (data.rating || 0) + ')';

    if (data.whitelist_type) {
      html += '<br><span style="color:#f80; font-weight:600;">✦ ' + data.whitelist_type.toUpperCase() + '</span>';
      if (data.authorized_positions && data.authorized_positions.length > 0) {
        html += '<br><small>Authorized: ' + data.authorized_positions.join(', ') + '</small>';
      }
    }

    // Warnings
    const requiredSubdiv = '<?php echo esc_js(vatcar_detect_subdivision()); ?>';
    if (data.division !== 'CAR' && !data.whitelist_type) {
      html += '<br><span style="color:#c33; font-weight:600;">⚠ Not in VATCAR division</span>';
    }
    if (data.subdivision !== requiredSubdiv && !data.whitelist_type) {
      html += '<br><span style="color:#c33; font-weight:600;">⚠ Wrong subdivision</span>';
    }

    resultDiv.innerHTML = html;
  }

  function lookupController(cid) {
    if (!cid || !/^\d{1,10}$/.test(cid)) {
      resultDiv.style.display = 'none';
      return;
    }

    showLoading();

    const formData = new FormData();
    formData.append('action', 'lookup_controller');
    formData.append('cid', cid);
    formData.append('vatcar_lookup_controller_nonce', '<?php echo wp_create_nonce("vatcar_lookup_controller"); ?>');

    fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
      method: 'POST',
      body: formData,
    })
    .then(r => r.json())
    .then(resp => {
      if (resp.success) {
        showSuccess(resp.data);
      } else {
        showError(resp.data || 'Failed to lookup controller');
      }
    })
    .catch(err => {
      showError('Network error');
      console.error(err);
    });
  }

  cidInput.addEventListener('input', function() {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(() => {
      lookupController(cidInput.value.trim());
    }, 600);
  });

  // Initial check if field has a value
  if (cidInput.value.trim()) {
    lookupController(cidInput.value.trim());
  }
})();
<?php endif; ?>
</script>
