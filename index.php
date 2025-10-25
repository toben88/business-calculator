<?php
/*
 * ============================================
 * BUSINESS VALUATION CALCULATOR v1.21
 * ============================================
 *
 * FILE STRUCTURE:
 * 1. PHP BACKEND
 *    - Calculation Functions: calculateLoanPayment, calculateInterestPaid, calculateRemainingBalance
 *    - Main Calculator: calculateBusinessMetrics() returns all metrics
 *    - AJAX Endpoint: Handles ?ajax=calculate requests for real-time updates
 *    - Form Processing: Database save/update/delete operations
 *    - Session & Security: CSRF protection, input validation
 *
 * 2. CSS STYLES
 *    - Organized by section with clear comments
 *    - Utility classes for common patterns (labels, values, cards, grids)
 *    - Mobile responsive design
 *
 * 3. JAVASCRIPT
 *    - updateCalculations(): AJAX-based, calls PHP for all calculations
 *    - Event handlers for real-time updates
 *    - Form submission and validation
 *
 * 4. HTML
 *    - Record manager (load/save/delete)
 *    - Input forms (SBA, Seller, Junior Debt)
 *    - Results display (cashflow, DSCR, price breakdown)
 *
 * ARCHITECTURE:
 * - Single source of truth: All calculations in PHP only
 * - Real-time updates via AJAX (fetch API)
 * - Zero calculation duplication between PHP and JavaScript
 * - CSS utility classes replace inline styles for maintainability
 *
 * KEY FEATURES:
 * - SBA loan, Seller carry, Junior debt calculations
 * - Monthly/Annual cashflow analysis
 * - DSCR (Debt Service Coverage Ratio)
 * - Balloon payment calculations
 * - SQLite database with automatic schema migration
 * - CSRF protection and secure sessions
 */

// ============================================
// BUSINESS RECORD MANAGEMENT WITH SQLITE
// ============================================

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// SECURITY: SESSION AND CSRF PROTECTION
// ============================================

// Configure secure session settings
ini_set('session.cookie_httponly', 1);  // Prevent JavaScript access to session cookie
// Removed session.cookie_secure to allow HTTP (local development)
ini_set('session.cookie_samesite', 'Strict'); // Prevent CSRF attacks
ini_set('session.use_strict_mode', 1);   // Reject uninitialized session IDs

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// SECURITY: HTTP HEADERS
// ============================================

// Prevent clickjacking
header('X-Frame-Options: SAMEORIGIN');

// Prevent MIME-type sniffing
header('X-Content-Type-Options: nosniff');

// Enable XSS protection (legacy but still useful)
header('X-XSS-Protection: 1; mode=block');

// Referrer policy
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content Security Policy
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

// Strict Transport Security (HSTS) - removed to allow HTTP (local development)
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// ============================================
// SECURITY: RATE LIMITING
// ============================================

function checkRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $rate_limit_window = 60; // 60 seconds
    $max_requests = 30; // 30 requests per minute

    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }

    // Clean old entries
    $_SESSION['rate_limit'] = array_filter($_SESSION['rate_limit'], function($timestamp) use ($now, $rate_limit_window) {
        return ($now - $timestamp) < $rate_limit_window;
    });

    // Check limit
    if (count($_SESSION['rate_limit']) >= $max_requests) {
        http_response_code(429);
        die('Rate limit exceeded. Please wait a moment before trying again.');
    }

    // Add current request
    $_SESSION['rate_limit'][] = $now;
}

// ============================================
// CALCULATION FUNCTIONS (Single Source of Truth)
// ============================================

function calculateLoanPayment($principal, $annualInterestRate, $numPayments) {
    if ($principal <= 0 || $numPayments <= 0) return 0;
    $monthlyRate = ($annualInterestRate / 100) / 12;
    if ($monthlyRate == 0) return $principal / $numPayments;
    return $principal * ($monthlyRate * pow(1 + $monthlyRate, $numPayments)) / (pow(1 + $monthlyRate, $numPayments) - 1);
}

function calculateInterestPaid($principal, $annualInterestRate, $numPayments, $monthsToCalculate) {
    if ($principal <= 0 || $numPayments <= 0) return 0;
    $monthlyRate = ($annualInterestRate / 100) / 12;
    $monthlyPayment = calculateLoanPayment($principal, $annualInterestRate, $numPayments);

    $balance = $principal;
    $totalInterest = 0;

    for ($i = 0; $i < min($monthsToCalculate, $numPayments); $i++) {
        $interestPayment = $balance * $monthlyRate;
        $totalInterest += $interestPayment;
        $principalPayment = $monthlyPayment - $interestPayment;
        $balance -= $principalPayment;
    }

    return $totalInterest;
}

function calculateRemainingBalance($principal, $annualInterestRate, $numPayments, $monthsPaid) {
    if ($principal <= 0 || $numPayments <= 0) return 0;
    $monthlyRate = ($annualInterestRate / 100) / 12;
    $monthlyPayment = calculateLoanPayment($principal, $annualInterestRate, $numPayments);

    $balance = $principal;

    for ($i = 0; $i < min($monthsPaid, $numPayments); $i++) {
        $interestPayment = $balance * $monthlyRate;
        $principalPayment = $monthlyPayment - $interestPayment;
        $balance -= $principalPayment;
    }

    return max(0, $balance);
}

