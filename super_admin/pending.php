<?php 
require_once 'auth.php';
require_login();

include 'includes/db.php'; 
include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pending Disbursement Loans – Faida SACCO</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <style>
    .brand { color: #15a362; }
    .brand-bg { background-color: #15a362; }
    .brand-bg:hover { background-color: #128d51; }
  </style>
</head>
<body class="bg-gray-100 p-6 font-sans">
 <div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <div class="flex justify-between items-center mb-4">
      <h1 class="text-2xl font-bold brand">Pending Disbursement Loans</h1>
      <button onclick="exportToPDF()" class="px-4 py-2 rounded text-white brand-bg hover:brand-bg-hover">Export to PDF</button>
    </div>

    <div class="flex flex-wrap items-center gap-4 mb-4">
      <label class="text-sm brand font-medium">Filter by Status:</label>
      <select id="statusFilter" onchange="filterLoans()" class="px-3 py-2 border rounded">
        <option value="">-- All --</option>
        <option value="Pending" selected>Pending</option>
        <option value="Pending Disbursement">Pending Disbursement</option>
        <option value="Approved">Approved</option>
        <option value="Disbursed">Disbursed</option>
        <option value="Inactive">Inactive</option>
        <option value="Active">Active</option>
      </select>

      <label class="text-sm brand font-medium">Loans Per Page:</label>
      <select id="perPage" onchange="paginate()" class="px-3 py-2 border rounded">
        <option value="10">10</option>
        <option value="20">20</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>

    <div id="loanTableWrapper">
      <table id="loanTable" class="min-w-full text-sm text-left text-gray-700 border">
        <thead class="bg-gray-100 uppercase text-xs">
          <tr>
            <th class="px-4 py-2">#</th>
            <th class="px-4 py-2">Customer</th>
            <th class="px-4 py-2">Product</th>
            <th class="px-4 py-2">Principal Amount</th>
            <th class="px-4 py-2">Weekly Installment</th>
            <th class="px-4 py-2">Total Repayable</th>
            <th class="px-4 py-2">Balance</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2">Created</th>
          </tr>
        </thead>
        <tbody id="loanTableBody">
          <?php
            $query = mysqli_query($conn, "
              SELECT 
                loans.*, 
                CONCAT(c.first_name, ' ', c.middle_name, ' ', c.surname) AS customer_name,
                p.product_name,
                IFNULL(SUM(lp.principal_amount), 0) AS total_paid
              FROM loans
              JOIN customers c ON loans.customer_id = c.id
              JOIN loan_products p ON loans.product_id = p.id
              LEFT JOIN loan_payments lp ON lp.loan_id = loans.id
              WHERE loans.status IN ('Pending', 'Pending Disbursement')
              GROUP BY loans.id
              ORDER BY loans.id DESC
            ");
            $i = 1;
            while ($row = mysqli_fetch_assoc($query)) {
              $balance = $row['total_repayable'] - $row['total_paid'];
              echo "<tr class='loan-row' data-status='{$row['status']}'>
                <td class='px-4 py-2'>{$i}</td>
                <td class='px-4 py-2'>{$row['customer_name']}</td>
                <td class='px-4 py-2'>{$row['product_name']}</td>
                <td class='px-4 py-2'>KES " . number_format($row['principal_amount'], 2) . "</td>
                <td class='px-4 py-2'>KES " . number_format($row['weekly_installment'], 2) . "</td>
                <td class='px-4 py-2'>KES " . number_format($row['total_repayable'], 2) . "</td>
                <td class='px-4 py-2'>KES " . number_format($balance, 2) . "</td>
                <td class='px-4 py-2'>{$row['status']}</td>
                <td class='px-4 py-2'>" . date('d M Y', strtotime($row['created_at'])) . "</td>
              </tr>";
              $i++;
            }
          ?>
        </tbody>
      </table>
    </div>

    <div id="pagination" class="mt-6 flex flex-wrap gap-2"></div>
  </div>

  <script>
    let currentPage = 1;

    function filterLoans() {
      const status = document.getElementById("statusFilter").value.toLowerCase();
      const rows = document.querySelectorAll(".loan-row");
      rows.forEach(row => {
        const rowStatus = row.dataset.status.toLowerCase();
        row.style.display = (!status || rowStatus === status) ? '' : 'none';
      });
      paginate(); // refresh pagination
    }

    function paginate() {
      const perPage = parseInt(document.getElementById("perPage").value);
      const allRows = Array.from(document.querySelectorAll(".loan-row")).filter(r => r.style.display !== "none");
      const totalPages = Math.ceil(allRows.length / perPage);
      const pagination = document.getElementById("pagination");
      pagination.innerHTML = "";

      allRows.forEach((row, i) => {
        row.style.display = (i >= (currentPage - 1) * perPage && i < currentPage * perPage) ? "" : "none";
      });

      for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement("button");
        btn.innerText = i;
        btn.className = `px-3 py-1 rounded border ${i === currentPage ? 'bg-[#15a362] text-white' : 'bg-white text-gray-700'}`;
        btn.onclick = () => { currentPage = i; paginate(); };
        pagination.appendChild(btn);
      }
    }

    function exportToPDF() {
      const element = document.getElementById("loanTableWrapper");
      const opt = {
        margin: 0.3,
        filename: 'pending_disbursement_loans.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'a3', orientation: 'landscape' }
      };
      html2pdf().set(opt).from(element).save();
    }

    window.onload = () => {
      paginate();
      // Set filter dropdown default value to "Pending"
      document.getElementById("statusFilter").value = "Pending";
      filterLoans();
    }
  </script>
</body>
</html>
