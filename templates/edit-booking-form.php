<?php

/**

 * Edit Booking Modal Template

 * Location: wp-content/plugins/vatcar-fir-station-booking/templates/edit-booking-form.php

 */

?>



<div id="editBookingModal" class="modal" style="display:none;">

  <div class="modal-content">

    <span class="close">&times;</span>

    <h2>Edit Booking</h2>



    <!-- Inline message area -->

    <div id="editBookingMessage" style="display:none; margin:0 0 12px 0; padding:10px; border-radius:6px;"></div>



    <form method="post" id="editBookingForm">

      <?php wp_nonce_field('vatcar_update_booking', 'vatcar_update_nonce'); ?>

      <input type="hidden" name="action" value="update_booking">

      <input type="hidden" name="booking_id" id="edit_booking_id">

      <input type="hidden" name="callsign" id="edit_callsign_hidden">



      <div class="booking-context" style="margin-bottom:0.75rem;">

        <strong>Editing:</strong> <span id="edit_context_summary"></span>

      </div>



      <!-- Station (readonly display) -->

      <div class="form-row">

        <label>Station</label>

        <input type="text" id="edit_callsign_readonly" readonly>

      </div>



      <!-- Start Date -->

      <div class="form-row">

        <label>Start Date (Zulu / UTC)</label>

        <input type="date" name="start_date" id="edit_start_date" required>

        <small>Calendar input is interpreted as UTC midnight</small>

      </div>



      <!-- Start Time -->

      <div class="form-row">

        <label>Start Time (Zulu / UTC)</label>

        <select name="start_time" id="edit_start_time" required>

          <?php echo VatCar_ATC_Validation::time_options(); ?>

        </select>

      </div>



      <!-- End Date -->

      <div class="form-row">

        <label>End Date (Zulu / UTC)</label>

        <input type="date" name="end_date" id="edit_end_date" required>

        <small>Calendar input is interpreted as UTC midnight</small>

      </div>



      <!-- End Time -->

      <div class="form-row">

        <label>End Time (Zulu / UTC)</label>

        <select name="end_time" id="edit_end_time" required>

          <?php echo VatCar_ATC_Validation::time_options(); ?>

        </select>

      </div>



      <!-- Buttons -->

      <div class="form-row">

        <button type="submit" class="btn btn-primary" id="editSaveBtn">Save Changes</button>

        <button type="button" class="btn btn-secondary" id="cancelEdit">Cancel</button>

      </div>

    </form>

  </div>

</div>



<script>

