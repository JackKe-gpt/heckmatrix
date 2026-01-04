<?php
include 'includes/db.php';
include 'header.php';

$customer = null;
$loans = [];
$payments = [];
$total_disbursed = $total_repayable = $total_paid = $balance = $overpayment = 0;
$reg_fee_paid = $processing_fee_paid = 0;

if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
  $id = intval($_GET['customer_id']);
  $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE id = $id"));

  if ($customer) {
    $loans = mysqli_query($conn, "SELECT * FROM loans WHERE customer_id = $id");

    // Payment Summary
    $total_disbursed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans WHERE customer_id = $id"))['total'];
    $total_repayable = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(total_repayable), 0) AS total FROM loans WHERE customer_id = $id"))['total'];
    $total_paid = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loan_payments WHERE customer_id = $id"))['total'];
    $balance = $total_repayable - $total_paid;
    $overpayment = $balance < 0 ? abs($balance) : 0;

    // Registration & Processing Fees
    $reg_fee_paid = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM payments WHERE customer_id = $id AND purpose = 'registration'")) > 0;
    $processing_fee_paid = 0;
    if ($customer['status'] === 'Active') {
      $processing_fee_paid = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM payments WHERE customer_id = $id AND purpose = 'processing'")) > 0;
    }

    // Pagination for payments
    $limit = 10;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $limit;

    $total_payment_records = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM loan_payments WHERE customer_id = $id"))['count'];
    $total_pages = ceil($total_payment_records / $limit);

    $payments = mysqli_query($conn, "
      SELECT * FROM loan_payments 
      WHERE customer_id = $id 
      ORDER BY payment_date DESC 
      LIMIT $limit OFFSET $offset
    ");
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Customer Summary</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --primary-color: #15a362;
    }
    .text-primary { color: var(--primary-color); }
    .bg-primary { background-color: var(--primary-color); }
    .hover\:bg-primary-dark:hover { background-color: #128054; }
    .border-primary { border-color: var(--primary-color); }
  </style>
</head>
<body class="bg-gray-100 min-h-screen p-6 font-sans">

<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
  <h1 class="text-2xl md:text-3xl font-bold mb-6 text-primary">Customer Account Statement</h1>

  <!-- Search Form -->
  <form method="GET" action="summary.php" class="relative mb-8">
    <label for="customerSearch" class="block mb-2 text-sm font-medium text-gray-700">Search by Name, Phone, or National ID</label>
    <input type="text" id="customerSearch" placeholder="e.g. Jane Wanjiku, 12345678" class="border rounded w-full px-4 py-2 shadow-sm focus:ring-2 focus:ring-primary focus:outline-none" autocomplete="off">
    <ul id="suggestions" class="absolute bg-white border rounded w-full shadow z-10 hidden max-h-48 overflow-y-auto mt-1"></ul>
    <input type="hidden" name="customer_id" id="customer_id">
  </form>

  <?php if ($customer): ?>
    <!-- Customer Info -->
    <div class="mb-8">
      <h2 class="text-xl font-semibold text-gray-700 mb-2">Customer Information</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-4 rounded border">
        <div><span class="font-semibold">Name:</span> <?= htmlspecialchars("{$customer['first_name']} {$customer['middle_name']} {$customer['surname']}") ?></div>
        <div><span class="font-semibold">National ID:</span> <?= htmlspecialchars($customer['national_id']) ?></div>
        <div><span class="font-semibold">Phone:</span> <?= htmlspecialchars($customer['phone_number']) ?></div>
        <div><span class="font-semibold">Status:</span> <?= htmlspecialchars($customer['status']) ?></div>
      </div>
    </div>

    <!-- Financial Summary -->
    <div class="mb-10">
      <h2 class="text-xl font-semibold text-gray-700 mb-2">Account Summary</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-gray-50 p-4 rounded border text-sm">
        <div><strong>Total Disbursed:</strong><br><span class="text-primary font-semibold">KES <?= number_format($total_disbursed) ?></span></div>
        <div><strong>Total Repayable:</strong><br><span class="text-primary font-semibold">KES <?= number_format($total_repayable) ?></span></div>
        <div><strong>Total Paid:</strong><br><span class="text-primary font-semibold">KES <?= number_format($total_paid) ?></span></div>
        <div><strong>Outstanding:</strong><br><span class="text-red-600 font-semibold">KES <?= number_format(max(0, $balance)) ?></span></div>
        <?php if ($overpayment > 0): ?>
          <div class="col-span-2 text-green-600 font-semibold bg-green-50 p-2 rounded">Overpayment: KES <?= number_format($overpayment) ?></div>
        <?php endif; ?>
        <div><strong>Registration Fee:</strong><br><?= $reg_fee_paid ? "<span class='text-green-600 font-medium'>Paid</span>" : "<span class='text-red-600 font-medium'>Unpaid</span>" ?></div>
        <div><strong>Processing Fee:</strong><br><?= $processing_fee_paid ? "<span class='text-green-600 font-medium'>Paid</span>" : "<span class='text-red-600 font-medium'>Unpaid</span>" ?></div>
      </div>
    </div>

    <!-- Loan History -->
    <div class="mb-8">
      <h2 class="text-lg font-semibold text-gray-700 mb-2">Loan History</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm border border-gray-200 rounded">
          <thead class="bg-gray-100 text-left">
            <tr>
              <th class="p-2 border">#</th>
              <th class="p-2 border">Amount</th>
              <th class="p-2 border">Repayable</th>
              <th class="p-2 border">Disbursed</th>
              <th class="p-2 border">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php $n = 1; while ($loan = mysqli_fetch_assoc($loans)): ?>
              <tr class="hover:bg-gray-50">
                <td class="p-2 border"><?= $n++ ?></td>
                <td class="p-2 border">KES <?= number_format($loan['principal_amount']) ?></td>
                <td class="p-2 border">KES <?= number_format($loan['total_repayable']) ?></td>
                <td class="p-2 border"><?= htmlspecialchars($loan['disbursed_date']) ?></td>
                <td class="p-2 border"><?= htmlspecialchars($loan['status']) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Payment History -->
    <div>
      <h2 class="text-lg font-semibold text-gray-700 mb-2">Payments</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm border border-gray-200 rounded">
          <thead class="bg-gray-100 text-left">
            <tr>
              <th class="p-2 border">#</th>
              <th class="p-2 border">Amount</th>
              <th class="p-2 border">Date</th>
              <th class="p-2 border">Method</th>
            </tr>
          </thead>
          <tbody>
            <?php $m = 1; while ($pay = mysqli_fetch_assoc($payments)): ?>
              <tr class="hover:bg-gray-50">
                <td class="p-2 border"><?= $m++ ?></td>
                <td class="p-2 border">KES <?= number_format($pay['principal_amount']) ?></td>
                <td class="p-2 border"><?= htmlspecialchars($pay['payment_date']) ?></td>
                <td class="p-2 border"><?= htmlspecialchars($pay['method'] ?? 'Mpesa') ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="mt-4 flex justify-center items-center space-x-4">
          <?php if ($page > 1): ?>
            <a href="?customer_id=<?= $id ?>&page=<?= $page - 1 ?>" class="px-3 py-1 border rounded bg-gray-50 hover:bg-gray-100 text-sm">Previous</a>
          <?php endif; ?>
          <span class="text-sm text-gray-600">Page <?= $page ?> of <?= $total_pages ?></span>
          <?php if ($page < $total_pages): ?>
            <a href="?customer_id=<?= $id ?>&page=<?= $page + 1 ?>" class="px-3 py-1 border rounded bg-gray-50 hover:bg-gray-100 text-sm">Next</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif (isset($_GET['customer_id'])): ?>
    <div class="text-red-500 font-semibold mt-4">Customer not found.</div>
  <?php endif; ?>
</div>

<!-- Autocomplete Script -->
<script>
  const input = document.getElementById('customerSearch');
  const suggestions = document.getElementById('suggestions');
  const hiddenInput = document.getElementById('customer_id');

  input.addEventListener('input', () => {
    const query = input.value.trim();
    if (query.length < 2) {
      suggestions.classList.add('hidden');
      return;
    }

    fetch('customer_statement.php?query=' + encodeURIComponent(query))
      .then(res => res.json())
      .then(data => {
        suggestions.innerHTML = '';
        if (data.length === 0) {
          suggestions.classList.add('hidden');
          return;
        }

        data.forEach(item => {
          const li = document.createElement('li');
          li.textContent = `${item.full_name} (${item.national_id})`;
          li.className = 'px-3 py-2 hover:bg-primary/10 cursor-pointer';
          li.onclick = () => {
            input.value = item.full_name;
            hiddenInput.value = item.id;
            input.form.submit();
            suggestions.classList.add('hidden');
          };
          suggestions.appendChild(li);
        });

        suggestions.classList.remove('hidden');
      });
  });
</script>
</body>
</html>
