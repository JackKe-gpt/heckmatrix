<?php
session_start();
include 'includes/db.php';

// Fetch branches and officers for filters
$branches = mysqli_query($conn, "SELECT id, name, location FROM branches ORDER BY name ASC");
$officers = mysqli_query($conn, "SELECT id, name FROM admin_users WHERE role='loan_officer' ORDER BY name ASC");

// Fetch only active customers with branch and officer info
$query = mysqli_query($conn, "
    SELECT 
        c.*, 
        b.name AS branch_name, 
        o.name AS officer_name,
        c.group_id
    FROM customers c
    LEFT JOIN branches b ON c.branch_id=b.id
    LEFT JOIN admin_users o ON c.loan_officer_id=o.id
    WHERE c.status='Active'
    ORDER BY c.created_at DESC
");

$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $row['full_name'] = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['surname']);
    $row['created_on'] = date('d M Y', strtotime($row['created_at']));
    $row['savings_balance'] = number_format($row['savings_balance'] ?? 0, 2);
    $row['customer_type'] = $row['group_id'] ? 'Group' : 'Individual';
    $row['officer_name'] = $row['officer_name'] ?: 'Unassigned';
    $data[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Active Customers - Faida</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<?php include 'header.php'; ?>
</head>
<body class="bg-gray-50 font-sans">

<div class="max-w-7xl mx-auto p-6 bg-white shadow rounded-lg">

  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Active Customers</h1>
    <div class="flex gap-2">
      <a href="create_customer" class="bg-primary hover:bg-primary-dark text-white px-5 py-2 rounded-md text-sm font-medium">+ Add Customer</a>
      <button id="exportBtn" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-md text-sm font-medium">Export Excel</button>
    </div>
  </div>

  <!-- Filters & Search -->
  <div class="flex flex-wrap gap-4 mb-4">
    <input type="text" id="searchInput" placeholder="Search customers..." class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-1 focus:ring-primary focus:border-primary">

    <select id="branchFilter" class="px-3 py-2 border rounded-md" onchange="applyFilters()">
      <option value="">All Branches</option>
      <?php while($b = mysqli_fetch_assoc($branches)): ?>
        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['location']) ?>)</option>
      <?php endwhile; ?>
    </select>

    <select id="officerFilter" class="px-3 py-2 border rounded-md" onchange="applyFilters()">
      <option value="">All Officers</option>
      <?php while($o = mysqli_fetch_assoc($officers)): ?>
        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
      <?php endwhile; ?>
    </select>

    <select id="typeFilter" class="px-3 py-2 border rounded-md" onchange="applyFilters()">
      <option value="">All Types</option>
      <option value="Individual">Individual</option>
      <option value="Group">Group</option>
    </select>

    <select id="recordsPerPage" class="px-3 py-2 border rounded-md" onchange="applyFilters()">
      <option value="10">10</option>
      <option value="25" selected>25</option>
      <option value="50">50</option>
      <option value="100">100</option>
    </select>
  </div>

  <!-- Table -->
  <div class="overflow-x-auto shadow-sm rounded-lg">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
        <tr>
          <th class="px-4 py-3">#</th>
          <th class="px-4 py-3">Full Name</th>
          <th class="px-4 py-3">National ID</th>
          <th class="px-4 py-3">Phone</th>
          <th class="px-4 py-3">County</th>
          <th class="px-4 py-3">Branch</th>
          <th class="px-4 py-3">Officer</th>
          <th class="px-4 py-3">Type</th>
          <th class="px-4 py-3">Savings (KSh)</th>
          <th class="px-4 py-3">Registered</th>
          <th class="px-4 py-3">Actions</th>
        </tr>
      </thead>
      <tbody id="customerTableBody" class="bg-white divide-y divide-gray-200"></tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="mt-4 flex justify-between items-center text-sm text-gray-600">
    <div id="tableInfo"></div>
    <div id="pagination" class="flex gap-2"></div>
  </div>
</div>

