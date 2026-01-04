<?php
session_start();
include 'header.php';
include '../includes/db.php';

/* ===============================
   HELPER FUNCTION (DEVICE SAFE)
================================ */

function processImageUpload($file, $target_path) {
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception("Invalid upload source.");
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception("Uploaded file is not a valid image.");
    }

    $mime = $imageInfo['mime'];

    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $image = imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($file['tmp_name']);
            break;
        default:
            throw new Exception("Unsupported image format.");
    }

    // Fix iPhone rotation
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0); break;
            }
        }
    }

    imagejpeg($image, $target_path, 85);
    imagedestroy($image);
}

/* ===============================
   SESSION DATA
================================ */

$officer_id   = $_SESSION['admin']['id'] ?? null;
$branch_id    = $_SESSION['admin']['branch_id'] ?? null;
$officer_name = $_SESSION['admin']['name'] ?? '';
$branch_name  = '';
$success_msg  = '';
$error_msg    = '';

/* ===============================
   FETCH BRANCH NAME
================================ */

if ($branch_id) {
    $stmt = $conn->prepare("SELECT name FROM branches WHERE id=?");
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $branch_name = $row['name'];
    }
}

/* ===============================
   FETCH OFFICERS
================================ */

$officers = [];
$res = $conn->query("SELECT id, name FROM admin_users WHERE role='loan_officer'");
while ($row = $res->fetch_assoc()) {
    $officers[] = $row;
}

