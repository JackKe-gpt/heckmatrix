<?php
session_start();
include '../includes/db.php';

// Restrict access to loan officers only
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'loan_officer') {
    header("Location: ../index");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];
$loan_officer_id = $_SESSION['admin']['id'];

// Fetch all groups of this officer
$group_query = mysqli_query($conn, "SELECT id, group_name FROM groups WHERE loan_officer_id='$loan_officer_id' ORDER BY group_name");
$groups = [];
while($g = mysqli_fetch_assoc($group_query)){
    $groups[] = $g;
}

// Fetch customers assigned to this officer
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
        c.group_id,
        c.savings_balance,
        COUNT(l.id) AS total_loans,
        c.created_at
    FROM customers c
    LEFT JOIN loans l ON l.customer_id = c.id
    WHERE c.branch_id = '$branch_id'
      AND c.loan_officer_id = '$loan_officer_id'
      AND c.status IN ('Active', 'Inactive', 'Pending', 'Blacklisted')
    GROUP BY c.id
    ORDER BY c.created_at DESC
");

$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $row['full_name'] = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['surname']);
    $row['type'] = $row['group_id'] ? 'Group' : 'Individual';
    $row['savings_balance'] = number_format($row['savings_balance'] ?? 0, 2);
    $row['created_on'] = date('d M Y', strtotime($row['created_at']));

    switch (strtolower($row['status'])) {
        case 'active': $row['status_color'] = 'bg-green-100 text-green-700'; break;
        case 'pending': $row['status_color'] = 'bg-yellow-100 text-yellow-700'; break;
        case 'inactive': $row['status_color'] = 'bg-gray-200 text-gray-700'; break;
        case 'blacklisted': $row['status_color'] = 'bg-red-100 text-red-700'; break;
        default: $row['status_color'] = 'bg-gray-100 text-gray-700'; break;
    }
    $row['status_label'] = ucfirst($row['status']);
    $data[] = $row;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Customers - Faida</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>

<style>
/* Mobile – hide table, show cards */
@media (max-width: 768px) {
    .desktop-table { display: none; }
}

/* Desktop – show table, hide cards */
@media (min-width: 769px) {
    .mobile-card { display: none; }
}
</style>
</head>

<body class="bg-gray-100 font-sans p-6">

<div class="bg-white p-6 rounded-2xl shadow-lg max-w-full mx-auto">

    <!-- TITLE + ADD CUSTOMER BUTTON -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Customers</h1>
        <a href="create_customer" 
           class="inline-block px-5 py-2 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition">
            Add Customer
        </a>
    </div>

    <!-- FILTERS -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <input type="text" id="searchInput" placeholder="Search customers..."
            class="w-full px-4 py-2 border rounded-lg focus:ring-blue-500 focus:border-blue-500">

        <select id="typeFilter" class="border px-3 py-2 rounded-lg">
            <option value="all">All Types</option>
            <option value="Individual">Individual</option>
            <option value="Group">Group</option>
        </select>

        <select id="groupFilter" class="border px-3 py-2 rounded-lg">
            <option value="all">All Groups</option>
            <?php foreach($groups as $g): ?>
                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="recordsPerPage" class="border px-3 py-2 rounded-lg">
            <option value="10">10</option>
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
    </div>

    <!-- DESKTOP TABLE -->
    <div class="overflow-x-auto desktop-table rounded-xl border border-gray-200">
        <table class="min-w-full text-sm text-left text-gray-700">
            <thead class="bg-gray-200 text-gray-700 uppercase text-xs tracking-wider">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Full Name</th>
                    <th class="px-4 py-3">Gender</th>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Phone</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-center">Loans</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3 text-right">Savings</th>
                    <th class="px-4 py-3">Registered</th>
                    <th class="px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="customerTableBody" class="bg-white divide-y divide-gray-100"></tbody>
        </table>
    </div>

    <!-- MOBILE CARDS -->
    <div id="mobileCards" class="space-y-4 mobile-card"></div>

    <!-- PAGINATION -->
    <div class="mt-6 flex flex-col md:flex-row justify-between items-center text-sm text-gray-600 gap-2">
        <div id="tableInfo">Showing 0 to 0 of 0 entries</div>
        <div id="pagination" class="flex flex-wrap items-center gap-2"></div>
    </div>

</div>

<script>
const rawData = <?php echo json_encode($data); ?>;