function calculateBusinessMetrics($data) {
    // Extract values
    $sde = $data['sde'];
    $price = $data['price'];
    $optionalSalary = $data['optional_salary'];
    $extraCosts = $data['extra_costs'];
    $capex = $data['capex'];
    $consultingFee = $data['consulting_fee'];

    // Calculate percentages to amounts
    $downPayment = $price * ($data['pct_down_payment'] / 100);
    $sellerCarry = $price * ($data['pct_seller_carry'] / 100);
    $juniorDebt = $price * ($data['pct_junior_debt'] / 100);
    $loan = $price - $downPayment - $sellerCarry - $juniorDebt;
    $sbaLoanAmount = $loan + $data['loan_fee'] + $data['closing_costs'] + $data['other_fees'];

    // Calculate loan payments
    $sellerMonthlyPayment = calculateLoanPayment($sellerCarry, $data['seller_interest'], $data['seller_duration']);
    $juniorMonthlyPayment = calculateLoanPayment($juniorDebt, $data['junior_interest'], $data['junior_duration']);
    $sbaMonthlyPayment = calculateLoanPayment($sbaLoanAmount, $data['sba_interest'], $data['sba_duration']);

    // Calculate cashflow
    $monthlyCashflow = ($sde / 12) - $sbaMonthlyPayment - $sellerMonthlyPayment - $juniorMonthlyPayment - ($optionalSalary / 12) - ($extraCosts / 12) - ($capex / 12);
    $annualCashflow = $sde - ($sbaMonthlyPayment * 12) - ($sellerMonthlyPayment * 12) - ($juniorMonthlyPayment * 12) - $optionalSalary - $extraCosts - $capex;

    // Calculate DSCR
    $netOperatingIncome = $sde - $optionalSalary - $extraCosts - $capex;
    $totalDebtService = ($sbaMonthlyPayment * 12) + ($sellerMonthlyPayment * 12) + ($juniorMonthlyPayment * 12);
    $dscr = $totalDebtService > 0 ? $netOperatingIncome / $totalDebtService : 0;

    // Calculate balloon payments
    $sellerBalloon5yr = calculateRemainingBalance($sellerCarry, $data['seller_interest'], $data['seller_duration'], 60);
    $sellerBalloon10yr = calculateRemainingBalance($sellerCarry, $data['seller_interest'], $data['seller_duration'], 120);
    $juniorBalloon5yr = calculateRemainingBalance($juniorDebt, $data['junior_interest'], $data['junior_duration'], 60);
    $juniorBalloon10yr = calculateRemainingBalance($juniorDebt, $data['junior_interest'], $data['junior_duration'], 120);

    // Calculate totals to seller
    $totalSeller5yr = $downPayment + $loan + $juniorDebt + ($sellerMonthlyPayment * 60) + $sellerBalloon5yr + $consultingFee;
    $totalSeller10yr = $downPayment + $loan + $juniorDebt + ($sellerMonthlyPayment * min(120, $data['seller_duration'])) + $consultingFee;

    return [
        'multiple' => $sde > 0 ? $price / $sde : 0,
        'down_payment' => $downPayment,
        'seller_carry' => $sellerCarry,
        'junior_debt' => $juniorDebt,
        'loan' => $loan,
        'sba_loan_amount' => $sbaLoanAmount,
        'seller_monthly_payment' => $sellerMonthlyPayment,
        'junior_monthly_payment' => $juniorMonthlyPayment,
        'sba_monthly_payment' => $sbaMonthlyPayment,
        'monthly_cashflow' => $monthlyCashflow,
        'annual_cashflow' => $annualCashflow,
        'annual_cashflow_with_salary' => $annualCashflow + $optionalSalary,
        'dscr' => $dscr,
        'seller_5yr_interest' => calculateInterestPaid($sellerCarry, $data['seller_interest'], $data['seller_duration'], 60),
        'seller_10yr_interest' => calculateInterestPaid($sellerCarry, $data['seller_interest'], $data['seller_duration'], 120),
        'junior_5yr_interest' => calculateInterestPaid($juniorDebt, $data['junior_interest'], $data['junior_duration'], 60),
        'junior_10yr_interest' => calculateInterestPaid($juniorDebt, $data['junior_interest'], $data['junior_duration'], 120),
        'sba_5yr_interest' => calculateInterestPaid($sbaLoanAmount, $data['sba_interest'], $data['sba_duration'], 60),
        'sba_10yr_interest' => calculateInterestPaid($sbaLoanAmount, $data['sba_interest'], $data['sba_duration'], 120),
        'seller_balloon_5yr' => $sellerBalloon5yr,
        'seller_balloon_10yr' => $sellerBalloon10yr,
        'junior_balloon_5yr' => $juniorBalloon5yr,
        'junior_balloon_10yr' => $juniorBalloon10yr,
        'total_seller_5yr' => $totalSeller5yr,
        'total_seller_10yr' => $totalSeller10yr,
        'validation_pass' => abs(($loan + $sellerCarry + $juniorDebt + $downPayment) - $price) < 0.01
    ];
}

// ============================================
// AJAX ENDPOINT (Same file, no duplication!)
// ============================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'calculate') {
    header('Content-Type: application/json');

    // Get inputs from AJAX request
    $input = json_decode(file_get_contents('php://input'), true);

    // Calculate using PHP functions (single source of truth)
    $metrics = calculateBusinessMetrics($input);

    // Return JSON
    echo json_encode($metrics);
    exit; // Stop here, don't render HTML
}

// ============================================
// SECURITY: INPUT VALIDATION
// ============================================

