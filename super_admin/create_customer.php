<?php include 'header.php'; 

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Faida Customer Registration</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --faida-green: #15a362;
    }
    .faida-bg { background-color: var(--faida-green); }
    .faida-text { color: var(--faida-green); }
    .faida-border { border-color: var(--faida-green); }
    .faida-bg:hover { background-color: #128d51; }
  </style>
</head>
<body class="bg-gray-100 font-sans">

<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
  <h2 class="text-2xl font-bold faida-text border-b pb-2 mb-6">Faida Customer Registration</h2>

  <!-- Progress Bar -->
  <div class="mb-6">
    <div class="h-2 rounded-full bg-gray-300 overflow-hidden">
      <div id="progress-bar" class="h-full w-1/3 faida-bg transition-all duration-300"></div>
    </div>
    <div id="progress-text" class="text-right text-sm mt-1 text-gray-700">Step 1 of 3 (33%)</div>
  </div>

  <form id="regForm" method="POST" action="save_customer">
 <!-- Step 1 -->
<div class="step active">
  <h3 class="text-lg font-semibold text-gray-700 mb-4">Step 1: Customer Information</h3>
  <div class="flex flex-wrap gap-4">
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">First Name *</label><input name="first_name" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Middle Name</label><input name="middle_name" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Surname *</label><input name="surname" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">National ID *</label><input name="national_id" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">KRA PIN</label><input name="kra_pin" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]">
      <label class="block text-sm mb-1">Gender *</label>
      <select name="gender" required class="w-full border px-3 py-2 rounded faida-border">
        <option value="">Select</option><option>Male</option><option>Female</option><option>Other</option>
      </select>
    </div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Date of Birth</label><input type="date" name="date_of_birth" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]">
      <label class="block text-sm mb-1">Marital Status *</label>
      <select name="marital_status" required class="w-full border px-3 py-2 rounded faida-border">
        <option value="">Select</option><option>Single</option><option>Married</option><option>Divorced</option><option>Widowed</option>
      </select>
    </div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Dependents</label><input type="number" name="dependents" min="0" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Phone Number *</label><input name="phone_number" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">M-Pesa Number *</label><input name="mpesa_number" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Email</label><input type="email" name="email" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">County *</label><input name="county" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Sub-county *</label><input name="sub_county" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Ward</label><input name="ward" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Location</label><input name="location" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Sub-location</label><input name="sub_location" class="w-full border px-3 py-2 rounded" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Village</label><input name="village" class="w-full border px-3 py-2 rounded" /></div>
    <div class="w-full"><label class="block text-sm mb-1">Full Address</label><textarea name="address" rows="2" class="w-full border px-3 py-2 rounded"></textarea></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Next of Kin Name *</label><input name="next_of_kin_name" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Relationship *</label><input name="next_of_kin_relationship" required class="w-full border px-3 py-2 rounded faida-border" /></div>
    <div class="flex-1 min-w-[250px]"><label class="block text-sm mb-1">Next of Kin Phone *</label><input name="next_of_kin_phone" required class="w-full border px-3 py-2 rounded faida-border" /></div>

    <!-- ✅ Choose Group Dropdown -->
    <div class="flex-1 min-w-[250px]">
      <label class="block text-sm mb-1">Choose Group *</label>
      <select id="customerGroup" name="customer_group" required class="w-full border px-3 py-2 rounded faida-border">
        <option value="">Loading...</option>
      </select>
    </div>
  </div>

  <div class="mt-6 text-right">
    <button type="button" onclick="nextStep(1)" class="faida-bg text-white px-6 py-2 rounded hover:bg-green-700">Next</button>
  </div>
</div>

    <!-- Step 2 -->
    <div class="step hidden">
      <h3 class="text-lg font-semibold text-gray-700 mb-4">Step 2: Guarantor Information</h3>
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[250px]"><label>Guarantor First Name *</label><input name="guarantor_first_name" required class="w-full border px-3 py-2 rounded faida-border" /></div>
        <div class="flex-1 min-w-[250px]"><label>Guarantor Middle Name</label><input name="guarantor_middle_name" class="w-full border px-3 py-2 rounded" /></div>
        <div class="flex-1 min-w-[250px]"><label>Guarantor Surname *</label><input name="guarantor_surname" required class="w-full border px-3 py-2 rounded faida-border" /></div>
        <div class="flex-1 min-w-[250px]"><label>Guarantor ID Number *</label><input name="guarantor_id_number" required class="w-full border px-3 py-2 rounded faida-border" /></div>
        <div class="flex-1 min-w-[250px]"><label>Guarantor Phone *</label><input name="guarantor_phone" required class="w-full border px-3 py-2 rounded faida-border" /></div>
        <div class="flex-1 min-w-[250px]"><label>Relationship *</label><input name="guarantor_relationship" required class="w-full border px-3 py-2 rounded faida-border" /></div>
      </div>
      <div class="mt-6 flex justify-between">
        <button type="button" onclick="prevStep()" class="bg-gray-300 text-gray-800 px-6 py-2 rounded">Back</button>
        <button type="button" onclick="nextStep(2)" class="faida-bg text-white px-6 py-2 rounded hover:bg-green-700">Next</button>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="step hidden">
      <h3 class="text-lg font-semibold text-gray-700 mb-4">Step 3: Loan Information</h3>
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[250px]"><label>Pre-qualified Amount *</label><input type="number" step="0.01" name="pre_qualified_amount" required class="w-full border px-3 py-2 rounded faida-border" /></div>
        <input type="hidden" name="status" value="Inactive" />
      </div>
      <div class="mt-6 flex justify-between">
        <button type="button" onclick="prevStep()" class="bg-gray-300 text-gray-800 px-6 py-2 rounded">Back</button>
        <button type="submit" class="faida-bg text-white px-6 py-2 rounded hover:bg-green-700">Submit</button>
      </div>
    </div>
  </form>
</div>

<script>
  let currentStep = 1;
  const steps = document.querySelectorAll('.step');

  function showStep(step) {
    steps.forEach((el, idx) => el.classList.toggle('hidden', idx !== step - 1));
    updateProgress();
  }

  function validateStep(stepIndex) {
    const step = steps[stepIndex - 1];
    const required = step.querySelectorAll("[required]");
    for (let input of required) {
      if (!input.value.trim()) {
        alert("Please fill all required fields.");
        input.focus();
        return false;
      }
    }
    return true;
  }

  function nextStep(index) {
    if (validateStep(index)) {
      currentStep++;
      showStep(currentStep);
    }
  }

  function prevStep() {
    if (currentStep > 1) {
      currentStep--;
      showStep(currentStep);
    }
  }

  function updateProgress() {
    const percent = Math.round((currentStep / steps.length) * 100);
    document.getElementById("progress-bar").style.width = percent + "%";
    document.getElementById("progress-text").innerText = `Step ${currentStep} of ${steps.length} (${percent}%)`;
  }

  updateProgress();

    // Fetch groups from PHP API (get_groups.php)
  fetch('get.php')
    .then(res => res.json())
    .then(data => {
      const select = document.getElementById('customerGroup');
      select.innerHTML = '<option value="">Select Group</option>';
      data.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.id;
        opt.textContent = g.group_name;
        select.appendChild(opt);
      });
    })
    .catch(err => {
      console.error(err);
      document.getElementById('customerGroup').innerHTML = '<option value="">Error loading</option>';
    });
</script>

</body>
</html>