const tableBody = document.getElementById('customerTableBody');
const mobileCards = document.getElementById('mobileCards');
const paginationDiv = document.getElementById('pagination');
const tableInfo = document.getElementById('tableInfo');

const searchInput = document.getElementById('searchInput');
const typeFilter = document.getElementById('typeFilter');
const groupFilter = document.getElementById('groupFilter');
const recordsPerPageSelect = document.getElementById('recordsPerPage');

let currentPage = 1;
let filteredData = [...rawData];
let recordsPerPage = parseInt(recordsPerPageSelect.value);

function renderTable() {
    const start = (currentPage - 1) * recordsPerPage;
    const end = start + recordsPerPage;
    const pageData = filteredData.slice(start, end);

    // DESKTOP TABLE CONTENT
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
            <td class='px-4 py-3 text-center'>${row.total_loans}</td>
            <td class='px-4 py-3'>${row.type}</td>
            <td class='px-4 py-3 text-right font-semibold'>${row.savings_balance}</td>
            <td class='px-4 py-3'>${row.created_on}</td>
            <td class='px-4 py-3 text-center'>
                <a href='view_customer?id=${row.id}' class='text-blue-600 hover:text-blue-800'>
                    <i data-lucide="eye" class="w-4 h-4"></i>
                </a>
            </td>
        </tr>`;
    });

    // MOBILE CARDS CONTENT
    mobileCards.innerHTML = '';
    pageData.forEach(row => {
        mobileCards.innerHTML += `
        <div class="p-4 bg-white rounded-xl shadow border">
            <div class="flex justify-between items-center mb-2">
                <h2 class="text-lg font-semibold text-gray-800">${row.full_name}</h2>
                <a href="view_customer?id=${row.id}">
                    <i data-lucide="eye" class="w-5 h-5 text-blue-600"></i>
                </a>
            </div>

            <div class="grid grid-cols-2 text-sm gap-2">
                <div><span class="font-semibold">Phone:</span> ${row.phone_number}</div>
                <div><span class="font-semibold">ID:</span> ${row.national_id}</div>
                <div><span class="font-semibold">Type:</span> ${row.type}</div>
                <div><span class="font-semibold">Loans:</span> ${row.total_loans}</div>
                <div><span class="font-semibold">Savings:</span> ${row.savings_balance}</div>
                <div><span class="font-semibold">Registered:</span> ${row.created_on}</div>
            </div>

            <span class="px-2 py-1 mt-3 inline-block rounded-full text-xs font-semibold ${row.status_color}">
                ${row.status_label}
            </span>
        </div>`;
    });

    // PAGINATION
    const totalPages = Math.ceil(filteredData.length / recordsPerPage);
    paginationDiv.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
        paginationDiv.innerHTML += `
        <button onclick="gotoPage(${i})"
            class="px-3 py-1 rounded-lg ${i===currentPage?'bg-blue-600 text-white':'bg-gray-200 hover:bg-gray-300'}">
            ${i}
        </button>`;
    }

    const showingStart = filteredData.length === 0 ? 0 : start + 1;
    const showingEnd = end > filteredData.length ? filteredData.length : end;
    tableInfo.innerText = `Showing ${showingStart} to ${showingEnd} of ${filteredData.length} entries`;

    lucide.createIcons();
}

function applyFilters() {
    const type = typeFilter.value;
    const groupId = groupFilter.value;

    filteredData = rawData.filter(row => {
        let typeMatch = (type === 'all') || (row.type === type);
        let groupMatch = (groupId === 'all') || (String(row.group_id) === groupId);
        return typeMatch && groupMatch;
    });

    const keyword = searchInput.value.toLowerCase();
    if (keyword) {
        filteredData = filteredData.filter(row =>
            Object.values(row).some(val => String(val).toLowerCase().includes(keyword))
        );
    }

    currentPage = 1;
    renderTable();
}

function gotoPage(page) {
    currentPage = page;
    renderTable();
}

recordsPerPageSelect.addEventListener('change', () => {
    recordsPerPage = parseInt(recordsPerPageSelect.value);
    currentPage = 1;
    renderTable();
});

searchInput.addEventListener('input', applyFilters);
typeFilter.addEventListener('change', applyFilters);
groupFilter.addEventListener('change', applyFilters);

renderTable();
</script>

</body>
</html>