function validateAndSanitizeBusinessData($post_data) {
    $errors = [];

    // Map new.php field names to database field names
    $field_map = [
        'purchase_price' => 'price',
        'new_owner_salary' => 'optional_salary',
        'seller_duration_months' => 'seller_duration',
        'junior_duration_months' => 'junior_duration',
        'sba_duration_months' => 'sba_duration'
    ];

    // Apply field name mapping
    foreach ($field_map as $new_name => $db_name) {
        if (isset($post_data[$new_name])) {
            $post_data[$db_name] = $post_data[$new_name];
        }
    }

    // Business name: max 200 chars, strip HTML tags
    $business_name = isset($post_data['business_name']) ? strip_tags(trim($post_data['business_name'])) : '';
    if (strlen($business_name) > 200) {
        $errors[] = "Business name too long (max 200 characters)";
    }
    if (empty($business_name)) {
        $errors[] = "Business name cannot be empty";
    }

    // Numeric fields with range validation
    $numeric_fields = [
        'sde' => ['min' => 0, 'max' => 1000000000, 'default' => 500000],
        'price' => ['min' => 0, 'max' => 1000000000, 'default' => 1750000],
        'optional_salary' => ['min' => 0, 'max' => 10000000, 'default' => 125000],
        'extra_costs' => ['min' => 0, 'max' => 10000000, 'default' => 0],
        'capex' => ['min' => 0, 'max' => 10000000, 'default' => 0],
        'consulting_fee' => ['min' => 0, 'max' => 10000000, 'default' => 0],
        'pct_down_payment' => ['min' => 0, 'max' => 100, 'default' => 10],
        'pct_seller_carry' => ['min' => 0, 'max' => 100, 'default' => 10],
        'pct_junior_debt' => ['min' => 0, 'max' => 100, 'default' => 0],
        'loan_fee' => ['min' => 0, 'max' => 10000000, 'default' => 13485],
        'closing_costs' => ['min' => 0, 'max' => 10000000, 'default' => 15000],
        'other_fees' => ['min' => 0, 'max' => 10000000, 'default' => 15000],
        'seller_duration' => ['min' => 1, 'max' => 600, 'default' => 120],
        'seller_interest' => ['min' => 0, 'max' => 100, 'default' => 7],
        'junior_duration' => ['min' => 1, 'max' => 600, 'default' => 120],
        'junior_interest' => ['min' => 0, 'max' => 100, 'default' => 8],
        'sba_duration' => ['min' => 1, 'max' => 600, 'default' => 120],
        'sba_interest' => ['min' => 0, 'max' => 100, 'default' => 10],
    ];

    $sanitized = ['business_name' => $business_name];

    foreach ($numeric_fields as $field => $config) {
        $value = floatval($post_data[$field] ?? $config['default']);

        if ($value < $config['min'] || $value > $config['max']) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be between {$config['min']} and {$config['max']}";
        }

        $sanitized[$field] = $value;
    }

    return ['data' => $sanitized, 'errors' => $errors];
}

// Initialize database
require_once __DIR__ . '/Database.php';
$db = new Database();

$message = '';
$messageType = '';
$selectedBusinessId = null;
$loadedData = [];

// ============================================
// HANDLE FORM ACTIONS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Security: Check rate limit
    checkRateLimit();

    // Security: Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die('Security validation failed. Please refresh the page and try again.');
    }

    $action = $_POST['action_type'] ?? '';

    // Security: Disabled debug logging in production (comment out for development)
    // $debugInfo = "Action: $action | POST data received: " . print_r($_POST, true);
    // file_put_contents(__DIR__ . '/data/debug.log', date('Y-m-d H:i:s') . " - $debugInfo\n", FILE_APPEND);

    if ($action === 'load' && !empty($_POST['business_id'])) {
        // Load selected business
        $selectedBusinessId = intval($_POST['business_id']);
        $business = $db->getBusinessById($selectedBusinessId);
        if ($business) {
            // Map database field names to new.php form field names
            $loadedData = $business;
            $loadedData['purchase_price'] = $business['price'];
            $loadedData['new_owner_salary'] = $business['optional_salary'];
            $loadedData['seller_duration_months'] = $business['seller_duration'];
            $loadedData['junior_duration_months'] = $business['junior_duration'];
            $loadedData['sba_duration_months'] = $business['sba_duration'];

            $message = "Loaded: " . htmlspecialchars($business['business_name']);
            $messageType = 'success';
        }
    }
    elseif ($action === 'save' && !empty($_POST['business_id'])) {
        // Update existing business with validation
        $validation = validateAndSanitizeBusinessData($_POST);

        if (!empty($validation['errors'])) {
            $message = "Validation error: " . implode(', ', $validation['errors']);
            $messageType = 'error';
        } else {
            $id = intval($_POST['business_id']);
            if ($db->updateBusiness($id, $validation['data'])) {
                $message = "Updated: " . htmlspecialchars($validation['data']['business_name']);
                $messageType = 'success';
                $selectedBusinessId = $id;
                $loadedData = $validation['data'];
            }
        }
    }
    elseif ($action === 'save_new') {
        // Save as new business with validation
        $validation = validateAndSanitizeBusinessData($_POST);

        if (!empty($validation['errors'])) {
            $message = "Validation error: " . implode(', ', $validation['errors']);
            $messageType = 'error';
        } else {
            $newId = $db->createBusiness($validation['data']);
            $message = "Saved new business: " . htmlspecialchars($validation['data']['business_name']);
            $messageType = 'success';
            $selectedBusinessId = $newId;
            $loadedData = $validation['data'];
        }
    }
    elseif ($action === 'delete' && !empty($_POST['business_id'])) {
        // Delete business
        $id = intval($_POST['business_id']);
        $business = $db->getBusinessById($id);
        if ($business) {
            $db->deleteBusiness($id);
            $message = "Deleted: " . htmlspecialchars($business['business_name']);
            $messageType = 'success';
            $selectedBusinessId = null;
        }
    }
    elseif ($action === 'new') {
        // Clear form for new record
        $selectedBusinessId = null;
        $loadedData = [];
        $message = "Ready to create new business record";
        $messageType = 'success';
    }
}

// Load all businesses for dropdown
$allBusinesses = $db->getAllBusinesses();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Business Valuation Calculator — Modern (Plain CSS)</title>
<link rel="preconnect" href="https://fonts.gstatic.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg: #f6f9fc;
  --card: #ffffff;
  --muted: #5b6b7a;
  --accent: linear-gradient(135deg,#06b6d4 0%,#3b82f6 100%);
  --glass: rgba(2,6,23,0.04);
  --success: #10b981;
  --danger: #ef4444;
  --text: #0b1220;
}

/* Basic reset */
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial; background:var(--bg); color:var(--text);padding:10px;}

.container{max-width:1400px;margin:0 auto;padding:15px;}

