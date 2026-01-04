<?php
// full_reporting_dashboard.php
session_start();
require 'includes/db.php'; // mysqli connection

// --- Security & Validation ---
function sanitize_input($input) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($input));
}

function validate_date($date) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

// --- Filters with Validation ---
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$branch = $_GET['branch'] ?? '';
$product = $_GET['product'] ?? '';
$member_status = $_GET['member_status'] ?? '';
$loan_status = $_GET['loan_status'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';
$officer = $_GET['officer'] ?? '';
$search = $_GET['search'] ?? '';

// Validate dates
if ($from_date && !validate_date($from_date)) $from_date = '';
if ($to_date && !validate_date($to_date)) $to_date = '';

// Sanitize inputs
$branch = $branch ? (int)$branch : '';
$product = $product ? (int)$product : '';
$officer = $officer ? (int)$officer : '';
$member_status = $member_status ? sanitize_input($member_status) : '';
$loan_status = $loan_status ? sanitize_input($loan_status) : '';
$payment_method = $payment_method ? sanitize_input($payment_method) : '';
$search = $search ? sanitize_input($search) : '';

// --- WHERE clauses with proper table aliases ---
$where_customer = "1=1";
$where_loan = "1=1";
$where_payment = "1=1";

// --- Date filters ---
if ($from_date) {
    $where_loan .= " AND l.disbursed_date >= '$from_date'";
    $where_payment .= " AND lp.payment_date >= '$from_date'";
    $where_customer .= " AND c.created_at >= '$from_date'";
}
if ($to_date) {
    $where_loan .= " AND l.disbursed_date <= '$to_date'";
    $where_payment .= " AND lp.payment_date <= '$to_date'";
    $where_customer .= " AND c.created_at <= '$to_date'";
}

// --- Branch filter ---
if ($branch) {
    $where_customer .= " AND c.branch_id = $branch";
    $where_loan .= " AND c.branch_id = $branch";
    $where_payment .= " AND c.branch_id = $branch";
}

// --- Product filter ---
if ($product) {
    $where_loan .= " AND l.product_id = $product";
}

// --- Loan status filter ---
if ($loan_status) {
    $where_loan .= " AND l.status = '$loan_status'";
}

// --- Member status filter ---
if ($member_status) {
    $where_customer .= " AND c.status = '$member_status'";
}

// --- Officer filter ---
if ($officer) {
    $where_customer .= " AND c.loan_officer_id = $officer";
    $where_loan .= " AND c.loan_officer_id = $officer";
    $where_payment .= " AND c.loan_officer_id = $officer";
}

// --- Payment method filter ---
if ($payment_method) {
    $where_payment .= " AND lp.method = '$payment_method'";
}

// --- Search filter ---
if ($search) {
    $search_condition = " AND (c.first_name LIKE '%$search%' OR c.surname LIKE '%$search%' OR c.customer_code LIKE '%$search%' OR c.phone_number LIKE '%$search%' OR l.loan_code LIKE '%$search%' OR lp.transaction_id LIKE '%$search%')";
    $where_customer .= $search_condition;
    $where_loan .= $search_condition;
    $where_payment .= $search_condition;
}

// --- Dropdowns ---
$branches = mysqli_query($conn, "SELECT id, name FROM branches ORDER BY name");
$officers = mysqli_query($conn, "SELECT id, name FROM admin_users WHERE role='loan_officer' ORDER BY name");
$products = mysqli_query($conn, "SELECT id, product_name FROM loan_products ORDER BY product_name");

$summary_sql = "
SELECT 
    COUNT(DISTINCT c.id) AS total_members,
    SUM(CASE WHEN c.status = 'Active' THEN 1 ELSE 0 END) AS active_members,
    SUM(CASE WHEN c.status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_members,
    
    COUNT(DISTINCT l.id) AS total_loans,
    SUM(CASE WHEN l.status = 'Active' THEN 1 ELSE 0 END) AS active_loans,
    SUM(CASE WHEN l.status = 'Completed' THEN 1 ELSE 0 END) AS completed_loans,
    
    COALESCE(SUM(l.principal_amount), 0) AS total_loans_disbursed,
    COALESCE(SUM(l.total_repayable - COALESCE(lp_sum.paid, 0)), 0) AS total_outstanding,
    COALESCE(SUM(l.total_interest), 0) AS total_interest_charged,
    COALESCE(SUM(lp_sum.paid), 0) AS total_payments_made,
    
    COALESCE(SUM(s.total_savings), 0) AS total_savings,
    
    COALESCE(SUM(CASE 
        WHEN l.status = 'Active' AND l.total_repayable - COALESCE(lp_sum.paid, 0) > 0 
        THEN l.total_repayable - COALESCE(lp_sum.paid, 0) 
        ELSE 0 
    END), 0) AS total_arrears
FROM customers c
LEFT JOIN loans l ON c.id = l.customer_id AND $where_loan
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) AS paid 
    FROM loan_payments 
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
LEFT JOIN (
    SELECT customer_id, SUM(amount) AS total_savings 
    FROM savings_transactions
    GROUP BY customer_id
) s ON c.id = s.customer_id
WHERE $where_customer
";


$summary_result = mysqli_query($conn, $summary_sql);
$summary = $summary_result ? mysqli_fetch_assoc($summary_result) : [
    'total_members' => 0,
    'active_members' => 0,
    'inactive_members' => 0,
    'total_loans' => 0,
    'active_loans' => 0,
    'completed_loans' => 0,
    'total_loans_disbursed' => 0,
    'total_outstanding' => 0,
    'total_interest_charged' => 0,
    'total_payments_made' => 0,
    'total_savings' => 0,
    'total_arrears' => 0
];

// --- MEMBERS REPORT ---
$members_sql = "
SELECT 
    c.id, 
    c.customer_code, 
    CONCAT(c.first_name, ' ', c.surname) AS name,
    c.phone_number,
    b.name AS branch, 
    ao.name AS officer, 
    c.status, 
    c.created_at AS registration_date,
    COALESCE(s.total_savings, 0) AS savings_balance,
    COALESCE(loan_stats.total_loans, 0) AS total_loans,
    COALESCE(loan_stats.active_loans, 0) AS active_loans,
    COALESCE(loan_stats.total_borrowed, 0) AS total_borrowed,
    COALESCE(loan_stats.outstanding_balance, 0) AS outstanding_balance
FROM customers c
LEFT JOIN branches b ON c.branch_id = b.id
LEFT JOIN admin_users ao ON c.loan_officer_id = ao.id
LEFT JOIN (
    SELECT 
        customer_id,
        COUNT(*) AS total_loans,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active_loans,
        SUM(principal_amount) AS total_borrowed,
        SUM(total_repayable - COALESCE(lp.paid, 0)) AS outstanding_balance
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(principal_amount) AS paid 
        FROM loan_payments 
        GROUP BY loan_id
    ) lp ON l.id = lp.loan_id
    GROUP BY customer_id
) loan_stats ON c.id = loan_stats.customer_id
LEFT JOIN (
    SELECT customer_id, SUM(amount) AS total_savings 
    FROM savings_transactions
    GROUP BY customer_id
) s ON c.id = s.customer_id
WHERE $where_customer
ORDER BY c.created_at DESC
LIMIT 100
";

