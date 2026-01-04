<?php
include 'includes/db.php';
session_start();

$customer = null;
$loan = null;
$remaining_balance = null;
$success = '';
$overpayment_used = 0;
$retained_overpayment = 0;

function fetch_value($query, $key) {
  global $conn;
  $result = mysqli_fetch_assoc(mysqli_query($conn, $query));
  return $result[$key] ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_number'])) {
  $id_or_name = mysqli_real_escape_string($conn, $_POST['id_number']);
  $amount = floatval($_POST['principal_amount']);
  $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

  $customer_query = mysqli_query($conn, "
    SELECT * FROM customers 
    WHERE national_id = '$id_or_name' 
       OR CONCAT(first_name, ' ', middle_name, ' ', surname) LIKE '%$id_or_name%' 
    LIMIT 1
  ");
  $customer = mysqli_fetch_assoc($customer_query);

  if ($customer) {
    $customer_id = $customer['id'];
    $existing_balance = floatval($customer['customer_account_balance']);

    // ✅ Changed status from 'Disbursed' → 'Active'
    $loan_query = mysqli_query($conn, "
      SELECT * FROM loans 
      WHERE customer_id = '$customer_id' AND status = 'Active' 
      ORDER BY disbursed_date DESC 
      LIMIT 1
    ");
    $loan = mysqli_fetch_assoc($loan_query);

    if ($loan) {
      $loan_id = $loan['id'];
      $total_repayable = floatval($loan['total_repayable']);
      $total_paid = fetch_value("SELECT SUM(principal_amount) AS total_paid FROM loan_payments WHERE loan_id = '$loan_id'", 'total_paid');
      $remaining = $total_repayable - $total_paid;

      $available_payment = $existing_balance + $amount;
      $applied_to_loan = min($available_payment, $remaining);
      $overpayment_used = min($existing_balance, $applied_to_loan);
      $retained_overpayment = $available_payment - $applied_to_loan;

      if ($applied_to_loan > 0) {
        mysqli_query($conn, "INSERT INTO loan_payments 
          (loan_id, customer_id, id_number, principal_amount, payment_date) 
          VALUES ('$loan_id', '$customer_id', '{$customer['national_id']}', '$applied_to_loan', '$payment_date')");
      }

      // Update new overpayment balance
      mysqli_query($conn, "UPDATE customers SET customer_account_balance = $retained_overpayment WHERE id = '$customer_id'");

      $new_paid = fetch_value("SELECT SUM(principal_amount) AS total_paid FROM loan_payments WHERE loan_id = '$loan_id'", 'total_paid');
      if (round($new_paid, 2) >= round($total_repayable, 2)) {
        mysqli_query($conn, "UPDATE loans SET status = 'Inactive' WHERE id = '$loan_id'");
      }

      $unpaid = fetch_value("
        SELECT SUM(total_repayable - IFNULL(p.total_paid, 0)) AS remaining
        FROM loans
        LEFT JOIN (
          SELECT loan_id, SUM(principal_amount) AS total_paid
          FROM loan_payments
          GROUP BY loan_id
        ) p ON loans.id = p.loan_id
        WHERE loans.customer_id = '$customer_id' AND loans.status = 'Active'
      ", 'remaining');

      if (round($unpaid, 2) <= 0) {
        mysqli_query($conn, "UPDATE customers SET status = 'Inactive' WHERE id = '$customer_id'");
      }

      $_SESSION['success'] = "Payment of KES " . number_format($amount, 2) . " received!";
      $_SESSION['overpayment_used'] = $overpayment_used;
      $_SESSION['retained_overpayment'] = $retained_overpayment;
      $_SESSION['id_number'] = $customer['national_id'];
      header("Location: " . $_SERVER['PHP_SELF']);
      exit;
    }
  }
}

// After redirect
if (isset($_SESSION['success'])) {
  $success = $_SESSION['success'];
  $overpayment_used = $_SESSION['overpayment_used'] ?? 0;
  $retained_overpayment = $_SESSION['retained_overpayment'] ?? 0;
  $id_number = $_SESSION['id_number'];
  unset($_SESSION['success'], $_SESSION['id_number'], $_SESSION['overpayment_used'], $_SESSION['retained_overpayment']);

  $customer_query = mysqli_query($conn, "SELECT * FROM customers WHERE national_id = '$id_number' LIMIT 1");
  $customer = mysqli_fetch_assoc($customer_query);

  if ($customer) {
    $customer_id = $customer['id'];
    // ✅ Changed status from 'Disbursed' → 'Active'
    $loan_query = mysqli_query($conn, "
      SELECT * FROM loans 
      WHERE customer_id = '$customer_id' AND status = 'Active' 
      ORDER BY disbursed_date DESC 
      LIMIT 1
    ");
    $loan = mysqli_fetch_assoc($loan_query);

    if ($loan) {
      $loan_id = $loan['id'];
      $total_repayable = floatval($loan['total_repayable']);
      $total_paid = fetch_value("SELECT SUM(principal_amount) AS total_paid FROM loan_payments WHERE loan_id = '$loan_id'", 'total_paid');
      $remaining_balance = $total_repayable - $total_paid;
    }
  }
}

include 'header.php';
?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Loan Payment - Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    function suggest(input) {
      if (input.length < 2) {
        document.getElementById("suggestions").innerHTML = '';
        return;
      }

      fetch("search_customer.php?query=" + encodeURIComponent(input))
        .then(res => res.json())
        .then(data => {
          let list = '';
          data.forEach(item => {
            list += `<div class="cursor-pointer px-3 py-1 hover:bg-gray-200" onclick="selectSuggestion('${item.national_id}')">${item.full_name} (${item.national_id})</div>`;
          });
          document.getElementById("suggestions").innerHTML = list;
        });
    }

    function selectSuggestion(id) {
      document.getElementById("id_number").value = id;
      document.getElementById("suggestions").innerHTML = '';
    }
  </script>
</head>
<body class="bg-gray-100 font-sans p-6">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <h2 class="text-xl font-bold text-emerald-600 mb-4">Make Loan Payment</h2>

<div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
  <?= $success ?>
  <?php if ($overpayment_used > 0): ?>
    <br><span class="text-sm text-emerald-700">KES <?= number_format($overpayment_used, 2) ?> was used from overpayment balance.</span>
  <?php endif; ?>
  <?php if ($retained_overpayment > 0): ?>
    <br><span class="text-sm text-emerald-700">KES <?= number_format($retained_overpayment, 2) ?> retained as new overpayment.</span>
  <?php endif; ?>
</div>


    <form method="POST" class="space-y-4 relative">
      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">ID or Name</label>
        <input type="text" name="id_number" id="id_number" autocomplete="off" oninput="suggest(this.value)" required class="w-full border px-3 py-2 rounded" value="<?= htmlspecialchars($_POST['id_number'] ?? '') ?>">
        <div id="suggestions" class="absolute bg-white border rounded w-full z-10 mt-1 max-h-40 overflow-y-auto"></div>
      </div>

      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Amount to Pay (KES)</label>
        <input type="number" name="principal_amount" required min="1" step="0.01" class="w-full border px-3 py-2 rounded">
      </div>

      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Payment Date</label>
        <input type="date" name="payment_date" class="w-full border px-3 py-2 rounded" value="<?= date('Y-m-d') ?>">
      </div>

      <div>
        <label class="block text-sm font-semibold mb-1 text-emerald-700">Payment Method</label>
        <input type="text" class="w-full border px-3 py-2 rounded bg-gray-100 text-gray-600" value="Mpesa" readonly>
      </div>

      <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700">Submit Payment</button>
    </form>

<?php if ($loan): ?>
  <div class="mt-10 border border-emerald-200 bg-white shadow-sm rounded-lg p-6">
    <h3 class="text-lg font-semibold text-emerald-700 mb-4">Loan Summary</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm text-gray-800">
      <div><span class="font-medium text-gray-600">Loan Code:</span><br><?= $loan['loan_code'] ?></div>
      <div><span class="font-medium text-gray-600">Status:</span><br><?= $loan['status'] ?></div>
      <div><span class="font-medium text-gray-600">Total Repayable:</span><br>KES <?= number_format($loan['total_repayable'], 2) ?></div>
      <div><span class="font-medium text-gray-600">Total Paid:</span><br>KES <?= number_format($total_paid ?? 0, 2) ?></div>
      <div><span class="font-medium text-gray-600">Remaining Balance:</span><br>KES <?= number_format($remaining_balance ?? 0, 2) ?></div>
      <div><span class="font-medium text-gray-600">Account Balance (Overpayment):</span><br>KES <?= number_format($customer['balance'], 2) ?></div>
    </div>
  </div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <div class="mt-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded">
    <strong>No active disbursed loan found for this ID or name.</strong>
  </div>
<?php endif; ?>

  </div>
</body>
</html>