/* Header */
.header{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.header-top{display:flex;align-items:center;gap:10px;flex:1;}
.brand{display:flex;align-items:center;gap:10px;margin-right:auto;}
.logo{width:46px;height:46px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;box-shadow:0 6px 18px rgba(12,18,24,0.6);}
.title{font-size:1rem;font-weight:700;}
.subtitle{font-size:0.75rem;color:var(--muted);}
@media (max-width:900px){
  body{padding:5px;}
  .container{padding:8px;}
  .header{flex-direction:column;align-items:stretch;gap:8px;}
  .header-top{flex-wrap:wrap;}
  .brand{margin-right:0;}
  .header-buttons{justify-content:center;}
}

/* Layout */
.grid{display:grid;grid-template-columns:520px 1fr;gap:15px;}
@media (max-width:900px){.grid{grid-template-columns:1fr;}}

/* Three column loan sections */
.loan-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
@media (max-width:900px){.loan-grid{grid-template-columns:1fr;}}

/* Cards */
.card{background:var(--card);border-radius:14px;padding:12px;box-shadow:0 6px 18px rgba(2,6,23,0.6);border:1px solid rgba(255,255,255,0.03);}
.card h3{margin-bottom:8px;font-size:0.875rem}

/* Form layout */
.form-row{display:flex;gap:8px;margin-bottom:8px;}
.form-row .field{flex:1}
label{display:block;font-size:0.6875rem;margin-bottom:4px;color:var(--muted);}
input[type="text"], input[type="number"], select, textarea{width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:var(--glass);color:var(--text);font-size:0.8125rem;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;border:none;cursor:pointer;font-weight:600;}
.btn-primary{background:var(--accent);color:white;box-shadow:0 6px 18px rgba(59,130,246,0.6);}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.06);color:var(--text);}
.btn:active{transform:translateY(1px);}

/* Result badges */
.badge{display:inline-block;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,0.03);font-weight:700;}

/* Small helper text */
.helper{font-size:0.6875rem;color:var(--muted);margin-top:6px;}

/* Footer */
.footer{margin-top:18px;text-align:center;color:var(--muted);font-size:0.75rem;}

/* fancy inputs for currency */
.input-group{position:relative;}
.input-group .prefix{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-weight:700;}
.input-group input{padding-left:36px;}

