<<<<<<< HEAD
<?php
/**
 * New Booking Form Template
 * Location: wp-content/plugins/curacao-atc-bookings/templates/booking-form.php
 */
?>

<div id="zuluClock" style="font-weight:bold; margin-bottom:1em;">
  Current Zulu Time: <span id="zuluClockValue"></span>
</div>

<form method="post" id="newBookingForm">
  <?php wp_nonce_field('curacao_new_booking', 'curacao_booking_nonce'); ?>
    <!-- Dropdown stations -->
    <div class="form-row">
        <label>Station:</label>
        <select name="callsign" required>
            <option value="" disabled selected>-- Select --</option>
            <?php
            $stations = [
                'TNCA_RMP', 'TNCA_TWR', 'TNCA_APP',
                'TNCC_TWR', 'TNCB_TWR',
                'TNCF_APP', 'TNCF_CTR',
                'TNCM_DEL', 'TNCM_TWR', 'TNCM_APP',
                'TQPF_TWR'
            ];
            foreach ($stations as $station) {
              if ($station === 'TNCA_RMP') {
                echo '<option value="TNCA_GND">TNCA_RMP</option>';
            } else {
                echo '<option value="' . esc_attr($station) . '">' . esc_html($station) . '</option>';
            }
          }
            ?>
        </select>
    </div>

    <?php
    // Calculate min start date = now + 6 hours (UTC)
    $min_start_date = gmdate('Y-m-d', time() + 6*3600);
    ?>

    <div class="form-row">
        <label>Start Date (Zulu / UTC):</label>
        <input type="date" name="start_date" min="<?php echo $min_start_date; ?>" required>
        <small>Calendar input is interpreted as UTC midnight</small>
    </div>

    <div class="form-row">
        <label>Start Time (Zulu / UTC):</label>
        <select name="start_time" required>
            <option value="" disabled selected>-- Select Time --</option>
            <?php
            $hours = range(0,23);
            $quarters = ['00','15','30','45'];
            foreach ($hours as $h) {
                foreach ($quarters as $q) {
                    $time = sprintf('%02d:%s', $h, $q);
                    echo "<option value='$time'>$time</option>";
                }
            }
            ?>
        </select>
    </div>

    <div class="form-row">
        <label>End Date (Zulu / UTC):</label>
        <input type="date" name="end_date" required>
        <small>Calendar input is interpreted as UTC midnight</small>
    </div>

    <div class="form-row">
        <label>End Time (Zulu / UTC):</label>
        <select name="end_time" required>
            <option value="" disabled selected>-- Select Time --</option>
            <?php
            foreach ($hours as $h) {
                foreach ($quarters as $q) {
                    $time = sprintf('%02d:%s', $h, $q);
                    echo "<option value='$time'>$time</option>";
                }
            }
            ?>
        </select>
    </div>

    <div class="form-row">
        <button type="submit" class="btn btn-primary">Book</button>
    </div>
</form>

<script>
// Live Zulu clock
function updateZuluClock() {
  const now = new Date();
  const zulu = new Date(Date.UTC(
    now.getUTCFullYear(),
    now.getUTCMonth(),
    now.getUTCDate(),
    now.getUTCHours(),
    now.getUTCMinutes(),
    now.getUTCSeconds()
  ));
  const hh = String(zulu.getUTCHours()).padStart(2,'0');
  const mm = String(zulu.getUTCMinutes()).padStart(2,'0');
  const ss = String(zulu.getUTCSeconds()).padStart(2,'0');
  document.getElementById('zuluClockValue').textContent = `${hh}:${mm}:${ss}Z`;
}
setInterval(updateZuluClock, 1000);
updateZuluClock();

// Utility: now + 6h UTC
function getNowPlus6hUTC() {
  const now = new Date();
  const utcNow = new Date(Date.UTC(
    now.getUTCFullYear(),
    now.getUTCMonth(),
    now.getUTCDate(),
    now.getUTCHours(),
    now.getUTCMinutes(),
    now.getUTCSeconds()
  ));
  utcNow.setUTCHours(utcNow.getUTCHours() + 6);
  return utcNow;
}

const startDateInput = document.querySelector('#newBookingForm input[name="start_date"]');
const startTimeSelect = document.querySelector('#newBookingForm select[name="start_time"]');
const endDateInput   = document.querySelector('#newBookingForm input[name="end_date"]');
const endTimeSelect  = document.querySelector('#newBookingForm select[name="end_time"]');

// Cache all original end-time options once
const ALL_END_TIME_OPTIONS = Array.from(endTimeSelect.querySelectorAll('option')).map(opt => ({
  value: opt.value,
  text: opt.textContent
}));

