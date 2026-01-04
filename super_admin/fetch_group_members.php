<?php
include 'includes/db.php';

$group_id = intval($_GET['group_id'] ?? 0);
if (!$group_id) exit('Invalid group');

$group = $conn->query("SELECT g.*, a.name AS officer FROM groups g 
  LEFT JOIN admin_users a ON g.loan_officer_id=a.id WHERE g.id=$group_id")->fetch_assoc();

$members = $conn->query("SELECT id, first_name, surname, phone_number, national_id 
  FROM customers WHERE group_id=$group_id ORDER BY first_name");

$available = $conn->query("SELECT id, first_name, surname 
  FROM customers WHERE loan_officer_id={$group['loan_officer_id']} AND group_id IS NULL ORDER BY first_name");
?>

<div>
  <h4 class="font-semibold text-gray-700 mb-3">Group: <?= htmlspecialchars($group['group_name']) ?> (<?= htmlspecialchars($group['group_code']) ?>)</h4>
  <p class="text-sm text-gray-500 mb-4">Officer: <?= htmlspecialchars($group['officer']) ?></p>

  <div class="flex gap-2 mb-4">
    <select id="newMember" class="border p-2 rounded-lg flex-grow">
      <option value="">-- Select Customer to Add --</option>
      <?php while($c=$available->fetch_assoc()): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['first_name'].' '.$c['surname']) ?></option>
      <?php endwhile; ?>
    </select>
    <button onclick="addMember(<?= $group_id ?>)" class="bg-[#15a362] hover:bg-[#118652] text-white px-4 py-2 rounded-lg">Add</button>
  </div>

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
          <button class="removeBtn bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded" onclick="removeMember(<?= $m['id'] ?>, <?= $group_id ?>)">Remove</button>
        </td>
      </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="4" class="text-center text-gray-400 p-4">No members yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