/* ===============================
   FORM SUBMISSION
================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        $customer_code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));

        $national_id   = trim($_POST['national_id']);
        $phone_number  = trim($_POST['phone_number']);

        $first_name    = trim($_POST['first_name']);
        $middle_name   = trim($_POST['middle_name'] ?? '');
        $surname       = trim($_POST['surname']);
        $gender        = $_POST['gender'];
        $date_of_birth = $_POST['date_of_birth'] ?: NULL;
        $marital_status= $_POST['marital_status'];
        $email         = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

        $county        = trim($_POST['county']);
        $sub_county    = trim($_POST['sub_county']);
        $ward          = trim($_POST['ward'] ?? '');
        $location      = trim($_POST['location'] ?? '');
        $sub_location  = trim($_POST['sub_location'] ?? '');
        $village       = trim($_POST['village'] ?? '');
        $address       = trim($_POST['address'] ?? '');

        $loan_officer_id = intval($_POST['loan_officer_id']);
        $branch_id       = intval($_POST['branch_id']);
        $pre_qualified_amount = floatval($_POST['pre_qualified_amount']);

        if (!$first_name || !$surname || !$national_id || !$phone_number) {
            throw new Exception("Required fields missing.");
        }

        $mpesa_number = $phone_number;

        /* ===============================
           GUARANTOR & NEXT OF KIN
        ================================ */

        $guarantor_first_name = trim($_POST['guarantor_first_name']);
        $guarantor_middle_name= trim($_POST['guarantor_middle_name'] ?? '');
        $guarantor_surname    = trim($_POST['guarantor_surname']);
        $guarantor_id_number  = trim($_POST['guarantor_id_number']);
        $guarantor_phone      = trim($_POST['guarantor_phone']);
        $guarantor_relationship = trim($_POST['guarantor_relationship']);

        $next_of_kin_name = trim($_POST['next_of_kin_name']);
        $next_of_kin_relationship = trim($_POST['next_of_kin_relationship']);
        $next_of_kin_phone = trim($_POST['next_of_kin_phone']);

        /* ===============================
           DEFAULTS
        ================================ */

        $group_id = NULL;
        $kra_pin  = '';
        $dependents = 0;
        $employment_status = NULL;
        $employer_name = NULL;
        $occupation = NULL;
        $monthly_income = NULL;
        $status = 'Inactive';
        $customer_account_balance = 0;
        $balance = 0;
        $savings_balance = 0;
        $officer_id = $loan_officer_id;

        /* ===============================
           IMAGE UPLOADS
        ================================ */

        $photo_paths = [];
        $guarantor_paths = [];

        $customer_files = [
            'customer_photo' => 'customer/photo/',
            'customer_id_front' => 'customer/id_front/',
            'customer_id_back' => 'customer/id_back/'
        ];

        foreach ($customer_files as $field => $folder) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("$field required");
            }

            $upload_dir = "../uploads/$folder";
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

            $target = $upload_dir . $customer_code . "_$field.jpg";
            processImageUpload($_FILES[$field], $target);
            $photo_paths[$field] = $target;
        }

        $guarantor_files = [
            'guarantor_photo' => 'guarantor/photo/',
            'guarantor_id_front' => 'guarantor/id_front/',
            'guarantor_id_back' => 'guarantor/id_back/'
        ];

        foreach ($guarantor_files as $field => $folder) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("$field required");
            }

            $upload_dir = "../uploads/$folder";
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

            $target = $upload_dir . $customer_code . "_$field.jpg";
            processImageUpload($_FILES[$field], $target);
            $guarantor_paths[$field] = $target;
        }

        /* ===============================
           INSERT CUSTOMER
        ================================ */

        $sql = "INSERT INTO customers (
            branch_id, group_id, loan_officer_id,
            customer_photo, customer_id_front, customer_id_back,
            guarantor_photo, guarantor_id_front, guarantor_id_back,
            customer_code, first_name, middle_name, surname, gender,
            date_of_birth, national_id, kra_pin, marital_status, dependents,
            phone_number, mpesa_number, email,
            county, sub_county, ward, location, sub_location, village, address,
            employment_status, employer_name, occupation, monthly_income,
            next_of_kin_name, next_of_kin_relationship, next_of_kin_phone,
            guarantor_first_name, guarantor_middle_name, guarantor_surname,
            guarantor_id_number, guarantor_phone, guarantor_relationship,
            pre_qualified_amount, status,
            customer_account_balance, balance, savings_balance, officer_id
        ) VALUES (" . str_repeat('?,', 49) . "?)";

        $stmt = $conn->prepare($sql);

        $params = [
            $branch_id,$group_id,$loan_officer_id,
            $photo_paths['customer_photo'],$photo_paths['customer_id_front'],$photo_paths['customer_id_back'],
            $guarantor_paths['guarantor_photo'],$guarantor_paths['guarantor_id_front'],$guarantor_paths['guarantor_id_back'],
            $customer_code,$first_name,$middle_name,$surname,$gender,
            $date_of_birth,$national_id,$kra_pin,$marital_status,$dependents,
            $phone_number,$mpesa_number,$email,
            $county,$sub_county,$ward,$location,$sub_location,$village,$address,
            $employment_status,$employer_name,$occupation,$monthly_income,
            $next_of_kin_name,$next_of_kin_relationship,$next_of_kin_phone,
            $guarantor_first_name,$guarantor_middle_name,$guarantor_surname,
            $guarantor_id_number,$guarantor_phone,$guarantor_relationship,
            $pre_qualified_amount,$status,
            $customer_account_balance,$balance,$savings_balance,$officer_id
        ];

        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();
        $success_msg = "Customer registered successfully! Code: $customer_code";

    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Faida Customer Registration</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                faida: {
                    primary: '#15a362',
                    'primary-dark': '#128d51',
                    'primary-light': '#e8f7f0',
                    secondary: '#2d3748',
                    accent: '#3b82f6'
                }
            },
            animation: {
                'fade-in': 'fadeIn 0.5s ease-in-out',
                'slide-up': 'slideUp 0.3s ease-out',
                'pulse-slow': 'pulse 3s infinite'
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' }
                },
                slideUp: {
                    '0%': { transform: 'translateY(10px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' }
                }
            }
        }
    }
}
</script>
<style>
:root { 
    --faida-green: #15a362; 
    --faida-dark: #128d51;
    --faida-light: #e8f7f0;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: linear-gradient(135deg, #f6f9fc 0%, #f1f5f9 100%);
}

.form-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    overflow: hidden;
}

.form-header {
    background: linear-gradient(135deg, #15a362 0%, #128d51 100%);
    padding: 24px 32px;
    color: white;
    position: relative;
}

.form-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
}

.form-content {
    padding: 32px;
}

.step-container {
    animation: fade-in 0.4s ease-out;
}

.step-title {
    color: #1f2937;
    font-weight: 600;
    font-size: 1.25rem;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f3f4f6;
    display: flex;
    align-items: center;
    gap: 12px;
}

