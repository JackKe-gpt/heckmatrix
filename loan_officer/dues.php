<?php
session_start();
include '../includes/db.php';

// Only Loan Officers
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'loan_officer') {
    header("Location: ../index");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];

$filter = $_GET['filter'] ?? '';
$filter_date = $_GET['date'] ?? date('Y-m-d');

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$yesterday = date('Y-m-d', strtotime('-1 day'));

if ($filter === 'today') $filter_date = $today;
elseif ($filter === 'tomorrow') $filter_date = $tomorrow;
elseif ($filter === 'yesterday') $filter_date = $yesterday;
elseif ($filter === 'all') $filter_date = '';

// Fetch active loans for the branch
$query = mysqli_query($conn, "
  SELECT 
    l.*, 
    c.first_name, c.middle_name, c.surname,
    (SELECT IFNULL(SUM(principal_amount), 0) FROM loan_payments WHERE loan_id = l.id) AS total_paid
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
<title>Loan Dues</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .brand { color: #15a362; }
  .brand-bg { background-color: #15a362; }
</style>
</head>

<body class="bg-gray-100 p-4 sm:p-6 font-sans">

<div class="w-full bg-white rounded-lg shadow p-4 sm:p-6">

  <!-- HEADER -->
  <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-4">


    <!-- Filters -->
    <form method="GET" id="dateFilterForm" 
          class="flex flex-wrap items-center gap-2 text-sm">

      <input type="date" 
             name="date" 
             value="<?= $filter_date ?>" 
             class="border rounded px-3 py-1 w-full sm:w-auto"
             onchange="document.getElementById('dateFilterForm').submit();" />

      <div class="flex gap-2 mt-1 sm:mt-0">
        <a href="?filter=today" class="<?= $filter==='today' ? 'font-bold brand' : 'underline' ?>">Today</a>
        <a href="?filter=tomorrow" class="<?= $filter==='tomorrow' ? 'font-bold brand' : 'underline' ?>">Tomorrow</a>
      </div>

    </form>
  </div>

  <!-- RESPONSIVE TABLE WRAPPER -->
  <div class="overflow-x-auto">

    <table class="min-w-full text-sm border rounded-md">

      <thead class="bg-gray-200 text-gray-700 uppercase text-xs">
        <tr>
          <th class="px-2 py-2">#</th>
          <th class="px-2 py-2">Customer</th>
          <th class="px-2 py-2">Loan Code</th>
          <th class="px-2 py-2">Disbursed</th>
          <th class="px-2 py-2">Installment</th>
          <th class="px-2 py-2">Total Paid</th>
          <th class="px-2 py-2">Expected</th>
          <th class="px-2 py-2">Installment Balance</th>
          <th class="px-2 py-2">Balance</th>
          <th class="px-2 py-2">Status</th>
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

            $expected_total = $row['weekly_installment'] * $week;
            if ($expected_total > $row['total_repayable']) {
                $expected_total = $row['total_repayable'];
            }

            $balance = $row['total_repayable'] - $row['total_paid'];
            $installment_balance = $expected_total - $row['total_paid'];
            if ($installment_balance < 0) $installment_balance = 0;

            $full_name = "{$row['first_name']} {$row['middle_name']} {$row['surname']}";

            $status = $installment_balance > 0
                ? "<span class='text-red-600 font-medium'>Due</span>"
                : "<span class='text-green-600 font-medium'>Paid</span>";

            $grand_due += $expected;
            $grand_balance += $balance;

            echo "
            <tr class='hover:bg-gray-50'>
              <td class='px-2 py-2'>{$i}</td>
              <td class='px-2 py-2 whitespace-nowrap'>{$full_name}</td>
              <td class='px-2 py-2'>{$row['loan_code']}</td>
              <td class='px-2 py-2'>{$row['disbursed_date']}</td>
              <td class='px-2 py-2'>KES " . number_format($row['weekly_installment'], 2) . "</td>
              <td class='px-2 py-2'>KES " . number_format($row['total_paid'], 2) . "</td>
              <td class='px-2 py-2'>KES " . number_format($expected, 2) . "</td>
              <td class='px-2 py-2 text-red-700 font-semibold'>KES " . number_format($installment_balance, 2) . "</td>
              <td class='px-2 py-2'>KES " . number_format($balance, 2) . "</td>
              <td class='px-2 py-2'>{$status}</td>
            </tr>";

            $i++;
            $hasDue = true;

            if ($filter !== 'all') break;
        }
    }
}

if (!$hasDue) {
    echo "<tr><td colspan='10' class='text-center py-4 text-gray-500'>No loan installments found.</td></tr>";
}
?>
      </tbody>

<?php if ($hasDue): ?>
      <tfoot class="bg-gray-100 text-sm font-semibold">
        <tr>
          <td colspan="6" class="px-3 py-2 text-right">Totals:</td>
          <td class="px-3 py-2">KES <?= number_format($grand_due, 2) ?></td>
          <td></td>
          <td class="px-3 py-2">KES <?= number_format($grand_balance, 2) ?></td>
          <td></td>
        </tr>
      </tfoot>
<?php endif; ?>

    </table>

  </div>

</div>

</body>
</html>
