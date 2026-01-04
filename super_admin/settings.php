<?php
include 'auth.php';
require_login();
include 'includes/db.php';

$admin_id = $_SESSION['admin']['id'];
$message = "";

// --- Add Branch ---
if (isset($_POST['add_branch'])) {
    $branch_name = trim($_POST['branch_name']);
    if ($branch_name !== '') {
        $stmt = $conn->prepare("INSERT INTO branches (name) VALUES (?)");
        $stmt->bind_param("s", $branch_name);
        $stmt->execute();
        $message = "Branch added successfully.";
    } else {
        $message = "Branch name cannot be empty.";
    }
}

// --- Add Admin ---
if (isset($_POST['add_admin'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $branch_id = $_POST['branch_id'] !== '' ? intval($_POST['branch_id']) : null;
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admin_users (name,email,password,role,branch_id) VALUES (?,?,?,?,?)");
    $stmt->bind_param("ssssi",$name,$email,$password,$role,$branch_id);
    $stmt->execute();
    $message = "Admin added successfully.";
}

// --- Edit Admin ---
if (isset($_POST['edit_admin'])) {
    $id = $_POST['admin_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $branch_id = $_POST['branch_id'] !== '' ? intval($_POST['branch_id']) : null;

    $stmt = $conn->prepare("UPDATE admin_users SET name=?, email=?, role=?, branch_id=? WHERE id=?");
    $stmt->bind_param("sssii",$name,$email,$role,$branch_id,$id);
    $stmt->execute();
    $message = "Admin updated successfully.";
}

// --- Change Password ---
if (isset($_POST['change_password'])) {
    $id = $_POST['admin_id'];
    $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE admin_users SET password=? WHERE id=?");
    $stmt->bind_param("si",$password,$id);
    $stmt->execute();
    $message = "Password changed successfully.";
}

// --- Delete Admin ---
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    if($delete_id != $admin_id){
        $stmt = $conn->prepare("DELETE FROM admin_users WHERE id=?");
        $stmt->bind_param("i",$delete_id);
        $stmt->execute();
        $message = "Admin deleted successfully.";
    } else {
        $message = "You cannot delete your own account.";
    }
}

// --- Delete Branch ---
if(isset($_GET['delete_branch'])){
    $delete_id = intval($_GET['delete_branch']);
    $stmt = $conn->prepare("DELETE FROM branches WHERE id=?");
    $stmt->bind_param("i",$delete_id);
    $stmt->execute();
    $message = "Branch deleted successfully.";
}

// --- Fetch Branches ---
$branches_array=[];
$branches_result=mysqli_query($conn,"SELECT id,name FROM branches ORDER BY name ASC");
while($b=mysqli_fetch_assoc($branches_result)) $branches_array[]=$b;

// --- Fetch Roles ---
$roles_array=[];
$roles_result=mysqli_query($conn,"SELECT name FROM roles ORDER BY name ASC");
while($r=mysqli_fetch_assoc($roles_result)) $roles_array[]=$r['name'];

// --- Fetch Admins ---
$admins_result=mysqli_query($conn,"SELECT a.*,b.name AS branch_name FROM admin_users a LEFT JOIN branches b ON a.branch_id=b.id ORDER BY a.id DESC");

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | Super Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          primary: {
            50: '#f0f9ff',
            100: '#e0f2fe',
            500: '#0ea5e9',
            600: '#0284c7',
            700: '#0369a1',
          },
          secondary: {
            500: '#8b5cf6',
            600: '#7c3aed',
          },
          dark: {
            100: '#f3f4f6',
            200: '#e5e7eb',
            700: '#374151',
            800: '#1f2937',
            900: '#111827',
          }
        },
        fontFamily: {
          'sans': ['Inter', 'system-ui', 'sans-serif'],
        }
      }
    }
  }
</script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
  
  body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  }
  
  .glass-card {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 8px 32px rgba(31, 41, 55, 0.05);
  }
  
  .gradient-bg {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  }
  
  .hover-lift {
    transition: all 0.3s ease;
  }
  
  .hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
  }
  
  .stat-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  }
  
  .modal-overlay {
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
  }
</style>
</head>
<body class="text-gray-800">