.step-title i {
    color: #15a362;
    background: #e8f7f0;
    padding: 8px;
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.step-subtitle {
    color: #4b5563;
    font-weight: 500;
    font-size: 1rem;
    margin: 24px 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.step-subtitle i {
    color: #15a362;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 8px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.required::after {
    content: '*';
    color: #ef4444;
    margin-left: 2px;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 0.9375rem;
    transition: all 0.2s ease;
    background: white;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #15a362;
    box-shadow: 0 0 0 3px rgba(21, 163, 98, 0.1);
}

.form-input:hover, .form-select:hover {
    border-color: #d1d5db;
}

.form-textarea {
    min-height: 80px;
    resize: vertical;
}

.file-upload-group {
    position: relative;
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    padding: 20px;
    background: #f9fafb;
    transition: all 0.3s ease;
    cursor: pointer;
}

.file-upload-group:hover {
    border-color: #15a362;
    background: #f0fdf4;
    transform: translateY(-2px);
}

.file-upload-group input[type="file"] {
    position: absolute;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    top: 0;
    left: 0;
}

.file-upload-content {
    text-align: center;
    pointer-events: none;
}

.file-upload-icon {
    font-size: 2rem;
    color: #15a362;
    margin-bottom: 12px;
}

.file-upload-text {
    color: #374151;
    font-weight: 500;
    margin-bottom: 4px;
}

.file-upload-hint {
    font-size: 0.875rem;
    color: #6b7280;
}

.image-preview {
    margin-top: 12px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid #e5e7eb;
    max-height: 140px;
    width: auto;
    display: none;
}

.image-preview.visible {
    display: block;
    animation: slide-up 0.3s ease-out;
}

.progress-container {
    background: white;
    padding: 20px 32px;
    border-bottom: 1px solid #e5e7eb;
}

.progress-bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #15a362, #22c55e);
    border-radius: 3px;
    transition: width 0.5s ease;
}

.progress-text {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #4b5563;
    font-size: 0.875rem;
}

.step-indicators {
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
}

.step-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #d1d5db;
    transition: all 0.3s ease;
}

.step-dot.active {
    background: #15a362;
    transform: scale(1.2);
    box-shadow: 0 0 0 4px rgba(21, 163, 98, 0.2);
}

.step-dot.completed {
    background: #15a362;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 40px;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

.btn {
    padding: 12px 28px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9375rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, #15a362 0%, #128d51 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(21, 163, 98, 0.2);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(21, 163, 98, 0.3);
}

.btn-secondary {
    background: white;
    color: #374151;
    border: 2px solid #e5e7eb;
}

.btn-secondary:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 20px;
    color: #15803d;
    font-size: 0.875rem;
    font-weight: 500;
}

.status-badge i {
    color: #22c55e;
}

