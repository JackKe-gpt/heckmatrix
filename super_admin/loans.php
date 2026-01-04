<?php 
include 'includes/db.php'; 
include 'header.php';

function fetch_value($query, $key) {
  global $conn;
  $result = mysqli_fetch_assoc(mysqli_query($conn, $query));
  return $result[$key] ?? 0;
}

// Fetch branches for filter
$branches = mysqli_query($conn, "SELECT id, name, location FROM branches ORDER BY name ASC");

// Get summary statistics
$total_loans = fetch_value("SELECT COUNT(*) as total FROM loans", 'total');
$total_active = fetch_value("SELECT COUNT(*) as total FROM loans WHERE status IN ('Active', 'Disbursed')", 'total');
$total_pending = fetch_value("SELECT COUNT(*) as total FROM loans WHERE status = 'Pending'", 'total');
$total_amount = fetch_value("SELECT SUM(principal_amount) as total FROM loans WHERE status IN ('Active', 'Disbursed', 'Approved')", 'total');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Loan Portfolio Management - Faida SACCO</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .dashboard-card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border: 1px solid #e2e8f0;
    }
    
    .status-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: capitalize;
    }
    
    .status-pending { background: #fef3c7; color: #d97706; }
    .status-approved { background: #dbeafe; color: #1d4ed8; }
    .status-active { background: #dcfce7; color: #16a34a; }
    .status-disbursed { background: #f0f9ff; color: #0369a1; }
    .status-rejected { background: #fee2e2; color: #dc2626; }
    .status-defaulted { background: #fef2f2; color: #b91c1c; }
    .status-fully-paid { background: #f0fdf4; color: #15803d; }
    .status-inactive { background: #f3f4f6; color: #6b7280; }
    
    .action-btn {
      padding: 0.5rem;
      border-radius: 8px;
      transition: all 0.2s;
    }
    
    .action-btn:hover {
      transform: translateY(-1px);
    }
    
    @media print {
      .no-print { display: none !important; }
    }
  </style>
</head>
<body class="bg-gray-50 font-sans">
<div class="max-w-9xl mx-auto p-4 space-y-6">

  <!-- Header -->
  <div class="dashboard-card">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div>
        <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 flex items-center gap-3">
          <i class="fas fa-hand-holding-usd text-green-600"></i>
          Loan Portfolio Management
        </h1>
        <p class="text-gray-600 mt-2 flex items-center gap-2">
          <i class="fas fa-info-circle text-blue-500"></i>
          Comprehensive overview of all loans across all branches
        </p>
      </div>
      
      <div class="flex flex-wrap gap-2 no-print">
        <button onclick="exportToPDF()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center gap-2 font-medium">
          <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button onclick="exportToExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2 font-medium">
          <i class="fas fa-file-excel"></i> Export Excel
        </button>
      </div>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="dashboard-card">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-600">Total Loans</p>
          <p class="text-2xl font-bold text-gray-900 mt-1"><?= number_format($total_loans) ?></p>
        </div>
        <div class="p-3 bg-blue-100 rounded-lg">
          <i class="fas fa-file-invoice text-blue-600 text-xl"></i>
        </div>
      </div>
    </div>
    
    <div class="dashboard-card">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-600">Active Loans</p>
          <p class="text-2xl font-bold text-green-600 mt-1"><?= number_format($total_active) ?></p>
        </div>
        <div class="p-3 bg-green-100 rounded-lg">
          <i class="fas fa-chart-line text-green-600 text-xl"></i>
        </div>
      </div>
    </div>
    
    <div class="dashboard-card">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-600">Pending Approval</p>
          <p class="text-2xl font-bold text-orange-600 mt-1"><?= number_format($total_pending) ?></p>
        </div>
        <div class="p-3 bg-orange-100 rounded-lg">
          <i class="fas fa-clock text-orange-600 text-xl"></i>
        </div>
      </div>
    </div>
    
    <div class="dashboard-card">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-600">Total Portfolio</p>
          <p class="text-2xl font-bold text-purple-600 mt-1">KES <?= number_format($total_amount, 2) ?></p>
        </div>
        <div class="p-3 bg-purple-100 rounded-lg">
          <i class="fas fa-money-bill-wave text-purple-600 text-xl"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="dashboard-card no-print">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
          <i class="fas fa-filter text-blue-500"></i>
          Loan Status
        </label>
        <select id="statusFilter" onchange="filterLoans()" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="active">Active</option>
          <option value="disbursed">Disbursed</option>
          <option value="rejected">Rejected</option>
          <option value="defaulted">Defaulted</option>
          <option value="fully paid">Fully Paid</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
          <i class="fas fa-building text-green-500"></i>
          Branch
        </label>
        <select id="branchFilter" onchange="filterLoans()" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <option value="">All Branches</option>
          <?php while($b = mysqli_fetch_assoc($branches)): ?>
            <option value="<?= $b['id'] ?>">
              <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['location']) ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
          <i class="fas fa-list text-purple-500"></i>
          Records Per Page
        </label>
        <select id="perPage" onchange="paginate()" class="w-full border border-gray-300 px-3 py-2 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <option value="10">10 records</option>
          <option value="20">20 records</option>
          <option value="50">50 records</option>
          <option value="100">100 records</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Loans Table -->
  <div class="dashboard-card">
    <div class="overflow-x-auto">
      <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 border-b">
          <tr>
            <th class="p-3 font-semibold text-gray-700">Loan Details</th>
            <th class="p-3 font-semibold text-gray-700">Customer & Branch</th>
            <th class="p-3 font-semibold text-gray-700">Financial Information</th>
            <th class="p-3 font-semibold text-gray-700">Payment Status</th>
            <th class="p-3 font-semibold text-gray-700">Actions</th>
          </tr>
        </thead>
        <tbody id="loanTableBody" class="divide-y divide-gray-200">
          <?php
            $query = mysqli_query($conn, "
    SELECT 
        loans.*, 
        CONCAT(c.first_name, ' ', c.middle_name, ' ', c.surname) AS customer_name,
        c.phone_number,
        b.name AS branch_name,
        p.product_name AS product_name,   -- use the correct column name in your table
        c.branch_id,
        au.name AS loan_officer
    FROM loans
    JOIN customers c ON loans.customer_id = c.id
    LEFT JOIN branches b ON c.branch_id = b.id
    LEFT JOIN loan_products p ON loans.product_id = p.id
    LEFT JOIN admin_users au ON loans.officer_id = au.id
    WHERE loans.status IN ('Active', 'Disbursed')   -- filter active loans only
    ORDER BY loans.disbursed_date ASC, c.first_name ASC
");

            
            while ($row = mysqli_fetch_assoc($query)) {
              $loan_id = $row['id'];
              $total_repayable = floatval($row['total_repayable']);
              $total_paid = fetch_value("
                SELECT IFNULL(SUM(principal_amount), 0) AS total_paid 
                FROM loan_payments WHERE loan_id = '$loan_id'
              ", 'total_paid');
              $outstanding = $total_repayable - $total_paid;

              $raw_status = strtolower(trim($row['status'] ?? ''));
              if (round($total_paid, 2) >= round($total_repayable, 2)) {
                $status_display = "Fully Paid";
                $status_class = "status-fully-paid";
              } elseif (in_array($raw_status, ['pending','approved','active','inactive','disbursed','rejected','defaulted'])) {
                $status_display = ucfirst($raw_status);
                $status_class = "status-" . $raw_status;
              } else {
                $status_display = "Unknown";
                $status_class = "status-inactive";
              }

              $progress = $total_repayable > 0 ? min(100, ($total_paid / $total_repayable) * 100) : 0;
              ?>
              <tr class="loan-row hover:bg-gray-50 transition-colors" 
                  data-status="<?= strtolower($status_display) ?>" 
                  data-branch="<?= $row['branch_id'] ?>">
                
                <!-- Loan Details -->
                <td class="p-3">
                  <div class="space-y-1">
                    <div class="font-medium text-gray-900"><?= htmlspecialchars($row['loan_code']) ?></div>
                    <div class="text-xs text-gray-600"><?= htmlspecialchars($row['product_name']) ?></div>
                    <div class="text-xs text-gray-500">
                      Created: <?= date('M j, Y', strtotime($row['created_at'])) ?>
                    </div>
                    <?php if ($row['disbursed_date']): ?>
                    <div class="text-xs text-gray-500">
                      Disbursed: <?= date('M j, Y', strtotime($row['disbursed_date'])) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Customer & Branch -->
                <td class="p-3">
                  <div class="space-y-1">
                    <div class="font-medium text-gray-900"><?= htmlspecialchars($row['customer_name']) ?></div>
                    <div class="text-xs text-gray-600"><?= htmlspecialchars($row['phone_number']) ?></div>
                    <div class="text-sm font-medium"><?= htmlspecialchars($row['branch_name']) ?></div>
                    <?php if ($row['loan_officer']): ?>
                    <div class="text-xs text-gray-500">Officer: <?= htmlspecialchars($row['loan_officer']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Financial Information -->
                <td class="p-3">
                  <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Principal:</span>
                      <span class="font-medium">KES <?= number_format($row['principal_amount'], 2) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Total Due:</span>
                      <span class="font-medium">KES <?= number_format($row['total_repayable'], 2) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Weekly:</span>
                      <span class="font-medium">KES <?= number_format($row['weekly_installment'], 2) ?></span>
                    </div>
                    <div class="flex justify-between text-sm font-semibold text-blue-600">
                      <span>Outstanding:</span>
                      <span>KES <?= number_format($outstanding, 2) ?></span>
                    </div>
                  </div>
                </td>

                <!-- Payment Status -->
                <td class="p-3">
                  <div class="space-y-2">
                    <span class="status-badge <?= $status_class ?>">
                      <?= $status_display ?>
                    </span>
                    
                    <!-- Progress Bar -->
                    <div class="w-full bg-gray-200 rounded-full h-2">
                      <div class="bg-green-600 h-2 rounded-full" style="width: <?= $progress ?>%"></div>
                    </div>
                    
                    <div class="text-xs text-gray-600 text-center">
                      <?= number_format($progress, 1) ?>% Paid
                    </div>
                    
                    <div class="text-xs text-gray-600">
                      Paid: KES <?= number_format($total_paid, 2) ?>
                    </div>
                  </div>
                </td>

                <!-- Actions -->
                <td class="p-3">
                  <div class="flex flex-col gap-2">
                    <!-- View Details -->
                    <button onclick="openModal('view', <?= htmlspecialchars(json_encode($row)) ?>)" 
                            class="action-btn bg-blue-50 text-blue-600 hover:bg-blue-100 flex items-center gap-2">
                      <i class="fas fa-eye w-4"></i>
                      <span class="text-xs">View</span>
                    </button>

                    <!-- Edit -->
                    <button onclick="openModal('edit', <?= htmlspecialchars(json_encode($row)) ?>)" 
                            class="action-btn bg-green-50 text-green-600 hover:bg-green-100 flex items-center gap-2">
                      <i class="fas fa-edit w-4"></i>
                      <span class="text-xs">Edit</span>
                    </button>

                    <?php if ($status_display === 'Pending'): ?>
                    <!-- Approve/Reject -->
                    <button onclick="openModal('approve', {id:<?= $loan_id ?>})" 
                            class="action-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100 flex items-center gap-2">
                      <i class="fas fa-check w-4"></i>
                      <span class="text-xs">Approve</span>
                    </button>
                    
                    <button onclick="openModal('reject', {id:<?= $loan_id ?>})" 
                            class="action-btn bg-red-50 text-red-600 hover:bg-red-100 flex items-center gap-2">
                      <i class="fas fa-times w-4"></i>
                      <span class="text-xs">Reject</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($status_display === 'Active' && $outstanding > 0): ?>
                    <!-- Record Payment -->
                    <button onclick="openModal('payment', {id:<?= $loan_id ?>})" 
                            class="action-btn bg-yellow-50 text-yellow-600 hover:bg-yellow-100 flex items-center gap-2">
                      <i class="fas fa-money-bill w-4"></i>
                      <span class="text-xs">Payment</span>
                    </button>
                    <?php endif; ?>

                    <!-- Delete -->
                    <button onclick="openModal('delete', {id:<?= $loan_id ?>})" 
                            class="action-btn bg-red-50 text-red-600 hover:bg-red-100 flex items-center gap-2">
                      <i class="fas fa-trash w-4"></i>
                      <span class="text-xs">Delete</span>
                    </button>
                  </div>
                </td>
              </tr>
              <?php
            }
          ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div id="pagination" class="mt-6 flex flex-wrap gap-2 justify-center"></div>
  </div>
</div>

<!-- Modal -->
<div id="actionModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
    <div class="flex justify-between items-center p-6 border-b">
      <h2 id="modalTitle" class="text-xl font-bold text-gray-800"></h2>
      <button onclick="closeModal()" class="text-gray-500 hover:text-gray-800 text-2xl">&times;</button>
    </div>
    <div id="modalContent" class="p-6 text-gray-700">
      <!-- content will be injected -->
    </div>
    <div class="flex justify-end gap-3 p-6 border-t bg-gray-50 rounded-b-xl">
      <button onclick="closeModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium">Cancel</button>
      <button id="modalAction" onclick="performAction()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
        Confirm
      </button>
    </div>
  </div>
</div>

<script>
let currentPage = 1;
let currentAction = '';
let currentData = null;

function filterLoans() {
  const status = document.getElementById("statusFilter").value.toLowerCase();
  const branch = document.getElementById("branchFilter").value;
  
  document.querySelectorAll(".loan-row").forEach(row => {
    const st = row.dataset.status;
    const br = row.dataset.branch;
    row.style.display = 
      (!status || status === st) && (!branch || branch === br) ? '' : 'none';
  });
  
  currentPage = 1;
  paginate();
}

function paginate() {
  const perPage = +document.getElementById("perPage").value;
  const rows = Array.from(document.querySelectorAll(".loan-row"))
      .filter(r => r.style.display !== 'none');
  const totalPages = Math.ceil(rows.length / perPage);
  const pagination = document.getElementById("pagination");
  pagination.innerHTML = "";

  rows.forEach((r, idx) => {
    r.style.display = (idx >= (currentPage-1)*perPage && idx < currentPage*perPage) ? "" : "none";
  });

  // Previous button
  if (currentPage > 1) {
    const prevBtn = document.createElement("button");
    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
    prevBtn.className = "px-3 py-2 rounded-lg border bg-white text-gray-700 hover:bg-gray-50";
    prevBtn.onclick = () => { currentPage--; paginate(); };
    pagination.appendChild(prevBtn);
  }

  // Page numbers
  for (let p = 1; p <= totalPages; p++) {
    const btn = document.createElement("button");
    btn.innerText = p;
    btn.className = `px-4 py-2 rounded-lg border font-medium ${
      p === currentPage ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50'
    }`;
    btn.onclick = () => { currentPage = p; paginate(); };
    pagination.appendChild(btn);
  }

  // Next button
  if (currentPage < totalPages) {
    const nextBtn = document.createElement("button");
    nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
    nextBtn.className = "px-3 py-2 rounded-lg border bg-white text-gray-700 hover:bg-gray-50";
    nextBtn.onclick = () => { currentPage++; paginate(); };
    pagination.appendChild(nextBtn);
  }
}

function exportToPDF() {
  const element = document.querySelector('.dashboard-card:last-child');
  html2pdf().set({
    margin: [0.5, 0.5, 0.5, 0.5],
    filename: `loans-portfolio-${new Date().toISOString().split('T')[0]}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2 },
    jsPDF: { unit: 'in', format: 'a3', orientation: 'landscape' }
  }).from(element).save();
}

function exportToExcel() {
  // Simple CSV export for demonstration
  const rows = Array.from(document.querySelectorAll('.loan-row'))
    .filter(row => row.style.display !== 'none')
    .map(row => {
      const cells = row.querySelectorAll('td');
      return Array.from(cells).map(cell => cell.textContent.trim()).join(',');
    });
  
  const headers = 'Loan Details,Customer & Branch,Financial Information,Payment Status,Actions\n';
  const csv = headers + rows.join('\n');
  
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `loans-export-${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  window.URL.revokeObjectURL(url);
}

function openModal(type, data) {
  currentAction = type;
  currentData = data;
  
  const modal = document.getElementById("actionModal");
  const title = document.getElementById("modalTitle");
  const content = document.getElementById("modalContent");
  const actionBtn = document.getElementById("modalAction");
  
  modal.classList.remove("hidden");
  
  const actions = {
    view: {
      title: "Loan Details",
      content: `<div class="space-y-4"><pre class="bg-gray-50 p-4 rounded-lg overflow-x-auto">${JSON.stringify(data, null, 2)}</pre></div>`,
      actionText: "Close",
      showAction: false
    },
    edit: {
      title: "Edit Loan",
      content: `<p class="text-gray-600">Edit functionality for loan ID: ${data.id}</p>`,
      actionText: "Save Changes",
      showAction: true
    },
    approve: {
      title: "Approve Loan",
      content: `<p class="text-gray-600">Are you sure you want to approve loan application?</p>`,
      actionText: "Approve",
      showAction: true
    },
    reject: {
      title: "Reject Loan",
      content: `<p class="text-gray-600">Are you sure you want to reject this loan application?</p>`,
      actionText: "Reject",
      showAction: true
    },
    payment: {
      title: "Record Payment",
      content: `<p class="text-gray-600">Record payment for loan ID: ${data.id}</p>`,
      actionText: "Record Payment",
      showAction: true
    },
    delete: {
      title: "Delete Loan",
      content: `<p class="text-gray-600 text-red-600 font-medium">Warning: This action cannot be undone. Are you sure you want to permanently delete this loan?</p>`,
      actionText: "Delete",
      showAction: true
    }
  };
  
  const actionConfig = actions[type] || actions.view;
  title.textContent = actionConfig.title;
  content.innerHTML = actionConfig.content;
  actionBtn.textContent = actionConfig.actionText;
  actionBtn.style.display = actionConfig.showAction ? 'block' : 'none';
}

function performAction() {
  // Here you would typically make an AJAX call to perform the action
  alert(`${currentAction} action performed for loan ID: ${currentData.id}`);
  closeModal();
}

function closeModal() {
  document.getElementById("actionModal").classList.add("hidden");
  currentAction = '';
  currentData = null;
}

// Initialize
window.onload = paginate;

// Close modal on outside click
document.getElementById('actionModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>
</body>
</html>