// Enforce min start time (≥ now+6h UTC)
function enforceMinStart() {
  const minDate = getNowPlus6hUTC();
  if (!startDateInput.value) return;

  const selectedDate = new Date(startDateInput.value + 'T00:00:00Z');
  const validOptions = [];

  ALL_END_TIME_OPTIONS.forEach(opt => {
    if (!opt.value) { validOptions.push(opt); return; }
    const [h, m] = opt.value.split(':').map(Number);
    const candidate = new Date(selectedDate);
    candidate.setUTCHours(h, m, 0, 0);
    if (candidate >= minDate) validOptions.push(opt);
  });

  startTimeSelect.innerHTML = '';
  validOptions.forEach(opt => {
    const o = document.createElement('option');
    o.value = opt.value;
    o.textContent = opt.text;
    startTimeSelect.appendChild(o);
  });
}

// Enforce min end time (≥ start+1h UTC, across days)
function enforceMinEnd() {
  if (!startDateInput.value || !startTimeSelect.value || !endDateInput.value) return;

  const [sh, sm] = startTimeSelect.value.split(':').map(Number);
  const startCandidate = new Date(startDateInput.value + 'T00:00:00Z');
  startCandidate.setUTCHours(sh, sm, 0, 0);

  const minEnd = new Date(startCandidate.getTime() + 60 * 60000);
  const selectedEndDate = new Date(endDateInput.value + 'T00:00:00Z');

  const previousValue = endTimeSelect.value;
  const validOptions = [];

  ALL_END_TIME_OPTIONS.forEach(opt => {
    if (!opt.value) { validOptions.push(opt); return; }
    const [eh, em] = opt.value.split(':').map(Number);
    const endCandidate = new Date(selectedEndDate);
    endCandidate.setUTCHours(eh, em, 0, 0);
    if (endCandidate >= minEnd) validOptions.push(opt);
  });

  endTimeSelect.innerHTML = '';
  validOptions.forEach(opt => {
    const o = document.createElement('option');
    o.value = opt.value;
    o.textContent = opt.text;
    endTimeSelect.appendChild(o);
  });

  if (validOptions.some(opt => opt.value === previousValue)) {
    endTimeSelect.value = previousValue;
  } else if (validOptions.length > 0) {
    endTimeSelect.value = validOptions[0].value;
  }
}

// Hook up events
startDateInput.addEventListener('change', () => {
  enforceMinStart();
  endDateInput.min = startDateInput.value; // enforce end date ≥ start date
  enforceMinEnd();
});
startTimeSelect.addEventListener('change', enforceMinEnd);
endDateInput.addEventListener('change', enforceMinEnd);
endTimeSelect.addEventListener('focus', enforceMinEnd);
startTimeSelect.addEventListener('focus', enforceMinStart);
</script>
=======
<?php

/**

 * New Booking Form Template

 * Location: wp-content/plugins/vatcar-fir-station-booking/templates/booking-form.php

 */

?>



<div id="zuluClock" style="font-weight:bold; margin-bottom:1em;">

  Current Zulu Time: <span id="zuluClockValue"></span>

</div>



<form method="post" id="newBookingForm">

  <?php wp_nonce_field('vatcar_new_booking', 'vatcar_booking_nonce'); ?>

    <!-- Dropdown stations -->

    <div class="form-row">

        <label>Station:</label>

        <select name="callsign" required>

            <option value="" disabled selected>-- Select --</option>

            <?php

            $stations = [

                'TNCA_RMP', 'TNCA_TWR', 'TNCA_APP',

                'TNCC_TWR', 'TNCB_TWR',

                'TNCF_APP', 'TNCF_CTR',

                'TNCM_DEL', 'TNCM_TWR', 'TNCM_APP',

                'TQPF_TWR'

            ];

            foreach ($stations as $station) {

              if ($station === 'TNCA_RMP') {

                echo '<option value="TNCA_GND">TNCA_RMP</option>';

            } else {

                echo '<option value="' . esc_attr($station) . '">' . esc_html($station) . '</option>';

            }

          }

            ?>

        </select>

    </div>



    <?php

    // Calculate min start date = now + 6 hours (UTC)

    $min_start_date = gmdate('Y-m-d', time() + 6*3600);

    ?>



    <div class="form-row">

        <label>Start Date (Zulu / UTC):</label>

        <input type="date" name="start_date" min="<?php echo $min_start_date; ?>" required>

        <small>Calendar input is interpreted as UTC midnight</small>

    </div>



    <div class="form-row">

        <label>Start Time (Zulu / UTC):</label>

        <select name="start_time" required>

            <option value="" disabled selected>-- Select Time --</option>

            <?php

            $hours = range(0,23);

            $quarters = ['00','15','30','45'];

            foreach ($hours as $h) {

                foreach ($quarters as $q) {

                    $time = sprintf('%02d:%s', $h, $q);

                    echo "<option value='$time'>$time</option>";

                }

            }

            ?>

        </select>

    </div>



    <div class="form-row">

        <label>End Date (Zulu / UTC):</label>

        <input type="date" name="end_date" required>

        <small>Calendar input is interpreted as UTC midnight</small>

    </div>



    <div class="form-row">

        <label>End Time (Zulu / UTC):</label>

        <select name="end_time" required>

            <option value="" disabled selected>-- Select Time --</option>

            <?php

            foreach ($hours as $h) {

                foreach ($quarters as $q) {

                    $time = sprintf('%02d:%s', $h, $q);

                    echo "<option value='$time'>$time</option>";

                }

            }

            ?>

        </select>

    </div>



    <div class="form-row">

        <button type="submit" class="btn btn-primary">Book</button>

    </div>

