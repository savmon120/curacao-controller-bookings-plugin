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



  const formData = new FormData(this);

  const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';



  fetch(ajaxUrl, { method: 'POST', body: formData })

    .then(res => res.json())

    .then(data => {

      if (data.success) {

        showDeleteMessage('Booking deleted successfully.', 'success');

        // Remove row from table

        const row = document.querySelector(`tr[data-id="${formData.get('booking_id')}"]`);

        if (row) row.remove();

        setTimeout(() => {

          document.getElementById('deleteBookingModal').style.display = 'none';

        }, 1200);

      } else {

        showDeleteMessage('Error: ' + data.data, 'error');

      }

    })

    .catch(err => {

      console.error('Delete booking AJAX error:', err);

      showDeleteMessage('Unexpected error while deleting booking.', 'error');

    });

});

</script>
