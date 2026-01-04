<?php
session_start();
include '../includes/db.php';
include 'header.php';

// Only loan officers
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'loan_officer') {
    header("Location: ../index");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];
$officer_id = $_SESSION['admin']['id'];
$today = date('Y-m-d');

// Optional search
$search = $_GET['search'] ?? '';

$whereClause = "
    WHERE loans.status = 'active'
    AND customers.branch_id = '$branch_id'
    AND loans.officer_id = '$officer_id'
";

if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $whereClause .= "
        AND (
            customers.first_name LIKE '%$search%' OR 
            customers.surname LIKE '%$search%' OR 
            customers.phone_number LIKE '%$search%'
        )
    ";
}

// Fetch loans
$arrears_query = mysqli_query($conn, "
    SELECT 
        loans.*, 
        customers.first_name, 
        customers.middle_name, 
        customers.surname, 
        customers.phone_number
    FROM loans
    JOIN customers ON customers.id = loans.customer_id
    $whereClause
    ORDER BY loans.disbursed_date DESC
");

$arrears_data = [];
$total_arrears = 0;
$total_loans = 0;

while ($row = mysqli_fetch_assoc($arrears_query)) {

    // Total paid
    $paid_q = mysqli_query($conn, "
        SELECT IFNULL(SUM(principal_amount),0) AS total_paid 
        FROM loan_payments WHERE loan_id = '{$row['id']}'
    ");
    $total_paid = mysqli_fetch_assoc($paid_q)['total_paid'] ?? 0;

    // Time calculations
    $disbursed_date = new DateTime($row['disbursed_date']);
    $weeks_passed = floor($disbursed_date->diff(new DateTime($today))->days / 7);
    $expected_payment = min($weeks_passed * $row['weekly_installment'], $row['total_repayable']);
    $arrears_amount = $expected_payment - $total_paid;

    if ($arrears_amount > 0) {
        $row['arrears_amount'] = $arrears_amount;
        $row['expected_payment'] = $expected_payment;
        $row['total_paid'] = $total_paid;
        $row['weeks_passed'] = $weeks_passed;
        $row['days_passed'] = $disbursed_date->diff(new DateTime())->days;
        $row['loan_balance'] = $row['total_repayable'] - $total_paid;
        $row['paid_percent'] = ($total_paid / $row['total_repayable']) * 100;
        $row['next_payment_date'] = date('Y-m-d', strtotime($row['disbursed_date'] . " + $weeks_passed week"));

        $arrears_data[] = $row;
        $total_arrears += $arrears_amount;
        $total_loans++;
    }
}

?><?php
// ... keep your PHP logic as before
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loan Arrears – Faida SACCO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .brand { color:#15a362; }
    .brand-bg { background:#15a362; }
    .brand-bg:hover { background:#128d51; }
</style>
</head>
<body class="bg-gray-100 p-2 sm:p-6 font-sans">

<div class="max-w-full mx-auto">

    <!-- HEADER -->
    <div class="bg-white rounded-2xl shadow p-4 sm:p-6 mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <h1 class="text-2xl sm:text-3xl font-bold brand tracking-tight">Loan Arrears</h1>
        <form method="GET" class="flex w-full sm:w-auto">
            <input type="text" name="search" placeholder="Search name or phone..."
                value="<?= htmlspecialchars($search) ?>"
                class="border border-gray-300 px-3 sm:px-4 py-2 rounded-l-xl w-full sm:w-72 
                       focus:ring-2 focus:ring-green-500 outline-none text-sm sm:text-base">
            <button class="brand-bg text-white px-4 sm:px-6 py-2 rounded-r-xl font-medium text-sm sm:text-base">
                Search
            </button>
        </form>
    </div>

    <!-- SUMMARY -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6">
        <div class="bg-white p-4 sm:p-6 rounded-2xl shadow sm:col-span-2 lg:col-span-3 flex flex-col justify-between">
            <p class="text-gray-500 text-sm sm:text-base">Total Arrears Amount</p>
            <h2 class="text-2xl sm:text-3xl font-bold text-red-600">
                KES <?= number_format($total_arrears, 2) ?>
            </h2>
        </div>
    </div>

    <?php if ($total_loans > 0): ?>

    <!-- Desktop Table -->
    <div class="hidden md:block bg-white rounded-2xl shadow overflow-x-auto mb-4 w-full">
        <table class="min-w-full text-sm table-auto">
            <thead class="bg-gray-100 text-gray-600 uppercase sticky top-0 z-10">
                <tr>
                    <th class="px-4 py-3 border">#</th>
                    <th class="px-4 py-3 border">Customer</th>
                    <th class="px-4 py-3 border">Phone</th>
                    <th class="px-4 py-3 border">Loan</th>
                    <th class="px-4 py-3 border">Weekly Inst.</th>
                    <th class="px-4 py-3 border">Expected</th>
                    <th class="px-4 py-3 border">Paid</th>
                    <th class="px-4 py-3 border text-red-600">Arrears</th>
                    <th class="px-4 py-3 border">Balance</th>
                    <th class="px-4 py-3 border">Paid %</th>
                    <th class="px-4 py-3 border">Days</th>
                    <th class="px-4 py-3 border">Week</th>
                    <th class="px-4 py-3 border">Next Pay</th>
                    <th class="px-4 py-3 border">Status</th>
                    <th class="px-4 py-3 border text-center">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y">
                <?php $i=1; foreach ($arrears_data as $row):
                    $full_name = trim("{$row['first_name']} {$row['middle_name']} {$row['surname']}");
                ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-2 border"><?= $i++ ?></td>
                    <td class="px-4 py-2 border font-medium"><?= htmlspecialchars($full_name) ?></td>
                    <td class="px-4 py-2 border"><?= htmlspecialchars($row['phone_number']) ?></td>
                    <td class="px-4 py-2 border">KES <?= number_format($row['principal_amount'],2) ?></td>
                    <td class="px-4 py-2 border">KES <?= number_format($row['weekly_installment'],2) ?></td>
                    <td class="px-4 py-2 border">KES <?= number_format($row['expected_payment'],2) ?></td>
                    <td class="px-4 py-2 border">KES <?= number_format($row['total_paid'],2) ?></td>
                    <td class="px-4 py-2 border font-bold text-red-600">KES <?= number_format($row['arrears_amount'],2) ?></td>
                    <td class="px-4 py-2 border">KES <?= number_format($row['loan_balance'],2) ?></td>
                    <td class="px-4 py-2 border">
                        <div class="w-full bg-gray-200 h-2 rounded">
                            <div class="h-2 rounded <?= $row['paid_percent']<40?'bg-red-500':($row['paid_percent']<70?'bg-yellow-500':'bg-green-600') ?>" 
                                 style="width:<?= $row['paid_percent'] ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-600 mt-1"><?= number_format($row['paid_percent'],1) ?>%</div>
                    </td>
                    <td class="px-4 py-2 border"><?= $row['days_passed'] ?></td>
                    <td class="px-4 py-2 border"><?= $row['weeks_passed'] ?></td>
                    <td class="px-4 py-2 border"><?= date('d M Y', strtotime($row['next_payment_date'])) ?></td>
                    <td class="px-4 py-2 border"><span class="px-2 py-1 text-xs rounded bg-red-100 text-red-600">In Arrears</span></td>
                    <td class="px-4 py-2 border text-center">
                        <a href="view_loan?id=<?= $row['id'] ?>" class="px-4 py-1 bg-green-600 text-white rounded hover:bg-green-700 text-xs">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Cards -->
    <div class="md:hidden space-y-4">
        <?php foreach ($arrears_data as $row):
            $full_name = trim("{$row['first_name']} {$row['middle_name']} {$row['surname']}");
        ?>
        <div class="bg-white rounded-2xl shadow p-4 flex flex-col gap-3">
            <div class="flex justify-between items-center">
                <h2 class="font-bold text-base"><?= htmlspecialchars($full_name) ?></h2>
                <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-600">In Arrears</span>
            </div>
            <p class="text-gray-500 text-sm"><?= htmlspecialchars($row['phone_number']) ?></p>
            <div class="flex justify-between items-center">
                <p class="text-gray-700 font-medium">Loan: KES <?= number_format($row['principal_amount'],2) ?></p>
                <a href="view_loan?id=<?= $row['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">View</a>
            </div>
            <!-- Paid % bar -->
            <div class="w-full bg-gray-200 h-3 rounded-full mt-2">
                <div class="h-3 rounded-full <?= $row['paid_percent']<40?'bg-red-500':($row['paid_percent']<70?'bg-yellow-500':'bg-green-600') ?>" 
                     style="width:<?= $row['paid_percent'] ?>%"></div>
            </div>
            <div class="flex justify-between text-xs text-gray-600 mt-1">
                <span>Arrears: KES <?= number_format($row['arrears_amount'],2) ?></span>
                <span>Paid: <?= number_format($row['paid_percent'],1) ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
        <p class="text-gray-600 mt-10 text-center text-base sm:text-lg">
            ✅ No arrears found for your assigned clients.
        </p>
    <?php endif; ?>

</div>
</body>
</html>