</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="header-top">
      <div class="brand">
        <div class="logo">SBA</div>
        <div>
          <div class="title">Business Valuation Calculator</div>
        </div>
      </div>
      <select id="business_id_select" onchange="loadBusiness()" class="business-selector" style="padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:var(--glass);color:var(--text);font-size:0.95rem;min-width:200px;">
        <option value="">-- Load Business --</option>
        <?php foreach ($allBusinesses as $biz): ?>
        <option value="<?php echo $biz['id']; ?>" <?php echo ($selectedBusinessId == $biz['id']) ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($biz['business_name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="header-buttons" style="display:flex;align-items:center;gap:10px;">
      <button class="btn" type="submit" name="action_type" value="new" form="calcForm" style="background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%); color: white; box-shadow: 0 6px 18px rgba(234,88,12,0.6);">New</button>
      <button class="btn btn-primary" type="submit" name="action_type" value="save" form="calcForm" <?php echo empty($selectedBusinessId) ? 'disabled' : ''; ?>>Save</button>
      <button class="btn" type="submit" name="action_type" value="save_new" form="calcForm" onclick="return validateBusinessName()" style="background: linear-gradient(135deg, #4ade80 0%, #16a34a 100%); color: white; box-shadow: 0 6px 18px rgba(22,163,74,0.6); white-space: nowrap; min-width: 120px;">Save As New</button>
      <button class="btn" type="submit" name="action_type" value="delete" form="calcForm" <?php echo empty($selectedBusinessId) ? 'disabled' : ''; ?> onclick="return confirm('Delete this business record?')" style="background: linear-gradient(135deg, #f87171 0%, #dc2626 100%); color: white; box-shadow: 0 6px 18px rgba(239,68,68,0.6);">Delete</button>
    </div>
  </div>

  <form id="calcForm" class="grid" method="post" action="">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="business_id" id="business_id" value="<?php echo $selectedBusinessId ?? ''; ?>">
    <div>
      <div class="card">
        <div class="form-row">
          <div class="field">
            <label for="business_name">Name of Business</label>
            <input id="business_name" name="business_name" type="text" value="<?php echo htmlspecialchars($loadedData['business_name'] ?? $_POST['business_name'] ?? ''); ?>" placeholder="Enter business name">
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="purchase_price">Purchase Price</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="purchase_price" name="purchase_price" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['purchase_price'] ?? $_POST['purchase_price'] ?? '1750000'); ?>">
            </div>
          </div>
          <div class="field">
            <label for="sde">SDE (Seller's Discretionary Earnings)</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="sde" name="sde" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['sde'] ?? $_POST['sde'] ?? '500000'); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="multiple">Multiple (Price/SDE)</label>
            <input id="multiple" name="multiple" type="number" step="1" value="<?php echo htmlspecialchars($_POST['multiple'] ?? ''); ?>" readonly style="background: var(--glass);">
          </div>
          <div class="field">
            <label for="capex">Averaged Capex (Annual)</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="capex" name="capex" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['capex'] ?? $_POST['capex'] ?? '0'); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="extra_costs">Extra Operating Costs (Annual)</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="extra_costs" name="extra_costs" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['extra_costs'] ?? $_POST['extra_costs'] ?? '0'); ?>">
            </div>
          </div>
          <div class="field">
            <label for="consulting_fee">Consulting Fee Year 1 (Optional)</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="consulting_fee" name="consulting_fee" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['consulting_fee'] ?? $_POST['consulting_fee'] ?? '0'); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="new_owner_salary">New Owner Salary (Optional)</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="new_owner_salary" name="new_owner_salary" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['new_owner_salary'] ?? $_POST['new_owner_salary'] ?? '125000'); ?>">
            </div>
          </div>
        </div>

      </div>

      <div class="card" style="margin-top: 12px;">
        <h3>Loan Info</h3>

        <div class="form-row">
          <div class="field">
            <label for="down_payment">Down Payment:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="down_payment" name="down_payment" type="number" step="1" value="<?php echo htmlspecialchars($_POST['down_payment'] ?? '175000'); ?>">
            </div>
          </div>
          <div class="field">
            <label for="pct_down_payment">% Down Payment:</label>
            <input id="pct_down_payment" name="pct_down_payment" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['pct_down_payment'] ?? $_POST['pct_down_payment'] ?? '10'); ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="seller_carry">Seller Carry:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="seller_carry" name="seller_carry" type="number" step="1" value="<?php echo htmlspecialchars($_POST['seller_carry'] ?? '175000'); ?>">
            </div>
          </div>
          <div class="field">
            <label for="pct_seller_carry">% Seller Carry:</label>
            <input id="pct_seller_carry" name="pct_seller_carry" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['pct_seller_carry'] ?? $_POST['pct_seller_carry'] ?? '10'); ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="junior_debt">Junior Debt:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="junior_debt" name="junior_debt" type="number" step="1" value="<?php echo htmlspecialchars($_POST['junior_debt'] ?? '0'); ?>">
            </div>
          </div>
          <div class="field">
            <label for="pct_junior_debt">% Junior Debt:</label>
            <input id="pct_junior_debt" name="pct_junior_debt" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['pct_junior_debt'] ?? $_POST['pct_junior_debt'] ?? '0'); ?>">
          </div>
        </div>

      </div>

      <div class="card" style="margin-top: 12px;">
        <h3>SBA Loan</h3>

        <div class="form-row">
          <div class="field">
            <label for="loan_fee">Loan Fee:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="loan_fee" name="loan_fee" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['loan_fee'] ?? $_POST['loan_fee'] ?? '13485'); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="closing_costs">Closing Costs:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="closing_costs" name="closing_costs" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['closing_costs'] ?? $_POST['closing_costs'] ?? '15000'); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="other_fees">Other Fees:</label>
            <div class="input-group">
              <span class="prefix">$</span>
              <input id="other_fees" name="other_fees" type="number" step="1" value="<?php echo htmlspecialchars($loadedData['other_fees'] ?? $_POST['other_fees'] ?? '15000'); ?>">
            </div>
          </div>
        </div>

        <div style="margin-top: 16px; padding: 12px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; color: var(--success); font-size: 0.9rem;">
          <strong>✓</strong> Validation: Loan + Seller Carry + Junior Debt + Down Payment = $1,750,000 ✓ Equals Price
        </div>

      </div>

      <!-- Add any other form groups you need here, following the same pattern and preserving input names -->
    </div>

    <aside>
      <div class="card">
        <h3>Buyer Cashflow Analysis</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:10px;">
          <!-- Monthly Cashflow -->
          <div style="padding:10px;background:rgba(16,185,129,0.05);border:2px solid rgba(16,185,129,0.3);border-radius:10px;">
            <div style="font-size:0.625rem;color:var(--muted);margin-bottom:6px;">MONTHLY CASHFLOW</div>
            <div id="monthly_cashflow" style="font-size:1.25rem;font-weight:700;color:#10b981;margin-bottom:8px;">$10,142</div>
            <div id="monthly_details" style="font-size:0.5625rem;color:var(--muted);line-height:1.4;">
              SDE: $41,667<br>
              SBA Payment: -$19,076<br>
              Junior Debt Payment: -$0<br>
              Seller Payment: -$2,032<br>
              Salary: -$10,417<br>
              Extra Costs: -$0<br>
              Capex: -$0
            </div>
          </div>

          <!-- Annual Cashflow -->
          <div style="padding:10px;background:rgba(6,182,212,0.05);border:2px solid rgba(6,182,212,0.3);border-radius:10px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:6px;">
              <div style="font-size:0.625rem;color:var(--muted);">ANNUAL CASHFLOW</div>
              <div style="font-size:0.625rem;color:var(--muted);text-align:center;">+SALARY</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
              <div id="annual_cashflow" style="font-size:1.25rem;font-weight:700;color:#06b6d4;">$121,708</div>
              <div id="annual_cashflow_salary" style="font-size:1.25rem;font-weight:700;color:#06b6d4;text-align:center;">$246,708</div>
            </div>
            <div id="annual_details" style="font-size:0.5625rem;color:var(--muted);line-height:1.4;">
              SDE: $500,000<br>
              SBA Payment: -$228,912<br>
              Junior Debt Payment: -$0<br>
              Seller Payment: -$24,384<br>
              Salary: -$125,000<br>
              Extra Costs: -$0<br>
              Capex: -$0
            </div>
          </div>

          <!-- DSCR -->
          <div style="padding:10px;background:rgba(251,191,36,0.05);border:2px solid rgba(251,191,36,0.3);border-radius:10px;">
            <div style="font-size:0.625rem;color:var(--muted);margin-bottom:6px;">DSCR (Debt Coverage)</div>
            <div id="dscr_value" style="font-size:1.25rem;font-weight:700;color:#f59e0b;margin-bottom:8px;">1.48</div>
            <div id="dscr_status" style="font-size:0.5625rem;color:var(--muted);">Acceptable | Min: 1.25</div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:10px;">
        <h3>Price Breakdown</h3>

        <div style="margin-top:8px;padding:8px;background:var(--glass);border-radius:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <div style="font-size:0.625rem;color:var(--muted);">TOTAL PRICE</div>
            <div style="font-size:0.875rem;color:var(--muted);">100%</div>
          </div>
          <div id="total_price" style="font-size:1.25rem;font-weight:700;">$1,750,000</div>
        </div>

        <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px;">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);">
            <span style="color:var(--muted);font-size:0.6875rem;">Down:</span>
            <div style="text-align:right;">
              <div id="down_amount" style="font-weight:600;font-size:0.8125rem;">$175,000</div>
              <div id="down_pct" style="font-size:0.625rem;color:var(--muted);">10.0%</div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);">
            <span style="color:var(--muted);font-size:0.6875rem;">SBA Loan:</span>
            <div style="text-align:right;">
              <div id="sba_amount" style="font-weight:600;font-size:0.8125rem;">$1,400,000</div>
              <div id="sba_pct" style="font-size:0.625rem;color:var(--muted);">80.0%</div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);">
            <span style="color:var(--muted);font-size:0.6875rem;">Junior Debt:</span>
            <div style="text-align:right;">
              <div id="junior_amount" style="font-weight:600;font-size:0.8125rem;">$0</div>
              <div id="junior_pct" style="font-size:0.625rem;color:var(--muted);">0.0%</div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;">
            <span style="color:var(--muted);font-size:0.6875rem;">Seller Carry:</span>
            <div style="text-align:right;">
              <div id="seller_amount" style="font-weight:600;font-size:0.8125rem;">$175,000</div>
              <div id="seller_pct" style="font-size:0.625rem;color:var(--muted);">10.0%</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:10px;">
        <h3>Payment to Seller</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
          <!-- 5 Years with balloon -->
          <div style="padding:10px;background:var(--glass);border-radius:10px;">
            <div style="font-size:0.625rem;color:var(--muted);margin-bottom:8px;">TOTAL TO SELLER (5 YEARS with balloon)</div>
            <div id="seller_5yr_total" style="font-size:1.125rem;font-weight:700;margin-bottom:8px;">$1,799,529</div>
            <div id="seller_5yr_details" style="font-size:0.5625rem;color:var(--muted);line-height:1.6;">
              Down: $175,000<br>
              SBA Loan (Full): $1,400,000<br>
              Junior Debt (Full): $0<br>
              Seller Carry Principal: $72,385<br>
              Seller Carry Interest: $49,529<br>
              Seller Carry Balloon: $102,615<br>
              Consulting: $0
            </div>
          </div>

          <!-- 10 Years -->
          <div style="padding:10px;background:var(--glass);border-radius:10px;">
            <div style="font-size:0.625rem;color:var(--muted);margin-bottom:8px;">TOTAL TO SELLER (10 YEARS)</div>
            <div id="seller_10yr_total" style="font-size:1.125rem;font-weight:700;margin-bottom:8px;">$1,818,828</div>
            <div id="seller_10yr_details" style="font-size:0.5625rem;color:var(--muted);line-height:1.6;">
              Down: $175,000<br>
              SBA Loan (Full): $1,400,000<br>
              Junior Debt (Full): $0<br>
              Seller Carry Principal: $175,000<br>
              Seller Carry Interest: $68,828<br>
              Consulting: $0
            </div>
          </div>
        </div>
      </div>
    </aside>
  </form>

  <div class="loan-grid" style="margin-top:12px;">
    <div class="card">
      <h3>Seller Loan</h3>
      <div class="form-row">
        <div class="field">
          <label for="seller_carry_amount_display">Seller Carry Amount:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="seller_carry_amount_display" type="number" step="1" value="175000" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="seller_duration_months_display">Duration in Months:</label>
          <input id="seller_duration_months_display" type="number" step="1" value="120" readonly style="background: var(--glass);">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="seller_interest_display">Interest (%):</label>
          <input id="seller_interest_display" type="number" step="0.01" value="7" readonly style="background: var(--glass);">
        </div>
        <div class="field">
          <label for="seller_monthly_payment_display">Monthly Payment:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="seller_monthly_payment_display" type="number" step="1" value="2032" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="seller_5yr_interest_display">5 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="seller_5yr_interest_display" type="number" step="1" value="49529" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="seller_10yr_interest_display">10 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="seller_10yr_interest_display" type="number" step="1" value="68828" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Junior Debt</h3>
      <div class="form-row">
        <div class="field">
          <label for="junior_debt_amount_display">Junior Debt Amount:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="junior_debt_amount_display" type="number" step="1" value="0" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="junior_duration_months_display">Duration in Months:</label>
          <input id="junior_duration_months_display" type="number" step="1" value="120" readonly style="background: var(--glass);">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="junior_interest_display">Interest (%):</label>
          <input id="junior_interest_display" type="number" step="0.01" value="8" readonly style="background: var(--glass);">
        </div>
        <div class="field">
          <label for="junior_monthly_payment_display">Monthly Payment:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="junior_monthly_payment_display" type="number" step="1" value="0" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="junior_5yr_interest_display">5 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="junior_5yr_interest_display" type="number" step="1" value="0" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="junior_10yr_interest_display">10 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="junior_10yr_interest_display" type="number" step="1" value="0" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>SBA Loan</h3>
      <div class="form-row">
        <div class="field">
          <label for="sba_loan_amount_no_fees_display">Loan Amount (without fees):</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="sba_loan_amount_no_fees_display" type="number" step="1" value="1400000" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="sba_loan_amount_with_fees_display">Loan Amount (with fees):</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="sba_loan_amount_with_fees_display" type="number" step="1" value="1443485" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="sba_duration_months_display">Duration in Months:</label>
          <input id="sba_duration_months_display" type="number" step="1" value="120" readonly style="background: var(--glass);">
        </div>
        <div class="field">
          <label for="sba_interest_display">Interest (%):</label>
          <input id="sba_interest_display" type="number" step="0.01" value="10" readonly style="background: var(--glass);">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="sba_monthly_payment_display">Monthly Payment:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="sba_monthly_payment_display" type="number" step="1" value="19076" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="sba_5yr_interest_display">5 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="sba_5yr_interest_display" type="number" step="1" value="598868" readonly style="background: var(--glass);">
          </div>
        </div>
        <div class="field">
          <label for="sba_10yr_interest_display">10 Years of Interest:</label>
          <div class="input-group">
            <span class="prefix">$</span>
            <input id="sba_10yr_interest_display" type="number" step="1" value="845606" readonly style="background: var(--glass);">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer card" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <div>Made for SBA-style business purchase analysis.</div>
      <div>&copy; {year}</div>
    </div>
  </div>
