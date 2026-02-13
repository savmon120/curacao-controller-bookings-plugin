<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div id="deleteBookingModal" class="modal" style="display:none;">

  <div class="modal-content">

    <span class="close">&times;</span>

    <h2>Delete Booking</h2>



    <div id="deleteBookingMessage" style="display:none; margin:0 0 12px 0; padding:10px; border-radius:6px;"></div>



    <p id="delete_context_summary"></p>



    <form method="post" id="deleteBookingForm">

      <?php wp_nonce_field('vatcar_delete_booking', 'vatcar_delete_nonce'); ?>



      <input type="hidden" name="action" value="delete_booking">

      <input type="hidden" name="booking_id" id="delete_booking_id">



      <div class="form-row">

        <button type="submit" class="btn btn-danger" id="deleteConfirmBtn">Confirm Delete</button>

        <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>

      </div>

    </form>

  </div>

</div>



<script>

function openDeleteModal(el) {

  const modal = document.getElementById('deleteBookingModal');

  modal.style.display = 'flex';



  document.getElementById('delete_booking_id').value = el.dataset.id;

  document.getElementById('delete_context_summary').textContent =

    `Delete booking: ${el.dataset.callsign} — ${el.dataset.start} to ${el.dataset.end}?`;



  clearDeleteMessage();
  
  // Refresh nonce when modal opens to prevent stale nonce issues
  refreshDeleteNonce();
}

/**
 * Refresh the delete nonce to prevent stale nonce issues
 * This is especially important for long-lived page sessions
 */
function refreshDeleteNonce() {
  const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
  
  fetch(ajaxUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'action=refresh_delete_nonce',
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      const nonceField = document.getElementById('deleteBookingForm').querySelector('[name="vatcar_delete_nonce"]');
      if (nonceField) {
        const oldNonce = nonceField.value;
        nonceField.value = data.data.nonce;
        console.log('Delete nonce refreshed:', {
          old: oldNonce.substring(0, 10) + '...',
          new: data.data.nonce.substring(0, 10) + '...',
          user_id: data.data.user_id
        });
      }
    } else {
      console.error('Failed to refresh delete nonce:', data.data);
    }
  })
  .catch(err => {
    console.error('Error refreshing delete nonce:', err);
  });
}



function clearDeleteMessage() {

  const box = document.getElementById('deleteBookingMessage');

  box.style.display = 'none';

  box.textContent = '';

}



function showDeleteMessage(text, type) {

  const box = document.getElementById('deleteBookingMessage');

  box.textContent = text;

  box.style.display = 'block';

  box.style.background = type === 'success' ? '#ffecec' : '#ffecec';

  box.style.border = type === 'success' ? '1px solid #28a745' : '1px solid #dc3545';

  box.style.color = type === 'success' ? '#1e7e34' : '#721c24';

}



document.querySelector('#deleteBookingModal .close').onclick = () => {

  document.getElementById('deleteBookingModal').style.display = 'none';

};

document.getElementById('cancelDelete').onclick = () => {

  document.getElementById('deleteBookingModal').style.display = 'none';

};



document.getElementById('deleteBookingForm').addEventListener('submit', function(e) {

  e.preventDefault();

  clearDeleteMessage();

  
  // Prevent double submission
  const submitBtn = document.getElementById('deleteConfirmBtn');
  if (submitBtn.disabled) return;
  submitBtn.disabled = true;
  submitBtn.textContent = 'Deleting...';



  const formData = new FormData(this);

  const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';

  
  // Debug: Log comprehensive request details
  console.log('=== DELETE BOOKING REQUEST DEBUG ===');
  console.log('AJAX URL:', ajaxUrl);
  console.log('Current page URL:', window.location.href);
  console.log('Referrer:', document.referrer);
  console.log('User Agent:', navigator.userAgent);
  console.log('Cookies present:', document.cookie ? 'YES' : 'NO');
  console.log('Form data entries:');
  for (let [key, value] of formData.entries()) {
    console.log('  ' + key + ':', value);
  }
  console.log('=== END DEBUG ===');

  fetch(ajaxUrl, { method: 'POST', body: formData })

    .then(res => res.json())

    .then(data => {

      if (data.success) {

        showDeleteMessage('Booking deleted successfully.', 'success');

        // Remove row from table

        const bookingId = formData.get('booking_id');

        const row = document.querySelector(`tr[data-id="${bookingId}"]`);

        if (row) {

          row.remove();

          // Dispatch event for dashboard to update

          window.dispatchEvent(new CustomEvent('bookingDeleted', { detail: { bookingId } }));

        }

        setTimeout(() => {

          document.getElementById('deleteBookingModal').style.display = 'none';
          submitBtn.disabled = false;
          submitBtn.textContent = 'Confirm Delete';

        }, 1200);

      } else {

        showDeleteMessage('Error: ' + data.data, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Confirm Delete';

      }

    })

    .catch(err => {

      console.error('Delete booking AJAX error:', err);

      showDeleteMessage('Unexpected error while deleting booking.', 'error');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Delete';

    });

});

</script>
