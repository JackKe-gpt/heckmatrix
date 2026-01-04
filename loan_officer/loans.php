<?php 
ob_start();
session_start();
include '../includes/db.php'; 
include 'header.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'loan_officer') {
    header("Location: ../index");
    exit;
}

// Fetch loans data
$branch_id = $_SESSION['admin']['branch_id'];
$loans_data = [];
$query = mysqli_query($conn, "
    SELECT loans.*, CONCAT(c.first_name,' ',c.middle_name,' ',c.surname) AS customer_name,
           c.phone_number, p.product_name
    FROM loans
    JOIN customers c ON loans.customer_id=c.id
    JOIN loan_products p ON loans.product_id=p.id
    WHERE c.branch_id='$branch_id'
    ORDER BY loans.id DESC
");

function fetch_value($query, $key) {
    global $conn;
    $result = mysqli_fetch_assoc(mysqli_query($conn, $query));
    return $result[$key] ?? 0;
}

while($row = mysqli_fetch_assoc($query)) {
    $loan_id = $row['id'];
    $total_repayable = floatval($row['total_repayable']);
    $total_paid = fetch_value("SELECT IFNULL(SUM(principal_amount),0) AS total_paid FROM loan_payments WHERE loan_id='$loan_id'", 'total_paid');
    $outstanding = $total_repayable - $total_paid;

    $status = strtolower(trim($row['status'] ?? ''));
    if($total_paid >= $total_repayable && $total_repayable>0) $status_display = "Fully Paid";
    elseif($status==='pending') $status_display="Pending";
    elseif($status==='approved') $status_display="Approved";
    elseif($status==='pending disbursement') $status_display="Pending Disbursement";
    elseif($status==='active') $status_display="Active";
    elseif($status==='rejected') $status_display="Rejected";
    else $status_display="Unknown";

    $row['total_paid'] = $total_paid;
    $row['outstanding'] = $outstanding;
    $row['status_display'] = $status_display;
    $loans_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All Loans – Faida SACCO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .brand { color: #15a362; }
  th, td { white-space: nowrap; }
  .loan-table th { position: sticky; top: 0; background: #f9fafb; z-index: 10; }
  /* Small screens: show only essential columns */
  @media (max-width: 768px) {
    .desktop-only { display: none; }
    .loan-table td, .loan-table th { font-size: 0.75rem; padding: 0.5rem; }
  }
</style>
</head>
<body class="bg-gray-100 p-4 sm:p-6 font-sans">

<div class="max-w-full mx-auto">

  <!-- Filters -->
  <div class="flex flex-col sm:flex-row flex-wrap items-center gap-3 mb-4">
    <div class="flex items-center gap-2">
      <label class="text-sm brand font-medium">Filter by Status:</label>
      <select id="statusFilter" onchange="filterLoans()" class="px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-green-400">
        <option value="">-- All --</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="pending disbursement">Pending Disbursement</option>
        <option value="active">Active</option>
        <option value="rejected">Rejected</option>
        <option value="fully paid">Fully Paid</option>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <label class="text-sm brand font-medium">Loans Per Page:</label>
      <select id="perPage" onchange="paginate()" class="px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-green-400">
        <option value="10">10</option>
        <option value="20">20</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <!-- Responsive Table -->
  <div class="overflow-x-auto rounded-lg shadow border bg-white">
    <table class="min-w-full text-left text-gray-700 border-collapse loan-table">
      <thead class="bg-gray-50 text-xs sm:text-sm uppercase text-gray-500">
        <tr>
          <th class="px-3 py-2 border-b">#</th>
          <th class="px-3 py-2 border-b">Loan ID</th>
          <th class="px-3 py-2 border-b">Customer</th>
          <th class="px-3 py-2 border-b">Phone</th>
          <th class="px-3 py-2 border-b desktop-only">Product</th>
          <th class="px-3 py-2 border-b desktop-only">Principal</th>
          <th class="px-3 py-2 border-b desktop-only">Interest</th>
          <th class="px-3 py-2 border-b desktop-only">Term</th>
          <th class="px-3 py-2 border-b desktop-only">Weekly</th>
          <th class="px-3 py-2 border-b desktop-only">Total</th>
          <th class="px-3 py-2 border-b desktop-only">Paid</th>
          <th class="px-3 py-2 border-b desktop-only">Outstanding</th>
          <th class="px-3 py-2 border-b">Status</th>
          <th class="px-3 py-2 border-b desktop-only">Created</th>
          <th class="px-3 py-2 border-b desktop-only">Updated</th>
          <th class="px-3 py-2 border-b text-center">Action</th>
        </tr>
      </thead>
      <tbody id="loanTableBody" class="text-xs sm:text-sm">
        <?php 
        $i=1; 
        foreach($loans_data as $row):
        ?>
        <tr class="loan-row hover:bg-gray-50 transition" data-status="<?= strtolower($row['status_display']) ?>">
          <td class="px-3 py-2 border-b"><?= $i ?></td>
          <td class="px-3 py-2 border-b">L<?= $row['id'] ?></td>
          <td class="px-3 py-2 border-b font-medium"><?= $row['customer_name'] ?></td>
          <td class="px-3 py-2 border-b"><?= $row['phone_number'] ?></td>
          <td class="px-3 py-2 border-b desktop-only"><?= $row['product_name'] ?></td>
          <td class="px-3 py-2 border-b desktop-only">KES <?= number_format($row['principal_amount']) ?></td>
          <td class="px-3 py-2 border-b desktop-only"><?= $row['interest_rate'] ?>%</td>
          <td class="px-3 py-2 border-b desktop-only"><?= $row['duration_weeks'] ?> wks</td>
          <td class="px-3 py-2 border-b desktop-only">KES <?= number_format($row['weekly_installment']) ?></td>
          <td class="px-3 py-2 border-b desktop-only">KES <?= number_format($row['total_repayable']) ?></td>
          <td class="px-3 py-2 border-b desktop-only">KES <?= number_format($row['total_paid']) ?></td>
          <td class="px-3 py-2 border-b desktop-only">KES <?= number_format($row['outstanding']) ?></td>
          <td class="px-3 py-2 border-b font-semibold text-green-600"><?= $row['status_display'] ?></td>
          <td class="px-3 py-2 border-b desktop-only"><?= date('d M Y',strtotime($row['created_at'])) ?></td>
          <td class="px-3 py-2 border-b desktop-only"><?= date('d M Y',strtotime($row['updated_at'])) ?></td>
          <td class="px-3 py-2 border-b text-center">
            <a href="view_loan?id=<?= $row['id'] ?>" class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700 transition">View</a>
          </td>
        </tr>
        <?php $i++; endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div id="pagination" class="mt-4 flex flex-wrap gap-2"></div>

</div>

<script>
let currentPage=1;

function filterLoans() {
    const status=document.getElementById("statusFilter").value.toLowerCase();
    document.querySelectorAll(".loan-row").forEach(row=>{
        const st=row.dataset.status;
        row.style.display = (!status || status===st)?'table-row':'none';
    });
    currentPage=1;
    paginate();
}

function paginate() {
    const perPage=+document.getElementById("perPage").value;
    const rows=Array.from(document.querySelectorAll(".loan-row")).filter(r=>r.style.display!=='none');
    const totalPages=Math.ceil(rows.length/perPage);
    document.getElementById("pagination").innerHTML='';

    rows.forEach((r,idx)=>{
        if(idx>=(currentPage-1)*perPage && idx<currentPage*perPage){
            r.style.display='table-row';
        }else{
            r.style.display='none';
        }
    });

    for(let p=1;p<=totalPages;p++){
        const btn=document.createElement("button");
        btn.innerText=p;
        btn.className=`px-3 py-1 rounded border ${p===currentPage?'bg-[#15a362] text-white':'bg-white text-gray-700'}`;
        btn.onclick=()=>{currentPage=p; paginate();};
        document.getElementById("pagination").appendChild(btn);
    }
}

window.onload=()=>{ paginate(); filterLoans(); }
</script>

</body>
</html>