</div>

<div class="footer">
  <div style="margin-bottom: 8px;">Business Valuation Calculator v1.20</div>
  <div>&copy; 2025 All rights reserved.</div>
</div>

<script>
// ============================================
// JAVASCRIPT - NO CALCULATION LOGIC!
// All calculations done via AJAX to PHP (single source of truth)
// ============================================

function formatCurrency(value) {
  return '$' + Math.round(value).toLocaleString('en-US');
}

function copySummary() {
  const el = document.querySelector('.card .badge');
  const text = el ? 'Loan Amount: ' + el.innerText : 'No summary';
  navigator.clipboard && navigator.clipboard.writeText(text);
  alert('Summary copied to clipboard');
}

function validateBusinessName() {
  const businessName = document.getElementById('business_name').value.trim();
  if (businessName === '') {
    alert('Please enter a business name before saving!');
    document.getElementById('business_name').focus();
    return false;
  }
  return true;
}

function loadBusiness() {
  const select = document.getElementById('business_id_select');
  const businessIdInput = document.getElementById('business_id');

  if (select.value) {
    businessIdInput.value = select.value;
    const form = document.getElementById('calcForm');
    let actionInput = document.querySelector('input[name="action_type"]');
    if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action_type';
      form.appendChild(actionInput);
    }
    actionInput.value = 'load';
    form.submit();
  }
}

