<?php
session_start();

// Only loan officers
if ($_SESSION['admin']['role'] !== 'loan_officer') {
    header('Location: index.php'); exit;
}

include '../includes/db.php';
include 'header.php';

$loan_officer_id = $_SESSION['admin']['id'];

// Fetch groups for this officer
$groups = $conn->query("
    SELECT g.*, (SELECT COUNT(*) FROM customers c WHERE c.group_id=g.id) AS members
    FROM groups g
    WHERE g.loan_officer_id=$loan_officer_id
    ORDER BY g.id DESC
");
?>

<div class="p-4 md:p-6 w-full">
    <h2 class="text-2xl font-bold text-[#15a362] mb-6">My Groups</h2>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 w-full">
        <?php if ($groups->num_rows > 0): while($g=$groups->fetch_assoc()): ?>
            <div class="bg-white rounded-xl shadow-md border border-gray-100 p-5 flex flex-col justify-between hover:shadow-lg transition-all">
                <div>
                    <h3 class="font-semibold text-gray-800 text-lg"><?= htmlspecialchars($g['group_name']) ?></h3>
                    <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars($g['group_code']) ?></p>
                    <p class="text-sm text-gray-600"><strong>Members:</strong> <?= $g['members'] ?></p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button data-id="<?= $g['id'] ?>" class="bg-[#15a362] hover:bg-[#118652] text-white px-3 py-1.5 rounded-lg text-sm viewGroupBtn">View Members</button>
                </div>
            </div>
        <?php endwhile; else: ?>
            <p class="text-center text-gray-500 col-span-full py-10">No groups found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Group Modal -->
<div id="groupModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-3xl relative animate-fadeIn">
        <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-500 hover:text-red-600 text-xl">✖</button>
        <h3 class="text-xl font-semibold text-[#15a362] mb-4">Group Members</h3>
        <div id="modalContent" class="text-gray-700 text-sm overflow-y-auto max-h-[70vh]">
            <p class="text-center text-gray-500 py-10">Loading...</p>
        </div>
    </div>
</div>

<script>
// Open group modal
const modal = document.getElementById('groupModal');
document.querySelectorAll('.viewGroupBtn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        modal.classList.remove('hidden'); modal.classList.add('flex');
        document.getElementById('modalContent').innerHTML = '<p class="text-center text-gray-500 py-8">Loading...</p>';
        fetch('group_modal_code.php?group_id=' + id)
            .then(res => res.text())
            .then(html => document.getElementById('modalContent').innerHTML = html);
    });
});
function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
</script>

<style>
@keyframes fadeIn { from {opacity:0; transform:translateY(10px);} to {opacity:1; transform:translateY(0);} }
.animate-fadeIn { animation: fadeIn 0.3s ease-in-out; }
</style>
