<?php
session_start();
require '../includes/db.php';

// --- Access Control ---
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'super_admin') {
    header("Location: ../index");
    exit;
}

// --- Date Range Filter ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$branch_filter = $_GET['branch'] ?? 'all';

// Validate dates
if (!strtotime($start_date)) $start_date = date('Y-m-01');
if (!strtotime($end_date)) $end_date = date('Y-m-t');

// ======================================================
// 1. TOTAL INTEREST INCOME
// ======================================================

$interest_sql = "
    SELECT COALESCE(SUM(lp.principal_amount), 0) AS total_interest
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    WHERE lp.payment_date BETWEEN ? AND ?
";

if ($branch_filter != 'all') {
    $interest_sql .= " AND c.branch_id = ?";
    $stmt = $conn->prepare($interest_sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $branch_filter);
} else {
    $stmt = $conn->prepare($interest_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
}

$stmt->execute();
$interest_income = $stmt->get_result()->fetch_assoc()['total_interest'] ?? 0;
$stmt->close();

// ======================================================
// 2. REGISTRATION FEES
// ======================================================

$reg_sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_fees
    FROM payments
    WHERE purpose = 'registration'
    AND payment_date BETWEEN ? AND ?
";

if ($branch_filter != 'all') {
    $reg_sql .= " AND branch_id = ?";
    $stmt = $conn->prepare($reg_sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $branch_filter);
} else {
    $stmt = $conn->prepare($reg_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
}

$stmt->execute();
$registration_fees = $stmt->get_result()->fetch_assoc()['total_fees'] ?? 0;
$stmt->close();

// ======================================================
// 3. PROCESSING FEES
// ======================================================

$proc_sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_fees
    FROM payments
    WHERE purpose = 'processing'
    AND payment_date BETWEEN ? AND ?
";

if ($branch_filter != 'all') {
    $proc_sql .= " AND branch_id = ?";
    $stmt = $conn->prepare($proc_sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $branch_filter);
} else {
    $stmt = $conn->prepare($proc_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
}

$stmt->execute();
$processing_fees = $stmt->get_result()->fetch_assoc()['total_fees'] ?? 0;
$stmt->close();

// ======================================================
// 4. OTHER INCOME
// ======================================================

$other_sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_other
    FROM payments
    WHERE purpose = 'other'
    AND payment_date BETWEEN ? AND ?
";

if ($branch_filter != 'all') {
    $other_sql .= " AND branch_id = ?";
    $stmt = $conn->prepare($other_sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $branch_filter);
} else {
    $stmt = $conn->prepare($other_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
}

$stmt->execute();
$other_income = $stmt->get_result()->fetch_assoc()['total_other'] ?? 0;
$stmt->close();

// Total Income
$total_income = $interest_income + $registration_fees + $processing_fees + $other_income;


// ======================================================
// 5. MONTHLY TRENDS (last 6 months)
// ======================================================

$trends_sql = "
    SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
        COALESCE(SUM(CASE WHEN p.purpose = 'registration' THEN p.amount END), 0) AS registration_fees,
        COALESCE(SUM(CASE WHEN p.purpose = 'processing' THEN p.amount END), 0) AS processing_fees,
        COALESCE(SUM(CASE WHEN p.purpose = 'other' THEN p.amount END), 0) AS other_income
    FROM payments p
    WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
";

if ($branch_filter != 'all') {
    $trends_sql .= " AND p.branch_id = ?";
}

$trends_sql .= "
    GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
    ORDER BY month DESC
";

$stmt = $conn->prepare($trends_sql);

if ($branch_filter != 'all') {
    $stmt->bind_param("i", $branch_filter);
}

$stmt->execute();
$monthly_trends = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// ======================================================
// 6. BRANCH-WISE INCOME (FULL REPORT)
// ======================================================

$branch_income_sql = "
    SELECT 
        b.id,
        b.name AS branch_name,

        COALESCE(SUM(CASE WHEN p.purpose = 'registration' THEN p.amount END), 0) AS registration_fees,
        COALESCE(SUM(CASE WHEN p.purpose = 'processing' THEN p.amount END), 0) AS processing_fees,
        COALESCE(SUM(CASE WHEN p.purpose = 'other' THEN p.amount END), 0) AS other_income,

        COALESCE(SUM(CASE WHEN lp.payment_date BETWEEN ? AND ?
            AND c.branch_id = b.id THEN lp.principal_amount END), 0) AS interest_income

    FROM branches b
    LEFT JOIN payments p
        ON p.branch_id = b.id
        AND p.payment_date BETWEEN ? AND ?
    LEFT JOIN customers c
        ON c.branch_id = b.id
    LEFT JOIN loans l
        ON l.customer_id = c.id
    LEFT JOIN loan_payments lp
        ON lp.loan_id = l.id
    GROUP BY b.id
    ORDER BY branch_name ASC
";

$stmt = $conn->prepare($branch_income_sql);
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$branch_income = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// Branch list for dropdown
$branches = $conn->query("SELECT id, name FROM branches ORDER BY name");

include 'header.php';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income Dashboard | Faida LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1c752fff',
                        secondary: '#22bd43ff',
                        success: '#2ecc71',
                        warning: '#f39c12',
                        danger: '#e74c3c',
                        info: '#3498db',
                        light: '#ecf0f1',
                    }
                }
            }
        }
    </script>
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .slide-in {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }

        .card-hover {
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8 pb-4 border-b border-gray-200">
            <div>
                <p class="text-gray-600">Comprehensive income tracking and analytics</p>
            </div>
            <div class="flex items-center space-x-2">
                <button onclick="exportToExcel()" class="bg-success hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition duration-200">
                    <i class="fas fa-file-excel mr-2"></i> Export Excel
                </button>
                <button onclick="printReport()" class="bg-info hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition duration-200">
                    <i class="fas fa-print mr-2"></i> Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8 fade-in">
            <h2 class="text-xl font-semibold text-primary mb-4">Filters</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                    <select name="branch" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                        <option value="all">All Branches</option>
                        <?php while($branch = $branches->fetch_assoc()): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branch_filter == $branch['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($branch['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-secondary hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-md p-6 card-hover fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Total Income</h3>
                    <div class="w-12 h-12 bg-primary rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-money-bill-wave text-xl"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">KSh <?= number_format($total_income, 2) ?></p>
                <p class="text-sm text-gray-500 mt-2">Period: <?= date('M j, Y', strtotime($start_date)) ?> - <?= date('M j, Y', strtotime($end_date)) ?></p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 card-hover fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Interest Income</h3>
                    <div class="w-12 h-12 bg-success rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-percentage text-xl"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">KSh <?= number_format($interest_income, 2) ?></p>
                <p class="text-sm text-gray-500 mt-2"><?= $total_income > 0 ? number_format(($interest_income / $total_income) * 100, 1) : '0' ?>% of total</p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 card-hover fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Registration Fees</h3>
                    <div class="w-12 h-12 bg-info rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-user-plus text-xl"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">KSh <?= number_format($registration_fees, 2) ?></p>
                <p class="text-sm text-gray-500 mt-2"><?= $total_income > 0 ? number_format(($registration_fees / $total_income) * 100, 1) : '0' ?>% of total</p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 card-hover fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Processing Fees</h3>
                    <div class="w-12 h-12 bg-warning rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-cogs text-xl"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">KSh <?= number_format($processing_fees, 2) ?></p>
                <p class="text-sm text-gray-500 mt-2"><?= $total_income > 0 ? number_format(($processing_fees / $total_income) * 100, 1) : '0' ?>% of total</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Income Distribution Chart -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden fade-in">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-semibold text-primary">Income Distribution</h2>
                </div>
                <div class="p-6">
                    <canvas id="incomeDistributionChart" height="300"></canvas>
                </div>
            </div>

            <!-- Monthly Trends Chart -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden fade-in">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-semibold text-primary">Monthly Trends</h2>
                </div>
                <div class="p-6">
                    <canvas id="monthlyTrendsChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Branch Performance -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8 fade-in">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-xl font-semibold text-primary">Branch Performance</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Interest Income</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registration Fees</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Processing Fees</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Other Income</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Income</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $grand_total = 0;
                        foreach ($branch_income as $branch): 
                            $branch_total = $branch['interest_income'] + $branch['registration_fees'] + $branch['processing_fees'] + $branch['other_income'];
                            $grand_total += $branch_total;
                            $percentage = $total_income > 0 ? ($branch_total / $total_income) * 100 : 0;
                        ?>
                        <tr class="hover:bg-gray-50 transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                <?= htmlspecialchars($branch['branch_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                KSh <?= number_format($branch['interest_income'], 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                KSh <?= number_format($branch['registration_fees'], 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                KSh <?= number_format($branch['processing_fees'], 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                KSh <?= number_format($branch['other_income'], 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-semibold text-primary">
                                KSh <?= number_format($branch_total, 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                        <div class="bg-secondary h-2 rounded-full" style="width: <?= min($percentage, 100) ?>%"></div>
                                    </div>
                                    <span class="text-sm text-gray-600"><?= number_format($percentage, 1) ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($branch_income)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                No income data available for the selected period
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($branch_income)): ?>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">Total</td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">
                                KSh <?= number_format(array_sum(array_column($branch_income, 'interest_income')), 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">
                                KSh <?= number_format(array_sum(array_column($branch_income, 'registration_fees')), 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">
                                KSh <?= number_format(array_sum(array_column($branch_income, 'processing_fees')), 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">
                                KSh <?= number_format(array_sum(array_column($branch_income, 'other_income')), 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-primary">
                                KSh <?= number_format($grand_total, 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">100%</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-primary">Recent Income Transactions</h2>
                <a href="income_transactions.php" class="text-secondary hover:text-green-700 font-medium">
                    View All Transactions
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="recentTransactions">
                        <!-- Recent transactions will be loaded via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div id="loadingSpinner" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg flex items-center">
            <i class="fas fa-spinner fa-spin text-primary text-2xl mr-3"></i>
            <span class="text-gray-700">Loading income data...</span>
        </div>
    </div>

    <script>
        // Chart data from PHP
        const incomeData = {
            interest: <?= $interest_income ?>,
            registration: <?= $registration_fees ?>,
            processing: <?= $processing_fees ?>,
            other: <?= $other_income ?>,
            total: <?= $total_income ?>
        };

        const monthlyTrends = <?= json_encode($monthly_trends) ?>;
        const branchIncome = <?= json_encode($branch_income) ?>;

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            loadRecentTransactions();
        });

        function initializeCharts() {
            // Income Distribution Chart
            const incomeDistributionCtx = document.getElementById('incomeDistributionChart').getContext('2d');
            new Chart(incomeDistributionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Interest Income', 'Registration Fees', 'Processing Fees', 'Other Income'],
                    datasets: [{
                        data: [
                            incomeData.interest,
                            incomeData.registration,
                            incomeData.processing,
                            incomeData.other
                        ],
                        backgroundColor: [
                            '#2ecc71', // Green
                            '#3498db', // Blue
                            '#f39c12', // Orange
                            '#95a5a6'  // Gray
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0';
                                    return `${label}: KSh ${value.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });

            // Monthly Trends Chart
            const monthlyTrendsCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
            const months = monthlyTrends.map(trend => {
                const date = new Date(trend.month + '-01');
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short' });
            }).reverse();

            const trendsData = {
                interest: monthlyTrends.map(trend => trend.interest_income).reverse(),
                registration: monthlyTrends.map(trend => trend.registration_fees).reverse(),
                processing: monthlyTrends.map(trend => trend.processing_fees).reverse(),
                other: monthlyTrends.map(trend => trend.other_income).reverse()
            };

            new Chart(monthlyTrendsCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Interest Income',
                            data: trendsData.interest,
                            borderColor: '#2ecc71',
                            backgroundColor: 'rgba(46, 204, 113, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Registration Fees',
                            data: trendsData.registration,
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Processing Fees',
                            data: trendsData.processing,
                            borderColor: '#f39c12',
                            backgroundColor: 'rgba(243, 156, 18, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: KSh ${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'KSh ' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }

        async function loadRecentTransactions() {
            try {
                const response = await fetch('get_recent_income.php?limit=10');
                const data = await response.json();
                
                if (data.success) {
                    const transactionsContainer = document.getElementById('recentTransactions');
                    transactionsContainer.innerHTML = '';
                    
                    data.transactions.forEach(transaction => {
                        const row = document.createElement('tr');
                        row.className = 'hover:bg-gray-50 transition duration-150';
                        row.innerHTML = `
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                ${new Date(transaction.date).toLocaleDateString()}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full ${getTypeClass(transaction.type)}">
                                    ${transaction.type}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                ${transaction.description}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                ${transaction.branch || 'N/A'}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                KSh ${parseFloat(transaction.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                    Completed
                                </span>
                            </td>
                        `;
                        transactionsContainer.appendChild(row);
                    });
                }
            } catch (error) {
                console.error('Error loading recent transactions:', error);
            }
        }

        function getTypeClass(type) {
            const classes = {
                'Interest': 'bg-green-100 text-green-800',
                'Registration': 'bg-blue-100 text-blue-800',
                'Processing': 'bg-orange-100 text-orange-800',
                'Other': 'bg-gray-100 text-gray-800'
            };
            return classes[type] || 'bg-gray-100 text-gray-800';
        }

        function exportToExcel() {
            showLoading();
            // Simulate export process
            setTimeout(() => {
                hideLoading();
                showToast('Income report exported successfully!', 'success');
                
                // Create and download Excel file
                const data = [
                    ['Income Report', '', '', '', '', ''],
                    ['Period:', '<?= date('M j, Y', strtotime($start_date)) ?> - <?= date('M j, Y', strtotime($end_date)) ?>', '', '', '', ''],
                    ['Generated:', new Date().toLocaleDateString(), '', '', '', ''],
                    ['', '', '', '', '', ''],
                    ['Income Type', 'Amount (KSh)', 'Percentage', '', '', ''],
                    ['Interest Income', incomeData.interest.toLocaleString(undefined, {minimumFractionDigits: 2}), ((incomeData.interest / incomeData.total) * 100).toFixed(1) + '%'],
                    ['Registration Fees', incomeData.registration.toLocaleString(undefined, {minimumFractionDigits: 2}), ((incomeData.registration / incomeData.total) * 100).toFixed(1) + '%'],
                    ['Processing Fees', incomeData.processing.toLocaleString(undefined, {minimumFractionDigits: 2}), ((incomeData.processing / incomeData.total) * 100).toFixed(1) + '%'],
                    ['Other Income', incomeData.other.toLocaleString(undefined, {minimumFractionDigits: 2}), ((incomeData.other / incomeData.total) * 100).toFixed(1) + '%'],
                    ['Total Income', incomeData.total.toLocaleString(undefined, {minimumFractionDigits: 2}), '100%']
                ];
                
                let csvContent = "data:text/csv;charset=utf-8,";
                data.forEach(row => {
                    csvContent += row.join(",") + "\r\n";
                });
                
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", "income_report_<?= date('Y-m-d') ?>.csv");
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }, 1000);
        }

        function printReport() {
            window.print();
        }

        function showLoading() {
            document.getElementById('loadingSpinner').classList.remove('hidden');
        }

        function hideLoading() {
            document.getElementById('loadingSpinner').classList.add('hidden');
        }

        function showToast(message, type = 'success') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 ${type === 'success' ? 'bg-green-600' : 'bg-red-600'} text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full z-50`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
                toast.classList.add('translate-x-0');
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                toast.classList.remove('translate-x-0');
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }
    </script>
</body>
</html>