<!-- Main Container -->
<div class="min-h-screen p-4 md:p-6 lg:p-8">

  <!-- Header -->
  <div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-bold text-dark-900">Admin Management</h1>
        <p class="text-dark-600 mt-2">Manage branches, administrators, and system settings</p>
      </div>
      <div class="flex items-center gap-4">
        <div class="px-4 py-2 bg-primary-100 text-primary-700 rounded-full text-sm font-medium">
          <i class="fas fa-shield-alt mr-2"></i>Super Admin
        </div>
      </div>
    </div>
  </div>

  <!-- Success Message -->
  <?php if($message): ?>
  <div class="mb-6 p-4 rounded-lg border border-green-200 bg-green-50 text-green-700 flex items-center gap-3 animate-fade-in">
    <i class="fas fa-check-circle text-green-500"></i>
    <span><?= htmlspecialchars($message) ?></span>
  </div>
  <?php endif; ?>

  <!-- Stats Cards -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="stat-card rounded-xl p-6 shadow-sm hover-lift">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-dark-600 mb-1">Total Administrators</p>
          <?php 
            $count_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM admin_users");
            $count = mysqli_fetch_assoc($count_result)['count'];
          ?>
          <p class="text-2xl font-bold text-dark-900"><?= $count ?></p>
        </div>
        <div class="p-3 bg-primary-100 text-primary-600 rounded-lg">
          <i class="fas fa-users text-xl"></i>
        </div>
      </div>
    </div>
    
    <div class="stat-card rounded-xl p-6 shadow-sm hover-lift">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-dark-600 mb-1">Branches</p>
          <p class="text-2xl font-bold text-dark-900"><?= count($branches_array) ?></p>
        </div>
        <div class="p-3 bg-purple-100 text-purple-600 rounded-lg">
          <i class="fas fa-building text-xl"></i>
        </div>
      </div>
    </div>
    
    <div class="stat-card rounded-xl p-6 shadow-sm hover-lift">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-dark-600 mb-1">Admin Roles</p>
          <p class="text-2xl font-bold text-dark-900"><?= count($roles_array) ?></p>
        </div>
        <div class="p-3 bg-yellow-100 text-yellow-600 rounded-lg">
          <i class="fas fa-user-tag text-xl"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content Grid -->
  <div class="grid lg:grid-cols-3 gap-8">
    
    <!-- Left Column - Forms -->
    <div class="lg:col-span-2 space-y-8">
      
      <!-- Add Branch Card -->
      <div class="glass-card rounded-2xl p-6">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-primary-100 text-primary-600 rounded-lg">
            <i class="fas fa-plus"></i>
          </div>
          <h2 class="text-xl font-semibold text-dark-900">Add New Branch</h2>
        </div>
        <form method="POST" class="space-y-4">
          <div class="flex gap-3">
            <input type="text" name="branch_name" placeholder="Enter branch name" required
                   class="flex-1 border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all">
            <button type="submit" name="add_branch"
                    class="px-6 py-3 bg-primary-600 text-white font-medium rounded-xl hover:bg-primary-700 transition-all hover-lift">
              <i class="fas fa-plus mr-2"></i>Add
            </button>
          </div>
        </form>
      </div>

      <!-- Add Admin Card -->
      <div class="glass-card rounded-2xl p-6">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-secondary-100 text-secondary-600 rounded-lg">
            <i class="fas fa-user-plus"></i>
          </div>
          <h2 class="text-xl font-semibold text-dark-900">Create New Administrator</h2>
        </div>
        <form method="POST" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-dark-700 mb-2">Full Name</label>
              <input type="text" name="name" placeholder="John Doe" required
                     class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all">
            </div>
            <div>
              <label class="block text-sm font-medium text-dark-700 mb-2">Email Address</label>
              <input type="email" name="email" placeholder="admin@example.com" required
                     class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all">
            </div>
            <div>
              <label class="block text-sm font-medium text-dark-700 mb-2">Role</label>
              <select name="role" required
                      class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all bg-white">
                <?php foreach($roles_array as $role_name): ?>
                  <option value="<?= htmlspecialchars($role_name) ?>"><?= ucfirst($role_name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-dark-700 mb-2">Branch Assignment</label>
              <select name="branch_id"
                      class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all bg-white">
                <option value="">Select Branch (Optional)</option>
                <?php foreach($branches_array as $b): ?>
                  <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-dark-700 mb-2">Password</label>
              <div class="relative">
                <input type="password" name="password" placeholder="Create a strong password" required
                       class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none transition-all">
                <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-3 text-dark-500">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
          <button type="submit" name="add_admin"
                  class="w-full md:w-auto px-8 py-3 bg-secondary-600 text-white font-medium rounded-xl hover:bg-secondary-700 transition-all hover-lift">
            <i class="fas fa-user-plus mr-2"></i>Create Administrator
          </button>
        </form>
      </div>

      <!-- Administrators List -->
      <div class="glass-card rounded-2xl p-6">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-blue-100 text-blue-600 rounded-lg">
            <i class="fas fa-users-cog"></i>
          </div>
          <h2 class="text-xl font-semibold text-dark-900">Administrators</h2>
        </div>
        <div class="overflow-x-auto rounded-xl border border-dark-100">
          <table class="min-w-full divide-y divide-dark-100">
            <thead class="bg-dark-50">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-dark-600 uppercase tracking-wider">Admin</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-dark-600 uppercase tracking-wider">Role & Branch</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-dark-600 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-dark-100">
              <?php mysqli_data_seek($admins_result, 0); while($row=mysqli_fetch_assoc($admins_result)): ?>
              <tr class="hover:bg-dark-50 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center">
                    <div class="flex-shrink-0 h-10 w-10 bg-primary-100 text-primary-600 rounded-lg flex items-center justify-center">
                      <i class="fas fa-user"></i>
                    </div>
                    <div class="ml-4">
                      <div class="text-sm font-medium text-dark-900"><?= htmlspecialchars($row['name']) ?></div>
                      <div class="text-sm text-dark-500"><?= htmlspecialchars($row['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm">
                    <span class="px-3 py-1 bg-primary-100 text-primary-700 rounded-full text-xs font-medium">
                      <?= ucfirst($row['role']) ?>
                    </span>
                    <?php if($row['branch_name']): ?>
                    <div class="mt-2 text-sm text-dark-600">
                      <i class="fas fa-building mr-1"></i> <?= htmlspecialchars($row['branch_name']) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <div class="flex gap-2">
                    <button onclick='showEditModal(<?= json_encode($row) ?>)'
                            class="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors">
                      <i class="fas fa-edit text-xs"></i> Edit
                    </button>
                    <button onclick="showPasswordModal(<?= $row['id'] ?>)"
                            class="inline-flex items-center gap-1 px-3 py-1.5 bg-yellow-50 text-yellow-700 rounded-lg hover:bg-yellow-100 transition-colors">
                      <i class="fas fa-key text-xs"></i> Password
                    </button>
                    <?php if($row['id'] != $admin_id): ?>
                    <a href="?delete=<?= $row['id'] ?>"
                       onclick="return confirm('Are you sure you want to delete this administrator? This action cannot be undone.')"
                       class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors">
                      <i class="fas fa-trash text-xs"></i> Delete
                    </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right Column - Branches -->
    <div class="space-y-8">
      <!-- Branches Card -->
      <div class="glass-card rounded-2xl p-6">
        <div class="flex items-center justify-between mb-6">
          <div class="flex items-center gap-3">
            <div class="p-2 bg-purple-100 text-purple-600 rounded-lg">
              <i class="fas fa-sitemap"></i>
            </div>
            <h2 class="text-xl font-semibold text-dark-900">Branches</h2>
          </div>
          <span class="px-3 py-1 bg-dark-100 text-dark-700 rounded-full text-sm">
            <?= count($branches_array) ?> total
          </span>
        </div>
        <div class="space-y-3 max-h-[500px] overflow-y-auto pr-2">
          <?php foreach($branches_array as $branch): ?>
          <div class="flex items-center justify-between p-4 bg-white border border-dark-100 rounded-xl hover:shadow-sm transition-all">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-purple-50 text-purple-600 rounded-lg">
                <i class="fas fa-building"></i>
              </div>
              <span class="font-medium text-dark-900"><?= htmlspecialchars($branch['name']) ?></span>
            </div>
            <a href="?delete_branch=<?= $branch['id'] ?>"
               onclick="return confirm('Delete this branch? All associated data will be affected.')"
               class="p-2 text-red-500 hover:bg-red-50 rounded-lg transition-colors">
              <i class="fas fa-trash"></i>
            </a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="glass-card rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-dark-900 mb-4">System Overview</h3>
        <div class="space-y-4">
          <div class="flex items-center justify-between p-3 bg-blue-50 rounded-xl">
            <span class="text-sm text-dark-600">Active Sessions</span>
            <span class="font-semibold text-blue-700">12</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-green-50 rounded-xl">
            <span class="text-sm text-dark-600">Last Updated</span>
            <span class="font-semibold text-green-700">Just now</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-orange-50 rounded-xl">
            <span class="text-sm text-dark-600">Your Role</span>
            <span class="font-semibold text-orange-700">Super Admin</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Admin Modal -->
<div id="editModal" class="fixed inset-0 modal-overlay hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
    <div class="p-6 border-b border-dark-100">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-dark-900">Edit Administrator</h3>
        <button onclick="hideModals()" class="text-dark-400 hover:text-dark-600">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="POST" class="p-6">
      <input type="hidden" name="admin_id" id="edit_id">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-dark-700 mb-2">Full Name</label>
          <input type="text" name="name" id="edit_name" required
                 class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-dark-700 mb-2">Email</label>
          <input type="email" name="email" id="edit_email" required
                 class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-dark-700 mb-2">Role</label>
          <select name="role" id="edit_role"
                  class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none">
            <?php foreach($roles_array as $role_name): ?>
            <option value="<?= htmlspecialchars($role_name) ?>"><?= ucfirst($role_name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-dark-700 mb-2">Branch</label>
          <select name="branch_id" id="edit_branch_id"
                  class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none">
            <option value="">No Branch</option>
            <?php foreach($branches_array as $b): ?>
            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="flex gap-3 mt-6">
        <button type="button" onclick="hideModals()"
                class="flex-1 px-4 py-3 border border-dark-200 text-dark-700 rounded-xl hover:bg-dark-50 transition-colors">
          Cancel
        </button>
        <button type="submit" name="edit_admin"
                class="flex-1 px-4 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Change Password Modal -->
<div id="passwordModal" class="fixed inset-0 modal-overlay hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
    <div class="p-6 border-b border-dark-100">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-dark-900">Change Password</h3>
        <button onclick="hideModals()" class="text-dark-400 hover:text-dark-600">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="POST" class="p-6">
      <input type="hidden" name="admin_id" id="pass_admin_id">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-dark-700 mb-2">New Password</label>
          <div class="relative">
            <input type="password" name="new_password" placeholder="Enter new password" required
                   class="w-full border border-dark-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-primary-500 focus:border-transparent focus:outline-none">
            <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-3 text-dark-500">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <p class="mt-2 text-xs text-dark-500">Minimum 8 characters with letters and numbers</p>
        </div>
      </div>
      <div class="flex gap-3 mt-6">
        <button type="button" onclick="hideModals()"
                class="flex-1 px-4 py-3 border border-dark-200 text-dark-700 rounded-xl hover:bg-dark-50 transition-colors">
          Cancel
        </button>
        <button type="submit" name="change_password"
                class="flex-1 px-4 py-3 bg-yellow-600 text-white rounded-xl hover:bg-yellow-700 transition-colors">
          Update Password
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function showEditModal(admin){
  document.getElementById('edit_id').value = admin.id;
  document.getElementById('edit_name').value = admin.name;
  document.getElementById('edit_email').value = admin.email;
  document.getElementById('edit_role').value = admin.role;
  document.getElementById('edit_branch_id').value = admin.branch_id || '';
  document.getElementById('editModal').classList.remove('hidden');
}

function showPasswordModal(id){
  document.getElementById('pass_admin_id').value = id;
  document.getElementById('passwordModal').classList.remove('hidden');
}

function hideModals(){
  document.getElementById('editModal').classList.add('hidden');
  document.getElementById('passwordModal').classList.add('hidden');
}

function togglePassword(button){
  const input = button.parentElement.querySelector('input');
  const icon = button.querySelector('i');
  if(input.type === 'password'){
    input.type = 'text';
    icon.className = 'fas fa-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'fas fa-eye';
  }
}

// Close modal on ESC key
document.addEventListener('keydown', (e) => {
  if(e.key === 'Escape') hideModals();
});

// Close modal on outside click
document.addEventListener('click', (e) => {
  if(e.target.classList.contains('modal-overlay')) hideModals();
});
</script>
</body>
</html>