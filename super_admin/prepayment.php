<?php
include 'includes/db.php';
include 'header.php';

$filter_date = date('Y-m-d', strtotime('+1 day'));

function getNextInstallmentDue($disbursed_date, $duration_weeks) {
  $due_dates = [];
  for ($i = 1; $i <= $duration_weeks; $i++) {
    $due_dates[] = date('Y-m-d', strtotime($disbursed_date . " +$i weeks"));
  }
  return $due_dates;
}

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
  WHERE l.status = 'Disbursed'
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Loan Prepayments – Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .brand { color: #15a362; }
    .brand-bg { background-color: #15a362; }
    .brand-hover:hover { background-color: #128d51; }
  </style>
</head>
<body class="bg-gray-100 p-6 font-sans">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-xl font-bold brand">Loan Prepayments – <?= htmlspecialchars($filter_date) ?></h2>
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
            <th class="px-3 py-2">Paid</th>
            <th class="px-3 py-2">Expected by Tomorrow</th>
            <th class="px-3 py-2">Installment Balance</th>
            <th class="px-3 py-2">Total Balance</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y">
        <?php
          $i = 1;
          $has_due = false;
          while ($row = mysqli_fetch_assoc($query)) {
            if (!$row['disbursed_date']) continue;

            // Get all scheduled due dates
            $due_dates = getNextInstallmentDue($row['disbursed_date'], $row['duration_weeks']);
            $installment_number = array_search($filter_date, $due_dates);

            if ($installment_number === false) continue; // Skip if tomorrow is not a scheduled installment

            // installment_number is zero-indexed, so add 1
            $week_number = $installment_number + 1;
            $expected = $week_number * $row['weekly_installment'];
            $balance = $row['total_repayable'] - $row['total_paid'];
            $installment_balance = max(0, $expected - $row['total_paid']);

            $full_name = "{$row['first_name']} {$row['middle_name']} {$row['surname']}";
            $status = $installment_balance > 0 
              ? "<span class='text-orange-600'>Due Tomorrow</span>" 
              : "<span class='text-green-600'>Paid Ahead</span>";

            echo "<tr>
              <td class='px-3 py-2'>{$i}</td>
              <td class='px-3 py-2'>{$full_name}</td>
              <td class='px-3 py-2'>{$row['loan_code']}</td>
              <td class='px-3 py-2'>{$row['disbursed_date']}</td>
              <td class='px-3 py-2'>KES " . number_format($row['weekly_installment'], 2) . "</td>
              <td class='px-3 py-2'>KES " . number_format($row['total_paid'], 2) . "</td>
              <td class='px-3 py-2'>KES " . number_format($expected, 2) . "</td>
              <td class='px-3 py-2 text-red-600 font-semibold'>KES " . number_format($installment_balance, 2) . "</td>
              <td class='px-3 py-2'>KES " . number_format($balance, 2) . "</td>
              <td class='px-3 py-2 font-semibold'>{$status}</td>
            </tr>";
            $i++;
            $has_due = true;
          }

          if (!$has_due) {
            echo "<tr><td colspan='10' class='text-center text-red-600 py-4'>No loans due tomorrow.</td></tr>";
          }
        ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
