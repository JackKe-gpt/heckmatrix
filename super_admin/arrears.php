<?php
include 'includes/db.php';
session_start(); // Ensure sessions are started

// ==========================
// DEFAULTS
// ==========================
$today = date('Y-m-d');
$filter_date = $_GET['date'] ?? $today;
$branch_filter = $_GET['branch_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$officer_filter = $_GET['officer_id'] ?? '';

// Sanitize inputs
$filter_date = $filter_date ? mysqli_real_escape_string($conn, $filter_date) : $today;
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
$officer_filter = $officer_filter !== '' ? intval($officer_filter) : '';

// ==========================
// USER PERMISSIONS
// ==========================
$is_super_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
$current_user_branch = $_SESSION['branch_id'] ?? null;

// ==========================
// BUILD WHERE CLAUSE
// ==========================
$whereClauses = ["loans.status IN ('Active','Disbursed')"];
if ($branch_filter) $whereClauses[] = "customers.branch_id = $branch_filter";
elseif (!$is_super_admin && $current_user_branch) $whereClauses[] = "customers.branch_id = $current_user_branch";

if ($officer_filter) $whereClauses[] = "loans.officer_id = $officer_filter";
if ($status_filter) {
    if ($status_filter === 'overdue') $whereClauses[] = "loans.status = 'Active'";
    else $whereClauses[] = "loans.status = '$status_filter'";
}

$whereSql = implode(" AND ", $whereClauses) ?: '1=1';

// ==========================
// FETCH ARREARS DATA
// ==========================
$arrears_data = [];
$total_arrears = $total_principal_arrears = $total_interest_arrears = $total_penalty_arrears = 0;

$arrears_query = mysqli_query($conn, "
    SELECT 
        loans.*, 
        customers.first_name, customers.middle_name, customers.surname, customers.national_id, customers.phone_number, customers.branch_id,
        branches.name AS branch_name,
        admin_users.name AS loan_officer,
        loan_products.product_name AS product_name,
        (SELECT IFNULL(SUM(principal_amount),0) FROM loan_payments WHERE loan_id=loans.id) AS total_paid,
        (SELECT IFNULL(SUM(interest_amount),0) FROM loan_payments WHERE loan_id=loans.id) AS interest_paid
    FROM loans
    JOIN customers ON customers.id = loans.customer_id
    LEFT JOIN branches ON branches.id = customers.branch_id
    LEFT JOIN admin_users ON admin_users.id = loans.officer_id
    LEFT JOIN loan_products ON loan_products.id = loans.product_id
    WHERE $whereSql
    ORDER BY loans.disbursed_date DESC, customers.first_name ASC
") or die("Query Failed: " . mysqli_error($conn));

$today_date = new DateTime($filter_date);

while ($row = mysqli_fetch_assoc($arrears_query)) {
    if (!$row['disbursed_date']) continue;

    $disbursed_date = new DateTime($row['disbursed_date']);
    $weeks_passed = floor($disbursed_date->diff($today_date)->days / 7);
    $expected_payment = min($weeks_passed * $row['weekly_installment'], $row['total_repayable']);
    $arrears_amount = max(0, $expected_payment - $row['total_paid']);

    if ($arrears_amount > 0) {
        $principal_paid_ratio = $row['principal_amount'] > 0 ? min(1, $row['total_paid'] / $row['principal_amount']) : 0;
        $principal_arrears = max(0, $row['principal_amount'] - ($row['total_paid'] * $principal_paid_ratio));
        $interest_arrears = max(0, ($row['total_repayable'] - $row['principal_amount']) - $row['interest_paid']);
        $penalty_arrears = 0;

        $row['arrears_amount'] = $arrears_amount;
        $row['full_name'] = trim($row['first_name'].' '.$row['middle_name'].' '.$row['surname']);
        $row['principal_arrears'] = $principal_arrears;
        $row['interest_arrears'] = $interest_arrears;
        $row['penalty_arrears'] = $penalty_arrears;
        $row['total_arrears'] = $principal_arrears + $interest_arrears + $penalty_arrears;
        $row['overdue_days'] = $disbursed_date->diff($today_date)->days;
        $row['loan_status'] = $row['total_paid'] < $expected_payment ? 'Overdue' : 'Active';
        $row['weeks_passed'] = $weeks_passed;

        $total_arrears += $row['total_arrears'];
        $total_principal_arrears += $principal_arrears;
        $total_interest_arrears += $interest_arrears;
        $total_penalty_arrears += $penalty_arrears;

        $arrears_data[] = $row;
    }
}

// ==========================
// SERVER-SIDE EXPORT
// ==========================
if(isset($_GET['export']) && in_array($_GET['export'], ['xls','csv'])) {
    $filename = 'loan_arrears_report_'.date('Y-m-d_H-i');
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename={$filename}.xls");

    echo '<html><head>
<meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #000000; }
    h1,h2,p { margin:0; padding:0; text-align:center; color: #000000; }
    table { border-collapse: collapse; width: 100%; margin-top:15px; font-size:14px; }
    th { background-color:#374151; color:#000000; padding:8px; text-align:center; }
    td { padding:6px; text-align:left; color: #000000; }
    tr:nth-child(even) { background-color:#F3F4F6; }
    .overdue { color:#000000; font-weight:bold; }
    .summary { font-weight:bold; background-color:#E5E7EB; color:#000000; }
    .company-details { font-size:12px; color:#000000; }
    .logo { display:block; margin:0 auto 5px auto; max-height:80px; }
</style>
</head><body>';


    // Company logo and info
    echo '<img src="https://yourdomain.com/path_to_logo/logo.png" class="logo" />';
    echo '<h1>HECKMATRIX SOLUTIONS LTD</h1>';
    echo '<p class="company-details">P.O. Box 1234 – Nairobi, Kenya | Tel: +254 702 187 439 | Email: info@heckmatrix.co.ke</p>';
    echo '<h2>LOAN ARREARS REPORT</h2>';
    echo '<p>As of '.date('F j, Y', strtotime($filter_date)).'</p>';

    // Table header (without Email and Loan Code)
    echo '<table border="1">';
    echo '<tr>
        <th>Customer Name</th><th>Phone</th><th>National ID</th>
        <th>Branch</th><th>Loan Officer</th><th>Product</th>
        <th>Disbursed Date</th><th>Weeks Passed</th><th>Weekly Installment</th>
        <th>Total Paid</th><th>Principal Arrears</th><th>Interest Arrears</th>
        <th>Penalty Arrears</th><th>Total Arrears</th><th>Overdue Days</th><th>Payment Status</th>
    </tr>';

    if(!empty($arrears_data)){
        foreach($arrears_data as $r){
            $class = ($r['loan_status'] === 'Overdue') ? 'overdue' : '';
            echo '<tr>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['full_name']).'</td>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['phone_number']).'</td>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['national_id']).'</td>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['branch_name']).'</td>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['loan_officer']).'</td>';
            echo '<td class="'.$class.'">'.htmlspecialchars($r['product_name']).'</td>';
            echo '<td class="'.$class.'">'.date('d M Y', strtotime($r['disbursed_date'])).'</td>';
            echo '<td class="'.$class.'">'.$r['weeks_passed'].'</td>';
            echo '<td class="'.$class.'">'.number_format($r['weekly_installment'],2).'</td>';
            echo '<td class="'.$class.'">'.number_format($r['total_paid'],2).'</td>';
            echo '<td class="'.$class.'">'.number_format($r['principal_arrears'],2).'</td>';
            echo '<td class="'.$class.'">'.number_format($r['interest_arrears'],2).'</td>';
            echo '<td class="'.$class.'">'.number_format($r['penalty_arrears'],2).'</td>';
            echo '<td class="'.$class.'">'.number_format($r['total_arrears'],2).'</td>';
            echo '<td class="'.$class.'">'.$r['overdue_days'].'</td>';
            echo '<td class="'.$class.'">'.$r['loan_status'].'</td>';
            echo '</tr>';
        }

        // Summary row
        echo '<tr class="summary">
            <td colspan="9">Totals</td>
            <td>'.number_format(array_sum(array_column($arrears_data,'total_paid')),2).'</td>
            <td>'.number_format(array_sum(array_column($arrears_data,'principal_arrears')),2).'</td>
            <td>'.number_format(array_sum(array_column($arrears_data,'interest_arrears')),2).'</td>
            <td>'.number_format(array_sum(array_column($arrears_data,'penalty_arrears')),2).'</td>
            <td>'.number_format(array_sum(array_column($arrears_data,'total_arrears')),2).'</td>
            <td colspan="2"></td>
        </tr>';
    } else {
        echo '<tr><td colspan="16" style="text-align:center;">No arrears found for the selected filters.</td></tr>';
    }

    echo '</table></body></html>';
    exit;
}

include 'header.php';
?>



<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loan Arrears Report</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    @media print { .no-print { display: none !important; } body { background:white; } }
    .table-container { max-height:70vh; overflow-y:auto; }
    .status-overdue { color:#ef4444; font-weight:600; }
    .status-active { color:#10b981; font-weight:600; }
</style>
</head>
<body class="bg-gray-50 font-sans">
<div class="max-w-9xl mx-auto p-4 space-y-6">

<!-- HEADER -->
<div class="bg-white rounded-xl shadow-sm border p-6 no-print">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-chart-line text-blue-600"></i> Loan Arrears Report
            </h1>
            <p class="text-gray-600 mt-2 flex items-center gap-2">
                <i class="fas fa-info-circle text-blue-500"></i>
                <?= $filter_date ? "Arrears as of ".date("F j, Y", strtotime($filter_date)) : "All arrears from YTD to today" ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="?export=xls&date=<?= $filter_date ?>&branch_id=<?= $branch_filter ?>&officer_id=<?= $officer_filter ?>&status=<?= $status_filter ?>" 
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2 font-medium no-print">
               <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <a href="?export=csv&date=<?= $filter_date ?>&branch_id=<?= $branch_filter ?>&officer_id=<?= $officer_filter ?>&status=<?= $status_filter ?>" 
               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2 font-medium no-print">
               <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button onclick="window.print()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition flex items-center gap-2 font-medium no-print">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>
</div>

<!-- SUMMARY CARDS -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 no-print">
    <div class="bg-white rounded-xl shadow-sm border p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Total Accounts in Arrears</p>
                <p class="text-2xl font-bold text-gray-900 mt-1"><?= number_format(count($arrears_data)) ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
                <i class="fas fa-users text-blue-600 text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Total Arrears Amount</p>
                <p class="text-2xl font-bold text-red-600 mt-1">KES <?= number_format($total_arrears,2) ?></p>
            </div>
            <div class="p-3 bg-red-100 rounded-lg">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Principal Arrears</p>
                <p class="text-2xl font-bold text-orange-600 mt-1">KES <?= number_format($total_principal_arrears,2) ?></p>
            </div>
            <div class="p-3 bg-orange-100 rounded-lg">
                <i class="fas fa-landmark text-orange-600 text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Interest Arrears</p>
                <p class="text-2xl font-bold text-yellow-600 mt-1">KES <?= number_format($total_interest_arrears,2) ?></p>
            </div>
            <div class="p-3 bg-yellow-100 rounded-lg">
                <i class="fas fa-percent text-yellow-600 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="bg-white rounded-xl shadow-sm border p-6 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fas fa-calendar text-blue-500"></i> As of Date
            </label>
            <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
        </div>
        <?php if ($is_super_admin): ?>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fas fa-building text-green-500"></i> Branch Filter
            </label>
            <select name="branch_id" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <option value="">All Branches</option>
                <?php if(isset($branches)){ mysqli_data_seek($branches,0); while($b=mysqli_fetch_assoc($branches)): ?>
                    <option value="<?= $b['id'] ?>" <?= $branch_filter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endwhile; } ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fas fa-user-tie text-purple-500"></i> Loan Officer
            </label>
            <select name="officer_id" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <option value="">All Officers</option>
                <?php if(isset($officers_query)){ mysqli_data_seek($officers_query,0); while($o=mysqli_fetch_assoc($officers_query)): ?>
                    <option value="<?= $o['id'] ?>" <?= $officer_filter==$o['id']?'selected':'' ?>><?= htmlspecialchars($o['name']) ?></option>
                <?php endwhile;} ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fas fa-filter text-orange-500"></i> Status Filter
            </label>
            <select name="status" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <option value="">All Status</option>
                <option value="Active" <?= $status_filter=='Active'?'selected':'' ?>>Active</option>
                <option value="Disbursed" <?= $status_filter=='Disbursed'?'selected':'' ?>>Disbursed</option>
                <option value="overdue" <?= $status_filter=='overdue'?'selected':'' ?>>Overdue Only</option>
            </select>
        </div>
        <div class="md:col-span-2 lg:col-span-4 flex gap-2 pt-2">
            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition flex items-center justify-center gap-2 font-medium flex-1">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <a href="?" class="bg-gray-500 text-white px-6 py-3 rounded-lg hover:bg-gray-600 transition flex items-center justify-center gap-2 font-medium">
                <i class="fas fa-refresh"></i> Reset
            </a>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="p-4 border-b bg-gray-50">
        <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
            <i class="fas fa-table text-blue-500"></i> Arrears Details
            <span class="text-sm font-normal text-gray-600 ml-2">(<?= number_format(count($arrears_data)) ?> accounts)</span>
        </h2>
    </div>
    <div class="table-container">
        <table class="w-full text-sm border-collapse border border-gray-200">
            <thead class="bg-gray-50 sticky top-0">
                <tr class="border-b">
                    <th class="p-2 border">Customer Name</th>
                    <th class="p-2 border">Phone</th>
                    <th class="p-2 border">National ID</th>
                    <th class="p-2 border">Email</th>
                    <th class="p-2 border">Branch</th>
                    <th class="p-2 border">Loan Officer</th>
                    <th class="p-2 border">Loan Code</th>
                    <th class="p-2 border">Product</th>
                    <th class="p-2 border">Disbursed Date</th>
                    <th class="p-2 border">Weeks Passed</th>
                    <th class="p-2 border">Weekly Installment</th>
                    <th class="p-2 border">Total Paid</th>
                    <th class="p-2 border">Principal Arrears</th>
                    <th class="p-2 border">Interest Arrears</th>
                    <th class="p-2 border">Penalty Arrears</th>
                    <th class="p-2 border">Total Arrears</th>
                    <th class="p-2 border">Overdue Days</th>
                    <th class="p-2 border">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!empty($arrears_data)): foreach($arrears_data as $r): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="p-2 border"><?= htmlspecialchars($r['full_name']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['phone_number']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['national_id']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['branch_name']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['loan_officer']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['loan_code']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($r['product_name']) ?></td>
                        <td class="p-2 border"><?= date('d M Y', strtotime($r['disbursed_date'])) ?></td>
                        <td class="p-2 border text-center"><?= $r['weeks_passed'] ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['weekly_installment'],2) ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['total_paid'],2) ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['principal_arrears'],2) ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['interest_arrears'],2) ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['penalty_arrears'],2) ?></td>
                        <td class="p-2 border text-right"><?= number_format($r['total_arrears'],2) ?></td>
                        <td class="p-2 border text-center"><?= $r['overdue_days'] ?></td>
                        <td class="p-2 border text-center <?= $r['loan_status']=='Overdue'?'status-overdue':'status-active' ?>"><?= $r['loan_status'] ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="18" class="p-4 text-center text-gray-500">No arrears found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>
