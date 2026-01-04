<?php
session_start();
include '../includes/db.php';

// Only super admin
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'super_admin') {
    header("Location: ../index");
    exit;
}

// Fetch branches
$branches = mysqli_query($conn, "SELECT id, name, location FROM branches ORDER BY name ASC");

// Get selected branch
$selected_branch = $_GET['branch_id'] ?? '';

// Branch filter
$branch_condition = $selected_branch ? "WHERE c.branch_id='$selected_branch'" : "";

// Fetch savings transactions
$query = mysqli_query($conn, "
    SELECT s.*, c.first_name, c.middle_name, c.surname, c.customer_code, c.branch_id, c.savings_balance, b.name AS branch_name
    FROM savings_transactions s
    JOIN customers c ON s.customer_id = c.id
    LEFT JOIN branches b ON c.branch_id = b.id
    $branch_condition
    ORDER BY s.transaction_date DESC
");

// Prepare data
$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $row['full_name'] = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['surname']);
    $row['date'] = date('d M Y', strtotime($row['transaction_date']));
    $data[] = $row;
}

// Calculate branch totals
$branch_totals_query = mysqli_query($conn, "
    SELECT b.id AS branch_id, b.name AS branch_name, COUNT(s.id) AS transactions_count, SUM(s.amount) AS total_savings
    FROM branches b
    LEFT JOIN customers c ON c.branch_id = b.id
    LEFT JOIN savings_transactions s ON s.customer_id = c.id
    GROUP BY b.id
    ORDER BY b.name
");

$branch_totals = [];
while ($row = mysqli_fetch_assoc($branch_totals_query)) {
    $branch_totals[] = $row;
}

// Overall totals
$overall_total_savings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount) AS total FROM savings_transactions"))['total'] ?? 0;
$overall_total_transactions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM savings_transactions"))['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All Savings - Faida</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        primary: '#15a362',
        'primary-dark': '#128054',
        'primary-light': '#e6f7f0',
      }
    }
  }
}
</script>
<?php include 'header.php'; ?>
</head>
<body class="bg-gray-100 font-sans">

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

  <!-- Header -->
  <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <h1 class="text-3xl font-bold text-primary">Savings Dashboard</h1>
    <div class="flex gap-3 flex-wrap">
      <input type="text" id="searchInput" placeholder="Search customers..." 
        class="px-4 py-2 border rounded w-full md:w-72 focus:ring-primary focus:border-primary">
      <select id="branchFilter" 
        class="border px-3 py-2 rounded text-sm focus:ring-primary focus:border-primary" onchange="filterBranch()">
        <option value="">All Branches</option>
        <?php foreach($branches as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $selected_branch==$b['id']?'selected':'' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select id="recordsPerPage" 
        class="border px-3 py-2 rounded text-sm focus:ring-primary focus:border-primary">
        <option value="10">10</option>
        <option value="25" selected>25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <!-- Totals Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <div class="bg-white p-5 rounded-xl shadow border-t-4 border-primary hover:shadow-lg transition">
      <p class="text-sm text-gray-500">Overall Transactions</p>
      <p class="text-3xl font-bold text-primary-dark"><?= number_format($overall_total_transactions) ?></p>
    </div>
    <div class="bg-white p-5 rounded-xl shadow border-t-4 border-primary-dark hover:shadow-lg transition">
      <p class="text-sm text-gray-500">Overall Savings</p>
      <p class="text-3xl font-bold text-primary-dark">KSh <?= number_format($overall_total_savings,2) ?></p>
    </div>
    <?php foreach ($branch_totals as $b): ?>
    <div class="bg-white p-5 rounded-xl shadow border-t-4 border-green-500 hover:shadow-lg transition">
      <p class="text-sm text-gray-500"><?= htmlspecialchars($b['branch_name']) ?> Transactions</p>
      <p class="text-lg font-semibold text-gray-800 mt-1">KSh <?= number_format($b['total_savings'] ?? 0,2) ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Table -->
  <div class="overflow-x-auto rounded-xl shadow bg-white">
    <table class="min-w-full text-sm text-left text-gray-700">
      <thead class="bg-primary text-white sticky top-0 shadow-md z-10">
        <tr>
          <th class="px-4 py-3">#</th>
          <th class="px-4 py-3">Customer</th>
          <th class="px-4 py-3">Code</th>
          <th class="px-4 py-3">Branch</th>
          <th class="px-4 py-3">Amount</th>
          <th class="px-4 py-3">Balance</th>
          <th class="px-4 py-3">Method</th>
          <th class="px-4 py-3">Date</th>
        </tr>
      </thead>
      <tbody id="tableBody" class="divide-y divide-gray-200"></tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="mt-4 flex flex-col md:flex-row justify-between items-center text-sm text-gray-600 gap-2">
    <div id="tableInfo">Showing 0 to 0 of 0 entries</div>
    <div class="flex flex-wrap items-center gap-2" id="pagination"></div>
  </div>
</div>

<script>
const rawData = <?php echo json_encode($data); ?>;
const tableBody = document.getElementById('tableBody');
const paginationDiv = document.getElementById('pagination');
const tableInfo = document.getElementById('tableInfo');
const searchInput = document.getElementById('searchInput');
const recordsPerPageSelect = document.getElementById('recordsPerPage');
const branchFilter = document.getElementById('branchFilter');

let currentPage = 1;
let filteredData = [...rawData];
let recordsPerPage = parseInt(recordsPerPageSelect.value);

function renderTable() {
  const start = (currentPage-1)*recordsPerPage;
  const end = start+recordsPerPage;
  const pageData = filteredData.slice(start,end);

  tableBody.innerHTML = '';
  pageData.forEach((row,i)=>{
    tableBody.innerHTML += `
      <tr class="hover:bg-gray-50">
        <td class='px-4 py-3'>${start+i+1}</td>
        <td class='px-4 py-3 font-medium text-gray-900'>${row.full_name}</td>
        <td class='px-4 py-3'>${row.customer_code}</td>
        <td class='px-4 py-3'>${row.branch_name||'-'}</td>
        <td class='px-4 py-3 text-primary-dark font-semibold'>KSh ${Number(row.amount).toLocaleString()}</td>
        <td class='px-4 py-3'>KSh ${Number(row.savings_balance).toLocaleString()}</td>
        <td class='px-4 py-3'>${row.method}</td>
        <td class='px-4 py-3'>${row.date}</td>
      </tr>`;
  });

  const totalPages = Math.ceil(filteredData.length/recordsPerPage);
  paginationDiv.innerHTML = '';
  for(let i=1;i<=totalPages;i++){
    paginationDiv.innerHTML += `<button onclick="gotoPage(${i})" class="px-3 py-1 rounded ${i===currentPage?'bg-primary text-white':'bg-gray-200 hover:bg-gray-300'}">${i}</button>`;
  }

  const showingStart = filteredData.length===0?0:start+1;
  const showingEnd = end>filteredData.length?filteredData.length:end;
  tableInfo.innerText = `Showing ${showingStart} to ${showingEnd} of ${filteredData.length} entries`;
}

function gotoPage(page){ currentPage=page; renderTable(); }

searchInput.addEventListener('input',()=>{
  const keyword = searchInput.value.toLowerCase();
  filteredData = rawData.filter(row=>Object.values(row).some(val=>String(val).toLowerCase().includes(keyword)));
  currentPage=1;
  renderTable();
});

recordsPerPageSelect.addEventListener('change',()=>{
  recordsPerPage=parseInt(recordsPerPageSelect.value);
  currentPage=1;
  renderTable();
});

function filterBranch(){
  const branchId = branchFilter.value;
  filteredData = branchId?rawData.filter(row=>row.branch_id==branchId):[...rawData];
  currentPage=1;
  renderTable();
}

renderTable();
</script>
</body>
</html>
