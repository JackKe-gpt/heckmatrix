<?php
session_start();
require_once 'includes/db.php';

$unassigned = $conn->query("
  SELECT id, first_name, surname 
  FROM customers 
  WHERE group_id IS NULL 
  ORDER BY first_name
");
$groups = $conn->query("
  SELECT id, group_name 
  FROM groups 
  ORDER BY group_name
");
?>

<?php if ($unassigned->num_rows == 0): ?>
  <p class="text-center text-gray-500 py-10">All customers are already assigned to groups.</p>
<?php else: ?>
  <table class="w-full border-collapse text-sm">
    <thead class="bg-gray-100">
      <tr>
        <th class="p-2 text-left">Customer</th>
        <th class="p-2 text-left">Assign To Group</th>
        <th class="p-2">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php while($c = $unassigned->fetch_assoc()): ?>
        <tr class="border-t">
          <td class="p-2"><?= htmlspecialchars($c['first_name'] . ' ' . $c['surname']) ?></td>
          <td class="p-2">
            <select id="groupSelect<?= $c['id'] ?>" class="border border-gray-300 rounded-lg p-1.5 w-full">
              <option value="">-- Choose Group --</option>
              <?php $groups->data_seek(0); while($g=$groups->fetch_assoc()): ?>
                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
              <?php endwhile; ?>
            </select>
          </td>
          <td class="p-2 text-center">
            <button onclick="assignCustomer(<?= $c['id'] ?>)" class="bg-[#15a362] hover:bg-[#118652] text-white px-3 py-1 rounded-md">Assign</button>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
<?php endif; ?>

<script>
function assignCustomer(cid) {
  const gid = document.getElementById('groupSelect' + cid).value;
  if (!gid) return alert('Please select a group first.');
  fetch('group_actions.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'assign_unassigned', group_id: gid, customer_id: cid})
  })
  .then(r=>r.text()).then(res=>{
    if(res.includes('Assigned')) location.reload();
    else alert(res);
  });
}
</script>