$members = mysqli_query($conn, $members_sql);

// --- LOANS REPORT ---
$loans_sql = "
SELECT 
    l.id,
    l.loan_code, 
    CONCAT(c.first_name, ' ', c.surname) AS member,
    c.customer_code,
    p.product_name,
    l.principal_amount, 
    l.total_interest AS interest_amount, 
    l.total_repayable,
    COALESCE(lp_sum.paid, 0) AS amount_paid,
    l.total_repayable - COALESCE(lp_sum.paid, 0) AS remaining_balance,
    l.status, 
    l.disbursed_date, 
    l.due_date,
    b.name AS branch, 
    ao.name AS officer,
    DATEDIFF(CURDATE(), l.due_date) AS days_overdue
FROM loans l
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN branches b ON c.branch_id = b.id
LEFT JOIN admin_users ao ON c.loan_officer_id = ao.id
LEFT JOIN loan_products p ON l.product_id = p.id
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) AS paid 
    FROM loan_payments 
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
WHERE $where_loan
ORDER BY l.disbursed_date DESC
LIMIT 100
";
$loans = mysqli_query($conn, $loans_sql);

// --- PAYMENTS REPORT ---
$payments_sql = "
SELECT 
    lp.id AS payment_id, 
    CONCAT(c.first_name, ' ', c.surname) AS member,
    c.customer_code,
    l.loan_code, 
    lp.method, 
    lp.principal_amount AS amount_paid, 
    lp.payment_date,
    b.name AS branch,
    ao.name AS officer
