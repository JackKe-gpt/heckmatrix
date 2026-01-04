<?php
session_start();
include '../includes/db.php';
include 'header.php';

// Restrict to loan officers
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'branch_manager') {
    header("Location: ../index");
    exit;
}

$customer_id = (int)($_GET['id'] ?? 0);

// Fetch customer with additional details
$stmt = $conn->prepare("
    SELECT 
        c.*, 
        b.name AS branch_name,
        a.name AS officer_name
    FROM customers c
    LEFT JOIN branches b 
        ON c.branch_id = b.id
    LEFT JOIN admin_users a 
        ON c.loan_officer_id = a.id
    WHERE c.id = ?
");

$stmt->bind_param("i", $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) die("Customer not found.");

// Helper function to check and get image path
function getImagePath($db_path, $customer_code, $type, $subfolder = 'customer') {
    if (empty($db_path)) {
        return '../assets/img/default-' . $type . '.png';
    }
    
    // Check if path is absolute URL
    if (filter_var($db_path, FILTER_VALIDATE_URL)) {
        return $db_path;
    }
    
    // Check if path already has '../uploads/' prefix
    if (strpos($db_path, '../uploads/') === 0) {
        // Check if file exists
        if (file_exists($db_path)) {
            return $db_path;
        }
        // Try without the ../
        $alt_path = substr($db_path, 3); // Remove ../
        if (file_exists($alt_path)) {
            return $alt_path;
        }
    }
    
    // Check if it's just a filename or relative path
    $possible_paths = [
        '../uploads/' . $subfolder . '/' . $db_path,
        'uploads/' . $subfolder . '/' . $db_path,
        '../' . $db_path,
        $db_path,
        'uploads/' . $db_path
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Try to construct path from customer code
    if (!empty($customer_code)) {
        $filename_patterns = [
            $customer_code . '_' . $type . '.jpg',
            $customer_code . '_' . $type . '.jpeg',
            $customer_code . '_' . $type . '.png',
            $customer_code . '_' . $type . '.gif',
            $customer_code . '_' . $type
        ];
        
        $directories = [
            '../uploads/' . $subfolder . '/',
            'uploads/' . $subfolder . '/',
            '../uploads/',
            'uploads/'
        ];
        
        foreach ($directories as $dir) {
            foreach ($filename_patterns as $pattern) {
                $path = $dir . $pattern;
                if (file_exists($path)) {
                    return $path;
                }
            }
        }
    }
    
    // Return default image if nothing found
    return '../assets/img/default-' . $type . '.png';
}

// Get image paths using consistent naming
$customer_code = $customer['customer_code'] ?? '';

// Customer images
$img_customer_photo = getImagePath(
    $customer['customer_photo'] ?? '',
    $customer_code,
    'profile'
);

$img_customer_id_front = getImagePath(
    $customer['customer_id_front'] ?? '',
    $customer_code,
    'id_front'
);

$img_customer_id_back = getImagePath(
    $customer['customer_id_back'] ?? '',
    $customer_code,
    'id_back'
);

// Guarantor images
$img_guarantor_photo = getImagePath(
    $customer['guarantor_photo'] ?? '',
    $customer_code . '_guarantor',
    'profile',
    'guarantor'
);

$img_guarantor_id_front = getImagePath(
    $customer['guarantor_id_front'] ?? '',
    $customer_code . '_guarantor',
    'id_front',
    'guarantor'
);

$img_guarantor_id_back = getImagePath(
    $customer['guarantor_id_back'] ?? '',
    $customer_code . '_guarantor',
    'id_back',
    'guarantor'
);

// Savings transactions
$stmt = $conn->prepare("SELECT * FROM savings_transactions WHERE customer_id=? ORDER BY transaction_date DESC");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$savings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_savings = array_sum(array_column($savings, 'amount'));

// Monthly savings trend
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month, SUM(amount) AS total
    FROM savings_transactions
    WHERE customer_id=?
    GROUP BY month ORDER BY month ASC
");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$savings_monthly = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Loans
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT * FROM loans WHERE customer_id=? AND status='active'");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_loans = $total_repayments = $total_arrears = 0;
foreach ($loans as &$loan) {
    $res = $conn->query("SELECT IFNULL(SUM(principal_amount),0) AS total_paid FROM loan_payments WHERE loan_id='{$loan['id']}'");
    $paid_row = $res->fetch_assoc();
    $total_paid = $paid_row['total_paid'] ?? 0;

    $disbursed_date = new DateTime($loan['disbursed_date']);
    $today_date = new DateTime($today);
    $weeks_passed = floor($disbursed_date->diff($today_date)->days/7);

    $expected_payment = min($weeks_passed*$loan['weekly_installment'], $loan['total_repayable']);
    $arrears_amount = max($expected_payment - $total_paid,0);

    $loan['total_paid'] = $total_paid;
    $loan['arrears_amount'] = $arrears_amount;
    $loan['expected_payment'] = $expected_payment;

    $total_loans += $loan['principal_amount'];
    $total_repayments += $total_paid;
    $total_arrears += $arrears_amount;
}

$loanChartData = ['loans'=>$total_loans,'repayments'=>$total_repayments];

// Small helper to safely echo
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Profile - <?= e($customer['first_name'] . ' ' . $customer['surname']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body class="bg-gray-100 font-sans">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

  <!-- Header -->
  <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <h1 class="text-3xl font-bold text-gray-800">Customer Profile</h1>
    <button id="exportBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow">
      Export PDF
    </button>
  </div>

  <!-- Tabs -->
  <div class="bg-white rounded-lg shadow p-4 mb-6 overflow-x-auto">
    <ul class="flex flex-wrap border-b gap-2" id="tabs">
      <li><a href="#" class="tab-link bg-white inline-block py-2 px-4 font-semibold text-green-700 border-l border-t border-r rounded-t" data-tab="personal">Personal</a></li>
      <li><a href="#" class="tab-link inline-block py-2 px-4 text-green-700 hover:text-green-800 font-semibold" data-tab="employment">Employment</a></li>
      <li><a href="#" class="tab-link inline-block py-2 px-4 text-green-700 hover:text-green-800 font-semibold" data-tab="kin">Next of Kin</a></li>
      <li><a href="#" class="tab-link inline-block py-2 px-4 text-green-700 hover:text-green-800 font-semibold" data-tab="guarantor">Guarantor</a></li>
      <li><a href="#" class="tab-link inline-block py-2 px-4 text-green-700 hover:text-green-800 font-semibold" data-tab="photos">Documents & Photos</a></li>
    </ul>

    <div class="tab-content mt-4">

      <!-- Personal -->
      <div id="personal" class="tab-panel grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div><strong>Full Name:</strong> <?= e($customer['first_name'].' '.$customer['middle_name'].' '.$customer['surname']) ?></div>
        <div><strong>ID Number:</strong> <?= e($customer['national_id']) ?></div>
        <div><strong>Phone:</strong> <?= e($customer['phone_number']) ?></div>
        <div><strong>Email:</strong> <?= e($customer['email']) ?></div>
        <div><strong>County:</strong> <?= e($customer['county']) ?></div>
        <div><strong>Status:</strong>
          <span class="px-2 py-1 text-xs rounded-full <?= $customer['status']=='Active'?'bg-green-100 text-green-800':'bg-red-100 text-red-800'?>">
            <?= e($customer['status']) ?>
          </span>
        </div>
        <div><strong>Registered:</strong> <?= date("d M Y", strtotime($customer['created_at'])) ?></div>
      </div>

      <!-- Employment -->
      <div id="employment" class="tab-panel hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div><strong>Employment Status:</strong> <?= e($customer['employment_status'] ?? 'N/A') ?></div>
        <div><strong>Occupation:</strong> <?= e($customer['occupation'] ?? 'N/A') ?></div>
        <div><strong>Employer:</strong> <?= e($customer['employer_name'] ?? 'N/A') ?></div>
        <div><strong>Monthly Income:</strong> KSh <?= number_format($customer['monthly_income'] ?? 0,2) ?></div>
        <div><strong>Account Balance:</strong> KSh <?= number_format($customer['customer_account_balance'] ?? 0,2) ?></div>
        <div><strong>Savings Balance:</strong> KSh <?= number_format($customer['savings_balance'] ?? 0,2) ?></div>
        <div><strong>Total Balance:</strong> KSh <?= number_format($customer['balance'] ?? 0,2) ?></div>
      </div>

      <!-- Next of Kin -->
      <div id="kin" class="tab-panel hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div><strong>Name:</strong> <?= e($customer['next_of_kin_name'] ?? 'N/A') ?></div>
        <div><strong>Relationship:</strong> <?= e($customer['next_of_kin_relationship'] ?? 'N/A') ?></div>
        <div><strong>Phone:</strong> <?= e($customer['next_of_kin_phone'] ?? 'N/A') ?></div>
      </div>

      <!-- Guarantor -->
      <div id="guarantor" class="tab-panel hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div><strong>Full Name:</strong> <?= e($customer['guarantor_first_name'].' '.$customer['guarantor_middle_name'].' '.$customer['guarantor_surname'] ?? 'N/A') ?></div>
        <div><strong>ID Number:</strong> <?= e($customer['guarantor_id_number'] ?? 'N/A') ?></div>
        <div><strong>Phone:</strong> <?= e($customer['guarantor_phone'] ?? 'N/A') ?></div>
        <div><strong>Relationship:</strong> <?= e($customer['guarantor_relationship'] ?? 'N/A') ?></div>
      </div>

      <!-- Documents & Photos -->
      <div id="photos" class="tab-panel hidden grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <?php
        $images = [
          ['label'=>'Passport', 'url'=>$img_customer_photo],
          ['label'=>'ID Front', 'url'=>$img_customer_id_front],
          ['label'=>'ID Back', 'url'=>$img_customer_id_back],
          ['label'=>'Guarantor Passport', 'url'=>$img_guarantor_photo],
          ['label'=>'Guarantor ID Front', 'url'=>$img_guarantor_id_front],
          ['label'=>'Guarantor ID Back', 'url'=>$img_guarantor_id_back],
        ];
        $placeholder = "data:image/svg+xml;utf8," . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="Arial" font-size="18">No image</text></svg>');
        foreach ($images as $img):
          $src = $img['url'] ?? $placeholder;
        ?>
        <div class="bg-white rounded-lg shadow p-2 flex flex-col items-center">
          <div class="w-full h-32 sm:h-28 md:h-24 lg:h-32 overflow-hidden rounded-md flex items-center justify-center">
            <img src="<?= e($src) ?>" data-src="<?= e($img['url']) ?>" alt="<?= e($img['label']) ?>"
                 class="object-cover w-full h-full img-preview cursor-pointer" loading="lazy"
                 data-label="<?= e($img['label']) ?>">
          </div>
          <div class="mt-2 text-xs text-gray-600"><?= e($img['label']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white p-6 rounded-lg shadow flex flex-col items-center">
      <h3 class="text-gray-600 text-sm">Total Savings</h3>
      <p class="text-2xl font-bold text-green-700">KSh <?= number_format($total_savings,2) ?></p>
    </div>
    <div class="bg-white p-6 rounded-lg shadow flex flex-col items-center">
      <h3 class="text-gray-600 text-sm">Total Loans</h3>
      <p class="text-2xl font-bold text-green-700">KSh <?= number_format($total_loans,2) ?></p>
    </div>
    <div class="bg-white p-6 rounded-lg shadow flex flex-col items-center">
      <h3 class="text-gray-600 text-sm">Total Arrears</h3>
      <p class="text-2xl font-bold <?= $total_arrears>0?'text-red-600':'text-green-600'?>">
        KSh <?= number_format($total_arrears,2) ?></p>
    </div>
  </div>

  <!-- Active Loans Table -->
  <div class="bg-white rounded-lg shadow p-4 mb-6 overflow-x-auto">
    <h3 class="text-lg font-semibold text-green-700 mb-4">Active Loans</h3>
    <table class="w-full text-sm text-gray-700">
      <thead class="bg-green-50 text-green-700 uppercase text-xs">
        <tr>
          <th class="px-4 py-2 text-left">Loan ID</th>
          <th class="px-4 py-2 text-left">Principal</th>
          <th class="px-4 py-2 text-left">Total Paid</th>
          <th class="px-4 py-2 text-left">Expected Payment</th>
          <th class="px-4 py-2 text-left">Arrears</th>
          <th class="px-4 py-2 text-left">Disbursed</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php if (!empty($loans)): ?>
        <?php foreach($loans as $loan): ?>
        <tr>
          <td class="px-4 py-2"><?= e($loan['id']) ?></td>
          <td class="px-4 py-2">KSh <?= number_format($loan['principal_amount'],2) ?></td>
          <td class="px-4 py-2">KSh <?= number_format($loan['total_paid'],2) ?></td>
          <td class="px-4 py-2">KSh <?= number_format($loan['expected_payment'],2) ?></td>
          <td class="px-4 py-2 <?= $loan['arrears_amount']>0?'text-red-600':'text-green-600'?>">
            KSh <?= number_format($loan['arrears_amount'],2) ?></td>
          <td class="px-4 py-2"><?= date("d M Y",strtotime($loan['disbursed_date'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="6" class="text-center py-4 text-gray-500">No active loans</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Savings Transactions -->
  <div class="bg-white rounded-lg shadow p-4 mb-6 overflow-x-auto">
    <h3 class="text-lg font-semibold text-green-700 mb-4">Savings Transactions</h3>
    <table class="w-full text-sm text-gray-700">
      <thead class="bg-green-50 text-green-700 uppercase text-xs">
        <tr>
          <th class="px-4 py-2 text-left">Date</th>
          <th class="px-4 py-2 text-left">Amount</th>
          <th class="px-4 py-2 text-left">Method</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php if (!empty($savings)): ?>
        <?php foreach($savings as $s): ?>
        <tr>
          <td class="px-4 py-2"><?= date("d M Y",strtotime($s['transaction_date'])) ?></td>
          <td class="px-4 py-2 font-medium">KSh <?= number_format($s['amount'],2) ?></td>
          <td class="px-4 py-2"><?= e($s['method']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="3" class="text-center py-4 text-gray-500">No transactions</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
      <h3 class="text-lg font-semibold text-green-700 mb-4">Savings Trend</h3>
      <canvas id="savingsChart"></canvas>
    </div>
    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
      <h3 class="text-lg font-semibold text-green-700 mb-4">Loan vs Repayment</h3>
      <canvas id="loanChart"></canvas>
    </div>
  </div>

</div>

<!-- Image Modal -->
<div id="imgModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60">
  <div class="max-w-4xl w-full p-4">
    <div class="bg-white rounded shadow p-2">
      <div class="flex justify-between items-start">
        <h4 id="modalLabel" class="text-lg font-semibold"></h4>
        <button id="closeModal" class="text-gray-600 hover:text-gray-900">Close</button>
      </div>
      <div class="mt-2">
        <img id="modalImg" src="" alt="" class="w-full h-auto rounded">
      </div>
    </div>
  </div>
</div>

<script>
  // Tabs
  const tabs = document.querySelectorAll('.tab-link');
  const panels = document.querySelectorAll('.tab-panel');
  tabs.forEach(tab => {
    tab.addEventListener('click', e => {
      e.preventDefault();
      const target = tab.dataset.tab;
      panels.forEach(p=>p.classList.add('hidden'));
      document.getElementById(target).classList.remove('hidden');
      tabs.forEach(t=>t.classList.remove('border-l','border-t','border-r','rounded-t','bg-white'));
      tab.classList.add('border-l','border-t','border-r','rounded-t','bg-white');
    });
  });
  document.querySelector('.tab-link').click();

  // Charts
  const monthlySavings = <?= json_encode($savings_monthly); ?>;
  new Chart(document.getElementById('savingsChart'),{
    type:'line',
    data:{
      labels: monthlySavings.map(m=>m.month),
      datasets:[{
        label:'Monthly Savings (KSh)',
        data: monthlySavings.map(m=>m.total),
        borderColor:'#128d51',
        backgroundColor:'rgba(18,141,81,0.2)',
        fill:true,
        tension:0.4
      }]
    },
    options:{scales:{y:{beginAtZero:true}}}
  });

  const loanChartData = <?= json_encode($loanChartData); ?>;
  new Chart(document.getElementById('loanChart'),{
    type:'doughnut',
    data:{
      labels:['Loans','Repayments'],
      datasets:[{data:[loanChartData.loans,loanChartData.repayments],backgroundColor:['#f87171','#128d51']}]
    }
  });

  // Image Modal
  const modal = document.getElementById('imgModal');
  const modalImg = document.getElementById('modalImg');
  const modalLabel = document.getElementById('modalLabel');
  const closeModal = document.getElementById('closeModal');

  document.querySelectorAll('.img-preview').forEach(img => {
    img.addEventListener('click', () => {
      modalImg.src = img.getAttribute('data-src') || img.src;
      modalLabel.textContent = img.getAttribute('data-label') || '';
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    });
  });

  closeModal.addEventListener('click', () => {
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    modalImg.src = '';
  });

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.remove('flex');
      modal.classList.add('hidden');
      modalImg.src = '';
    }
  });

  // Export PDF logic
  async function imageToDataURL(img) {
    return new Promise(resolve => {
      if (!img || !img.src) return resolve(null);
      if (img.src.startsWith('data:')) return resolve(img.src);
      const image = new Image();
      image.crossOrigin = "Anonymous";
      image.onload = function() {
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(image,0,0);
        resolve(canvas.toDataURL('image/jpeg',0.92));
      };
      image.onerror = () => resolve(null);
      image.src = img.getAttribute('data-src') || img.src;
    });
  }

  async function inlineAllImages() {
    const imgs = Array.from(document.querySelectorAll('img'));
    for (const img of imgs) {
      if (img.width<5 && img.height<5) continue;
      const dataUrl = await imageToDataURL(img);
      if (dataUrl) { img.setAttribute('data-orig-src', img.src); img.src = dataUrl; }
    }
  }

  document.getElementById('exportBtn').addEventListener('click', async () => {
    const btn = document.getElementById('exportBtn');
    btn.disabled = true;
    btn.textContent = "Preparing PDF...";
    await inlineAllImages();
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p','pt','a4');
    await doc.html(document.body, { callback: function(doc) { doc.save('customer_profile.pdf'); }, x:10, y:10, html2canvas:{scale:1} });
    btn.disabled = false;
    btn.textContent = "Export PDF";
  });
</script>

</body>
</html>
