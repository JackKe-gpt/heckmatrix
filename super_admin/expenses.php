<?php
require_once 'auth.php';
require_login();
include 'includes/db.php';
include 'header.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
  $amount = floatval($_POST['amount']);
  $description = trim($_POST['description']);
  $category = trim($_POST['category']);
  $payment_method = trim($_POST['payment_method']);
  $date = $_POST['expense_date'] ?? date('Y-m-d');

  if ($amount <= 0 || !$category || !$description || !$payment_method) {
    $error = "Please fill all required fields.";
  } else {
    $stmt = $conn->prepare("INSERT INTO expenses (description, amount, category, payment_method, expense_date) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sdsss", $description, $amount, $category, $payment_method, $date);
    if ($stmt->execute()) {
      $success = "Expense recorded successfully.";
    } else {
      $error = "Error saving expense.";
    }
    $stmt->close();
  }
}

// Filter
$filter = "";
if (!empty($_GET['start']) && !empty($_GET['end'])) {
  $start = $_GET['start'];
  $end = $_GET['end'];
  $filter = "WHERE expense_date BETWEEN '$start' AND '$end'";
}

function get_total($conn, $query) {
  $result = mysqli_query($conn, $query);
  $row = mysqli_fetch_assoc($result);
  return $row ? (float)$row['total'] : 0;
}

// Totals
$total_expense = get_total($conn, "SELECT IFNULL(SUM(amount), 0) AS total FROM expenses $filter");
$total_repayable = get_total($conn, "SELECT IFNULL(SUM(total_repayable), 0) AS total FROM loans");
$total_disbursed = get_total($conn, "SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans");
$total_payments = get_total($conn, "SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loan_payments");
$processing = get_total($conn, "SELECT IFNULL(SUM(amount), 0) AS total FROM payments WHERE LOWER(purpose) = 'processing'");
$registration = get_total($conn, "SELECT IFNULL(SUM(amount), 0) AS total FROM payments WHERE LOWER(purpose) = 'registration'");

$gross_profit = ($total_repayable - $total_disbursed) + $processing + $registration;
$net_profit = $gross_profit - $total_expense;
$balance = $total_payments + $processing + $registration - $total_disbursed - $total_expense;

$expenses = mysqli_query($conn, "SELECT * FROM expenses $filter ORDER BY expense_date DESC");

$chart_data = mysqli_query($conn, "
  SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month, SUM(amount) AS total
  FROM expenses
  $filter
  GROUP BY month ORDER BY month ASC
");

$labels = [];
$totals = [];
while ($row = mysqli_fetch_assoc($chart_data)) {
  $labels[] = $row['month'];
  $totals[] = $row['total'];
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Expenses – Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-gray-100 p-6 font-sans">
<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md" id="reportArea">
  <div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold text-emerald-600">Expenses</h2>
    <button onclick="exportToPDF()" class="bg-emerald-600 text-white px-4 py-2 rounded">Export to PDF</button>
  </div>

  <!-- Add Expense Form -->
  <form method="POST" class="mb-6 grid grid-cols-1 md:grid-cols-5 gap-4 text-sm">
    <input type="text" name="description" placeholder="Description" class="border rounded px-3 py-2" required>
    <input type="number" step="0.01" name="amount" placeholder="Amount (KES)" class="border rounded px-3 py-2" required>
    <input type="text" name="category" placeholder="Category" class="border rounded px-3 py-2" required>
    <input type="text" name="payment_method" placeholder="Paid From (e.g. Bank, Cash)" class="border rounded px-3 py-2" required>
    <input type="date" name="expense_date" class="border rounded px-3 py-2" value="<?= date('Y-m-d') ?>" required>
    <button type="submit" class="bg-emerald-600 text-white rounded px-4 py-2 col-span-full md:col-span-1">Add</button>
  </form>

  <!-- Alerts -->
  <?php if ($success): ?><div class="bg-green-100 text-green-800 p-2 mb-4 rounded"><?= $success ?></div><?php endif; ?>
  <?php if ($error): ?><div class="bg-red-100 text-red-800 p-2 mb-4 rounded"><?= $error ?></div><?php endif; ?>

  <!-- Filter -->
  <form method="GET" class="mb-6 flex flex-wrap gap-4 text-sm">
    <div>
      <label class="block mb-1">Start Date</label>
      <input type="date" name="start" value="<?= $_GET['start'] ?? '' ?>" class="border rounded px-2 py-1">
    </div>
    <div>
      <label class="block mb-1">End Date</label>
      <input type="date" name="end" value="<?= $_GET['end'] ?? '' ?>" class="border rounded px-2 py-1">
    </div>
    <div class="flex items-end">
      <button type="submit" class="bg-gray-800 text-white px-3 py-1 rounded">Filter</button>
    </div>
  </form>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-sm">
    <div class="bg-yellow-100 p-4 rounded shadow">
      <p class="text-gray-700">Total Expenses</p>
      <p class="text-xl font-bold text-yellow-800">KES <?= number_format($total_expense, 2) ?></p>
    </div>
    <div class="bg-emerald-100 p-4 rounded shadow">
      <p class="text-gray-700">Net Profit (after Expenses)</p>
      <p class="text-xl font-bold text-emerald-800">KES <?= number_format($net_profit, 2) ?></p>
    </div>
    <div class="bg-blue-100 p-4 rounded shadow">
      <p class="text-gray-700">Account Balance</p>
      <p class="text-xl font-bold text-blue-800">KES <?= number_format($balance, 2) ?></p>
    </div>
  </div>

  <!-- Chart -->
  <div class="mb-6">
    <canvas id="expenseChart" height="100" class="w-full"></canvas>
  </div>

  <!-- Table -->
  <table class="w-full text-sm border border-collapse">
    <thead class="bg-emerald-100">
      <tr>
        <th class="px-3 py-2 border">Date</th>
        <th class="px-3 py-2 border">Description</th>
        <th class="px-3 py-2 border">Category</th>
        <th class="px-3 py-2 border">Paid From</th>
        <th class="px-3 py-2 border">Amount (KES)</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($exp = mysqli_fetch_assoc($expenses)): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-3 py-2 border"><?= $exp['expense_date'] ?></td>
          <td class="px-3 py-2 border"><?= htmlspecialchars($exp['description']) ?></td>
          <td class="px-3 py-2 border"><?= htmlspecialchars($exp['category']) ?></td>
          <td class="px-3 py-2 border"><?= htmlspecialchars($exp['payment_method']) ?></td>
          <td class="px-3 py-2 border text-right"><?= number_format($exp['amount'], 2) ?></td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<script>
  new Chart(document.getElementById('expenseChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: [{
        label: 'Monthly Expenses (KES)',
        data: <?= json_encode($totals) ?>,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.2)',
        fill: true,
        tension: 0.4
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true } }
    }
  });

  function exportToPDF() {
    html2pdf().from(document.getElementById('reportArea')).set({
      margin: 0.5,
      filename: 'expenses_report.pdf',
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2 },
      jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    }).save();
  }
</script>
</body>
</html>