FROM loan_payments lp
LEFT JOIN loans l ON lp.loan_id = l.id
LEFT JOIN customers c ON lp.customer_id = c.id
LEFT JOIN branches b ON c.branch_id = b.id
LEFT JOIN admin_users ao ON c.loan_officer_id = ao.id
WHERE $where_payment
ORDER BY lp.payment_date DESC
LIMIT 100
";

$payments = mysqli_query($conn, $payments_sql);

// --- ARREARS REPORT ---
$arrears_sql = "
SELECT 
    l.loan_code, 
    CONCAT(c.first_name, ' ', c.surname) as member,
    c.customer_code,
    l.total_repayable - COALESCE(lp_sum.paid, 0) as overdue_amount,
    DATEDIFF(CURDATE(), l.due_date) as days_overdue, 
    b.name as branch, 
    ao.name as officer,
    l.due_date
FROM loans l
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN branches b ON c.branch_id = b.id
LEFT JOIN admin_users ao ON c.loan_officer_id = ao.id
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) as paid 
    FROM loan_payments 
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
WHERE l.status = 'Active' 
AND l.total_repayable - COALESCE(lp_sum.paid, 0) > 0
AND l.due_date < CURDATE()
AND ($where_loan)
ORDER BY days_overdue DESC
LIMIT 100
";
$arrears = mysqli_query($conn, $arrears_sql);

// --- BRANCH PERFORMANCE ---
$branch_perf_sql = "
SELECT 
    b.name AS branch, 
    COUNT(DISTINCT c.id) AS total_members,
    COUNT(l.id) AS loans_count,
    COALESCE(SUM(l.principal_amount), 0) AS loans_total,
    COALESCE(SUM(lp_sum.paid), 0) AS collections_total,
    COALESCE(SUM(s.total_savings), 0) AS total_savings
FROM branches b
LEFT JOIN customers c ON b.id = c.branch_id
LEFT JOIN loans l ON c.id = l.customer_id
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) AS paid
    FROM loan_payments
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
LEFT JOIN (
    SELECT customer_id, SUM(amount) AS total_savings
    FROM savings_transactions
    GROUP BY customer_id
) s ON c.id = s.customer_id
WHERE 1=1
" . ($branch ? " AND b.id = ".(int)$branch : "") . "
GROUP BY b.id, b.name
ORDER BY loans_total DESC
";

$branch_perf = mysqli_query($conn, $branch_perf_sql);
// --- OFFICER PERFORMANCE ---
$officer_perf_sql = "
SELECT 
    ao.name AS officer, 
    b.name AS branch,
    COUNT(DISTINCT c.id) AS customers_managed,
    COUNT(l.id) AS loans_approved,
    COALESCE(SUM(l.principal_amount), 0) AS total_disbursed,
    COALESCE(SUM(lp_sum.paid), 0) AS collections_made,
    COALESCE(SUM(
        CASE 
            WHEN l.status = 'Active' AND l.total_repayable - COALESCE(lp_sum.paid, 0) > 0 
            THEN l.total_repayable - COALESCE(lp_sum.paid, 0) 
            ELSE 0 
        END
    ), 0) AS total_arrears
FROM admin_users ao
LEFT JOIN customers c ON ao.id = c.loan_officer_id
LEFT JOIN branches b ON c.branch_id = b.id
LEFT JOIN loans l ON c.id = l.customer_id
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) AS paid
    FROM loan_payments
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
WHERE ao.role = 'loan_officer'
" . ($branch ? " AND b.id = ".(int)$branch : "") . "
GROUP BY ao.id, ao.name, b.name
ORDER BY collections_made DESC
";

$officer_perf = mysqli_query($conn, $officer_perf_sql);

// --- LOAN STATUS DISTRIBUTION ---
$loan_status_dist_sql = "
SELECT 
    l.status, 
    COUNT(*) as count, 
    COALESCE(SUM(l.principal_amount), 0) as amount,
    COALESCE(SUM(l.total_repayable - COALESCE(lp_sum.paid, 0)), 0) as outstanding
FROM loans l
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN (
    SELECT loan_id, SUM(principal_amount) as paid 
    FROM loan_payments 
    GROUP BY loan_id
) lp_sum ON l.id = lp_sum.loan_id
WHERE $where_loan
GROUP BY l.status
ORDER BY count DESC
";
$loan_status_dist = mysqli_query($conn, $loan_status_dist_sql);