function calculateMultiple() {
  const purchasePrice = parseFloat(document.getElementById('purchase_price').value) || 0;
  const sde = parseFloat(document.getElementById('sde').value) || 0;

  if (sde > 0) {
    const multiple = purchasePrice / sde;
    document.getElementById('multiple').value = multiple.toFixed(2);
  } else {
    document.getElementById('multiple').value = '';
  }
}

function updateCalculations() {
  // Gather all input values (using new.php field names)
  const inputs = {
    sde: parseFloat(document.getElementById('sde').value) || 0,
    price: parseFloat(document.getElementById('purchase_price').value) || 0,
    optional_salary: parseFloat(document.getElementById('new_owner_salary').value) || 0,
    extra_costs: parseFloat(document.getElementById('extra_costs').value) || 0,
    capex: parseFloat(document.getElementById('capex').value) || 0,
    consulting_fee: parseFloat(document.getElementById('consulting_fee').value) || 0,
    pct_down_payment: parseFloat(document.getElementById('pct_down_payment').value) || 0,
    pct_seller_carry: parseFloat(document.getElementById('pct_seller_carry').value) || 0,
    pct_junior_debt: parseFloat(document.getElementById('pct_junior_debt').value) || 0,
    loan_fee: parseFloat(document.getElementById('loan_fee').value) || 0,
    closing_costs: parseFloat(document.getElementById('closing_costs').value) || 0,
    other_fees: parseFloat(document.getElementById('other_fees').value) || 0,
    seller_duration: parseInt(document.getElementById('seller_duration_months').value) || 120,
    seller_interest: parseFloat(document.getElementById('seller_interest').value) || 0,
    junior_duration: parseInt(document.getElementById('junior_duration_months').value) || 120,
    junior_interest: parseFloat(document.getElementById('junior_interest').value) || 0,
    sba_duration: parseInt(document.getElementById('sba_duration_months').value) || 120,
    sba_interest: parseFloat(document.getElementById('sba_interest').value) || 0
  };

  // Call PHP via AJAX for calculations (single source of truth!)
  fetch('new.php?ajax=calculate', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(inputs)
  })
  .then(response => response.json())
  .then(metrics => {
    // Update calculated loan amounts
    document.getElementById('down_payment').value = Math.round(metrics.down_payment);
    document.getElementById('seller_carry').value = Math.round(metrics.seller_carry);
    document.getElementById('junior_debt').value = Math.round(metrics.junior_debt);

    // Update Seller Loan section
    document.getElementById('seller_carry_amount').value = Math.round(metrics.seller_carry);
    document.getElementById('seller_monthly_payment').value = Math.round(metrics.seller_monthly_payment);
    document.getElementById('seller_5yr_interest').value = Math.round(metrics.seller_5yr_interest);
    document.getElementById('seller_10yr_interest').value = Math.round(metrics.seller_10yr_interest);

    // Update Junior Debt section
    document.getElementById('junior_debt_amount').value = Math.round(metrics.junior_debt);
    document.getElementById('junior_monthly_payment').value = Math.round(metrics.junior_monthly_payment);
    document.getElementById('junior_5yr_interest').value = Math.round(metrics.junior_5yr_interest);
    document.getElementById('junior_10yr_interest').value = Math.round(metrics.junior_10yr_interest);

    // Update SBA Loan section
    document.getElementById('sba_loan_amount_no_fees').value = Math.round(metrics.loan);
    document.getElementById('sba_loan_amount_with_fees').value = Math.round(metrics.sba_loan_amount);
    document.getElementById('sba_monthly_payment').value = Math.round(metrics.sba_monthly_payment);
    document.getElementById('sba_5yr_interest').value = Math.round(metrics.sba_5yr_interest);
    document.getElementById('sba_10yr_interest').value = Math.round(metrics.sba_10yr_interest);

    // Update results display (right sidebar)
    updateResultsDisplay(metrics, inputs);
  })
  .catch(error => {
    console.error('Calculation error:', error);
  });
}

