<?php
session_start();
require '../includes/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// --- Access Control ---
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'super_admin') {
    header("Location: ../index");
    exit;
}

$message = '';
$message_type = '';

// --- Handle Form Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_branch':
                $branch_name = trim($_POST['branch_name']);
                $location = trim($_POST['location']);
                $manager_name = trim($_POST['manager_name']);
                $manager_email = trim($_POST['manager_email']);
                $manager_phone = trim($_POST['manager_phone']);
                $status = $_POST['status'];

                // Check if branch already exists
                $check_sql = "SELECT id FROM branches WHERE name = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $branch_name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $message = "Branch with this name already exists!";
                    $message_type = "error";
                } else {
                    // Insert new branch
                    $insert_sql = "INSERT INTO branches (name, location) VALUES (?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ss", $branch_name, $location);
                    
                    if ($insert_stmt->execute()) {
                        $branch_id = $conn->insert_id;
                        
                        // Create branch manager admin user
                        $password = password_hash('password123', PASSWORD_DEFAULT); // Default password
                        $role = 'branch_manager';
                        
                        $manager_sql = "INSERT INTO admin_users (name, email, phone, password, role, branch_id, status) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $manager_stmt = $conn->prepare($manager_sql);
                        $manager_stmt->bind_param("sssssis", $manager_name, $manager_email, $manager_phone, 
                                                $password, $role, $branch_id, $status);
                        
                        if ($manager_stmt->execute()) {
                            $message = "Branch created successfully!";
                            $message_type = "success";
                        } else {
                            $message = "Branch created but failed to create manager account!";
                            $message_type = "warning";
                        }
                    } else {
                        $message = "Failed to create branch!";
                        $message_type = "error";
                    }
                }
                break;

            case 'edit_branch':
                $branch_id = $_POST['branch_id'];
                $branch_name = trim($_POST['branch_name']);
                $location = trim($_POST['location']);
                $status = $_POST['status'];

                $update_sql = "UPDATE branches SET name = ?, location = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssi", $branch_name, $location, $branch_id);
                
                if ($update_stmt->execute()) {
                    $message = "Branch updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Failed to update branch!";
                    $message_type = "error";
                }
                break;
        }
    }
}

// --- Handle Delete Action ---
if (isset($_GET['delete_branch'])) {
    $branch_id = (int)$_GET['delete_branch'];
    
    // Check if branch has customers or loans
    $check_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE branch_id = $branch_id");
    $check_loans = $conn->query("SELECT COUNT(*) as count FROM loans WHERE branch_id = $branch_id");
    $check_officers = $conn->query("SELECT COUNT(*) as count FROM admin_users WHERE branch_id = $branch_id");
    
    $customer_count = $check_customers->fetch_assoc()['count'];
    $loan_count = $check_loans->fetch_assoc()['count'];
    $officer_count = $check_officers->fetch_assoc()['count'];
    
    if ($customer_count > 0 || $loan_count > 0 || $officer_count > 0) {
        $message = "Cannot delete branch. It has associated customers, loans, or officers!";
        $message_type = "error";
    } else {
        // Delete branch manager and then branch
        $delete_manager_sql = "DELETE FROM admin_users WHERE branch_id = ? AND role = 'branch_manager'";
        $delete_manager_stmt = $conn->prepare($delete_manager_sql);
        $delete_manager_stmt->bind_param("i", $branch_id);
        $delete_manager_stmt->execute();
        
        $delete_sql = "DELETE FROM branches WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $branch_id);
        
        if ($delete_stmt->execute()) {
            $message = "Branch deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to delete branch!";
            $message_type = "error";
        }
    }
}

