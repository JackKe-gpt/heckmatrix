<?php
include 'includes/db.php';

// ==========================
// USER PERMISSIONS
// ==========================
$is_super_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
$current_user_branch = $_SESSION['branch_id'] ?? null;

// ==========================
// FILTERS
// ==========================
$filter = $_GET['filter'] ?? '';
$filter_date = $_GET['date'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$status_filter = $_GET['status'] ?? 'due';

if ($filter === 'today') $filter_date = date('Y-m-d');
elseif ($filter === 'tomorrow') $filter_date = date('Y-m-d', strtotime('+1 day'));
elseif ($filter === 'yesterday') $filter_date = date('Y-m-d', strtotime('-1 day'));

// ==========================
// BASE QUERY
// ==========================
$whereClauses = ["l.status IN ('Active','Disbursed')"];
if ($branch_filter) $whereClauses[] = "c.branch_id = $branch_filter";
elseif (!$is_super_admin && $current_user_branch) $whereClauses[] = "c.branch_id = $current_user_branch";
$whereSql = implode(" AND ", $whereClauses);

$query_sql = "
SELECT l.*, 
       c.first_name, c.middle_name, c.surname, c.phone_number,
       b.name AS branch_name,
       lp.product_name,
       au.name AS loan_officer,
       (SELECT IFNULL(SUM(principal_amount),0) FROM loan_payments WHERE loan_id=l.id) total_paid
FROM loans l
JOIN customers c ON l.customer_id=c.id
LEFT JOIN branches b ON c.branch_id=b.id
LEFT JOIN loan_products lp ON l.product_id=lp.id
LEFT JOIN admin_users au ON l.officer_id=au.id
WHERE $whereSql
ORDER BY l.disbursed_date ASC
";

$query = mysqli_query($conn, $query_sql);

// ==========================
// PREPARE DATA
// ==========================
$export_data = [];
$today = new DateTime();

while ($row = mysqli_fetch_assoc($query)) {
    if (!$row['disbursed_date']) continue;

    $disbursed = new DateTime($row['disbursed_date']);
    $weeks = floor($disbursed->diff($today)->days / 7);
    $expected = min($weeks * $row['weekly_installment'], $row['total_repayable']);
    $arrears = max(0, $expected - $row['total_paid']);
    $balance = $row['total_repayable'] - $row['total_paid'];

    for ($w = 1; $w <= $row['duration_weeks']; $w++) {
        $due = date('Y-m-d', strtotime($row['disbursed_date']." +$w weeks"));
        if ($filter_date && $due !== $filter_date) continue;

        $dueObj = new DateTime($due);
        $overdue = $dueObj < $today;

        $export_data[] = [
            'Customer Name' => trim($row['first_name'].' '.$row['surname']),
            'Phone' => $row['phone_number'],
            'Branch' => $row['branch_name'],
            'Loan Officer' => $row['loan_officer'] ?? 'N/A',
            'Loan Product' => $row['product_name'],
            'Loan Code' => $row['loan_code'],
            'Disbursed Date' => $row['disbursed_date'],
            'Due Date' => $due,
            'Weekly Installment' => $row['weekly_installment'],
            'Amount Paid' => $row['total_paid'],
            'Remaining Balance' => $balance,
            'Payment Status' => $overdue ? 'Overdue' : 'Upcoming',
            'Arrears Amount' => $overdue ? $arrears : 0
        ];
    }
}

// ==========================
// EXPORT AS STYLED HTML TABLE (Excel-compatible)
// ==========================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=loan_dues_report_".date('Y-m-d_H-i').".xls");

    echo '<html><head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial; }
        h1, h2, h3, p { margin: 0; padding: 0; text-align: center; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        th { background-color: #1F4E78; color: #fff; padding: 8px; text-align: center; }
        td { padding: 6px; text-align: left; }
        tr:nth-child(even) { background-color: #E8F1FF; }
        .overdue { color: red; font-weight: bold; }
        .summary { font-weight: bold; background-color: #D3D3D3; }
        .company-details { font-size: 12px; color: #333; }
        .logo { display: block; margin: 0 auto 5px auto; max-height: 80px; }
    </style>
    </head><body>';

    // Company logo and info
    echo '<img src="https://yourdomain.com/path_to_logo/logo.png" class="logo" />';
    echo '<h1>HECKMATRIX SOLUTIONS LTD</h1>';
    echo '<p class="company-details">P.O. Box 1234 – Nairobi, Kenya | Tel: +254 702 187 439 | Email: info@heckmatrix.co.ke</p>';
    echo '<h2>LOAN DUES REPORT</h2>';
    echo '<p>Generated on: '.date('F j, Y g:i A').'</p>';

    echo '<table border="1">';
    
    if (!empty($export_data)) {
        // Table header
        echo '<tr>';
        foreach (array_keys($export_data[0]) as $header) {
            echo '<th>'.htmlspecialchars($header).'</th>';
        }
        echo '</tr>';

        // Table rows
        foreach ($export_data as $row) {
            $class = ($row['Payment Status'] == 'Overdue') ? 'overdue' : '';
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td class="'.$class.'">'.htmlspecialchars($value).'</td>';
            }
            echo '</tr>';
        }

        // Summary
        echo '<tr class="summary"><td>Total Records</td><td>'.count($export_data).'</td>';
        echo '<td>Total Overdue</td><td>'.array_sum(array_column($export_data,'Arrears Amount')).'</td>';
        $remaining = count($export_data[0])-4;
        for ($i=0;$i<$remaining;$i++) echo '<td></td>';
        echo '</tr>';

    } else {
        echo '<tr><td colspan="12">No loan dues found for the selected criteria.</td></tr>';
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
    <title>Loan Dues Report - Faida SACCO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        .status-overdue { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
        .status-due { background: #fffbeb; color: #d97706; border-left: 4px solid #d97706; }
        .status-upcoming { background: #f0f9ff; color: #0369a1; border-left: 4px solid #0369a1; }
        .status-cleared { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
        
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
<div class="max-w-9xl mx-auto p-4 space-y-6">

    <!-- HEADER -->
    <div class="dashboard-card">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 flex items-center gap-3">
                    <i class="fas fa-calendar-check text-blue-600"></i>
                    Loan Dues Report
                </h1>
                <p class="text-gray-600 mt-2 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    <?= $filter_date ? "Dues for " . date("F j, Y", strtotime($filter_date)) : "All upcoming and overdue dues" ?>
                </p>
            </div>
            
            <div class="flex flex-wrap gap-2 no-print">
                <a href="?export=csv&date=<?= $filter_date ?>&branch_id=<?= $branch_filter ?>" 
                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2 font-medium">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="?export=xlsx&date=<?= $filter_date ?>&branch_id=<?= $branch_filter ?>" 
                   class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2 font-medium">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <button onclick="window.print()" 
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition flex items-center gap-2 font-medium">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="dashboard-card no-print">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                    <i class="fas fa-calendar text-blue-500"></i>
                    Due Date
                </label>
                <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" 
                       class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <?php if ($is_super_admin): ?>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                    <i class="fas fa-building text-green-500"></i>
                    Branch
                </label>
                <select name="branch_id" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Branches</option>
                    <?php
                    if (isset($branches)) {
                        mysqli_data_seek($branches, 0);
                        while($b = mysqli_fetch_assoc($branches)):
                    ?>
                    <option value="<?= $b['id'] ?>" <?= $branch_filter == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['location']) ?>)
                    </option>
                    <?php endwhile; } ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                    <i class="fas fa-filter text-orange-500"></i>
                    Quick Filters
                </label>
                <select name="filter" onchange="this.form.submit()" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select Period</option>
                    <option value="today" <?= $filter == 'today' ? 'selected' : '' ?>>Today's Dues</option>
                    <option value="tomorrow" <?= $filter == 'tomorrow' ? 'selected' : '' ?>>Tomorrow's Dues</option>
                    <option value="yesterday" <?= $filter == 'yesterday' ? 'selected' : '' ?>>Yesterday's Dues</option>
                    <option value="this_week" <?= $filter == 'this_week' ? 'selected' : '' ?>>This Week</option>
                    <option value="next_week" <?= $filter == 'next_week' ? 'selected' : '' ?>>Next Week</option>
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition flex items-center justify-center gap-2 font-medium flex-1">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="?" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition flex items-center justify-center">
                    <i class="fas fa-refresh"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- SUMMARY CARDS -->
    <?php
    mysqli_data_seek($query, 0);
    $today = new DateTime();
    $stats = ['overdue' => 0, 'due_today' => 0, 'upcoming' => 0, 'total_amount' => 0];
    
    while ($row = mysqli_fetch_assoc($query)) {
        if (!$row['disbursed_date']) continue;
        
        $disbursed_date = new DateTime($row['disbursed_date']);
        for ($week = 1; $week <= $row['duration_weeks']; $week++) {
            $due_date = date('Y-m-d', strtotime($row['disbursed_date'] . " +{$week} weeks"));
            $due_date_obj = new DateTime($due_date);
            
            if ($filter_date && $due_date !== $filter_date) continue;
            if (!$filter_date && $due_date_obj < $today && $row['total_paid'] >= ($week * $row['weekly_installment'])) continue;
            
            $is_overdue = $due_date_obj < $today;
            $is_due_today = $due_date_obj == $today;
            
            if ($is_overdue) $stats['overdue']++;
            elseif ($is_due_today) $stats['due_today']++;
            else $stats['upcoming']++;
            
            $stats['total_amount'] += $row['weekly_installment'];
        }
    }
    ?>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 no-print">
        <div class="dashboard-card status-overdue">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium">Overdue Dues</p>
                    <p class="text-2xl font-bold mt-1"><?= number_format($stats['overdue']) ?></p>
                </div>
                <i class="fas fa-exclamation-triangle text-2xl opacity-50"></i>
            </div>
        </div>
        
        <div class="dashboard-card status-due">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium">Due Today</p>
                    <p class="text-2xl font-bold mt-1"><?= number_format($stats['due_today']) ?></p>
                </div>
                <i class="fas fa-calendar-day text-2xl opacity-50"></i>
            </div>
        </div>
        
        <div class="dashboard-card status-upcoming">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium">Upcoming Dues</p>
                    <p class="text-2xl font-bold mt-1"><?= number_format($stats['upcoming']) ?></p>
                </div>
                <i class="fas fa-calendar-alt text-2xl opacity-50"></i>
            </div>
        </div>
        
        <div class="dashboard-card bg-gradient-to-r from-blue-600 to-blue-700 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium">Total Due Amount</p>
                    <p class="text-2xl font-bold mt-1">KES <?= number_format($stats['total_amount'], 2) ?></p>
                </div>
                <i class="fas fa-money-bill-wave text-2xl opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- MAIN TABLE -->
    <div class="dashboard-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="p-3 font-semibold text-gray-700">Customer Details</th>
                        <th class="p-3 font-semibold text-gray-700">Loan Information</th>
                        <th class="p-3 font-semibold text-gray-700">Due Date & Amount</th>
                        <th class="p-3 font-semibold text-gray-700">Payment Status</th>
                        <?php if ($is_super_admin): ?>
                        <th class="p-3 font-semibold text-gray-700">Branch & Officer</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                <?php
                mysqli_data_seek($query, 0);
                $today = new DateTime();
                $has_data = false;
                $counter = 0;

                while ($row = mysqli_fetch_assoc($query)) {
                    if (!$row['disbursed_date']) continue;
                    if ($branch_filter && $row['branch_id'] != $branch_filter) continue;

                    $full_name = trim("{$row['first_name']} {$row['middle_name']} {$row['surname']}");
                    $disbursed_date = new DateTime($row['disbursed_date']);
                    
                    for ($week = 1; $week <= $row['duration_weeks']; $week++) {
                        $due_date = date('Y-m-d', strtotime($row['disbursed_date'] . " +{$week} weeks"));
                        $due_date_obj = new DateTime($due_date);
                        
                        // Apply date filter
                        if ($filter_date && $due_date !== $filter_date) continue;
                        
                        // Skip paid installments when showing all
                        if (!$filter_date && $row['total_paid'] >= ($week * $row['weekly_installment'])) continue;
                        
                        $is_overdue = $due_date_obj < $today;
                        $is_due_today = $due_date_obj == $today;
                        $days_diff = $due_date_obj->diff($today)->days;
                        $days_text = $is_overdue ? "{$days_diff} days ago" : "in {$days_diff} days";
                        
                        $status_class = $is_overdue ? 'status-overdue' : ($is_due_today ? 'status-due' : 'status-upcoming');
                        $status_text = $is_overdue ? 'Overdue' : ($is_due_today ? 'Due Today' : 'Upcoming');
                        
                        $has_data = true;
                        $counter++;
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <!-- Customer Details -->
                            <td class="p-3">
                                <div class="space-y-1">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($full_name) ?></div>
                                    <div class="text-xs text-gray-600"><?= htmlspecialchars($row['phone_number']) ?></div>
                                </div>
                            </td>
                            
                            <!-- Loan Information -->
                            <td class="p-3">
                                <div class="space-y-1">
                                    <div class="font-medium"><?= htmlspecialchars($row['loan_code']) ?></div>
                                    <div class="text-xs text-gray-600"><?= htmlspecialchars($row['product_name'] ?? 'N/A') ?></div>
                                    <div class="text-xs">
                                        <span class="font-medium">Disbursed:</span> 
                                        <?= date('M j, Y', strtotime($row['disbursed_date'])) ?>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Due Date & Amount -->
                            <td class="p-3">
                                <div class="space-y-1">
                                    <div class="font-medium text-gray-900">
                                        <?= date('M j, Y', strtotime($due_date)) ?>
                                    </div>
                                    <div class="text-lg font-bold text-blue-600">
                                        KES <?= number_format($row['weekly_installment'], 2) ?>
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        Week <?= $week ?> of <?= $row['duration_weeks'] ?>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Payment Status -->
                            <td class="p-3">
                                <div class="space-y-1">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                    <div class="text-xs text-gray-600">
                                        <?= $days_text ?>
                                    </div>
                                    <div class="text-xs">
                                        Paid: KES <?= number_format($row['total_paid'], 2) ?>
                                    </div>
                                </div>
                            </td>
                            
                            <?php if ($is_super_admin): ?>
                            <!-- Branch & Officer -->
                            <td class="p-3">
                                <div class="space-y-1">
                                    <div class="text-sm font-medium"><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></div>
                                    <div class="text-xs text-gray-600"><?= htmlspecialchars($row['loan_officer'] ?? 'Unassigned') ?></div>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                    }
                }

                if (!$has_data): ?>
                    <tr>
                        <td colspan="<?= $is_super_admin ? 5 : 4 ?>" class="text-center p-8 text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                            <div class="text-lg font-medium">No loan dues found</div>
                            <p class="text-sm mt-1">No dues match your current filter criteria.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit form when date or branch changes
    document.querySelectorAll('input[name="date"], select[name="branch_id"]').forEach(el => {
        el.addEventListener('change', function() {
            document.querySelector('form').submit();
        });
    });
});
</script>
</body>
</html>