(function() {

  const modal            = document.getElementById('editBookingModal');

  const messageBox       = document.getElementById('editBookingMessage');

  const form             = document.getElementById('editBookingForm');

  const saveBtn          = document.getElementById('editSaveBtn');

  const closeBtn         = document.querySelector('#editBookingModal .close');

  const cancelBtn        = document.getElementById('cancelEdit');



  const startDateInput   = form.querySelector('input[name="start_date"]');

  const startTimeSelect  = form.querySelector('select[name="start_time"]');

  const endDateInput     = form.querySelector('input[name="end_date"]');

  const endTimeSelect    = form.querySelector('select[name="end_time"]');



  // Cache all original end-time options once

  const ALL_END_TIME_OPTIONS = Array.from(endTimeSelect.querySelectorAll('option')).map(opt => ({

    value: opt.value,

    text: opt.textContent

  }));



  function showMessage(text, type) {

    messageBox.textContent = text;

    messageBox.style.display = 'block';

    messageBox.style.background = type === 'success' ? '#e6ffed' : '#ffecec';

    messageBox.style.border = type === 'success' ? '1px solid #28a745' : '1px solid #dc3545';

    messageBox.style.color = type === 'success' ? '#1e7e34' : '#721c24';

  }



  function clearMessage() {

    messageBox.style.display = 'none';

    messageBox.textContent = '';

  }



  // Utility: now + 6h UTC (matches booking form)

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



  // Ensure end >= start + 1h (UTC, across days)

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

    } else {

      const firstReal = validOptions.find(o => o.value);

      endTimeSelect.value = firstReal ? firstReal.value : '';

    }

  }



  // Enforce now+6h rule on submit (UTC-safe)

  function validateStartTime() {

    if (!startDateInput.value || !startTimeSelect.value) return true;



    const [h, m] = startTimeSelect.value.split(':').map(Number);

    const startCandidate = new Date(startDateInput.value + 'T00:00:00Z');

    startCandidate.setUTCHours(h, m, 0, 0);



    const minStart = getNowPlus6hUTC();



    if (startCandidate < minStart) {

      showMessage('Start time must be at least 6 hours from now (Zulu).', 'error');

      return false;

    }

    return true;

  }



  // Modal open handler (called from Edit link)

  window.openEditModal = function(el) {

    clearMessage();

    modal.style.display = 'flex';



    document.getElementById('edit_booking_id').value = el.dataset.id;



    const callsign = el.dataset.callsign || '';

    document.getElementById('edit_callsign_readonly').value = callsign;

    document.getElementById('edit_callsign_hidden').value = callsign;



    // Split start/end into date/time parts (expects: YYYY-MM-DD HH:MM:SS)

    const startParts = (el.dataset.start || '').split(' ');

    const endParts   = (el.dataset.end   || '').split(' ');



    document.getElementById('edit_start_date').value = startParts[0] || '';

    document.getElementById('edit_start_time').value = (startParts[1] || '').substring(0,5);



    document.getElementById('edit_end_date').value   = endParts[0] || '';

    document.getElementById('edit_end_time').value   = (endParts[1] || '').substring(0,5);



    const summary = `${callsign} — ${el.dataset.start} to ${el.dataset.end}`;

    document.getElementById('edit_context_summary').textContent = summary;



    // Only enforce end constraints

    document.getElementById('edit_end_date').min = document.getElementById('edit_start_date').value || '';

    enforceMinEnd();

  };



  // Close/cancel

  closeBtn.onclick = () => { modal.style.display = 'none'; };

  cancelBtn.onclick = () => { modal.style.display = 'none'; };



  // Events

  startDateInput.addEventListener('change', () => {

    endDateInput.min = startDateInput.value;

    enforceMinEnd();

  });

  startTimeSelect.addEventListener('change', enforceMinEnd);

  endDateInput.addEventListener('change', enforceMinEnd);

  endTimeSelect.addEventListener('focus', enforceMinEnd);



  // AJAX submission (JSON-first, text fallback, reload-after-success)

  form.addEventListener('submit', function(e) {

    e.preventDefault();

    clearMessage();



    if (!validateStartTime()) return;



    // Disable save to prevent double submits

    saveBtn.disabled = true;

    saveBtn.textContent = 'Saving...';



    const formData = new FormData(this);

    const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';



    fetch(ajaxUrl, { method: 'POST', body: formData })

      .then(async (res) => {

        // Try JSON first; if it fails, fallback to text

        try {

          const data = await res.json();

          return { ok: res.ok, data };

        } catch {

          const text = await res.text();

          return { ok: res.ok, data: { success: false, data: text || 'Unexpected response' } };

        }

      })

      .then(({ ok, data }) => {

        console.log('Edit booking AJAX response:', data);

        if (ok && data && data.success) {

          showMessage('Booking updated successfully.', 'success');

          // Prompt to close or auto-close after a short delay

          setTimeout(() => {

            modal.style.display = 'none';

            location.reload();

          }, 1200);

        } else {

          showMessage('Error: ' + (data && data.data ? data.data : 'Unknown error'), 'error');

        }

      })

      .catch(err => {

        console.error('Edit booking AJAX error:', err);

        showMessage('Unexpected error while saving changes.', 'error');

      })

      .finally(() => {

        saveBtn.disabled = false;

        saveBtn.textContent = 'Save Changes';

      });

  });

})();

</script>