// --- MONTHLY TRENDS ---
$monthly_trends_sql = "
SELECT 
    DATE_FORMAT(l.disbursed_date, '%Y-%m') AS month,
    COUNT(*) AS loans_count,
    COALESCE(SUM(l.principal_amount), 0) AS loans_amount,
    COALESCE(SUM(l.total_interest), 0) AS interest_amount,
    COALESCE(SUM(lp_month.paid), 0) AS collections_amount
FROM loans l
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN (
    SELECT 
        loan_id, 
        SUM(principal_amount) AS paid
    FROM loan_payments
    WHERE 1=1
    -- optional: add date filter if needed
    -- AND created_at BETWEEN '$from_date' AND '$to_date'
    GROUP BY loan_id
) lp_month ON l.id = lp_month.loan_id
WHERE $where_loan AND l.disbursed_date IS NOT NULL
GROUP BY DATE_FORMAT(l.disbursed_date, '%Y-%m')
ORDER BY month DESC
LIMIT 12
";

$monthly_trends = mysqli_query($conn, $monthly_trends_sql);

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Reporting Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
<div class="container mx-auto px-4 py-8">


    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Filters</h2>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <!-- Date Range -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" 
                       class="w-full border border-gray-300 rounded-md px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" 
                       class="w-full border border-gray-300 rounded-md px-3 py-2">
            </div>

            <!-- Branch -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                <select name="branch" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Branches</option>
                    <?php while($b = mysqli_fetch_assoc($branches)): ?>
                        <option value="<?= $b['id'] ?>" <?= $branch == $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Officer -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Loan Officer</label>
                <select name="officer" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Officers</option>
                    <?php while($o = mysqli_fetch_assoc($officers)): ?>
                        <option value="<?= $o['id'] ?>" <?= $officer == $o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Product -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Loan Product</label>
                <select name="product" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Products</option>
                    <?php while($p = mysqli_fetch_assoc($products)): ?>
                        <option value="<?= $p['id'] ?>" <?= $product == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['product_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Member Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Member Status</label>
                <select name="member_status" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $member_status == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $member_status == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <!-- Loan Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Loan Status</label>
                <select name="loan_status" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $loan_status == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Completed" <?= $loan_status == 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Pending" <?= $loan_status == 'Pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>

            <!-- Search -->
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search members, loans, payments..." 
                       class="w-full border border-gray-300 rounded-md px-3 py-2">
            </div>

            <!-- Buttons -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                    Apply Filters
                </button>
                <a href="?" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <?php
        $summary_cards = [
            ['title' => 'Total Members', 'value' => $summary['total_members'], 'color' => 'blue', 'icon' => '👥'],
            ['title' => 'Active Loans', 'value' => $summary['active_loans'], 'color' => 'green', 'icon' => '💰'],
            ['title' => 'Total Disbursed', 'value' => 'KSh ' . number_format($summary['total_loans_disbursed'], 2), 'color' => 'purple', 'icon' => '📊'],
            ['title' => 'Outstanding Balance', 'value' => 'KSh ' . number_format($summary['total_outstanding'], 2), 'color' => 'orange', 'icon' => '⚖️'],
            ['title' => 'Total Collections', 'value' => 'KSh ' . number_format($summary['total_payments_made'], 2), 'color' => 'teal', 'icon' => '💳'],
            ['title' => 'Total Savings', 'value' => 'KSh ' . number_format($summary['total_savings'], 2), 'color' => 'indigo', 'icon' => '🏦'],
            ['title' => 'Total Arrears', 'value' => 'KSh ' . number_format($summary['total_arrears'], 2), 'color' => 'red', 'icon' => '⚠️'],
            ['title' => 'Completed Loans', 'value' => $summary['completed_loans'], 'color' => 'green', 'icon' => '✅'],
        ];

        $color_classes = [
            'blue' => 'bg-blue-500',
            'green' => 'bg-green-500',
            'purple' => 'bg-purple-500',
            'orange' => 'bg-orange-500',
            'teal' => 'bg-teal-500',
            'indigo' => 'bg-indigo-500',
            'red' => 'bg-red-500',
        ];

        foreach ($summary_cards as $card): 
        ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <span class="text-2xl"><?= $card['icon'] ?></span>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-500"><?= $card['title'] ?></h3>
                    <p class="text-2xl font-semibold text-gray-900"><?= $card['value'] ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabs for Different Reports -->
    <div class="bg-white rounded-lg shadow-md">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <button onclick="showTab('members')" class="tab-button py-4 px-6 border-b-2 font-medium text-sm border-blue-500 text-blue-600">
                    Members
                </button>
                <button onclick="showTab('loans')" class="tab-button py-4 px-6 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Loans
                </button>
                <button onclick="showTab('payments')" class="tab-button py-4 px-6 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Payments
                </button>
                <button onclick="showTab('arrears')" class="tab-button py-4 px-6 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Arrears
                </button>
                <button onclick="showTab('performance')" class="tab-button py-4 px-6 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Performance
                </button>
            </nav>
        </div>

        <!-- Members Tab -->
        <div id="members-tab" class="tab-content p-6">
            <h3 class="text-lg font-semibold mb-4">Members Report</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Officer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Savings</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loans</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($member = mysqli_fetch_assoc($members)): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($member['name']) ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($member['customer_code']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($member['branch']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($member['officer']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $member['status'] == 'Active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= htmlspecialchars($member['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($member['savings_balance'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= $member['total_loans'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($member['outstanding_balance'], 2) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Loans Tab -->
        <div id="loans-tab" class="tab-content p-6 hidden">
            <h3 class="text-lg font-semibold mb-4">Loans Report</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($loan = mysqli_fetch_assoc($loans)): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($loan['loan_code']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($loan['member']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($loan['product_name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($loan['principal_amount'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($loan['amount_paid'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($loan['remaining_balance'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?= $loan['status'] == 'Active' ? 'bg-green-100 text-green-800' : 
                                       ($loan['status'] == 'Completed' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                    <?= htmlspecialchars($loan['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payments Tab -->
        <div id="payments-tab" class="tab-content p-6 hidden">
            <h3 class="text-lg font-semibold mb-4">Payments Report</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transaction ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($payment = mysqli_fetch_assoc($payments)): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['transaction_id']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['member']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['loan_code']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['method']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">KSh <?= number_format($payment['amount_paid'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['payment_date']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Arrears Tab -->
        <div id="arrears-tab" class="tab-content p-6 hidden">
            <h3 class="text-lg font-semibold mb-4">Arrears Report</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Overdue Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days Overdue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Officer</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($arrear = mysqli_fetch_assoc($arrears)): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($arrear['loan_code']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($arrear['member']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-semibold">KSh <?= number_format($arrear['overdue_amount'], 2) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?= $arrear['days_overdue'] > 90 ? 'bg-red-100 text-red-800' : 
                                       ($arrear['days_overdue'] > 30 ? 'bg-orange-100 text-orange-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                    <?= $arrear['days_overdue'] ?> days
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($arrear['branch']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($arrear['officer']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Performance Tab -->
        <div id="performance-tab" class="tab-content p-6 hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Branch Performance -->
                <div>
                    <h4 class="text-lg font-semibold mb-4">Branch Performance</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Members</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Loans</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Disbursed</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Collections</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while($branch = mysqli_fetch_assoc($branch_perf)): ?>
                                <tr>
                                    <td class="px-4 py-2 text-sm font-medium text-gray-900"><?= htmlspecialchars($branch['branch']) ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900"><?= $branch['total_members'] ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900"><?= $branch['loans_count'] ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900">KSh <?= number_format($branch['loans_total'], 2) ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900">KSh <?= number_format($branch['collections_total'], 2) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Officer Performance -->
                <div>
                    <h4 class="text-lg font-semibold mb-4">Officer Performance</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Officer</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customers</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Loans</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Disbursed</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Collections</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while($officer = mysqli_fetch_assoc($officer_perf)): ?>
                                <tr>
                                    <td class="px-4 py-2 text-sm font-medium text-gray-900"><?= htmlspecialchars($officer['officer']) ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900"><?= $officer['customers_managed'] ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900"><?= $officer['loans_approved'] ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900">KSh <?= number_format($officer['total_disbursed'], 2) ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-900">KSh <?= number_format($officer['collections_made'], 2) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.remove('hidden');
    
    // Activate selected button
    event.target.classList.remove('border-transparent', 'text-gray-500');
    event.target.classList.add('border-blue-500', 'text-blue-600');
}

// Show members tab by default
document.addEventListener('DOMContentLoaded', function() {
    showTab('members');
});
</script>

</body>
</html>