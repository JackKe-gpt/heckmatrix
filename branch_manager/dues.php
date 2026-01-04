<?php
session_start();
include '../includes/db.php';

// Only branch managers can view
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];

// Filter options
$filter = $_GET['filter'] ?? '';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$yesterday = date('Y-m-d', strtotime('-1 day'));

if ($filter === 'today') {
    $filter_date = $today;
} elseif ($filter === 'tomorrow') {
    $filter_date = $tomorrow;
} elseif ($filter === 'yesterday') {
    $filter_date = $yesterday;
} elseif ($filter === 'all') {
    $filter_date = ''; // special case: show all
}

// Fetch loans disbursed under this branch
$query = mysqli_query($conn, "
  SELECT 
    l.*, 
    c.first_name, c.middle_name, c.surname,
    (
      SELECT IFNULL(SUM(principal_amount), 0) 
      FROM loan_payments 
      WHERE loan_id = l.id
    ) AS total_paid
  FROM loans l
  JOIN customers c ON l.customer_id = c.id
  WHERE l.status = 'active' 
    AND l.branch_id = '$branch_id'
");

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Loan Dues - Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .brand { color: #15a362; }
    .brand-bg { background-color: #15a362; }
    .brand-hover:hover { background-color: #128d51; }
    .active-link { font-weight: bold; color: #15a362; }
  </style>
</head>
<body class="bg-gray-100 p-6 font-sans">
<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
  <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
    <h2 class="text-xl font-bold brand">
      Loan Dues 
      <?= $filter === 'all' ? " – All Dates" : " – " . htmlspecialchars($filter_date) ?>
    </h2>
    <form method="GET" class="flex gap-2 items-center" id="dateFilterForm">
      <input 
        type="date" 
        name="date" 
        value="<?= $filter_date ?>" 
        class="border rounded px-3 py-1"
        onchange="document.getElementById('dateFilterForm').submit();" 
      />
      <noscript>
        <button type="submit" class="brand-bg text-white px-3 py-1 rounded brand-hover">Search</button>
      </noscript>
      <a href="?filter=today" class="text-sm underline <?= $filter==='today'?'active-link':'' ?>">Today</a>
      <a href="?filter=tomorrow" class="text-sm underline <?= $filter==='tomorrow'?'active-link':'' ?>">Tomorrow</a>
      <a href="?filter=yesterday" class="text-sm underline <?= $filter==='yesterday'?'active-link':'' ?>">Yesterday</a>
      <a href="?filter=all" class="text-sm underline <?= $filter==='all'?'active-link':'' ?>">All</a>
    </form>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm text-left text-gray-700 border">
      <thead class="bg-gray-100 uppercase text-xs">
        <tr>
          <th class="px-3 py-2">#</th>
          <th class="px-3 py-2">Customer</th>
          <th class="px-3 py-2">Loan Code</th>
          <th class="px-3 py-2">Disbursed</th>
          <th class="px-3 py-2">Installment</th>
          <th class="px-3 py-2">Total Paid</th>
          <th class="px-3 py-2">Expected</th>
          <th class="px-3 py-2">Installment Balance</th>
          <th class="px-3 py-2">Balance</th>
          <th class="px-3 py-2">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y">
      <?php
        $i = 1;
        $grand_due = $grand_paid = $grand_balance = 0;
        $hasDue = false;

        while ($row = mysqli_fetch_assoc($query)) {
            if (!$row['disbursed_date']) continue;

            for ($week = 1; $week <= $row['duration_weeks']; $week++) {
                $expected_date = date('Y-m-d', strtotime($row['disbursed_date'] . " +{$week} weeks"));

                if ($filter === 'all' || $expected_date === $filter_date) {
                    $expected = $row['weekly_installment'];

                    // Calculate what should have been paid up to this installment
                    $expected_total_until_now = $row['weekly_installment'] * $week;
                    if ($expected_total_until_now > $row['total_repayable']) {
                        $expected_total_until_now = $row['total_repayable'];
                    }

                    // Remaining balances
                    $balance = $row['total_repayable'] - $row['total_paid'];
                    $installment_balance = $expected_total_until_now - $row['total_paid'];
                    if ($installment_balance < 0) $installment_balance = 0;

                    $full_name = "{$row['first_name']} {$row['middle_name']} {$row['surname']}";
                    $status = ($installment_balance > 0) 
                        ? "<span class='text-red-600'>Due</span>" 
                        : "<span class='text-green-600'>Paid</span>";

                    $grand_due += $expected;
                    $grand_paid += $row['total_paid'];
                    $grand_balance += $balance;
                    $hasDue = true;

                    echo "<tr>
                        <td class='px-3 py-2'>{$i}</td>
                        <td class='px-3 py-2'>{$full_name}</td>
                        <td class='px-3 py-2'>{$row['loan_code']}</td>
                        <td class='px-3 py-2'>{$row['disbursed_date']}</td>
                        <td class='px-3 py-2'>KES " . number_format($row['weekly_installment'], 2) . "</td>
                        <td class='px-3 py-2'>KES " . number_format($row['total_paid'], 2) . "</td>
                        <td class='px-3 py-2'>KES " . number_format($expected, 2) . "</td>
                        <td class='px-3 py-2 text-red-700 font-semibold'>KES " . number_format($installment_balance, 2) . "</td>
                        <td class='px-3 py-2'>KES " . number_format($balance, 2) . "</td>
                        <td class='px-3 py-2 font-semibold'>{$status}</td>
                    </tr>";
                    $i++;

                    if ($filter !== 'all') break; // stop at first match if not "all"
                }
            }
        }

        if (!$hasDue) {
            echo "<tr><td colspan='10' class='text-center py-4 text-gray-500'>No loan installments found for this filter.</td></tr>";
        }
      ?>
      </tbody>
      <?php if ($hasDue): ?>
      <tfoot class="bg-gray-100 font-semibold text-sm">
        <tr>
          <td colspan="6" class="px-3 py-2 text-right">Totals:</td>
          <td class="px-3 py-2">KES <?= number_format($grand_due, 2) ?></td>
          <td class="px-3 py-2"></td>
          <td class="px-3 py-2">KES <?= number_format($grand_balance, 2) ?></td>
          <td class="px-3 py-2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
</body>
</html>
