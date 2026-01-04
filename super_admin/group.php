<?php
ob_start();
require_once 'auth.php';
require_login();
include 'includes/db.php';
include 'header.php';

// Flash helper
function flash($msg = null) {
  if ($msg === null) { $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m; }
  $_SESSION['flash'] = $msg;
}

// CSRF Token
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Handle Create Group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $branch_id = intval($_POST['branch_id']);
  $loan_officer_id = intval($_POST['loan_officer_id']);
  $group_name = trim($_POST['group_name']);
  if ($branch_id && $loan_officer_id && $group_name) {
    $group_code = 'GRP-' . time();
    $stmt = $conn->prepare("INSERT INTO groups (branch_id, loan_officer_id, group_code, group_name, created_at, updated_at)
      VALUES (?, ?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('iiss', $branch_id, $loan_officer_id, $group_code, $group_name);
    $stmt->execute();
    flash('✅ Group created successfully.');
  } else flash('⚠️ Please fill all required fields.');
  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// Delete Group
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
  $id = intval($_GET['delete']);
  $conn->query("UPDATE customers SET group_id=NULL WHERE group_id=$id");
  $conn->query("DELETE FROM groups WHERE id=$id");
  flash('🗑️ Group deleted successfully.');
  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// Fetch data
$branches = $conn->query("SELECT id,name FROM branches ORDER BY name");
$officers = $conn->query("SELECT id,name,branch_id FROM admin_users WHERE role='loan_officer' ORDER BY name");
$officerList = [];
while ($o = $officers->fetch_assoc()) $officerList[$o['branch_id']][] = ['id'=>$o['id'],'name'=>$o['name']];

// Filters
$branchFilter = $_GET['branch_id'] ?? '';
$officerFilter = $_GET['officer_id'] ?? '';
$where = "1";
if ($branchFilter) $where .= " AND g.branch_id=" . intval($branchFilter);
if ($officerFilter) $where .= " AND g.loan_officer_id=" . intval($officerFilter);

// Groups
$groups = $conn->query("SELECT g.*, b.name AS branch, a.name AS officer,
  (SELECT COUNT(*) FROM customers c WHERE c.group_id=g.id) AS members
  FROM groups g
  LEFT JOIN branches b ON g.branch_id=b.id
  LEFT JOIN admin_users a ON g.loan_officer_id=a.id
  WHERE $where ORDER BY g.id DESC");

// Stats
$total_groups = $conn->query("SELECT COUNT(*) AS total FROM groups")->fetch_assoc()['total'] ?? 0;
$total_members = $conn->query("SELECT COUNT(*) AS total FROM customers WHERE group_id IS NOT NULL")->fetch_assoc()['total'] ?? 0;
$unassigned = $conn->query("SELECT COUNT(*) AS total FROM customers WHERE group_id IS NULL")->fetch_assoc()['total'] ?? 0;
?>

<div class="p-4 md:p-6 space-y-6 w-full">
  <div class="text-center">
    <h2 class="text-2xl font-bold text-[#15a362]">Group Management</h2>
    <p class="text-gray-500">Create, manage and assign members to groups efficiently</p>
  </div>

  <?php if ($msg = flash()): ?>
    <div class="bg-green-50 border border-green-400 text-green-700 px-4 py-3 rounded-xl text-center shadow-sm">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl shadow-md p-5 text-center border border-gray-100">
      <h3 class="text-sm text-gray-600">Total Groups</h3>
      <p class="text-2xl font-bold text-[#15a362]"><?= $total_groups ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-md p-5 text-center border border-gray-100">
      <h3 class="text-sm text-gray-600">Total Members</h3>
      <p class="text-2xl font-bold text-[#15a362]"><?= $total_members ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-md p-5 text-center border border-gray-100 cursor-pointer hover:shadow-lg transition-all" onclick="showUnassigned()">
      <h3 class="text-sm text-gray-600">Unassigned Customers</h3>
      <p class="text-2xl font-bold text-[#15a362]"><?= $unassigned ?></p>
      <p class="text-xs text-gray-400 mt-1">Click to assign</p>
    </div>
    <div class="bg-white rounded-xl shadow-md p-5 text-center border border-gray-100">
      <h3 class="text-sm text-gray-600">Officer Filter</h3>
      <p class="text-lg font-semibold text-[#15a362]">
        <?= $officerFilter ? htmlspecialchars($conn->query("SELECT name FROM admin_users WHERE id=$officerFilter")->fetch_assoc()['name']) : 'All Officers' ?>
      </p>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="bg-white rounded-xl shadow-md p-5 border border-gray-100 flex flex-col md:flex-row gap-3 items-center">
    <select name="branch_id" id="branchFilter" class="border border-gray-200 rounded-lg p-2 w-full md:w-1/3">
      <option value="">All Branches</option>
      <?php $branches->data_seek(0); while($b=$branches->fetch_assoc()): ?>
        <option value="<?= $b['id'] ?>" <?= ($b['id']==$branchFilter?'selected':'') ?>><?= htmlspecialchars($b['name']) ?></option>
      <?php endwhile; ?>
    </select>
    <select name="officer_id" id="officerFilter" class="border border-gray-200 rounded-lg p-2 w-full md:w-1/3">
      <option value="">All Officers</option>
      <?php foreach ($officerList as $branchId => $list): foreach ($list as $officer): ?>
        <option value="<?= $officer['id'] ?>" <?= ($officer['id']==$officerFilter?'selected':'') ?>><?= htmlspecialchars($officer['name']) ?></option>
      <?php endforeach; endforeach; ?>
    </select>
    <button class="bg-[#15a362] hover:bg-[#118652] text-white font-medium px-6 py-2 rounded-lg w-full md:w-auto">
      Apply Filter
    </button>
  </form>

  <!-- Create Group -->
  <div class="bg-white rounded-xl shadow-md p-6 border border-gray-100">
    <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Create New Group</h3>
    <form method="post" class="grid md:grid-cols-3 gap-4">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="create">

      <div>
        <label class="text-sm text-gray-600">Branch</label>
        <select name="branch_id" id="branchSelect" class="border border-gray-200 rounded-lg p-2 w-full mt-1" required>
          <option value="">-- Select Branch --</option>
          <?php $branches->data_seek(0); while($b=$branches->fetch_assoc()): ?>
            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div>
        <label class="text-sm text-gray-600">Loan Officer</label>
        <select name="loan_officer_id" id="officerSelect" class="border border-gray-200 rounded-lg p-2 w-full mt-1" required>
          <option value="">-- Select Officer --</option>
        </select>
      </div>

      <div>
        <label class="text-sm text-gray-600">Group Name</label>
        <input type="text" name="group_name" class="border border-gray-200 rounded-lg p-2 w-full mt-1" placeholder="Enter group name" required>
      </div>

      <div class="md:col-span-3 flex justify-end">
        <button type="submit" class="bg-[#15a362] hover:bg-[#118652] text-white font-semibold px-5 py-2 rounded-lg">
          + Create Group
        </button>
      </div>
    </form>
  </div>

  <!-- Group Cards -->
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 w-full">
    <?php if ($groups->num_rows > 0): while($g=$groups->fetch_assoc()): ?>
      <div class="bg-white rounded-xl shadow-md border border-gray-100 p-5 flex flex-col justify-between hover:shadow-lg transition-all">
        <div>
          <h3 class="font-semibold text-gray-800 text-lg"><?= htmlspecialchars($g['group_name']) ?></h3>
          <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars($g['group_code']) ?></p>
          <p class="text-sm text-gray-600"><strong>Branch:</strong> <?= htmlspecialchars($g['branch']) ?></p>
          <p class="text-sm text-gray-600"><strong>Officer:</strong> <?= htmlspecialchars($g['officer']) ?></p>
          <p class="text-sm text-gray-600"><strong>Members:</strong> <?= $g['members'] ?></p>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button data-id="<?= $g['id'] ?>" class="bg-[#15a362] hover:bg-[#118652] text-white px-3 py-1.5 rounded-lg text-sm viewGroupBtn">View</button>
          <a href="?delete=<?= $g['id'] ?>" onclick="return confirm('Delete this group?')" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm">Delete</a>
        </div>
      </div>
    <?php endwhile; else: ?>
      <p class="text-center text-gray-500 col-span-full py-10">No groups found for this officer.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Modal for Group Members -->
<div id="groupModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-3xl relative animate-fadeIn">
    <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-500 hover:text-red-600 text-xl">✖</button>
    <h3 id="modalTitle" class="text-xl font-semibold text-[#15a362] mb-4">Group Members</h3>
    <div id="modalContent" class="text-gray-700 text-sm overflow-y-auto max-h-[70vh]">
      <p class="text-center text-gray-500 py-10">Loading...</p>
    </div>
  </div>
</div>

<!-- Modal for Unassigned -->
<div id="unassignedModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-3xl relative animate-fadeIn">
    <button onclick="closeUnassigned()" class="absolute top-3 right-3 text-gray-500 hover:text-red-600 text-xl">✖</button>
    <h3 class="text-xl font-semibold text-[#15a362] mb-4">Unassigned Customers</h3>
    <div id="unassignedContent" class="text-gray-700 text-sm overflow-y-auto max-h-[70vh]">
      <p class="text-center text-gray-500 py-10">Loading...</p>
    </div>
  </div>
</div>

<script>
const officerData = <?= json_encode($officerList) ?>;
const branchSelect = document.getElementById('branchSelect');
const officerSelect = document.getElementById('officerSelect');
branchSelect.addEventListener('change', () => {
  const branchId = branchSelect.value;
  officerSelect.innerHTML = '<option value="">-- Select Officer --</option>';
  if (branchId && officerData[branchId]) {
    officerData[branchId].forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.id; opt.textContent = o.name;
      officerSelect.appendChild(opt);
    });
  }
});

// View Group Modal
const modal = document.getElementById('groupModal');
const modalContent = document.getElementById('modalContent');
document.querySelectorAll('.viewGroupBtn').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.id;
    modal.classList.remove('hidden'); modal.classList.add('flex');
    modalContent.innerHTML = '<p class="text-center text-gray-500 py-8">Loading...</p>';
    fetch('fetch_group_members.php?group_id=' + id)
      .then(res => res.text()).then(html => modalContent.innerHTML = html);
  });
});
function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }

