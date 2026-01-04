<?php
include '../includes/db.php';

$group_id = intval($_GET['group_id'] ?? 0);
if (!$group_id) exit('<p class="text-center text-gray-500 py-8">Invalid group.</p>');

// Fetch group info
$group = $conn->query("SELECT * FROM groups WHERE id=$group_id")->fetch_assoc();
if (!$group) exit('<p class="text-center text-gray-500 py-8">Group not found.</p>');

// Fetch current members
$members = $conn->query("SELECT * FROM customers WHERE group_id=$group_id ORDER BY first_name");

// Fetch available customers for this group's officer
$available = $conn->query("SELECT id, first_name, surname FROM customers 
    WHERE loan_officer_id={$group['loan_officer_id']} AND group_id IS NULL ORDER BY first_name");
?>

<div>
  <h4 class="font-semibold text-gray-700 mb-3"><?= htmlspecialchars($group['group_name']) ?> Members</h4>

  <form id="addMemberForm" class="flex gap-2 mb-4">
    <select id="newMember" class="border p-2 rounded-lg flex-grow" disabled>
      <option value="">-- Select Customer to Add --</option>
      <?php while($c=$available->fetch_assoc()): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['first_name'].' '.$c['surname']) ?></option>
      <?php endwhile; ?>
    </select>
    <button type="button" class="bg-gray-400 text-white px-4 py-2 rounded-lg cursor-not-allowed" disabled>Add</button>
</form>


  <table class="w-full text-sm border">
    <thead class="bg-gray-50">
      <tr>
        <th class="p-2 text-left">Name</th>
        <th class="p-2 text-left">Phone</th>
        <th class="p-2 text-left">National ID</th>
        <th class="p-2 text-center">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($members->num_rows): while($m=$members->fetch_assoc()): ?>
      <tr class="border-t">
        <td class="p-2"><?= htmlspecialchars($m['first_name'].' '.$m['surname']) ?></td>
        <td class="p-2"><?= htmlspecialchars($m['phone_number']) ?></td>
        <td class="p-2"><?= htmlspecialchars($m['national_id']) ?></td>
        <td class="p-2 text-center">
          <button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded"
                  onclick="removeMember(<?= $m['id'] ?>, <?= $group_id ?>)">Remove</button>
        </td>
      </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="4" class="text-center text-gray-400 p-4">No members yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
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
      if(res.includes('Added')) updateGroupModal(groupId);
      else alert(res);
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
      if(res.includes('Removed')) updateGroupModal(groupId);
      else alert(res);
    });
}

function updateGroupModal(groupId) {
  fetch('group_modal_code.php?group_id=' + groupId)
    .then(r => r.text())
    .then(html => document.getElementById('modalContent').innerHTML = html);
}
</script>
