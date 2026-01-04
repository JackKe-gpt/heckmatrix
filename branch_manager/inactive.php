<?php
session_start();
include '../includes/db.php';

// Restrict access to branch managers only
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'branch_manager') {
    header("Location: ../index");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];

// 🟢 Fetch all Inactive and Pending customers in this branch with their loan officer
$query = mysqli_query($conn, "
    SELECT 
        c.id, 
        c.first_name, 
        c.middle_name, 
        c.surname, 
        c.gender, 
        c.national_id, 
        c.phone_number, 
        c.status, 
        c.savings_balance, 
        c.created_at,
        COUNT(l.id) AS total_loans,
        MAX(l.due_date) AS last_due_date,
        a.name AS officer_name
    FROM customers c
    LEFT JOIN loans l ON l.customer_id = c.id
    LEFT JOIN admin_users a ON a.id = c.loan_officer_id
    WHERE c.branch_id = '$branch_id'
      AND c.status IN ('Inactive', 'Pending')  -- Only inactive & pending
    GROUP BY c.id
    ORDER BY c.created_at DESC
");

if (!$query) {
    die("SQL Error: " . mysqli_error($conn));
}

$data = [];

while ($row = mysqli_fetch_assoc($query)) {
    $row['full_name'] = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['surname']);
    $row['officer_name'] = $row['officer_name'] ?? '-';
    $row['created_on'] = date('d M Y', strtotime($row['created_at']));
    $row['savings_balance'] = number_format($row['savings_balance'] ?? 0, 2);

    // --- Dynamic color coding for status ---
    switch (strtolower($row['status'])) {
        case 'pending':
            $row['status_color'] = 'bg-yellow-100 text-yellow-700';
            break;
        case 'inactive':
            $row['status_color'] = 'bg-gray-200 text-gray-700';
            break;
        default:
            $row['status_color'] = 'bg-gray-100 text-gray-700';
            break;
    }

    $row['status_label'] = ucfirst($row['status']);
    $data[] = $row;
}

// Debug output if no customers
if (empty($data)) {
    echo "<pre style='color:red;'>No Inactive or Pending customers found for branch_id: $branch_id</pre>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Branch Customers - Inactive/Pending</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#15a362',
            'primary-dark': '#128054',
            'gray-soft': '#f9fafb',
            'gray-line': '#e5e7eb'
          }
        }
      }
    }
  </script>
  <?php include 'header.php'; ?>
</head>
<body class="bg-gray-100 font-sans">

<div class="w-full p-6 mt-4 bg-white rounded-2xl shadow-lg">
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Inactive & Pending Customers</h1>
  </div>

  <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-5">
    <input type="text" id="searchInput" placeholder="🔍 Search customers..." class="w-full md:w-1/2 px-4 py-2 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
    <div class="flex items-center gap-2">
      <label class="text-sm text-gray-700">Show</label>
      <select id="recordsPerPage" class="border px-3 py-2 rounded-lg text-sm focus:ring-primary focus:border-primary">
        <option value="10">10</option>
        <option value="25" selected>25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="overflow-x-auto rounded-xl border border-gray-200">
    <table class="min-w-full text-sm text-left text-gray-700">
      <thead class="bg-gray-200 text-gray-700 uppercase text-xs tracking-wider">
        <tr>
          <th class="px-4 py-3">#</th>
          <th class="px-4 py-3">Full Name</th>
          <th class="px-4 py-3">Gender</th>
          <th class="px-4 py-3">National ID</th>
          <th class="px-4 py-3">Phone</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Loan Officer</th>
          <th class="px-4 py-3 text-center">Total Loans</th>
          <th class="px-4 py-3 text-right">Savings (KSh)</th>
          <th class="px-4 py-3">Registered</th>
          <th class="px-4 py-3 text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="customerTableBody" class="bg-white divide-y divide-gray-100"></tbody>
    </table>
  </div>

  <div class="mt-6 flex flex-col md:flex-row justify-between items-center text-sm text-gray-600 gap-2">
    <div id="tableInfo">Showing 0 to 0 of 0 entries</div>
    <div class="flex flex-wrap items-center gap-2" id="pagination"></div>
  </div>
</div>

<script>
const rawData = <?php echo json_encode($data); ?>;
const tableBody = document.getElementById('customerTableBody');
const paginationDiv = document.getElementById('pagination');
const tableInfo = document.getElementById('tableInfo');
const searchInput = document.getElementById('searchInput');
const recordsPerPageSelect = document.getElementById('recordsPerPage');

let currentPage = 1;
let filteredData = [...rawData];
let recordsPerPage = parseInt(recordsPerPageSelect.value);

function renderTable() {
    const start = (currentPage - 1) * recordsPerPage;
    const end = start + recordsPerPage;
    const pageData = filteredData.slice(start, end);

    tableBody.innerHTML = '';
    pageData.forEach((row, i) => {
        tableBody.innerHTML += `
            <tr class="hover:bg-gray-50 transition">
              <td class='px-4 py-3 text-gray-500'>${start + i + 1}</td>
              <td class='px-4 py-3 font-medium text-gray-800'>${row.full_name}</td>
              <td class='px-4 py-3'>${row.gender ?? '-'}</td>
              <td class='px-4 py-3'>${row.national_id}</td>
              <td class='px-4 py-3'>${row.phone_number}</td>
              <td class='px-4 py-3'>
                <span class='px-2 py-1 rounded-full text-xs font-semibold ${row.status_color}'>
                  ${row.status_label}
                </span>
              </td>
              <td class='px-4 py-3'>${row.officer_name}</td>
              <td class='px-4 py-3 text-center text-gray-700'>${row.total_loans}</td>
              <td class='px-4 py-3 text-right font-semibold text-gray-900'>${row.savings_balance}</td>
              <td class='px-4 py-3 text-gray-600'>${row.created_on}</td>
              <td class='px-4 py-3 flex items-center justify-center gap-3'>
                <a href='view_customer?id=${row.id}' class='text-primary hover:text-primary-dark' title='View'>
                  <i data-lucide="eye" class="w-4 h-4"></i>
                </a>
                <a href='edit_customer?id=${row.id}' class='text-yellow-600 hover:text-yellow-700' title='Edit'>
                  <i data-lucide="edit-3" class="w-4 h-4"></i>
                </a>
              </td>
            </tr>`;
    });

    const totalPages = Math.ceil(filteredData.length / recordsPerPage);
    paginationDiv.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
        paginationDiv.innerHTML += `
          <button onclick="gotoPage(${i})" 
            class="px-3 py-1 rounded-lg ${i === currentPage ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'}">
            ${i}
          </button>`;
    }

    const showingStart = filteredData.length === 0 ? 0 : start + 1;
    const showingEnd = end > filteredData.length ? filteredData.length : end;
    tableInfo.innerText = `Showing ${showingStart} to ${showingEnd} of ${filteredData.length} entries`;

    lucide.createIcons();
}

function gotoPage(page) {
    currentPage = page;
    renderTable();
}

searchInput.addEventListener('input', () => {
    const keyword = searchInput.value.toLowerCase();
    filteredData = rawData.filter(row =>
        Object.values(row).some(val => String(val).toLowerCase().includes(keyword))
    );
    currentPage = 1;
    renderTable();
});

recordsPerPageSelect.addEventListener('change', () => {
    recordsPerPage = parseInt(recordsPerPageSelect.value);
    currentPage = 1;
    renderTable();
});

renderTable();
</script>
</body>
</html>