// Show Unassigned Customers
function showUnassigned() {
  const modal = document.getElementById('unassignedModal');
  const content = document.getElementById('unassignedContent');
  modal.classList.remove('hidden'); modal.classList.add('flex');
  content.innerHTML = '<p class="text-center text-gray-500 py-8">Loading...</p>';
  fetch('fetch_unassigned_customers.php')
    .then(res => res.text()).then(html => content.innerHTML = html);
}
function closeUnassigned() { document.getElementById('unassignedModal').classList.add('hidden'); }


function addMember(groupId) {
  const cid = document.getElementById('newMember').value;
  if (!cid) return alert('Please select a customer.');

  const form = new FormData();
  form.append('action','add');
  form.append('group_id', groupId);
  form.append('customer_id', cid);

  fetch('group_action.php', {method:'POST', body:form})
    .then(res => res.text())
    .then(res => {
      if(res.includes('Added')) {
        alert('✅ Member added!');
        updateGroupModal(groupId);
      } else alert('❌ ' + res);
    });
}

function removeMember(cid, groupId) {
  if (!confirm('Remove this member?')) return;
  const form = new FormData();
  form.append('action','remove');
  form.append('customer_id', cid);

  fetch('group_action.php', {method:'POST', body:form})
    .then(res => res.text())
    .then(res => {
      if(res.includes('Removed')) {
        alert('🚫 Member removed');
        updateGroupModal(groupId);
      } else alert('❌ ' + res);
    });
}

// Reload modal content
function updateGroupModal(groupId) {
  fetch('fetch_group_members.php?group_id=' + groupId)
    .then(r => r.text())
    .then(html => document.getElementById('modalContent').innerHTML = html);
}
</script>



<style>
@keyframes fadeIn { from {opacity:0; transform:translateY(10px);} to {opacity:1; transform:translateY(0);} }
.animate-fadeIn { animation: fadeIn 0.3s ease-in-out; }
</style>

<?php ob_end_flush(); ?>