<script>
const rawData = <?php echo json_encode($data); ?>;
let filteredData = [...rawData];
let currentPage = 1;
let recordsPerPage = parseInt(document.getElementById('recordsPerPage').value);

const tableBody = document.getElementById('customerTableBody');
const tableInfo = document.getElementById('tableInfo');
const paginationDiv = document.getElementById('pagination');

function applyFilters() {
  const branch = document.getElementById('branchFilter').value;
  const officer = document.getElementById('officerFilter').value;
  const type = document.getElementById('typeFilter').value.toLowerCase();
  const search = document.getElementById('searchInput').value.toLowerCase();
  recordsPerPage = parseInt(document.getElementById('recordsPerPage').value);

  filteredData = rawData.filter(row => {
    const branchMatch = !branch || row.branch_id == branch;
    const officerMatch = !officer || row.loan_officer_id == officer;
    const typeMatch = !type || row.customer_type.toLowerCase() === type;
    const searchMatch = !search || Object.values(row).some(val => String(val).toLowerCase().includes(search));
    return branchMatch && officerMatch && typeMatch && searchMatch;
  });

  currentPage = 1;
  renderTable();
}

function renderTable() {
  const start = (currentPage - 1) * recordsPerPage;
  const end = start + recordsPerPage;
  const pageData = filteredData.slice(start, end);

  tableBody.innerHTML = '';
  pageData.forEach((row, i) => {
    tableBody.innerHTML += `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2">${start + i + 1}</td>
        <td class="px-4 py-2 font-medium text-gray-800">${row.full_name}</td>
        <td class="px-4 py-2">${row.national_id}</td>
        <td class="px-4 py-2">${row.phone_number}</td>
        <td class="px-4 py-2">${row.county}</td>
        <td class="px-4 py-2">${row.branch_name||'-'}</td>
        <td class="px-4 py-2">${row.officer_name}</td>
        <td class="px-4 py-2">${row.customer_type}</td>
        <td class="px-4 py-2">${row.savings_balance}</td>
        <td class="px-4 py-2">${row.created_on}</td>
        <td class="px-4 py-2 space-x-2">
          <a href="view_customer?id=${row.id}" class="text-primary hover:underline">View</a>
          <a href="edit_customer?id=${row.id}" class="text-yellow-600 hover:underline">Edit</a>
          <a href="delete_customer?id=${row.id}" class="text-red-600 hover:underline" onclick="return confirm('Are you sure?')">Delete</a>
        </td>
      </tr>`;
  });

  const totalPages = Math.ceil(filteredData.length / recordsPerPage);
  paginationDiv.innerHTML = '';
  for(let i=1;i<=totalPages;i++){
    paginationDiv.innerHTML += `<button onclick="gotoPage(${i})" class="px-2 py-1 rounded ${i===currentPage?'bg-primary text-white':'bg-gray-200 hover:bg-gray-300'}">${i}</button>`;
  }

  const showingStart = filteredData.length===0?0:start+1;
  const showingEnd = end>filteredData.length?filteredData.length:end;
  tableInfo.innerText = `Showing ${showingStart} to ${showingEnd} of ${filteredData.length} active customers`;
}

function gotoPage(page){
  currentPage = page;
  renderTable();
}

document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('recordsPerPage').addEventListener('change', applyFilters);
document.getElementById('branchFilter').addEventListener('change', applyFilters);
document.getElementById('officerFilter').addEventListener('change', applyFilters);
document.getElementById('typeFilter').addEventListener('change', applyFilters);

// Export Excel
document.getElementById('exportBtn').addEventListener('click', ()=>{
  const ws = XLSX.utils.json_to_sheet(filteredData.map(row=>({
    'Full Name': row.full_name,
    'National ID': row.national_id,
    'Phone': row.phone_number,
    'County': row.county,
    'Branch': row.branch_name,
    'Officer': row.officer_name,
    'Type': row.customer_type,
    'Savings': row.savings_balance,
    'Registered': row.created_on
  })));
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Active Customers");
  XLSX.writeFile(wb, "active_customers.xlsx");
});

renderTable();
</script>
</body>
</html>
