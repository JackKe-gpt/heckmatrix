<?php 
include '../includes/db.php'; 
include 'header.php';

// Helper to fetch a single value
function fetch_value($query, $key) {
  global $conn;
  $result = mysqli_fetch_assoc(mysqli_query($conn, $query));
  return $result[$key] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>All Loans – Faida SACCO</title>
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
  <div class="w-full bg-white p-6 rounded shadow">
    <div class="flex justify-between items-center mb-4">
      <h1 class="text-2xl font-bold brand">All Loans</h1>
      <button onclick="exportToPDF()" class="px-4 py-2 rounded text-white brand-bg">Export to PDF</button>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-4 mb-4">
      <label class="text-sm brand font-medium">Filter by Status:</label>
      <select id="statusFilter" onchange="filterLoans()" class="px-3 py-2 border rounded">
        <option value="">-- All --</option>
        <option value="Pending">Pending</option>
        <option value="Approved">Approved</option>
        <option value="Pending Disbursement">Pending Disbursement</option>
        <option value="Active">Active</option>
        <option value="Rejected">Rejected</option>
        <option value="Fully Paid">Fully Paid</option>
      </select>

      <label class="text-sm brand font-medium">Loans Per Page:</label>
      <select id="perPage" onchange="paginate()" class="px-3 py-2 border rounded">
        <option value="10">10</option>
        <option value="20">20</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>

    <!-- Loan Table -->
     <div id="loanTableWrapper" class="w-full overflow-x-auto">
  <table class="w-full text-xs sm:text-sm text-left text-gray-700 border">
    <thead class="bg-gray-100 uppercase text-[10px] sm:text-xs">
      <tr>
        <th class="px-2 sm:px-4 py-2">#</th>
        <th class="px-2 sm:px-4 py-2">Loan ID</th>
        <th class="px-2 sm:px-4 py-2">Customer</th>
        <th class="px-2 sm:px-4 py-2">Phone</th>
        <th class="px-2 sm:px-4 py-2">Product</th>
        <th class="px-2 sm:px-4 py-2">Principal</th>
        <th class="px-2 sm:px-4 py-2">Interest</th>
        <th class="px-2 sm:px-4 py-2">Term</th>
        <th class="px-2 sm:px-4 py-2">Weekly</th>
        <th class="px-2 sm:px-4 py-2">Total</th>
        <th class="px-2 sm:px-4 py-2">Paid</th>
        <th class="px-2 sm:px-4 py-2">Outstanding</th>
        <th class="px-2 sm:px-4 py-2">Status</th>
        <th class="px-2 sm:px-4 py-2">Created</th>
        <th class="px-2 sm:px-4 py-2">Updated</th>
      </tr>
    </thead>
        <tbody id="loanTableBody">
          <?php
            $query = mysqli_query($conn, "
              SELECT loans.*, 
                CONCAT(c.first_name, ' ', c.middle_name, ' ', c.surname) AS customer_name,
                c.phone_number,
                p.product_name
              FROM loans 
              JOIN customers c ON loans.customer_id = c.id
              JOIN loan_products p ON loans.product_id = p.id
              ORDER BY loans.id DESC
            ");
            $i = 1;
            while ($row = mysqli_fetch_assoc($query)) {
              $loan_id = $row['id'];
              $total_repayable = floatval($row['total_repayable']);
              $total_paid = fetch_value("
                SELECT IFNULL(SUM(principal_amount), 0) AS total_paid 
                FROM loan_payments WHERE loan_id = '$loan_id'
              ", 'total_paid');
              $outstanding = $total_repayable - $total_paid;

              // Normalize status
              $raw_status = strtolower(trim($row['status'] ?? ''));
              if ($total_paid >= $total_repayable && $total_repayable > 0) {
                $status_display = "Fully Paid";
              } elseif ($raw_status === 'pending') {
                $status_display = "Pending";
              } elseif ($raw_status === 'approved') {
                $status_display = "Approved";
              } elseif ($raw_status === 'pending disbursement') {
                $status_display = "Pending Disbursement";
              } elseif ($raw_status === 'active') {
                $status_display = "Active";
              } elseif ($raw_status === 'rejected') {
                $status_display = "Rejected";
              } else {
                $status_display = "Unknown";
              }

              echo "<tr class='loan-row' data-status='" . strtolower($status_display) . "'>
                <td class='px-4 py-2'>{$i}</td>
                <td class='px-4 py-2'>L{$row['id']}</td>
                <td class='px-4 py-2'>{$row['customer_name']}</td>
                <td class='px-4 py-2'>{$row['phone_number']}</td>
                <td class='px-4 py-2'>{$row['product_name']}</td>
                <td class='px-4 py-2'>KES " . number_format($row['principal_amount']) . "</td>
                <td class='px-4 py-2'>{$row['interest_rate']}%</td>
                <td class='px-4 py-2'>{$row['duration_weeks']} weeks</td>
                <td class='px-4 py-2'>KES " . number_format($row['weekly_installment']) . "</td>
                <td class='px-4 py-2'>KES " . number_format($row['total_repayable']) . "</td>
                <td class='px-4 py-2'>KES " . number_format($total_paid) . "</td>
                <td class='px-4 py-2'>KES " . number_format($outstanding) . "</td>
                <td class='px-4 py-2 font-semibold'>$status_display</td>
                <td class='px-4 py-2'>" . date('d M Y', strtotime($row['created_at'])) . "</td>
                <td class='px-4 py-2'>" . date('d M Y', strtotime($row['updated_at'])) . "</td>
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
      document.querySelectorAll(".loan-row").forEach(row => {
        const st = row.dataset.status;
        row.style.display = (!status || status === st) ? '' : 'none';
      });
      currentPage = 1;
      paginate();
    }

    function paginate() {
      const perPage = +document.getElementById("perPage").value;
      const rows = Array.from(document.querySelectorAll(".loan-row"))
          .filter(r => r.style.display !== 'none');
      const totalPages = Math.ceil(rows.length / perPage);
      document.getElementById("pagination").innerHTML = "";

      rows.forEach((r, idx) => {
        r.style.display = (idx >= (currentPage-1)*perPage && idx < currentPage*perPage) ? "" : "none";
      });

      for (let p = 1; p <= totalPages; p++) {
        const btn = document.createElement("button");
        btn.innerText = p;
        btn.className = `px-3 py-1 rounded border ${p===currentPage?'bg-[#15a362] text-white':'bg-white text-gray-700'}`;
        btn.onclick = () => { currentPage = p; paginate(); };
        document.getElementById("pagination").appendChild(btn);
      }
    }

    function exportToPDF() {
      const el = document.getElementById("loanTableWrapper");
      html2pdf().set({
        margin: 0.3, jsPDF:{unit:'in',format:'a3',orientation:'landscape'},
        image:{type:'jpeg',quality:0.98}, html2canvas:{scale:2},
        filename:'loans_list.pdf'
      }).from(el).save();
    }

    window.onload = paginate;
  </script>
</body>
</html>
