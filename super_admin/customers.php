<?php
session_start();
include 'includes/db.php';

// Fetch branches and officers for filters
$branches = mysqli_query($conn, "SELECT id, name, location FROM branches ORDER BY name ASC");
$officers = mysqli_query($conn, "SELECT id, name FROM admin_users WHERE role='loan_officer' ORDER BY name ASC");

// Fetch all customers with branch and officer info
$query = mysqli_query($conn, "
    SELECT 
        c.*, 
        b.name AS branch_name, 
        o.name AS officer_name,
        c.group_id
    FROM customers c
    LEFT JOIN branches b ON c.branch_id=b.id
    LEFT JOIN admin_users o ON c.loan_officer_id=o.id
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
<title>Customers - Faida</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<?php include 'header.php'; ?>
</head>
<body class="bg-gray-100 font-sans">

<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">

  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Customers</h1>
    <div class="flex gap-2">
      <a href="create_customer" class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded text-sm">+ Add Customer</a>
      <button id="exportBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">Export Excel</button>
    </div>
  </div>

  <!-- Filters & Search -->
  <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-4">
    <input type="text" id="searchInput" placeholder="Search..." class="w-full md:w-1/2 px-4 py-2 border rounded">

    <div class="flex items-center gap-2">
      <label class="text-sm">Branch</label>
      <select id="branchFilter" class="border px-3 py-2 rounded text-sm" onchange="applyFilters()">
        <option value="">All Branches</option>
        <?php while($b = mysqli_fetch_assoc($branches)): ?>
          <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['location']) ?>)</option>
        <?php endwhile; ?>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <label class="text-sm">Officer</label>
      <select id="officerFilter" class="border px-3 py-2 rounded text-sm" onchange="applyFilters()">
        <option value="">All Officers</option>
        <?php while($o = mysqli_fetch_assoc($officers)): ?>
          <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <label class="text-sm">Customer Type</label>
      <select id="typeFilter" class="border px-3 py-2 rounded text-sm" onchange="applyFilters()">
        <option value="">All</option>
        <option value="Individual">Individual</option>
        <option value="Group">Group</option>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <label class="text-sm">Show</label>
      <select id="recordsPerPage" class="border px-3 py-2 rounded text-sm" onchange="applyFilters()">
        <option value="10">10</option>
        <option value="25" selected>25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <!-- Table -->
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm text-left text-gray-700">
      <thead class="bg-gray-200 uppercase text-xs">
        <tr>
          <th class="px-4 py-3">#</th>
          <th class="px-4 py-3">Full Name</th>
          <th class="px-4 py-3">National ID</th>
          <th class="px-4 py-3">Phone</th>
          <th class="px-4 py-3">County</th>
          <th class="px-4 py-3">Branch</th>
          <th class="px-4 py-3">Officer</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Type</th>
          <th class="px-4 py-3">Savings</th>
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
  const start = (currentPage -1) * recordsPerPage;
  const end = start + recordsPerPage;
  const pageData = filteredData.slice(start, end);
  
  tableBody.innerHTML = '';
  pageData.forEach((row,i)=>{
    tableBody.innerHTML += `
      <tr>
        <td class="px-4 py-2">${start+i+1}</td>
        <td class="px-4 py-2">${row.full_name}</td>
        <td class="px-4 py-2">${row.national_id}</td>
        <td class="px-4 py-2">${row.phone_number}</td>
        <td class="px-4 py-2">${row.county}</td>
        <td class="px-4 py-2">${row.branch_name||'-'}</td>
        <td class="px-4 py-2">${row.officer_name}</td>
        <td class="px-4 py-2">${row.status}</td>
        <td class="px-4 py-2">${row.customer_type}</td>
        <td class="px-4 py-2">KSh ${row.savings_balance}</td>
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
  tableInfo.innerText = `Showing ${showingStart} to ${showingEnd} of ${filteredData.length} entries`;
}

function gotoPage(page){
  currentPage = page;
  renderTable();
}

document.getElementById('searchInput').addEventListener('input',applyFilters);
document.getElementById('recordsPerPage').addEventListener('change',applyFilters);
document.getElementById('branchFilter').addEventListener('change',applyFilters);
document.getElementById('officerFilter').addEventListener('change',applyFilters);
document.getElementById('typeFilter').addEventListener('change',applyFilters);

// --- Export Excel ---
document.getElementById('exportBtn').addEventListener('click', ()=>{
  const ws = XLSX.utils.json_to_sheet(filteredData.map(row=>{
    return {
      'Full Name': row.full_name,
      'National ID': row.national_id,
      'Phone': row.phone_number,
      'County': row.county,
      'Branch': row.branch_name,
      'Officer': row.officer_name,
      'Status': row.status,
      'Customer Type': row.customer_type,
      'Savings': row.savings_balance,
      'Registered': row.created_on
    }
  }));
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Customers");
  XLSX.writeFile(wb, "customers.xlsx");
});

renderTable();
</script>
</body>
</html>