/* Loading animation */
.loading-spinner {
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Notification styles */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
    animation: slide-up 0.3s ease-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-content {
        padding: 20px;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .form-actions button {
        width: 100%;
    }
}

@media (max-width: 640px) {
    .form-header {
        padding: 20px;
    }
    
    .progress-container {
        padding: 16px 20px;
    }
}
</style>
</head>
<body class="min-h-screen p-4 md:p-6">
<div class="w-full max-w-6xl mx-auto">
    
    <!-- Success/Error Messages -->
    <?php if ($success_msg): ?>
    <div class="notification mb-6 animate-slide-up">
        <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-lg shadow-lg">
            <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-green-500"></i>
                <span class="font-medium"><?php echo htmlspecialchars($success_msg); ?></span>
                <a href="customers.php" class="ml-4 text-sm underline hover:no-underline">View Customers</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
    <div class="notification mb-6 animate-slide-up">
        <div class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded-lg shadow-lg">
            <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-red-500"></i>
                <span class="font-medium"><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="form-container">
        <div class="form-header">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">Customer Registration</h1>
                    <p class="text-white/90 mt-1">Complete all steps to register a new customer</p>
                </div>
            </div>
        </div>
        <!-- Header with Progress -->
        <div class="progress-container">
            <div class="progress-bar">
                <div id="progress-fill" class="progress-fill" style="width: 25%"></div>
            </div>
            <div class="progress-text">
                <span id="progress-status">Step 1 of 4</span>
                <span id="progress-percent" class="font-semibold text-faida-primary">25%</span>
            </div>
            <div class="step-indicators">
                <div class="step-dot active"></div>
                <div class="step-dot"></div>
                <div class="step-dot"></div>
                <div class="step-dot"></div>
            </div>
        </div>

        <!-- Form Content -->
        <div class="form-content">
            <form id="regForm" method="POST" enctype="multipart/form-data">
                <!-- Step 1 -->
                <div class="step step-container">
                    <div class="step-title">
                        <i class="fas fa-user-circle"></i>
                        Customer Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">First Name</label>
                            <input type="text" name="first_name" required class="form-input" 
                                   placeholder="Enter first name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-input" 
                                   placeholder="Enter middle name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Surname</label>
                            <input type="text" name="surname" required class="form-input" 
                                   placeholder="Enter surname" value="<?php echo htmlspecialchars($_POST['surname'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">National ID</label>
                            <input type="text" name="national_id" required class="form-input" 
                                   placeholder="Enter ID number" value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Gender</label>
                            <select name="gender" required class="form-select">
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($_POST['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($_POST['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($_POST['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-input" 
                                   value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Marital Status</label>
                            <select name="marital_status" required class="form-select">
                                <option value="">Select Status</option>
                                <option value="Single" <?php echo ($_POST['marital_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo ($_POST['marital_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Divorced" <?php echo ($_POST['marital_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                <option value="Widowed" <?php echo ($_POST['marital_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Phone Number</label>
                            <input type="tel" name="phone_number" id="phone_number" required class="form-input" 
                                   placeholder="07XXXXXXXX" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" 
                                   placeholder="customer@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">County</label>
                            <input type="text" name="county" required class="form-input" 
                                   placeholder="Enter county" value="<?php echo htmlspecialchars($_POST['county'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Sub-county</label>
                            <input type="text" name="sub_county" required class="form-input" 
                                   placeholder="Enter sub-county" value="<?php echo htmlspecialchars($_POST['sub_county'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Ward</label>
                            <input type="text" name="ward" class="form-input" 
                                   placeholder="Enter ward" value="<?php echo htmlspecialchars($_POST['ward'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-input" 
                                   placeholder="Enter location" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sub-location</label>
                            <input type="text" name="sub_location" class="form-input" 
                                   placeholder="Enter sub-location" value="<?php echo htmlspecialchars($_POST['sub_location'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Village</label>
                            <input type="text" name="village" class="form-input" 
                                   placeholder="Enter village" value="<?php echo htmlspecialchars($_POST['village'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label class="form-label">Full Address</label>
                        <textarea name="address" rows="2" class="form-textarea" 
                                  placeholder="Enter complete address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Customer Photos -->
                    <div class="step-subtitle">
                        <i class="fas fa-camera"></i>
                        Customer Photos
                    </div>
                    
                    <div class="form-grid">
                        <div class="file-upload-group">
                            <input type="file" name="customer_photo" id="customer_photo" accept="image/*" required onchange="previewImg(this,'cust_photo_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-portrait"></i>
                                </div>
                                <div class="file-upload-text required">Passport Photo</div>
                                <div class="file-upload-hint">Click or drag to upload</div>
                            </div>
                            <img id="cust_photo_preview" class="image-preview">
                        </div>
                        
                        <div class="file-upload-group">
                            <input type="file" name="customer_id_front" id="customer_id_front" accept="image/*" required onchange="previewImg(this,'cust_id_front_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="file-upload-text required">ID Front Photo</div>
                                <div class="file-upload-hint">Clear image of ID front</div>
                            </div>
                            <img id="cust_id_front_preview" class="image-preview">
                        </div>
                        
                        <div class="file-upload-group">
                            <input type="file" name="customer_id_back" id="customer_id_back" accept="image/*" required onchange="previewImg(this,'cust_id_back_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="file-upload-text required">ID Back Photo</div>
                                <div class="file-upload-hint">Clear image of ID back</div>
                            </div>
                            <img id="cust_id_back_preview" class="image-preview">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <div></div>
                        <button type="button" onclick="nextStep(1)" class="btn btn-primary">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="step step-container hidden">
                    <div class="step-title">
                        <i class="fas fa-user-shield"></i>
                        Guarantor Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">First Name</label>
                            <input type="text" name="guarantor_first_name" required class="form-input" 
                                   placeholder="Enter first name" value="<?php echo htmlspecialchars($_POST['guarantor_first_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="guarantor_middle_name" class="form-input" 
                                   placeholder="Enter middle name" value="<?php echo htmlspecialchars($_POST['guarantor_middle_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Surname</label>
                            <input type="text" name="guarantor_surname" required class="form-input" 
                                   placeholder="Enter surname" value="<?php echo htmlspecialchars($_POST['guarantor_surname'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">ID Number</label>
                            <input type="text" name="guarantor_id_number" required class="form-input" 
                                   placeholder="Enter ID number" value="<?php echo htmlspecialchars($_POST['guarantor_id_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Phone</label>
                            <input type="tel" name="guarantor_phone" required class="form-input" 
                                   placeholder="Enter phone number" value="<?php echo htmlspecialchars($_POST['guarantor_phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Relationship</label>
                            <input type="text" name="guarantor_relationship" required class="form-input" 
                                   placeholder="Relationship to customer" value="<?php echo htmlspecialchars($_POST['guarantor_relationship'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Guarantor Photos -->
                    <div class="step-subtitle">
                        <i class="fas fa-images"></i>
                        Guarantor Photos
                    </div>
                    
                    <div class="form-grid">
                        <div class="file-upload-group">
                            <input type="file" name="guarantor_photo" accept="image/*" required onchange="previewImg(this,'g_photo_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <div class="file-upload-text required">Passport Photo</div>
                                <div class="file-upload-hint">Click or drag to upload</div>
                            </div>
                            <img id="g_photo_preview" class="image-preview">
                        </div>
                        
                        <div class="file-upload-group">
                            <input type="file" name="guarantor_id_front" accept="image/*" required onchange="previewImg(this,'g_id_front_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="file-upload-text required">ID Front Photo</div>
                                <div class="file-upload-hint">Clear image of ID front</div>
                            </div>
                            <img id="g_id_front_preview" class="image-preview">
                        </div>
                        
                        <div class="file-upload-group">
                            <input type="file" name="guarantor_id_back" accept="image/*" required onchange="previewImg(this,'g_id_back_preview')">
                            <div class="file-upload-content">
                                <div class="file-upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="file-upload-text required">ID Back Photo</div>
                                <div class="file-upload-hint">Clear image of ID back</div>
                            </div>
                            <img id="g_id_back_preview" class="image-preview">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="prevStep()" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="button" onclick="nextStep(2)" class="btn btn-primary">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="step step-container hidden">
                    <div class="step-title">
                        <i class="fas fa-users"></i>
                        Next of Kin Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Full Name</label>
                            <input type="text" name="next_of_kin_name" required class="form-input" 
                                   placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['next_of_kin_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Relationship</label>
                            <input type="text" name="next_of_kin_relationship" required class="form-input" 
                                   placeholder="Relationship to customer" value="<?php echo htmlspecialchars($_POST['next_of_kin_relationship'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Phone Number</label>
                            <input type="tel" name="next_of_kin_phone" required class="form-input" 
                                   placeholder="Enter phone number" value="<?php echo htmlspecialchars($_POST['next_of_kin_phone'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="prevStep()" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="button" onclick="nextStep(3)" class="btn btn-primary">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="step step-container hidden">
                    <div class="step-title">
                        <i class="fas fa-file-invoice-dollar"></i>
                        Loan Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Pre-qualified Amount</label>
                            <div class="relative">
                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-medium">
                                    KES
                                </div>
                                <input type="number" step="0.01" name="pre_qualified_amount" required class="form-input pl-16" 
                                       placeholder="0.00" value="<?php echo htmlspecialchars($_POST['pre_qualified_amount'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Branch</label>
                            <div class="relative">
                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-faida-primary">
                                    <i class="fas fa-building"></i>
                                </div>
                                <input type="text" value="<?php echo htmlspecialchars($branch_name ?: 'Not assigned'); ?>" disabled class="form-input pl-12 bg-gray-50">
                                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Loan Officer</label>
                            <select name="loan_officer_id" required class="form-select">
                                <option value="">Select Loan Officer</option>
                                <?php foreach ($officers as $officer): ?>
                                    <option value="<?php echo $officer['id']; ?>" 
                                        <?php echo (($_POST['loan_officer_id'] ?? $officer_id) == $officer['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($officer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-8 p-4 bg-blue-50 border border-blue-100 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-info-circle text-blue-500 text-lg mt-0.5"></i>
                            <div>
                                <h4 class="font-semibold text-blue-800 mb-1">Important Note</h4>
                                <p class="text-blue-700 text-sm">Confirm all the information before submitting. The customer will be registered as <span class="font-semibold">Inactive</span> initially.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="prevStep()" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i>
                            Submit Registration
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Footer note -->
    <div class="mt-6 text-center text-sm text-gray-500">
        <i class="fas fa-shield-alt mr-1"></i>
        All information is securely encrypted and protected
    </div>
</div>

<script>
let currentStep = 1;
const steps = document.querySelectorAll('.step');
const stepDots = document.querySelectorAll('.step-dot');

function showStep(step) {
    steps.forEach((el, idx) => {
        el.classList.toggle('hidden', idx !== step - 1);
        if (idx === step - 1) {
            el.style.animation = 'fade-in 0.4s ease-out';
        }
    });
    
    // Update progress indicators
    stepDots.forEach((dot, idx) => {
        dot.classList.remove('active', 'completed');
        if (idx + 1 < step) {
            dot.classList.add('completed');
        } else if (idx + 1 === step) {
            dot.classList.add('active');
        }
    });
    
    updateProgress(step);
}

function validateStep(stepIndex) {
    const step = steps[stepIndex - 1];
    const required = step.querySelectorAll("[required]");
    
    for (let input of required) {
        if (input.type === "file" && input.files.length === 0) {
            showToast("Please upload all required photos.", "error");
            input.closest('.file-upload-group').style.borderColor = '#ef4444';
            setTimeout(() => {
                input.closest('.file-upload-group').style.borderColor = '';
            }, 2000);
            return false;
        }
        if (input.type !== "file" && !input.value.trim()) {
            showToast("Please fill all required fields.", "error");
            input.focus();
            input.style.borderColor = '#ef4444';
            setTimeout(() => {
                input.style.borderColor = '';
            }, 2000);
            return false;
        }
    }
    return true;
}

function nextStep(index) {
    if (validateStep(index)) {
        currentStep++;
        showStep(currentStep);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function prevStep() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function updateProgress(step) {
    const percent = Math.round((step / steps.length) * 100);
    document.getElementById("progress-fill").style.width = percent + "%";
    document.getElementById("progress-status").textContent = `Step ${step} of ${steps.length}`;
    document.getElementById("progress-percent").textContent = `${percent}%`;
}

// Phone validation + auto-format
document.getElementById('phone_number').addEventListener('blur', function() {
    let val = this.value.replace(/\s+/g, '');
    if (/^07\d{8}$/.test(val)) {
        this.value = val; // Store as 07XXXXXXXX format
        showToast("Phone number validated successfully", "success");
    } else if (/^\+2547\d{8}$/.test(val)) {
        this.value = '0' + val.substr(4); // Convert to 07 format
    } else if (/^2547\d{8}$/.test(val)) {
        this.value = '0' + val.substr(3); // Convert to 07 format
    } else if (!/^07\d{8}$/.test(this.value) && this.value) {
        showToast("Invalid phone number format. Use 07XXXXXXXX", "error");
        this.focus();
    }
});

// Image preview
function previewImg(input, previewId) {
    const img = document.getElementById(previewId);
    const fileUpload = input.closest('.file-upload-group');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate file size (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            showToast("File size exceeds 5MB limit", "error");
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.classList.add('visible');
            
            // Update file upload UI
            const content = fileUpload.querySelector('.file-upload-content');
            content.innerHTML = `
                <div class="text-left">
                    <div class="text-sm font-medium text-faida-primary mb-1">
                        <i class="fas fa-check-circle mr-1"></i>
                        File Selected
                    </div>
                    <div class="text-xs text-gray-600 truncate">${file.name}</div>
                    <div class="text-xs text-gray-500 mt-1">${(file.size / 1024).toFixed(1)} KB</div>
                </div>
            `;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Toast notification
function showToast(message, type = "info") {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification fixed top-6 right-6 px-6 py-4 rounded-lg shadow-lg z-50 animate-slide-up ${
        type === 'error' ? 'bg-red-50 border border-red-200 text-red-700' :
        type === 'success' ? 'bg-green-50 border border-green-200 text-green-700' :
        'bg-blue-50 border border-blue-200 text-blue-700'
    }`;
    
    toast.innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Form submission handling
document.getElementById('regForm').addEventListener('submit', function(e) {
    // Validate all steps before submission
    for (let i = 1; i <= steps.length; i++) {
        if (!validateStep(i)) {
            // Go to the step with validation error
            currentStep = i;
            showStep(currentStep);
            e.preventDefault();
            return;
        }
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<div class="loading-spinner"></div> Processing...';
    submitBtn.disabled = true;
    
    // Allow form to submit normally
});

// Add drag and drop for file uploads
document.querySelectorAll('.file-upload-group').forEach(container => {
    const input = container.querySelector('input[type="file"]');
    
    container.addEventListener('dragover', (e) => {
        e.preventDefault();
        container.style.borderColor = '#15a362';
        container.style.background = '#f0fdf4';
    });
    
    container.addEventListener('dragleave', () => {
        container.style.borderColor = '';
        container.style.background = '';
    });
    
    container.addEventListener('drop', (e) => {
        e.preventDefault();
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
        container.style.borderColor = '';
        container.style.background = '';
    });
});

// Initialize first step
showStep(currentStep);

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>
</body>
</html>