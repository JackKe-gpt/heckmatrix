<?php
include '../includes/db.php';
include 'header.php';

$success = '';
$error = '';

define('REG_FEE', 300);
define('PROCESSING_FEE', 500);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_number'])) {
  $id_number = mysqli_real_escape_string($conn, $_POST['id_number']);
  $amount = floatval($_POST['amount']);
  $purpose = $_POST['purpose'];
  $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

  $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE national_id = '$id_number' LIMIT 1"));

  if (!$customer) {
    $error = "Customer not found.";
  } else {
    $customer_id = $customer['id'];
    $status = $customer['status'];

    if ($purpose === 'registration') {
      if ($status !== 'Pending') {
        $error = "Registration fee already paid.";
      } elseif ($amount != REG_FEE) {
        $error = "Registration fee must be exactly KES " . REG_FEE;
      } else {
        mysqli_query($conn, "INSERT INTO payments (customer_id, amount, purpose, payment_date) 
          VALUES ('$customer_id', '$amount', 'registration', '$payment_date')");
        mysqli_query($conn, "UPDATE customers SET status = 'Inactive' WHERE id = '$customer_id'");
        $success = "Registration fee paid. Customer is now Inactive.";
      }
    } elseif ($purpose === 'processing') {
      if ($status !== 'Inactive') {
        $error = "Processing fee is only for Inactive customers.";
      } elseif ($amount != PROCESSING_FEE) {
        $error = "Processing fee must be exactly KES " . PROCESSING_FEE;
      } else {
        mysqli_query($conn, "INSERT INTO payments (customer_id, amount, purpose, payment_date) 
          VALUES ('$customer_id', '$amount', 'processing', '$payment_date')");
        mysqli_query($conn, "UPDATE customers SET status = 'Active' WHERE id = '$customer_id'");
        $success = "Processing fee paid. Customer is now Active.";
      }
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Account Fee Payment – Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100 font-sans p-6">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <h2 class="text-xl font-bold text-emerald-600 mb-4">Pay Account Fee</h2>

    <?php if ($success): ?>
      <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4"><?= $success ?></div>
    <?php elseif ($error): ?>
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <!-- ID / Name with Autocomplete -->
      <div class="relative">
        <label class="block text-sm font-semibold mb-1 text-emerald-700">ID or Name</label>
        <input type="text" name="id_number" id="id_number" required autocomplete="off" class="w-full border px-3 py-2 rounded">
        <ul id="suggestions" class="absolute w-full z-10 border bg-white mt-1 max-h-40 overflow-y-auto hidden shadow text-sm"></ul>
      </div>

      <!-- Purpose -->
      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Payment Purpose</label>
        <select name="purpose" id="purpose" required class="w-full border px-3 py-2 rounded">
          <option value="">-- Select --</option>
          <option value="registration">Registration Fee</option>
          <option value="processing">Processing Fee</option>
        </select>
      </div>

      <!-- Amount Auto-filled -->
      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Amount (KES)</label>
        <input type="number" name="amount" id="amount" required readonly class="w-full border px-3 py-2 rounded bg-gray-100">
      </div>

      <!-- Date -->
      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Payment Date</label>
        <input type="date" name="payment_date" class="w-full border px-3 py-2 rounded" value="<?= date('Y-m-d') ?>">
      </div>

      <!-- Info Message -->
      <div id="statusMessage" class="hidden text-red-600 text-sm font-medium"></div>

      <!-- Submit -->
      <button type="submit" id="submitBtn" class="bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700">Submit Payment</button>
    </form>
  </div>

<script>
  const REG_FEE = <?= REG_FEE ?>;
  const PROC_FEE = <?= PROCESSING_FEE ?>;

  $('#id_number').on('input', function () {
    let query = $(this).val();
    if (query.length < 2) {
      $('#suggestions').hide().empty();
      resetForm();
      return;
    }

    $.post('account_search.php', { query }, function (data) {
      if (data.length > 0) {
        $('#suggestions').show().html(data.map(item =>
          `<li class="px-3 py-2 cursor-pointer hover:bg-emerald-100" 
              data-id="${item.national_id}" 
              data-status="${item.status}">
              ${item.full_name} (${item.national_id})
          </li>`
        ).join(''));
      } else {
        $('#suggestions').hide().empty();
        resetForm();
      }
    }, 'json');
  });

  $(document).on('click', '#suggestions li', function () {
    const national_id = $(this).data('id');
    const status = $(this).data('status');

    $('#id_number').val(national_id);
    $('#suggestions').hide();

    if (status === 'Pending') {
      $('#purpose').val('registration');
      $('#amount').val(REG_FEE);
      $('#statusMessage').hide();
      $('#submitBtn').prop('disabled', false);
    } else if (status === 'Inactive') {
      $('#purpose').val('processing');
      $('#amount').val(PROC_FEE);
      $('#statusMessage').hide();
      $('#submitBtn').prop('disabled', false);
    } else {
      $('#purpose').val('');
      $('#amount').val('');
      $('#statusMessage').text("No fee required for this customer.").show();
      $('#submitBtn').prop('disabled', true);
    }
  });

  function resetForm() {
    $('#purpose').val('');
    $('#amount').val('');
    $('#statusMessage').hide();
    $('#submitBtn').prop('disabled', false);
  }

  $(document).click(function (e) {
    if (!$(e.target).closest('#id_number, #suggestions').length) {
      $('#suggestions').hide();
    }
  });
</script>
</body>
</html>
