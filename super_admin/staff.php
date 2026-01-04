<?php
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management | Faida LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1c752fff',
                        secondary: '#22bd43ff',
                        success: '#2ecc71',
                        warning: '#f39c12',
                        danger: '#e74c3c',
                        light: '#ecf0f1',
                    }
                }
            }
        }
    </script>
    <style>
        .status-active { background-color: #e8f6ef; color: #2ecc71; }
        .status-suspended { background-color: #fdebd0; color: #f39c12; }
        .status-terminated { background-color: #fadbd8; color: #e74c3c; }
        .status-resigned { background-color: #e8e8e8; color: #95a5a6; }
        .status-on_leave { background-color: #d6eaf8; color: #3498db; }
        
        .role-super_admin { background-color: #8e44ad; }
        .role-loan_officer { background-color: #3498db; }
        .role-branch_manager { background-color: #2ecc71; }
        .role-accountant { background-color: #f39c12; }
        .role-cashier { background-color: #16a085; }
        .role-regional_manager { background-color: #e74c3c; }

        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .slide-in {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8 pb-4 border-b border-gray-200">
            <h1 class="text-3xl font-bold text-primary">Staff Management</h1>
            <div class="flex items-center space-x-4">
                <button id="addStaffBtn" class="bg-secondary hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition duration-200">
                    <i class="fas fa-plus mr-2"></i> Add Staff
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition duration-200 fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Total Staff</h3>
                    <div class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-user-tie"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">4</p>
                <p class="text-sm text-gray-500 mt-2">All staff members</p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition duration-200 fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Active Staff</h3>
                    <div class="w-10 h-10 bg-success rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">4</p>
                <p class="text-sm text-gray-500 mt-2">Currently working</p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition duration-200 fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Loan Officers</h3>
                    <div class="w-10 h-10 bg-warning rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">1</p>
                <p class="text-sm text-gray-500 mt-2">Active loan officers</p>
            </div>
            
            <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition duration-200 fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-gray-500 font-medium">Branch Managers</h3>
                    <div class="w-10 h-10 bg-danger rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">2</p>
                <p class="text-sm text-gray-500 mt-2">Managing branches</p>
            </div>
        </div>

        <!-- Staff Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8 fade-in">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-primary">Staff Members</h2>
                <div class="flex items-center space-x-2">
                    <div class="relative">
                        <input type="text" id="searchStaff" placeholder="Search staff..." class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-secondary focus:border-transparent w-64">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                    <button id="filterBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full" id="staffTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="name">
                                Name <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="email">
                                Email <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="role">
                                Role <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="status">
                                Status <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="date">
                                Date Joined <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="staffTableBody">
                        <!-- Staff data will be populated here -->
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-between items-center">
                <div class="text-sm text-gray-500" id="paginationInfo">Showing 4 of 4 staff members</div>
                <div class="flex space-x-2" id="paginationControls">
                    <!-- Pagination controls will be added here -->
                </div>
            </div>
        </div>

        <!-- Role Distribution -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 fade-in">
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-semibold text-primary">Staff by Role</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4" id="roleDistribution">
                        <!-- Role distribution will be populated here -->
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-semibold text-primary">Staff by Branch</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4" id="branchDistribution">
                        <!-- Branch distribution will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-xl bg-white slide-in">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-xl font-semibold text-primary">Add New Staff Member</h3>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl transition duration-200">
                    &times;
                </button>
            </div>
            
            <form id="addStaffForm" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                        <input type="text" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">National ID</label>
                        <input type="text" name="national_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                        <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                            <option value="">Select Role</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="loan_officer">Loan Officer</option>
                            <option value="branch_manager">Branch Manager</option>
                            <option value="accountant">Accountant</option>
                            <option value="cashier">Cashier</option>
                            <option value="regional_manager">Regional Manager</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                        <select name="branch" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                            <option value="">Select Branch</option>
                            <option value="masii">masii</option>
                            <option value="kikima">kikima</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee Number</label>
                        <input type="text" name="employee_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                        <input type="text" name="position" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employment Type</label>
                        <select name="employment_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="intern">Intern</option>
                            <option value="casual">Casual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Joined</label>
                        <input type="date" name="date_joined" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                    <input type="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required minlength="6">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
                    <input type="password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                </div>
            </form>
            
            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                <button class="close-modal px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                    Cancel
                </button>
                <button id="saveStaffBtn" class="px-4 py-2 bg-success text-white rounded-lg hover:bg-green-600 transition duration-200">
                    Save Staff
                </button>
            </div>
        </div>
    </div>

    <!-- View Staff Modal -->
    <div id="viewStaffModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-xl bg-white slide-in">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-xl font-semibold text-primary">Staff Details</h3>
                <button class="close-view-modal text-gray-400 hover:text-gray-600 text-2xl transition duration-200">
                    &times;
                </button>
            </div>
            
            <div class="mt-4 space-y-4" id="staffDetails">
                <!-- Staff details will be populated here -->
            </div>
            
            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                <button class="close-view-modal px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div id="editStaffModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-xl bg-white slide-in">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-xl font-semibold text-primary">Edit Staff Member</h3>
                <button class="close-edit-modal text-gray-400 hover:text-gray-600 text-2xl transition duration-200">
                    &times;
                </button>
            </div>
            
            <form id="editStaffForm" class="mt-4 space-y-4">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                        <input type="text" name="edit_name" id="edit_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="edit_email" id="edit_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="edit_phone" id="edit_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">National ID</label>
                        <input type="text" name="edit_national_id" id="edit_national_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                        <select name="edit_role" id="edit_role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent" required>
                            <option value="">Select Role</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="loan_officer">Loan Officer</option>
                            <option value="branch_manager">Branch Manager</option>
                            <option value="accountant">Accountant</option>
                            <option value="cashier">Cashier</option>
                            <option value="regional_manager">Regional Manager</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                        <select name="edit_branch" id="edit_branch" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                            <option value="">Select Branch</option>
                            <option value="masii">masii</option>
                            <option value="kikima">kikima</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee Number</label>
                        <input type="text" name="edit_employee_number" id="edit_employee_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                        <input type="text" name="edit_position" id="edit_position" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employment Type</label>
                        <select name="edit_employment_type" id="edit_employment_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="intern">Intern</option>
                            <option value="casual">Casual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employment Status</label>
                        <select name="edit_employment_status" id="edit_employment_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="terminated">Terminated</option>
                            <option value="resigned">Resigned</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Joined</label>
                        <input type="date" name="edit_date_joined" id="edit_date_joined" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contract End Date</label>
                        <input type="date" name="edit_contract_end_date" id="edit_contract_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-secondary focus:border-transparent">
                    </div>
                </div>
            </form>
            
            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                <button class="close-edit-modal px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                    Cancel
                </button>
                <button id="updateStaffBtn" class="px-4 py-2 bg-success text-white rounded-lg hover:bg-green-600 transition duration-200">
                    Update Staff
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteStaffModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-xl bg-white slide-in">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-xl font-semibold text-primary">Confirm Deletion</h3>
                <button class="close-delete-modal text-gray-400 hover:text-gray-600 text-2xl transition duration-200">
                    &times;
                </button>
            </div>
            
            <div class="mt-4">
                <p class="text-gray-700">Are you sure you want to delete <strong id="deleteStaffName"></strong>?</p>
                <p class="text-red-600 text-sm mt-2">This action cannot be undone!</p>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                <button class="close-delete-modal px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                    Cancel
                </button>
                <button id="confirmDeleteBtn" class="px-4 py-2 bg-danger text-white rounded-lg hover:bg-red-700 transition duration-200">
                    Delete Staff
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed top-4 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full z-50">
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <span id="toastMessage">Operation completed successfully!</span>
        </div>
    </div>

    <script>
        // Sample staff data
        const staffData = [
            {
                id: 1,
                name: "Jackson Mbwika",
                email: "jacksonmuoki16@gmail.com",
                phone: "0795470003",
                national_id: "46583645",
                role: "super_admin",
                branch: "",
                employee_number: "",
                position: "",
                employment_type: "permanent",
                employment_status: "active",
                date_joined: "2025-08-24",
                contract_end_date: null
            },
            {
                id: 2,
                name: "jackson mbwika",
                email: "branch@gmail.com",
                phone: "",
                national_id: "",
                role: "branch_manager",
                branch: "masii",
                employee_number: "",
                position: "",
                employment_type: "permanent",
                employment_status: "active",
                date_joined: "2025-08-24",
                contract_end_date: null
            },
            {
                id: 3,
                name: "faida",
                email: "admin@faida.com",
                phone: "",
                national_id: "",
                role: "branch_manager",
                branch: "kikima",
                employee_number: "",
                position: "",
                employment_type: "permanent",
                employment_status: "active",
                date_joined: "2025-08-24",
                contract_end_date: null
            },
            {
                id: 4,
                name: "Jackson Jack Tech Mbwika",
                email: "rahisicapital@gmail.com",
                phone: "0795470003",
                national_id: "46583645",
                role: "loan_officer",
                branch: "masii",
                employee_number: "100",
                position: "officer",
                employment_type: "contract",
                employment_status: "active",
                date_joined: "2025-11-14",
                contract_end_date: "2025-11-30"
            }
        ];

        // State management
        let currentStaffData = [...staffData];
        let currentSort = { field: 'name', direction: 'asc' };
        let currentPage = 1;
        const itemsPerPage = 5;
        let staffToDelete = null;

        // DOM Elements
        const staffTableBody = document.getElementById('staffTableBody');
        const roleDistribution = document.getElementById('roleDistribution');
        const branchDistribution = document.getElementById('branchDistribution');
        const paginationInfo = document.getElementById('paginationInfo');
        const paginationControls = document.getElementById('paginationControls');
        const searchStaff = document.getElementById('searchStaff');

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            initializeModals();
            renderStaffTable();
            updateDistributions();
            setupEventListeners();
        });

        function initializeModals() {
            // Add Staff Modal
            const addStaffBtn = document.getElementById('addStaffBtn');
            const addStaffModal = document.getElementById('addStaffModal');
            const closeModal = document.querySelectorAll('.close-modal');
            
            addStaffBtn.addEventListener('click', function() {
                addStaffModal.classList.remove('hidden');
                document.getElementById('addStaffForm').reset();
            });
            
            closeModal.forEach(btn => {
                btn.addEventListener('click', function() {
                    addStaffModal.classList.add('hidden');
                });
            });

            // View Staff Modal
            const closeViewModal = document.querySelectorAll('.close-view-modal');
            closeViewModal.forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('viewStaffModal').classList.add('hidden');
                });
            });

            // Edit Staff Modal
            const closeEditModal = document.querySelectorAll('.close-edit-modal');
            closeEditModal.forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('editStaffModal').classList.add('hidden');
                });
            });

            // Delete Staff Modal
            const closeDeleteModal = document.querySelectorAll('.close-delete-modal');
            closeDeleteModal.forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('deleteStaffModal').classList.add('hidden');
                });
            });

            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                const modals = ['addStaffModal', 'viewStaffModal', 'editStaffModal', 'deleteStaffModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (event.target === modal) {
                        modal.classList.add('hidden');
                    }
                });
            });

            // Role-based branch selection
            const roleSelect = document.querySelector('select[name="role"]');
            const branchSelect = document.querySelector('select[name="branch"]');
            
            if (roleSelect) {
                roleSelect.addEventListener('change', function() {
                    if (roleSelect.value === 'super_admin') {
                        branchSelect.disabled = true;
                        branchSelect.value = '';
                    } else {
                        branchSelect.disabled = false;
                    }
                });
            }

            // Edit form role-based branch selection
            const editRoleSelect = document.getElementById('edit_role');
            const editBranchSelect = document.getElementById('edit_branch');
            
            if (editRoleSelect) {
                editRoleSelect.addEventListener('change', function() {
                    if (editRoleSelect.value === 'super_admin') {
                        editBranchSelect.disabled = true;
                        editBranchSelect.value = '';
                    } else {
                        editBranchSelect.disabled = false;
                    }
                });
            }
        }

        function setupEventListeners() {
            // Search functionality
            searchStaff.addEventListener('input', function() {
                filterStaff(this.value);
            });

            // Sort functionality
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const field = this.dataset.sort;
                    sortStaff(field);
                });
            });

            // Save staff
            document.getElementById('saveStaffBtn').addEventListener('click', function() {
                saveStaff();
            });

            // Update staff
            document.getElementById('updateStaffBtn').addEventListener('click', function() {
                updateStaff();
            });

            // Confirm delete
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                deleteStaff();
            });
        }

        function renderStaffTable() {
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedData = currentStaffData.slice(startIndex, endIndex);

            staffTableBody.innerHTML = '';

            if (paginatedData.length === 0) {
                staffTableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                            No staff members found
                        </td>
                    </tr>
                `;
                return;
            }

            paginatedData.forEach(staff => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50 transition duration-150 fade-in';
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">${staff.name}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500">${staff.email}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full text-white role-${staff.role}">
                            ${formatRole(staff.role)}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${staff.branch || '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full status-${staff.employment_status}">
                            ${formatStatus(staff.employment_status)}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${formatDate(staff.date_joined)}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div class="flex space-x-2">
                            <button class="view-staff text-secondary hover:text-blue-800 transition duration-150" data-id="${staff.id}">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="edit-staff text-warning hover:text-yellow-700 transition duration-150" data-id="${staff.id}">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="delete-staff text-danger hover:text-red-800 transition duration-150" data-id="${staff.id}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                staffTableBody.appendChild(row);
            });

            // Add event listeners to action buttons
            document.querySelectorAll('.view-staff').forEach(btn => {
                btn.addEventListener('click', function() {
                    viewStaff(parseInt(this.dataset.id));
                });
            });

            document.querySelectorAll('.edit-staff').forEach(btn => {
                btn.addEventListener('click', function() {
                    editStaff(parseInt(this.dataset.id));
                });
            });

            document.querySelectorAll('.delete-staff').forEach(btn => {
                btn.addEventListener('click', function() {
                    confirmDelete(parseInt(this.dataset.id));
                });
            });

            updatePagination();
        }

        function updateDistributions() {
            // Role distribution
            const roleCounts = {};
            currentStaffData.forEach(staff => {
                roleCounts[staff.role] = (roleCounts[staff.role] || 0) + 1;
            });

            roleDistribution.innerHTML = '';
            Object.entries(roleCounts).forEach(([role, count]) => {
                const item = document.createElement('div');
                item.className = 'flex justify-between items-center';
                item.innerHTML = `
                    <span class="text-gray-700">${formatRole(role)}</span>
                    <span class="font-semibold">${count}</span>
                `;
                roleDistribution.appendChild(item);
            });

            // Branch distribution
            const branchCounts = {};
            currentStaffData.forEach(staff => {
                const branch = staff.branch || 'No Branch';
                branchCounts[branch] = (branchCounts[branch] || 0) + 1;
            });

            branchDistribution.innerHTML = '';
            Object.entries(branchCounts).forEach(([branch, count]) => {
                const item = document.createElement('div');
                item.className = 'flex justify-between items-center';
                item.innerHTML = `
                    <span class="text-gray-700">${branch}</span>
                    <span class="font-semibold">${count}</span>
                `;
                branchDistribution.appendChild(item);
            });
        }

        function updatePagination() {
            const totalPages = Math.ceil(currentStaffData.length / itemsPerPage);
            const startItem = (currentPage - 1) * itemsPerPage + 1;
            const endItem = Math.min(currentPage * itemsPerPage, currentStaffData.length);

            paginationInfo.textContent = `Showing ${startItem}-${endItem} of ${currentStaffData.length} staff members`;

            paginationControls.innerHTML = '';

            if (totalPages <= 1) return;

            // Previous button
            const prevButton = document.createElement('button');
            prevButton.className = `px-3 py-1 rounded-lg border border-gray-300 ${currentPage === 1 ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white text-gray-700 hover:bg-gray-50'}`;
            prevButton.innerHTML = '<i class="fas fa-chevron-left"></i>';
            prevButton.disabled = currentPage === 1;
            prevButton.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderStaffTable();
                }
            });
            paginationControls.appendChild(prevButton);

            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                const pageButton = document.createElement('button');
                pageButton.className = `px-3 py-1 rounded-lg border ${currentPage === i ? 'bg-secondary text-white border-secondary' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'}`;
                pageButton.textContent = i;
                pageButton.addEventListener('click', () => {
                    currentPage = i;
                    renderStaffTable();
                });
                paginationControls.appendChild(pageButton);
            }

            // Next button
            const nextButton = document.createElement('button');
            nextButton.className = `px-3 py-1 rounded-lg border border-gray-300 ${currentPage === totalPages ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white text-gray-700 hover:bg-gray-50'}`;
            nextButton.innerHTML = '<i class="fas fa-chevron-right"></i>';
            nextButton.disabled = currentPage === totalPages;
            nextButton.addEventListener('click', () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderStaffTable();
                }
            });
            paginationControls.appendChild(nextButton);
        }

        function filterStaff(searchTerm) {
            if (!searchTerm) {
                currentStaffData = [...staffData];
            } else {
                currentStaffData = staffData.filter(staff => 
                    staff.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    staff.email.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    staff.role.toLowerCase().includes(searchTerm.toLowerCase()) ||
                    (staff.branch && staff.branch.toLowerCase().includes(searchTerm.toLowerCase()))
                );
            }
            currentPage = 1;
            renderStaffTable();
            updateDistributions();
        }

        function sortStaff(field) {
            if (currentSort.field === field) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.field = field;
                currentSort.direction = 'asc';
            }

            currentStaffData.sort((a, b) => {
                let aValue = a[field];
                let bValue = b[field];

                if (field === 'date') {
                    aValue = a.date_joined;
                    bValue = b.date_joined;
                }

                if (aValue < bValue) return currentSort.direction === 'asc' ? -1 : 1;
                if (aValue > bValue) return currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });

            renderStaffTable();

            // Update sort indicators
            document.querySelectorAll('.sortable i').forEach(icon => {
                icon.className = 'fas fa-sort ml-1';
            });

            const currentHeader = document.querySelector(`[data-sort="${field}"] i`);
            if (currentHeader) {
                currentHeader.className = currentSort.direction === 'asc' ? 
                    'fas fa-sort-up ml-1' : 'fas fa-sort-down ml-1';
            }
        }

        function viewStaff(id) {
            const staff = staffData.find(s => s.id === id);
            if (!staff) return;

            const staffDetails = document.getElementById('staffDetails');
            staffDetails.innerHTML = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.name}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.email}</div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.phone || 'Not provided'}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">National ID</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.national_id || 'Not provided'}</div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${formatRole(staff.role)}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.branch || 'Not assigned'}</div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee Number</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.employee_number || 'Not assigned'}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.position || 'Not specified'}</div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employment Type</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${formatEmploymentType(staff.employment_type)}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employment Status</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full status-${staff.employment_status}">
                                ${formatStatus(staff.employment_status)}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Joined</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${formatDate(staff.date_joined)}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contract End Date</label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">${staff.contract_end_date ? formatDate(staff.contract_end_date) : 'Not specified'}</div>
                    </div>
                </div>
            `;

            document.getElementById('viewStaffModal').classList.remove('hidden');
        }

        function editStaff(id) {
            const staff = staffData.find(s => s.id === id);
            if (!staff) return;

            // Populate form fields
            document.getElementById('edit_id').value = staff.id;
            document.getElementById('edit_name').value = staff.name;
            document.getElementById('edit_email').value = staff.email;
            document.getElementById('edit_phone').value = staff.phone || '';
            document.getElementById('edit_national_id').value = staff.national_id || '';
            document.getElementById('edit_role').value = staff.role;
            document.getElementById('edit_branch').value = staff.branch || '';
            document.getElementById('edit_employee_number').value = staff.employee_number || '';
            document.getElementById('edit_position').value = staff.position || '';
            document.getElementById('edit_employment_type').value = staff.employment_type;
            document.getElementById('edit_employment_status').value = staff.employment_status;
            document.getElementById('edit_date_joined').value = staff.date_joined;
            document.getElementById('edit_contract_end_date').value = staff.contract_end_date || '';

            // Handle role-based branch selection
            if (staff.role === 'super_admin') {
                document.getElementById('edit_branch').disabled = true;
            } else {
                document.getElementById('edit_branch').disabled = false;
            }

            document.getElementById('editStaffModal').classList.remove('hidden');
        }

        function confirmDelete(id) {
            const staff = staffData.find(s => s.id === id);
            if (!staff) return;

            staffToDelete = id;
            document.getElementById('deleteStaffName').textContent = staff.name;
            document.getElementById('deleteStaffModal').classList.remove('hidden');
        }

        function saveStaff() {
            const form = document.getElementById('addStaffForm');
            const formData = new FormData(form);

            // Basic validation
            if (!formData.get('name') || !formData.get('email') || !formData.get('password')) {
                showToast('Please fill in all required fields', 'error');
                return;
            }

            if (formData.get('password') !== formData.get('confirm_password')) {
                showToast('Passwords do not match', 'error');
                return;
            }

            // Create new staff object
            const newStaff = {
                id: staffData.length + 1,
                name: formData.get('name'),
                email: formData.get('email'),
                phone: formData.get('phone'),
                national_id: formData.get('national_id'),
                role: formData.get('role'),
                branch: formData.get('branch'),
                employee_number: formData.get('employee_number'),
                position: formData.get('position'),
                employment_type: formData.get('employment_type'),
                employment_status: 'active',
                date_joined: formData.get('date_joined') || new Date().toISOString().split('T')[0],
                contract_end_date: null
            };

            // Add to data
            staffData.push(newStaff);
            currentStaffData = [...staffData];

            // Update UI
            renderStaffTable();
            updateDistributions();

            // Close modal and show success message
            document.getElementById('addStaffModal').classList.add('hidden');
            showToast('Staff member added successfully!');

            // Reset form
            form.reset();
        }

        function updateStaff() {
            const form = document.getElementById('editStaffForm');
            const formData = new FormData(form);
            const id = parseInt(formData.get('edit_id'));

            const staffIndex = staffData.findIndex(s => s.id === id);
            if (staffIndex === -1) return;

            // Update staff data
            staffData[staffIndex] = {
                ...staffData[staffIndex],
                name: formData.get('edit_name'),
                email: formData.get('edit_email'),
                phone: formData.get('edit_phone'),
                national_id: formData.get('edit_national_id'),
                role: formData.get('edit_role'),
                branch: formData.get('edit_branch'),
                employee_number: formData.get('edit_employee_number'),
                position: formData.get('edit_position'),
                employment_type: formData.get('edit_employment_type'),
                employment_status: formData.get('edit_employment_status'),
                date_joined: formData.get('edit_date_joined'),
                contract_end_date: formData.get('edit_contract_end_date') || null
            };

            currentStaffData = [...staffData];

            // Update UI
            renderStaffTable();
            updateDistributions();

            // Close modal and show success message
            document.getElementById('editStaffModal').classList.add('hidden');
            showToast('Staff member updated successfully!');
        }

        function deleteStaff() {
            if (!staffToDelete) return;

            const staffIndex = staffData.findIndex(s => s.id === staffToDelete);
            if (staffIndex === -1) return;

            // Remove from data
            staffData.splice(staffIndex, 1);
            currentStaffData = [...staffData];

            // Update UI
            renderStaffTable();
            updateDistributions();

            // Close modal and show success message
            document.getElementById('deleteStaffModal').classList.add('hidden');
            showToast('Staff member deleted successfully!');

            staffToDelete = null;
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            toastMessage.textContent = message;
            
            if (type === 'error') {
                toast.className = toast.className.replace('bg-green-600', 'bg-red-600');
            } else {
                toast.className = toast.className.replace('bg-red-600', 'bg-green-600');
            }

            toast.classList.remove('translate-x-full');
            toast.classList.add('translate-x-0');

            setTimeout(() => {
                toast.classList.remove('translate-x-0');
                toast.classList.add('translate-x-full');
            }, 3000);
        }

        // Utility functions
        function formatRole(role) {
            return role.split('_').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
        }

        function formatStatus(status) {
            return status.split('_').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
        }

        function formatEmploymentType(type) {
            return type.charAt(0).toUpperCase() + type.slice(1);
        }

        function formatDate(dateString) {
            if (!dateString) return 'Not specified';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
    </script>
</body>
</html>