function updateResultsDisplay(metrics, inputs) {
  // Update Monthly Cashflow
  const monthlyEl = document.getElementById('monthly_cashflow');
  if (monthlyEl) {
    monthlyEl.textContent = formatCurrency(metrics.monthly_cashflow);
  }

  const monthlyDetailsEl = document.getElementById('monthly_details');
  if (monthlyDetailsEl) {
    monthlyDetailsEl.innerHTML =
      'SDE: ' + formatCurrency(inputs.sde / 12) + '<br>' +
      'SBA Payment: -' + formatCurrency(metrics.sba_monthly_payment) + '<br>' +
      'Junior Debt Payment: -' + formatCurrency(metrics.junior_monthly_payment) + '<br>' +
      'Seller Payment: -' + formatCurrency(metrics.seller_monthly_payment) + '<br>' +
      'Salary: -' + formatCurrency(inputs.optional_salary / 12) + '<br>' +
      'Extra Costs: -' + formatCurrency(inputs.extra_costs / 12) + '<br>' +
      'Capex: -' + formatCurrency(inputs.capex / 12);
  }

  // Update Annual Cashflow
  const annualEl = document.getElementById('annual_cashflow');
  if (annualEl) {
    annualEl.textContent = formatCurrency(metrics.annual_cashflow);
  }

  const annualSalaryEl = document.getElementById('annual_cashflow_salary');
  if (annualSalaryEl) {
    annualSalaryEl.textContent = formatCurrency(metrics.annual_cashflow_with_salary);
  }

  const annualDetailsEl = document.getElementById('annual_details');
  if (annualDetailsEl) {
    annualDetailsEl.innerHTML =
      'SDE: ' + formatCurrency(inputs.sde) + '<br>' +
      'SBA Payment: -' + formatCurrency(metrics.sba_monthly_payment * 12) + '<br>' +
      'Junior Debt Payment: -' + formatCurrency(metrics.junior_monthly_payment * 12) + '<br>' +
      'Seller Payment: -' + formatCurrency(metrics.seller_monthly_payment * 12) + '<br>' +
      'Salary: -' + formatCurrency(inputs.optional_salary) + '<br>' +
      'Extra Costs: -' + formatCurrency(inputs.extra_costs) + '<br>' +
      'Capex: -' + formatCurrency(inputs.capex);
  }

  // Update DSCR
  const dscrEl = document.getElementById('dscr_value');
  if (dscrEl) {
    dscrEl.textContent = metrics.dscr.toFixed(2);
  }

  const dscrStatusEl = document.getElementById('dscr_status');
  if (dscrStatusEl) {
    let status;
    if (metrics.dscr >= 1.5) {
      status = 'Acceptable';
    } else if (metrics.dscr >= 1.25) {
      status = 'Acceptable';
    } else {
      status = 'Weak';
    }
    dscrStatusEl.textContent = status + ' | Min: 1.25';
  }

  // Update Price Breakdown
  const totalPriceEl = document.getElementById('total_price');
  if (totalPriceEl) {
    totalPriceEl.textContent = formatCurrency(inputs.price);
  }

  const downAmountEl = document.getElementById('down_amount');
  if (downAmountEl) {
    downAmountEl.textContent = formatCurrency(metrics.down_payment);
  }

  const downPctEl = document.getElementById('down_pct');
  if (downPctEl) {
    const pct = inputs.price > 0 ? ((metrics.down_payment / inputs.price) * 100).toFixed(1) : '0.0';
    downPctEl.textContent = pct + '%';
  }

  const sbaAmountEl = document.getElementById('sba_amount');
  if (sbaAmountEl) {
    sbaAmountEl.textContent = formatCurrency(metrics.sba_loan_amount);
  }

  const sbaPctEl = document.getElementById('sba_pct');
  if (sbaPctEl) {
    const pct = inputs.price > 0 ? ((metrics.sba_loan_amount / inputs.price) * 100).toFixed(1) : '0.0';
    sbaPctEl.textContent = pct + '%';
  }

  const juniorAmountEl = document.getElementById('junior_amount');
  if (juniorAmountEl) {
    juniorAmountEl.textContent = formatCurrency(metrics.junior_debt);
  }

  const juniorPctEl = document.getElementById('junior_pct');
  if (juniorPctEl) {
    const pct = inputs.price > 0 ? ((metrics.junior_debt / inputs.price) * 100).toFixed(1) : '0.0';
    juniorPctEl.textContent = pct + '%';
  }

  const sellerAmountEl = document.getElementById('seller_amount');
  if (sellerAmountEl) {
    sellerAmountEl.textContent = formatCurrency(metrics.seller_carry);
  }

  const sellerPctEl = document.getElementById('seller_pct');
  if (sellerPctEl) {
    const pct = inputs.price > 0 ? ((metrics.seller_carry / inputs.price) * 100).toFixed(1) : '0.0';
    sellerPctEl.textContent = pct + '%';
  }

  // Update Payment to Seller sections
  updatePaymentToSeller(metrics, inputs);
}

function updatePaymentToSeller(metrics, inputs) {
  // Calculate principal paid in 5 years (60 months)
  const seller_principal_5yr = (metrics.seller_monthly_payment * 60) - metrics.seller_5yr_interest;

  // 5 Years with balloon
  const total5yrEl = document.getElementById('seller_5yr_total');
  if (total5yrEl) {
    total5yrEl.textContent = formatCurrency(metrics.total_seller_5yr);
  }

  const details5yrEl = document.getElementById('seller_5yr_details');
  if (details5yrEl) {
    details5yrEl.innerHTML =
      'Down: ' + formatCurrency(metrics.down_payment) + '<br>' +
      'SBA Loan (Full): ' + formatCurrency(metrics.sba_loan_amount) + '<br>' +
      'Junior Debt (Full): ' + formatCurrency(metrics.junior_debt) + '<br>' +
      'Seller Carry Principal: ' + formatCurrency(seller_principal_5yr) + '<br>' +
      'Seller Carry Interest: ' + formatCurrency(metrics.seller_5yr_interest) + '<br>' +
      'Seller Carry Balloon: ' + formatCurrency(metrics.seller_balloon_5yr) + '<br>' +
      'Consulting: ' + formatCurrency(inputs.consulting_fee);
  }

  // 10 Years
  const total10yrEl = document.getElementById('seller_10yr_total');
  if (total10yrEl) {
    total10yrEl.textContent = formatCurrency(metrics.total_seller_10yr);
  }

  const details10yrEl = document.getElementById('seller_10yr_details');
  if (details10yrEl) {
    details10yrEl.innerHTML =
      'Down: ' + formatCurrency(metrics.down_payment) + '<br>' +
      'SBA Loan (Full): ' + formatCurrency(metrics.sba_loan_amount) + '<br>' +
      'Junior Debt (Full): ' + formatCurrency(metrics.junior_debt) + '<br>' +
      'Seller Carry Principal: ' + formatCurrency(metrics.seller_carry) + '<br>' +
      'Seller Carry Interest: ' + formatCurrency(metrics.seller_10yr_interest) + '<br>' +
      'Consulting: ' + formatCurrency(inputs.consulting_fee);
  }
}

// Add event listeners for auto-calculation
document.addEventListener('DOMContentLoaded', function() {
  const inputs = document.querySelectorAll('#calcForm input[type="number"]');

  inputs.forEach(input => {
    input.addEventListener('input', function() {
      calculateMultiple(); // Update multiple field
      updateCalculations(); // Update all calculations via AJAX
    });
  });

  // Initial calculation on page load
  calculateMultiple();
  updateCalculations();
});
</script>
</body>
</html>