// --- Get Statistics ---
$total_branches = $conn->query("SELECT COUNT(*) as count FROM branches")->fetch_assoc()['count'];
$active_branches = $conn->query("SELECT COUNT(DISTINCT b.id) as count FROM branches b 
                                JOIN admin_users au ON b.id = au.branch_id 
                                WHERE au.role = 'branch_manager'")->fetch_assoc()['count'];
$total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
$total_loans = $conn->query("SELECT COUNT(*) as count FROM loans")->fetch_assoc()['count'];
$total_officers = $conn->query("SELECT COUNT(*) as count FROM admin_users WHERE role = 'loan_officer'")->fetch_assoc()['count'];

// --- Get Branches with Statistics ---
$branches_sql = "
    SELECT 
        b.id,
        b.name as branch_name,
        b.location,
        au.name as manager_name,
        au.email as manager_email,
        au.phone as manager_phone,
        au.status,
        b.created_at,
        COUNT(DISTINCT c.id) as total_customers,
        COUNT(DISTINCT l.id) as total_loans,
        COUNT(DISTINCT au2.id) as total_officers
    FROM branches b
    LEFT JOIN admin_users au ON b.id = au.branch_id AND au.role = 'branch_manager'
    LEFT JOIN customers c ON b.id = c.branch_id
    LEFT JOIN loans l ON b.id = l.branch_id
    LEFT JOIN admin_users au2 ON b.id = au2.branch_id AND au2.role = 'loan_officer'
    GROUP BY b.id, b.name, b.location, au.name, au.email, au.phone, au.status, b.created_at
    ORDER BY b.created_at DESC
";
$branches = $conn->query($branches_sql);

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Super Admin - Branch Management</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body { background: #f8fafc; }
.card { background: white; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
.btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s; }
.btn-primary { background: #3b82f6; color: white; }
.btn-primary:hover { background: #2563eb; }
.btn-danger { background: #ef4444; color: white; }
.btn-danger:hover { background: #dc2626; }
.btn-success { background: #10b981; color: white; }
.btn-success:hover { background: #059669; }
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; }
.modal-content { background: white; margin: 2rem auto; padding: 0; border-radius: 0.75rem; max-width: 500px; }
</style>
</head>
<body class="font-sans text-gray-800">
<div class="min-h-screen bg-gray-50">
    
    <!-- Main Content -->
    <div class="p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Branch Management</h1>
                <p class="text-gray-600">Manage all branches and their operations</p>
            </div>
            <button onclick="openModal('addBranchModal')" class="btn btn-primary">
                Add New Branch
            </button>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
        <div class="p-4 mb-6 rounded-lg <?= $message_type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="card p-6">
                <div class="text-sm text-gray-600 font-medium">Total Branches</div>
                <div class="text-2xl font-bold text-blue-600 mt-2"><?= number_format($total_branches) ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= number_format($active_branches) ?> active</div>
            </div>
            <div class="card p-6">
                <div class="text-sm text-gray-600 font-medium">Total Customers</div>
                <div class="text-2xl font-bold text-green-600 mt-2"><?= number_format($total_customers) ?></div>
                <div class="text-xs text-gray-500 mt-1">Across all branches</div>
            </div>
            <div class="card p-6">
                <div class="text-sm text-gray-600 font-medium">Total Loans</div>
                <div class="text-2xl font-bold text-purple-600 mt-2"><?= number_format($total_loans) ?></div>
                <div class="text-xs text-gray-500 mt-1">Active and completed</div>
            </div>
            <div class="card p-6">
                <div class="text-sm text-gray-600 font-medium">Loan Officers</div>
                <div class="text-2xl font-bold text-orange-600 mt-2"><?= number_format($total_officers) ?></div>
                <div class="text-xs text-gray-500 mt-1">All branches</div>
            </div>
        </div>

        <!-- Branches Section -->
        <div class="card p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-900">All Branches</h2>
                <span class="text-sm text-gray-500"><?= number_format($branches->num_rows) ?> branches</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Branch Name</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Location</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Manager</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Customers</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Loans</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Officers</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Created</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-700">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php while($branch = $branches->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($branch['branch_name']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($branch['location'] ?? 'Not specified') ?></td>
                            <td class="px-4 py-3">
                                <div class="text-gray-900 font-medium"><?= htmlspecialchars($branch['manager_name'] ?? 'Not assigned') ?></div>
                                <?php if ($branch['manager_email']): ?>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($branch['manager_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-gray-600 text-center"><?= number_format($branch['total_customers']) ?></td>
                            <td class="px-4 py-3 text-gray-600 text-center"><?= number_format($branch['total_loans']) ?></td>
                            <td class="px-4 py-3 text-gray-600 text-center"><?= number_format($branch['total_officers']) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs rounded-full <?= ($branch['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= htmlspecialchars(ucfirst($branch['status'] ?? 'active')) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-sm"><?= date('M j, Y', strtotime($branch['created_at'])) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <button onclick="editBranch(<?= htmlspecialchars(json_encode($branch)) ?>)" 
                                            class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        Edit
                                    </button>
                                    <button onclick="confirmDelete(<?= $branch['id'] ?>)" 
                                            class="text-red-600 hover:text-red-800 text-sm font-medium">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($branches->num_rows === 0): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                No branches found. Create your first branch to get started.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Branch Modal -->
<div id="addBranchModal" class="modal">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Add New Branch</h3>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_branch">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Name *</label>
                    <input type="text" name="branch_name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Enter branch name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
                    <input type="text" name="location" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Enter branch location">
                </div>
                <div class="border-t pt-4">
                    <h4 class="text-md font-medium text-gray-900 mb-3">Branch Manager Details</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Name *</label>
                            <input type="text" name="manager_name" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Manager full name">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Email *</label>
                            <input type="email" name="manager_email" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="manager@example.com">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Manager Phone *</label>
                        <input type="tel" name="manager_phone" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Phone number">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addBranchModal')" class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Create Branch
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Branch Modal -->
<div id="editBranchModal" class="modal">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Edit Branch</h3>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_branch">
            <input type="hidden" name="branch_id" id="edit_branch_id">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Name *</label>
                    <input type="text" name="branch_name" id="edit_branch_name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
                    <input type="text" name="location" id="edit_branch_location" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="edit_branch_status" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editBranchModal')" class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Update Branch
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function editBranch(branch) {
    document.getElementById('edit_branch_id').value = branch.id;
    document.getElementById('edit_branch_name').value = branch.branch_name;
    document.getElementById('edit_branch_location').value = branch.location || '';
    document.getElementById('edit_branch_status').value = branch.status || 'active';
    openModal('editBranchModal');
}

function confirmDelete(branchId) {
    if (confirm('Are you sure you want to delete this branch? This action cannot be undone.')) {
        window.location.href = '?delete_branch=' + branchId;
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>
</body>
</html>