</form>



<script>

// Live Zulu clock

function updateZuluClock() {

  const now = new Date();

  const zulu = new Date(Date.UTC(

    now.getUTCFullYear(),

    now.getUTCMonth(),

    now.getUTCDate(),

    now.getUTCHours(),

    now.getUTCMinutes(),

    now.getUTCSeconds()

  ));

  const hh = String(zulu.getUTCHours()).padStart(2,'0');

  const mm = String(zulu.getUTCMinutes()).padStart(2,'0');

  const ss = String(zulu.getUTCSeconds()).padStart(2,'0');

  document.getElementById('zuluClockValue').textContent = `${hh}:${mm}:${ss}Z`;

}

setInterval(updateZuluClock, 1000);

updateZuluClock();



// Utility: now + 6h UTC

function getNowPlus6hUTC() {

  const now = new Date();

  const utcNow = new Date(Date.UTC(

    now.getUTCFullYear(),

    now.getUTCMonth(),

    now.getUTCDate(),

    now.getUTCHours(),

    now.getUTCMinutes(),

    now.getUTCSeconds()

  ));

  utcNow.setUTCHours(utcNow.getUTCHours() + 6);

  return utcNow;

}



const startDateInput = document.querySelector('#newBookingForm input[name="start_date"]');

const startTimeSelect = document.querySelector('#newBookingForm select[name="start_time"]');

const endDateInput   = document.querySelector('#newBookingForm input[name="end_date"]');

const endTimeSelect  = document.querySelector('#newBookingForm select[name="end_time"]');



// Cache all original end-time options once

const ALL_END_TIME_OPTIONS = Array.from(endTimeSelect.querySelectorAll('option')).map(opt => ({

  value: opt.value,

  text: opt.textContent

}));



// Enforce min start time (≥ now+6h UTC)

function enforceMinStart() {

  const minDate = getNowPlus6hUTC();

  if (!startDateInput.value) return;



  const selectedDate = new Date(startDateInput.value + 'T00:00:00Z');

  const validOptions = [];



  ALL_END_TIME_OPTIONS.forEach(opt => {

    if (!opt.value) { validOptions.push(opt); return; }

    const [h, m] = opt.value.split(':').map(Number);

    const candidate = new Date(selectedDate);

    candidate.setUTCHours(h, m, 0, 0);

    if (candidate >= minDate) validOptions.push(opt);

  });



  startTimeSelect.innerHTML = '';

  validOptions.forEach(opt => {

    const o = document.createElement('option');

    o.value = opt.value;

    o.textContent = opt.text;

    startTimeSelect.appendChild(o);

  });

}



// Enforce min end time (≥ start+1h UTC, across days)

function enforceMinEnd() {

  if (!startDateInput.value || !startTimeSelect.value || !endDateInput.value) return;



  const [sh, sm] = startTimeSelect.value.split(':').map(Number);

  const startCandidate = new Date(startDateInput.value + 'T00:00:00Z');

  startCandidate.setUTCHours(sh, sm, 0, 0);



  const minEnd = new Date(startCandidate.getTime() + 60 * 60000);

  const selectedEndDate = new Date(endDateInput.value + 'T00:00:00Z');



  const previousValue = endTimeSelect.value;

  const validOptions = [];



  ALL_END_TIME_OPTIONS.forEach(opt => {

    if (!opt.value) { validOptions.push(opt); return; }

    const [eh, em] = opt.value.split(':').map(Number);

    const endCandidate = new Date(selectedEndDate);

    endCandidate.setUTCHours(eh, em, 0, 0);

    if (endCandidate >= minEnd) validOptions.push(opt);

  });



  endTimeSelect.innerHTML = '';

  validOptions.forEach(opt => {

    const o = document.createElement('option');

    o.value = opt.value;

    o.textContent = opt.text;

    endTimeSelect.appendChild(o);

  });



  if (validOptions.some(opt => opt.value === previousValue)) {

    endTimeSelect.value = previousValue;

  } else if (validOptions.length > 0) {

    endTimeSelect.value = validOptions[0].value;

  }

}



// Hook up events

startDateInput.addEventListener('change', () => {

  enforceMinStart();

  endDateInput.min = startDateInput.value; // enforce end date ≥ start date

  enforceMinEnd();

});

startTimeSelect.addEventListener('change', enforceMinEnd);

endDateInput.addEventListener('change', enforceMinEnd);

endTimeSelect.addEventListener('focus', enforceMinEnd);

startTimeSelect.addEventListener('focus', enforceMinStart);

</script>

>>>>>>> ee05cba (Generalise from Curacao references to VATCAR references)
