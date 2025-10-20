<?php
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
// SECURITY: INPUT VALIDATION
// ============================================

function validateAndSanitizeBusinessData($post_data) {
    $errors = [];

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
            $loadedData = $business; // SQLite already has flat structure
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Valuation Calculator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 10px;
            font-size: 12px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background-color: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .main-content {
            display: grid;
            grid-template-columns: 550px 1fr;
            gap: 20px;
        }

        h1 {
            color: #333;
            margin-bottom: 15px;
            text-align: center;
            font-size: 18px;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 200px 150px;
            gap: 10px;
            margin-bottom: 8px;
            align-items: center;
        }

        .form-row label {
            font-weight: 600;
            color: #555;
            font-size: 11px;
        }

        .form-row input {
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            position: relative;
            z-index: 2;
        }

        .form-row input:disabled {
            background-color: #f0f0f0;
            color: #666;
        }

        .form-row input[type="text"]:not(:disabled) {
            border-color: #2196F3;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #2c3e50;
            margin: 15px 0 8px 0;
            padding-bottom: 5px;
            border-bottom: 2px solid #2196F3;
        }

        .loan-sections {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
            position: relative;
            z-index: 1;
        }

        .loan-section {
            padding: 10px;
            background-color: #fafafa;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            position: relative;
        }

        .loan-section h3 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .results-section {
            margin-top: 0;
            padding: 15px;
            background-color: #e3f2fd;
            border-radius: 4px;
            border-left: 3px solid #2196F3;
        }

        .results-section h2 {
            color: #2c3e50;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .result-item {
            background-color: white;
            padding: 8px;
            border-radius: 3px;
            border: 1px solid #bbdefb;
        }

        .result-label {
            font-weight: 600;
            color: #555;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .result-value {
            font-size: 13px;
            color: #2c3e50;
            font-weight: bold;
        }

        .validation-check {
            margin-top: 10px;
            padding: 8px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 11px;
        }

        .validation-pass {
            background-color: #bbdefb;
            color: #1565c0;
        }

        .validation-fail {
            background-color: #ffcdd2;
            color: #c62828;
        }

        .record-manager {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 2px solid #2196F3;
        }

        .record-manager-title {
            font-size: 14px;
            font-weight: bold;
            color: #1565c0;
            margin-bottom: 10px;
        }

        .record-controls {
            display: grid;
            grid-template-columns: 1fr auto auto auto auto;
            gap: 8px;
            align-items: center;
        }

        .record-controls select {
            padding: 6px 10px;
            border: 2px solid #2196F3;
            border-radius: 4px;
            font-size: 12px;
            background-color: white;
        }

        .record-controls button {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-new {
            background-color: #4CAF50;
            color: white;
        }

        .btn-new:hover {
            background-color: #45a049;
        }

        .btn-save {
            background-color: #2196F3;
            color: white;
        }

        .btn-save:hover {
            background-color: #0b7dda;
        }

        .btn-save-new {
            background-color: #FF9800;
            color: white;
        }

        .btn-save-new:hover {
            background-color: #e68900;
        }

        .btn-delete {
            background-color: #f44336;
            color: white;
        }

        .btn-delete:hover {
            background-color: #da190b;
        }

        .message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .message-success {
            background-color: #c8e6c9;
            color: #2e7d32;
            border: 1px solid #4CAF50;
        }

        .message-error {
            background-color: #ffcdd2;
            color: #c62828;
            border: 1px solid #f44336;
        }

        @media (max-width: 768px) {
            body {
                padding: 5px;
                font-size: 11px;
            }

            .container {
                padding: 10px;
            }

            .main-content {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .left-column {
                order: 1;
            }

            .right-column {
                order: 2;
            }

            #loan-sections {
                order: 3;
            }

            h1 {
                font-size: 16px;
                margin-bottom: 10px;
            }

            .form-row {
                grid-template-columns: 150px 120px;
                gap: 8px;
                margin-bottom: 6px;
            }

            .form-row label {
                font-size: 10px;
            }

            .form-row input {
                padding: 4px 6px;
                font-size: 11px;
            }

            .section-title {
                font-size: 12px;
                margin: 10px 0 6px 0;
            }

            .loan-sections {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .loan-section {
                padding: 8px;
            }

            .loan-section h3 {
                font-size: 12px;
                margin-bottom: 6px;
            }

            .results-section {
                padding: 10px;
                margin-top: 15px;
            }

            .results-section h2 {
                font-size: 13px;
                margin-bottom: 8px;
            }

            .results-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .result-item {
                padding: 6px;
            }

            .result-label {
                font-size: 9px;
            }

            .result-value {
                font-size: 12px;
            }

            .validation-check {
                padding: 6px;
                font-size: 10px;
            }

            .results-section h2 {
                font-size: 12px;
            }

            .results-section > div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
            }
        }

    </style>
    <script>
        // Auto-calculate fields in real-time
        function calculateLoanPayment(principal, annualInterestRate, numPayments) {
            if (principal <= 0 || numPayments <= 0) return 0;
            const monthlyRate = (annualInterestRate / 100) / 12;
            if (monthlyRate === 0) return principal / numPayments;
            return principal * (monthlyRate * Math.pow(1 + monthlyRate, numPayments)) / (Math.pow(1 + monthlyRate, numPayments) - 1);
        }

        function calculateInterestPaid(principal, annualInterestRate, numPayments, monthsToCalculate) {
            if (principal <= 0 || numPayments <= 0) return 0;
            const monthlyRate = (annualInterestRate / 100) / 12;
            const monthlyPayment = calculateLoanPayment(principal, annualInterestRate, numPayments);

            let balance = principal;
            let totalInterest = 0;

            for (let i = 0; i < Math.min(monthsToCalculate, numPayments); i++) {
                const interestPayment = balance * monthlyRate;
                totalInterest += interestPayment;
                const principalPayment = monthlyPayment - interestPayment;
                balance -= principalPayment;
            }

            return totalInterest;
        }

        function calculateRemainingBalance(principal, annualInterestRate, numPayments, monthsPaid) {
            if (principal <= 0 || numPayments <= 0) return 0;
            const monthlyRate = (annualInterestRate / 100) / 12;
            const monthlyPayment = calculateLoanPayment(principal, annualInterestRate, numPayments);

            let balance = principal;

            for (let i = 0; i < Math.min(monthsPaid, numPayments); i++) {
                const interestPayment = balance * monthlyRate;
                const principalPayment = monthlyPayment - interestPayment;
                balance -= principalPayment;
            }

            return Math.max(0, balance);
        }

        function formatCurrency(value) {
            return '$' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

        function updateCalculations() {
            // Get input values
            const sde = parseFloat(document.getElementById('sde').value) || 0;
            const price = parseFloat(document.getElementById('price').value) || 0;
            const optionalSalary = parseFloat(document.getElementById('optional_salary').value) || 0;
            const extraCosts = parseFloat(document.getElementById('extra_costs').value) || 0;
            const capex = parseFloat(document.getElementById('capex').value) || 0;
            const consultingFee = parseFloat(document.getElementById('consulting_fee').value) || 0;
            const pctDownPayment = parseFloat(document.getElementById('pct_down_payment').value) || 0;
            const pctSellerCarry = parseFloat(document.getElementById('pct_seller_carry').value) || 0;
            const pctJuniorDebt = parseFloat(document.getElementById('pct_junior_debt').value) || 0;
            const loanFee = parseFloat(document.getElementById('loan_fee').value) || 0;
            const closingCosts = parseFloat(document.getElementById('closing_costs').value) || 0;
            const otherFees = parseFloat(document.getElementById('other_fees').value) || 0;
            const sellerDuration = parseInt(document.getElementById('seller_duration').value) || 120;
            const sellerInterest = parseFloat(document.getElementById('seller_interest').value) || 0;
            const juniorDuration = parseInt(document.getElementById('junior_duration').value) || 120;
            const juniorInterest = parseFloat(document.getElementById('junior_interest').value) || 0;
            const sbaDuration = parseInt(document.getElementById('sba_duration').value) || 120;
            const sbaInterest = parseFloat(document.getElementById('sba_interest').value) || 0;

            // Calculate Multiple (2 decimal places)
            const multiple = sde > 0 ? (price / sde).toFixed(2) : '0.00';
            document.getElementById('multiple').value = multiple;

            // Calculate Down Payment, Seller Carry, and Junior Debt
            const downPayment = price * (pctDownPayment / 100);
            const sellerCarry = price * (pctSellerCarry / 100);
            const juniorDebt = price * (pctJuniorDebt / 100);
            document.getElementById('down_payment').value = formatCurrency(downPayment);
            document.getElementById('seller_carry').value = formatCurrency(sellerCarry);
            document.getElementById('junior_debt').value = formatCurrency(juniorDebt);

            // Calculate loan amount (SBA gets what's left after down, seller carry, and junior debt)
            const loan = price - downPayment - sellerCarry - juniorDebt;
            const sbaLoanAmount = loan + loanFee + closingCosts + otherFees;

            // Update loan amounts in the form
            document.getElementById('seller_loan_amount').value = formatCurrency(sellerCarry);
            document.getElementById('junior_loan_amount').value = formatCurrency(juniorDebt);
            document.getElementById('sba_loan_base').value = formatCurrency(loan);
            document.getElementById('sba_loan_amount').value = formatCurrency(sbaLoanAmount);

            // Calculate Seller loan payments and interest
            const sellerMonthlyPayment = calculateLoanPayment(sellerCarry, sellerInterest, sellerDuration);
            const seller5yrInterest = calculateInterestPaid(sellerCarry, sellerInterest, sellerDuration, 60);
            const seller10yrInterest = calculateInterestPaid(sellerCarry, sellerInterest, sellerDuration, 120);

            document.getElementById('seller_monthly_payment').value = formatCurrency(sellerMonthlyPayment);
            document.getElementById('seller_5yr_interest').value = formatCurrency(seller5yrInterest);
            document.getElementById('seller_10yr_interest').value = formatCurrency(seller10yrInterest);

            // Calculate Junior Debt loan payments and interest
            const juniorMonthlyPayment = calculateLoanPayment(juniorDebt, juniorInterest, juniorDuration);
            const junior5yrInterest = calculateInterestPaid(juniorDebt, juniorInterest, juniorDuration, 60);
            const junior10yrInterest = calculateInterestPaid(juniorDebt, juniorInterest, juniorDuration, 120);

            document.getElementById('junior_monthly_payment').value = formatCurrency(juniorMonthlyPayment);
            document.getElementById('junior_5yr_interest').value = formatCurrency(junior5yrInterest);
            document.getElementById('junior_10yr_interest').value = formatCurrency(junior10yrInterest);

            // Calculate SBA loan payments and interest
            const sbaMonthlyPayment = calculateLoanPayment(sbaLoanAmount, sbaInterest, sbaDuration);
            const sba5yrInterest = calculateInterestPaid(sbaLoanAmount, sbaInterest, sbaDuration, 60);
            const sba10yrInterest = calculateInterestPaid(sbaLoanAmount, sbaInterest, sbaDuration, 120);

            document.getElementById('sba_monthly_payment').value = formatCurrency(sbaMonthlyPayment);
            document.getElementById('sba_5yr_interest').value = formatCurrency(sba5yrInterest);
            document.getElementById('sba_10yr_interest').value = formatCurrency(sba10yrInterest);

            // Calculate balloon payments on seller carry and junior debt loans
            const sellerBalloon5yr = calculateRemainingBalance(sellerCarry, sellerInterest, sellerDuration, 60);
            const sellerBalloon10yr = calculateRemainingBalance(sellerCarry, sellerInterest, sellerDuration, 120);
            const juniorBalloon5yr = calculateRemainingBalance(juniorDebt, juniorInterest, juniorDuration, 60);
            const juniorBalloon10yr = calculateRemainingBalance(juniorDebt, juniorInterest, juniorDuration, 120);

            // Calculate cashflow (includes junior debt payment)
            const monthlyCashflow = (sde / 12) - sbaMonthlyPayment - sellerMonthlyPayment - juniorMonthlyPayment - (optionalSalary / 12) - (extraCosts / 12) - (capex / 12);
            const annualCashflow = sde - (sbaMonthlyPayment * 12) - (sellerMonthlyPayment * 12) - (juniorMonthlyPayment * 12) - optionalSalary - extraCosts - capex;

            // Calculate DSCR (Debt Service Coverage Ratio) - includes junior debt
            const netOperatingIncome = sde - optionalSalary - extraCosts - capex;
            const totalDebtService = (sbaMonthlyPayment * 12) + (sellerMonthlyPayment * 12) + (juniorMonthlyPayment * 12);
            const dscr = totalDebtService > 0 ? netOperatingIncome / totalDebtService : 0;

            // Total payments over 5 and 10 years (includes junior debt)
            const totalPaid5yr = (sbaMonthlyPayment * 60) + (sellerMonthlyPayment * 60) + (juniorMonthlyPayment * 60) + downPayment + (extraCosts * 5) + consultingFee + sellerBalloon5yr + juniorBalloon5yr;
            const totalPaid10yr = (sbaMonthlyPayment * 120) + (sellerMonthlyPayment * 120) + (juniorMonthlyPayment * 120) + downPayment + (extraCosts * 10) + consultingFee;

            // Total to seller - NOW INCLUDES JUNIOR DEBT
            // At 5 years: Down + Full SBA Loan + Full Junior Debt + Seller Carry payments (5 years) + Seller Carry Balloon + Consulting
            const totalSeller5yr = downPayment + loan + juniorDebt + (sellerMonthlyPayment * 60) + sellerBalloon5yr + consultingFee;

            // At 10 years: Down + Full SBA Loan + Full Junior Debt + Seller Carry payments (full duration) + Consulting
            const totalSeller10yr = downPayment + loan + juniorDebt + (sellerMonthlyPayment * Math.min(120, sellerDuration)) + consultingFee;

            // Update results section - Monthly Cashflow
            const monthlyCashflowContainer = document.getElementById('monthly_cashflow_container');
            const monthlyCashflowValue = document.getElementById('monthly_cashflow_value');
            const monthlyCashflowDetails = document.getElementById('monthly_cashflow_details');

            if (monthlyCashflowContainer) {
                monthlyCashflowContainer.style.borderLeftColor = monthlyCashflow >= 0 ? '#2196F3' : '#f44336';
            }
            if (monthlyCashflowValue) {
                monthlyCashflowValue.textContent = '$' + Math.round(monthlyCashflow).toLocaleString('en-US');
                monthlyCashflowValue.style.color = monthlyCashflow >= 0 ? '#1565c0' : '#c62828';
            }
            if (monthlyCashflowDetails) {
                monthlyCashflowDetails.innerHTML =
                    'SDE: $' + Math.round(sde / 12).toLocaleString('en-US') + '<br>' +
                    'SBA Payment: -$' + Math.round(sbaMonthlyPayment).toLocaleString('en-US') + '<br>' +
                    'Junior Debt Payment: -$' + Math.round(juniorMonthlyPayment).toLocaleString('en-US') + '<br>' +
                    'Seller Payment: -$' + Math.round(sellerMonthlyPayment).toLocaleString('en-US') + '<br>' +
                    'Salary: -$' + Math.round(optionalSalary / 12).toLocaleString('en-US') + '<br>' +
                    'Extra Costs: -$' + Math.round(extraCosts / 12).toLocaleString('en-US') + '<br>' +
                    'Capex: -$' + Math.round(capex / 12).toLocaleString('en-US');
            }

            // Update results section - Annual Cashflow
            const annualEl = document.querySelector('.results-section > div:nth-child(2) > div:nth-child(2)');
            if (annualEl) {
                annualEl.style.borderLeftColor = annualCashflow >= 0 ? '#2196F3' : '#f44336';
                const annualValueEl = annualEl.querySelector('div > div:nth-child(1) > div:nth-child(2)');
                const annualSalaryEl = annualEl.querySelector('div > div:nth-child(2) > div:nth-child(2)');
                const color = annualCashflow >= 0 ? '#1565c0' : '#c62828';
                if (annualValueEl) {
                    annualValueEl.textContent = '$' + Math.round(annualCashflow).toLocaleString('en-US');
                    annualValueEl.style.color = color;
                }
                if (annualSalaryEl) {
                    annualSalaryEl.textContent = '$' + Math.round(annualCashflow + optionalSalary).toLocaleString('en-US');
                    annualSalaryEl.style.color = color;
                }
            }

            // Update DSCR
            const dscrEl = document.querySelector('.results-section > div:nth-child(2) > div:nth-child(3)');
            if (dscrEl) {
                let dscrColor, dscrBorderColor;
                if (dscr >= 1.5) {
                    dscrColor = '#2e7d32'; // Strong green
                    dscrBorderColor = '#4CAF50';
                } else if (dscr >= 1.25) {
                    dscrColor = '#f57c00'; // Orange
                    dscrBorderColor = '#FF9800';
                } else {
                    dscrColor = '#c62828'; // Red
                    dscrBorderColor = '#f44336';
                }
                dscrEl.style.borderLeftColor = dscrBorderColor;
                const dscrValueEl = dscrEl.querySelector('div:nth-child(2)');
                if (dscrValueEl) {
                    dscrValueEl.textContent = dscr.toFixed(2);
                    dscrValueEl.style.color = dscrColor;
                }
            }

            // Update Price Breakdown section
            const priceBreakdownDown = document.getElementById('price_breakdown_down');
            const priceBreakdownSba = document.getElementById('price_breakdown_sba');
            const priceBreakdownJunior = document.getElementById('price_breakdown_junior');
            const priceBreakdownSeller = document.getElementById('price_breakdown_seller');

            if (priceBreakdownDown) {
                const downPct = price > 0 ? ((downPayment / price) * 100).toFixed(1) : '0.0';
                priceBreakdownDown.innerHTML = '$' + Math.round(downPayment).toLocaleString('en-US') +
                    '<span style="font-size: 10px; color: #888; margin-left: 8px;">' + downPct + '%</span>';
            }
            if (priceBreakdownSba) {
                const sbaPct = price > 0 ? ((loan / price) * 100).toFixed(1) : '0.0';
                priceBreakdownSba.innerHTML = '$' + Math.round(loan).toLocaleString('en-US') +
                    '<span style="font-size: 10px; color: #888; margin-left: 8px;">' + sbaPct + '%</span>';
            }
            if (priceBreakdownJunior) {
                const juniorPct = price > 0 ? ((juniorDebt / price) * 100).toFixed(1) : '0.0';
                priceBreakdownJunior.innerHTML = '$' + Math.round(juniorDebt).toLocaleString('en-US') +
                    '<span style="font-size: 10px; color: #888; margin-left: 8px;">' + juniorPct + '%</span>';
            }
            if (priceBreakdownSeller) {
                const sellerPct = price > 0 ? ((sellerCarry / price) * 100).toFixed(1) : '0.0';
                priceBreakdownSeller.innerHTML = '$' + Math.round(sellerCarry).toLocaleString('en-US') +
                    '<span style="font-size: 10px; color: #888; margin-left: 8px;">' + sellerPct + '%</span>';
            }

            // Update Total to Seller (5 Years) - using direct IDs
            const seller5yrValueEl = document.getElementById('total_seller_5yr_value');
            const seller5yrDetailsEl = document.getElementById('total_seller_5yr_details');
            if (seller5yrValueEl) {
                seller5yrValueEl.textContent = '$' + Math.round(totalSeller5yr).toLocaleString('en-US');
            }
            if (seller5yrDetailsEl) {
                seller5yrDetailsEl.innerHTML =
                    'Down: $' + Math.round(downPayment).toLocaleString('en-US') + '<br>' +
                    'SBA Loan (Full): $' + Math.round(loan).toLocaleString('en-US') + '<br>' +
                    'Junior Debt (Full): $' + Math.round(juniorDebt).toLocaleString('en-US') + '<br>' +
                    'Seller Carry Principal: $' + Math.round((sellerMonthlyPayment * 60) - seller5yrInterest).toLocaleString('en-US') + '<br>' +
                    'Seller Carry Interest: $' + Math.round(seller5yrInterest).toLocaleString('en-US') + '<br>' +
                    'Seller Carry Balloon: $' + Math.round(sellerBalloon5yr).toLocaleString('en-US') + '<br>' +
                    'Consulting: $' + Math.round(consultingFee).toLocaleString('en-US');
            }

            // Update Total to Seller (10 Years) - using direct IDs
            const seller10yrValueEl = document.getElementById('total_seller_10yr_value');
            const seller10yrDetailsEl = document.getElementById('total_seller_10yr_details');
            if (seller10yrValueEl) {
                seller10yrValueEl.textContent = '$' + Math.round(totalSeller10yr).toLocaleString('en-US');
            }
            if (seller10yrDetailsEl) {
                seller10yrDetailsEl.innerHTML =
                    'Down: $' + Math.round(downPayment).toLocaleString('en-US') + '<br>' +
                    'SBA Loan (Full): $' + Math.round(loan).toLocaleString('en-US') + '<br>' +
                    'Junior Debt (Full): $' + Math.round(juniorDebt).toLocaleString('en-US') + '<br>' +
                    'Seller Carry Principal: $' + Math.round((sellerMonthlyPayment * Math.min(120, sellerDuration)) - seller10yrInterest).toLocaleString('en-US') + '<br>' +
                    'Seller Carry Interest: $' + Math.round(seller10yrInterest).toLocaleString('en-US') + '<br>' +
                    'Consulting: $' + Math.round(consultingFee).toLocaleString('en-US');
            }

            // Validation check - includes junior debt
            const totalCheck = loan + sellerCarry + juniorDebt + downPayment;
            const validationPass = Math.abs(totalCheck - price) < 0.01;
            const validationEl = document.getElementById('validation');
            validationEl.textContent = 'Validation: Loan + Seller Carry + Junior Debt + Down Payment = ' + formatCurrency(totalCheck) +
                (validationPass ? ' ✓ Equals Price' : ' ✗ Does NOT equal Price (' + formatCurrency(price) + ')');
            validationEl.className = 'validation-check ' + (validationPass ? 'validation-pass' : 'validation-fail');
        }

        // Handle business dropdown selection
        function loadBusiness() {
            const select = document.getElementById('business_id');
            if (select.value) {
                const form = document.getElementById('mainForm');
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

        // Add event listeners when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = [
                'sde', 'price', 'optional_salary', 'extra_costs', 'capex', 'consulting_fee',
                'pct_down_payment', 'pct_seller_carry', 'pct_junior_debt', 'loan_fee', 'closing_costs', 'other_fees',
                'seller_duration', 'seller_interest', 'junior_duration', 'junior_interest', 'sba_duration', 'sba_interest'
            ];

            inputs.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('input', updateCalculations);
                }
            });

            // Initial calculation on page load
            updateCalculations();
        });
    </script>
</head>
<body>
    <div class="container">
        <h1>Business Valuation Calculator</h1>

        <div class="main-content">
            <div class="left-column">
                <form method="POST" action="" id="mainForm">
                <!-- CSRF Protection -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <!-- Record Manager -->
                <?php if ($message): ?>
                <div class="message message-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <div class="record-manager">
                    <div class="record-manager-title">Business Records</div>
                    <div class="record-controls">
                        <select name="business_id" id="business_id" onchange="loadBusiness()">
                            <option value="">-- New Business --</option>
                            <?php foreach ($allBusinesses as $biz): ?>
                            <option value="<?php echo $biz['id']; ?>" <?php echo ($selectedBusinessId == $biz['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($biz['business_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="action_type" value="new" class="btn-new">New</button>
                        <button type="submit" name="action_type" value="save" class="btn-save" <?php echo empty($selectedBusinessId) ? 'disabled' : ''; ?>>Save</button>
                        <button type="submit" name="action_type" value="save_new" class="btn-save-new" onclick="return validateBusinessName()">Save As New</button>
                        <button type="submit" name="action_type" value="delete" class="btn-delete" <?php echo empty($selectedBusinessId) ? 'disabled' : ''; ?> onclick="return confirm('Delete this business record?')">Delete</button>
                    </div>
                </div>

            <div class="form-section">
                <div class="form-row">
                    <label for="business_name">Name of Business: *</label>
                    <input type="text" id="business_name" name="business_name" value="<?php echo htmlspecialchars($loadedData['business_name'] ?? $_POST['business_name'] ?? ''); ?>" required placeholder="Enter business name">
                </div>

                <div class="form-row">
                    <label for="sde">SDE (Seller's Discretionary Earnings):</label>
                    <input type="number" id="sde" name="sde" value="<?php echo htmlspecialchars($loadedData['sde'] ?? $_POST['sde'] ?? '500000'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="price">Price:</label>
                    <input type="number" id="price" name="price" value="<?php echo htmlspecialchars($loadedData['price'] ?? $_POST['price'] ?? '1750000'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="optional_salary">Optional Salary:</label>
                    <input type="number" id="optional_salary" name="optional_salary" value="<?php echo htmlspecialchars($loadedData['optional_salary'] ?? $_POST['optional_salary'] ?? '125000'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label>Multiple (Price/SDE):</label>
                    <input type="text" disabled id="multiple" value="">
                </div>

                <div class="form-row">
                    <label for="extra_costs">Extra Operating Costs (Annual):</label>
                    <input type="number" id="extra_costs" name="extra_costs" value="<?php echo htmlspecialchars($loadedData['extra_costs'] ?? $_POST['extra_costs'] ?? '0'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="capex">Averaged Capex (Annual):</label>
                    <input type="number" id="capex" name="capex" value="<?php echo htmlspecialchars($loadedData['capex'] ?? $_POST['capex'] ?? '0'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="consulting_fee">Consulting Fee (Year 1):</label>
                    <input type="number" id="consulting_fee" name="consulting_fee" value="<?php echo htmlspecialchars($loadedData['consulting_fee'] ?? $_POST['consulting_fee'] ?? '0'); ?>" step="1">
                </div>

                <div class="section-title">Down Payment & Seller Carry</div>

                <div class="form-row">
                    <label>Down Payment:</label>
                    <input type="text" disabled id="down_payment" value="">
                </div>

                <div class="form-row">
                    <label for="pct_down_payment">% Down Payment:</label>
                    <input type="number" id="pct_down_payment" name="pct_down_payment" value="<?php echo htmlspecialchars($loadedData['pct_down_payment'] ?? $_POST['pct_down_payment'] ?? '10'); ?>" step="0.01" min="0" max="100">
                </div>

                <div class="form-row">
                    <label>Seller Carry:</label>
                    <input type="text" disabled id="seller_carry" value="">
                </div>

                <div class="form-row">
                    <label for="pct_seller_carry">% Seller Carry:</label>
                    <input type="number" id="pct_seller_carry" name="pct_seller_carry" value="<?php echo htmlspecialchars($loadedData['pct_seller_carry'] ?? $_POST['pct_seller_carry'] ?? '10'); ?>" step="0.01" min="0" max="100">
                </div>

                <div class="form-row">
                    <label>Junior Debt:</label>
                    <input type="text" disabled id="junior_debt" value="">
                </div>

                <div class="form-row">
                    <label for="pct_junior_debt">% Junior Debt:</label>
                    <input type="number" id="pct_junior_debt" name="pct_junior_debt" value="<?php echo htmlspecialchars($loadedData['pct_junior_debt'] ?? $_POST['pct_junior_debt'] ?? '0'); ?>" step="0.01" min="0" max="100">
                </div>

                <div class="section-title">SBA Loan</div>

                <div class="form-row">
                    <label for="loan_fee">Loan Fee:</label>
                    <input type="number" id="loan_fee" name="loan_fee" value="<?php echo htmlspecialchars($loadedData['loan_fee'] ?? $_POST['loan_fee'] ?? '13485'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="closing_costs">Closing Costs:</label>
                    <input type="number" id="closing_costs" name="closing_costs" value="<?php echo htmlspecialchars($loadedData['closing_costs'] ?? $_POST['closing_costs'] ?? '15000'); ?>" step="1">
                </div>

                <div class="form-row">
                    <label for="other_fees">Other Fees:</label>
                    <input type="number" id="other_fees" name="other_fees" value="<?php echo htmlspecialchars($loadedData['other_fees'] ?? $_POST['other_fees'] ?? '15000'); ?>" step="1">
                </div>

                <div class="validation-check" id="validation"></div>
            </div>

            <div class="loan-sections" id="loan-sections">
                <div class="loan-section">
                    <h3>Seller Loan</h3>
                    <div class="form-row">
                        <label>Seller Carry Amount:</label>
                        <input type="text" disabled id="seller_loan_amount" value="">
                    </div>
                    <div class="form-row">
                        <label for="seller_duration">Duration in Months:</label>
                        <input type="number" id="seller_duration" name="seller_duration" value="<?php echo htmlspecialchars($loadedData['seller_duration'] ?? $_POST['seller_duration'] ?? '120'); ?>">
                    </div>
                    <div class="form-row">
                        <label for="seller_interest">Interest (%):</label>
                        <input type="number" id="seller_interest" name="seller_interest" value="<?php echo htmlspecialchars($loadedData['seller_interest'] ?? $_POST['seller_interest'] ?? '7'); ?>" step="0.01">
                    </div>
                    <div class="form-row">
                        <label>Monthly Payment:</label>
                        <input type="text" disabled id="seller_monthly_payment" value="">
                    </div>
                    <div class="form-row">
                        <label>5 Years of Interest:</label>
                        <input type="text" disabled id="seller_5yr_interest" value="">
                    </div>
                    <div class="form-row">
                        <label>10 Years of Interest:</label>
                        <input type="text" disabled id="seller_10yr_interest" value="">
                    </div>
                </div>

                <div class="loan-section">
                    <h3>Junior Debt</h3>
                    <div class="form-row">
                        <label>Junior Debt Amount:</label>
                        <input type="text" disabled id="junior_loan_amount" value="">
                    </div>
                    <div class="form-row">
                        <label for="junior_duration">Duration in Months:</label>
                        <input type="number" id="junior_duration" name="junior_duration" value="<?php echo htmlspecialchars($loadedData['junior_duration'] ?? $_POST['junior_duration'] ?? '120'); ?>">
                    </div>
                    <div class="form-row">
                        <label for="junior_interest">Interest (%):</label>
                        <input type="number" id="junior_interest" name="junior_interest" value="<?php echo htmlspecialchars($loadedData['junior_interest'] ?? $_POST['junior_interest'] ?? '8'); ?>" step="0.01">
                    </div>
                    <div class="form-row">
                        <label>Monthly Payment:</label>
                        <input type="text" disabled id="junior_monthly_payment" value="">
                    </div>
                    <div class="form-row">
                        <label>5 Years of Interest:</label>
                        <input type="text" disabled id="junior_5yr_interest" value="">
                    </div>
                    <div class="form-row">
                        <label>10 Years of Interest:</label>
                        <input type="text" disabled id="junior_10yr_interest" value="">
                    </div>
                </div>

                <div class="loan-section">
                    <h3>SBA Loan</h3>
                    <div class="form-row">
                        <label>Loan Amount (without fees):</label>
                        <input type="text" disabled id="sba_loan_base" value="">
                    </div>
                    <div class="form-row">
                        <label>Loan Amount (with fees):</label>
                        <input type="text" disabled id="sba_loan_amount" value="">
                    </div>
                    <div class="form-row">
                        <label for="sba_duration">Duration in Months:</label>
                        <input type="number" id="sba_duration" name="sba_duration" value="<?php echo htmlspecialchars($loadedData['sba_duration'] ?? $_POST['sba_duration'] ?? '120'); ?>">
                    </div>
                    <div class="form-row">
                        <label for="sba_interest">Interest (%):</label>
                        <input type="number" id="sba_interest" name="sba_interest" value="<?php echo htmlspecialchars($loadedData['sba_interest'] ?? $_POST['sba_interest'] ?? '10'); ?>" step="0.01">
                    </div>
                    <div class="form-row">
                        <label>Monthly Payment:</label>
                        <input type="text" disabled id="sba_monthly_payment" value="">
                    </div>
                    <div class="form-row">
                        <label>5 Years of Interest:</label>
                        <input type="text" disabled id="sba_5yr_interest" value="">
                    </div>
                    <div class="form-row">
                        <label>10 Years of Interest:</label>
                        <input type="text" disabled id="sba_10yr_interest" value="">
                    </div>
                </div>
            </div>
        </form>
            </div>

            <div class="right-column">
        <?php
        // Always calculate and show results
        if (true) {
            // Get input values
            $business_name = $_POST['business_name'] ?? '';
            $sde = floatval($_POST['sde'] ?? 500000);
            $price = floatval($_POST['price'] ?? 1750000);
            $optional_salary = floatval($_POST['optional_salary'] ?? 125000);
            $extra_costs = floatval($_POST['extra_costs'] ?? 0);
            $capex = floatval($_POST['capex'] ?? 0);
            $consulting_fee = floatval($_POST['consulting_fee'] ?? 0);
            $pct_down_payment = floatval($_POST['pct_down_payment'] ?? 10);
            $pct_seller_carry = floatval($_POST['pct_seller_carry'] ?? 10);
            $pct_junior_debt = floatval($_POST['pct_junior_debt'] ?? 0);
            $loan_fee = floatval($_POST['loan_fee'] ?? 13485);
            $closing_costs = floatval($_POST['closing_costs'] ?? 15000);
            $other_fees = floatval($_POST['other_fees'] ?? 15000);
            $seller_duration = intval($_POST['seller_duration'] ?? 120);
            $seller_interest = floatval($_POST['seller_interest'] ?? 7);
            $junior_duration = intval($_POST['junior_duration'] ?? 120);
            $junior_interest = floatval($_POST['junior_interest'] ?? 8);
            $sba_duration = intval($_POST['sba_duration'] ?? 120);
            $sba_interest = floatval($_POST['sba_interest'] ?? 10);

            // Calculate derived values
            $monthly_sde = $sde / 12;
            $annual_sde = $sde;
            $multiple = $sde > 0 ? $price / $sde : 0;
            $down_payment = $price * ($pct_down_payment / 100);
            $seller_carry = $price * ($pct_seller_carry / 100);
            $junior_debt = $price * ($pct_junior_debt / 100);

            // Calculate loan amount (what's left after down payment, seller carry, and junior debt)
            $loan = $price - $down_payment - $seller_carry - $junior_debt;

            // SBA loan amount includes the loan plus all fees
            $sba_loan_amount = $loan + $loan_fee + $closing_costs + $other_fees;

            // Loan amortization function
            function calculateLoanPayment($principal, $annual_interest_rate, $num_payments) {
                if ($principal <= 0 || $num_payments <= 0) return 0;
                $monthly_rate = ($annual_interest_rate / 100) / 12;
                if ($monthly_rate == 0) return $principal / $num_payments;
                return $principal * ($monthly_rate * pow(1 + $monthly_rate, $num_payments)) / (pow(1 + $monthly_rate, $num_payments) - 1);
            }

            // Calculate interest paid over time
            function calculateInterestPaid($principal, $annual_interest_rate, $num_payments, $months_to_calculate) {
                if ($principal <= 0 || $num_payments <= 0) return 0;
                $monthly_rate = ($annual_interest_rate / 100) / 12;
                $monthly_payment = calculateLoanPayment($principal, $annual_interest_rate, $num_payments);

                $balance = $principal;
                $total_interest = 0;

                for ($i = 0; $i < min($months_to_calculate, $num_payments); $i++) {
                    $interest_payment = $balance * $monthly_rate;
                    $total_interest += $interest_payment;
                    $principal_payment = $monthly_payment - $interest_payment;
                    $balance -= $principal_payment;
                }

                return $total_interest;
            }

            // Calculate remaining balance at a specific month
            function calculateRemainingBalance($principal, $annual_interest_rate, $num_payments, $months_paid) {
                if ($principal <= 0 || $num_payments <= 0) return 0;
                $monthly_rate = ($annual_interest_rate / 100) / 12;
                $monthly_payment = calculateLoanPayment($principal, $annual_interest_rate, $num_payments);

                $balance = $principal;

                for ($i = 0; $i < min($months_paid, $num_payments); $i++) {
                    $interest_payment = $balance * $monthly_rate;
                    $principal_payment = $monthly_payment - $interest_payment;
                    $balance -= $principal_payment;
                }

                return max(0, $balance);
            }

            // Seller loan calculations
            $seller_monthly_payment = calculateLoanPayment($seller_carry, $seller_interest, $seller_duration);
            $seller_annual_payment = $seller_monthly_payment * 12;
            $seller_5yr_interest = calculateInterestPaid($seller_carry, $seller_interest, $seller_duration, 60);
            $seller_10yr_interest = calculateInterestPaid($seller_carry, $seller_interest, $seller_duration, 120);

            // Junior debt loan calculations
            $junior_monthly_payment = calculateLoanPayment($junior_debt, $junior_interest, $junior_duration);
            $junior_annual_payment = $junior_monthly_payment * 12;
            $junior_5yr_interest = calculateInterestPaid($junior_debt, $junior_interest, $junior_duration, 60);
            $junior_10yr_interest = calculateInterestPaid($junior_debt, $junior_interest, $junior_duration, 120);

            // SBA loan calculations
            $sba_monthly_payment = calculateLoanPayment($sba_loan_amount, $sba_interest, $sba_duration);
            $sba_annual_payment = $sba_monthly_payment * 12;
            $sba_5yr_interest = calculateInterestPaid($sba_loan_amount, $sba_interest, $sba_duration, 60);
            $sba_10yr_interest = calculateInterestPaid($sba_loan_amount, $sba_interest, $sba_duration, 120);

            // Calculate balloon payments on seller carry and junior debt loans
            $seller_balloon_5yr = calculateRemainingBalance($seller_carry, $seller_interest, $seller_duration, 60);
            $seller_balloon_10yr = calculateRemainingBalance($seller_carry, $seller_interest, $seller_duration, 120);
            $junior_balloon_5yr = calculateRemainingBalance($junior_debt, $junior_interest, $junior_duration, 60);
            $junior_balloon_10yr = calculateRemainingBalance($junior_debt, $junior_interest, $junior_duration, 120);

            // Total to seller calculations - NOW INCLUDES JUNIOR DEBT
            // At 5 years: Down + Full SBA Loan + Full Junior Debt + Seller Carry payments (5 years) + Seller Carry Balloon + Consulting
            $total_seller_5yr = $down_payment + $loan + $junior_debt + ($seller_monthly_payment * 60) + $seller_balloon_5yr + $consulting_fee;

            // At 10 years: Down + Full SBA Loan + Full Junior Debt + Seller Carry payments (full duration) + Consulting
            $total_seller_10yr = $down_payment + $loan + $junior_debt + ($seller_monthly_payment * min(120, $seller_duration)) + $consulting_fee;

            // Cashflow calculation - includes junior debt
            $monthly_cashflow = ($sde / 12) - $sba_monthly_payment - $seller_monthly_payment - $junior_monthly_payment - ($optional_salary / 12) - ($extra_costs / 12) - ($capex / 12);
            $annual_cashflow = $sde - $sba_annual_payment - $seller_annual_payment - $junior_annual_payment - $optional_salary - $extra_costs - $capex;

            // Calculate DSCR (Debt Service Coverage Ratio) - includes junior debt
            $net_operating_income = $sde - $optional_salary - $extra_costs - $capex;
            $total_debt_service = $sba_annual_payment + $seller_annual_payment + $junior_annual_payment;
            $dscr = $total_debt_service > 0 ? $net_operating_income / $total_debt_service : 0;

            // Determine DSCR status and color
            if ($dscr >= 1.5) {
                $dscr_color = '#2e7d32'; // Strong green
                $dscr_border_color = '#4CAF50';
                $dscr_status = 'Strong';
            } elseif ($dscr >= 1.25) {
                $dscr_color = '#f57c00'; // Orange
                $dscr_border_color = '#FF9800';
                $dscr_status = 'Acceptable';
            } else {
                $dscr_color = '#c62828'; // Red
                $dscr_border_color = '#f44336';
                $dscr_status = 'Weak';
            }

            // Total payments over 5 and 10 years - includes junior debt
            $total_paid_5yr = ($sba_monthly_payment * 60) + ($seller_monthly_payment * 60) + ($junior_monthly_payment * 60) + $down_payment + ($extra_costs * 5) + $consulting_fee + $seller_balloon_5yr + $junior_balloon_5yr;
            $total_paid_10yr = ($sba_monthly_payment * 120) + ($seller_monthly_payment * 120) + ($junior_monthly_payment * 120) + $down_payment + ($extra_costs * 10) + $consulting_fee;

            // Validation - includes junior debt
            $total_check = $loan + $seller_carry + $junior_debt + $down_payment;
            $validation_pass = abs($total_check - $price) < 0.01;

            ?>

            <div class="results-section">
                <h2>Buyer Cashflow Analysis</h2>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div id="monthly_cashflow_container" style="background-color: white; padding: 12px; border-radius: 4px; border-left: 3px solid <?php echo $monthly_cashflow >= 0 ? '#2196F3' : '#f44336'; ?>;">
                        <div style="font-size: 10px; color: #666; margin-bottom: 4px;">MONTHLY CASHFLOW</div>
                        <div id="monthly_cashflow_value" style="font-size: 20px; font-weight: bold; color: <?php echo $monthly_cashflow >= 0 ? '#1565c0' : '#c62828'; ?>;">
                            $<?php echo number_format($monthly_cashflow, 0); ?>
                        </div>
                        <div id="monthly_cashflow_details" style="font-size: 9px; color: #888; margin-top: 6px;">
                            SDE: $<?php echo number_format($sde / 12, 0); ?><br>
                            SBA Payment: -$<?php echo number_format($sba_monthly_payment, 0); ?><br>
                            Junior Debt Payment: -$<?php echo number_format($junior_monthly_payment, 0); ?><br>
                            Seller Payment: -$<?php echo number_format($seller_monthly_payment, 0); ?><br>
                            Salary: -$<?php echo number_format($optional_salary / 12, 0); ?><br>
                            Extra Costs: -$<?php echo number_format($extra_costs / 12, 0); ?><br>
                            Capex: -$<?php echo number_format($capex / 12, 0); ?>
                        </div>
                    </div>

                    <div style="background-color: white; padding: 12px; border-radius: 4px; border-left: 3px solid <?php echo $annual_cashflow >= 0 ? '#2196F3' : '#f44336'; ?>;">
                        <div style="display: grid; grid-template-columns: auto auto; gap: 0px; justify-content: start;">
                            <div style="padding-right: 15px;">
                                <div style="font-size: 10px; color: #666; margin-bottom: 4px;">ANNUAL CASHFLOW</div>
                                <div style="font-size: 20px; font-weight: bold; color: <?php echo $annual_cashflow >= 0 ? '#1565c0' : '#c62828'; ?>;">
                                    $<?php echo number_format($annual_cashflow, 0); ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 10px; color: #666; margin-bottom: 4px;">+SALARY</div>
                                <div style="font-size: 20px; font-weight: bold; color: <?php echo $annual_cashflow >= 0 ? '#1565c0' : '#c62828'; ?>;">
                                    $<?php echo number_format($annual_cashflow + $optional_salary, 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="background-color: white; padding: 12px; border-radius: 4px; border-left: 3px solid <?php echo $dscr_border_color; ?>;">
                        <div style="font-size: 10px; color: #666; margin-bottom: 4px;">DSCR (Debt Coverage)</div>
                        <div style="font-size: 20px; font-weight: bold; color: <?php echo $dscr_color; ?>;">
                            <?php echo number_format($dscr, 2); ?>
                        </div>
                        <div style="font-size: 9px; color: #888; margin-top: 4px;">
                            <?php echo $dscr_status; ?> | Min: 1.25
                        </div>
                    </div>
                </div>

                <h2 style="margin-top: 20px;">Price Breakdown</h2>

                <div style="background-color: white; padding: 12px; border-radius: 4px; border-left: 3px solid #2196F3; margin-bottom: 20px;">
                    <div style="font-size: 10px; color: #666; margin-bottom: 4px;">TOTAL PRICE</div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 20px; font-weight: bold; color: #2c3e50;">
                            $<?php echo number_format($price, 0); ?>
                        </span>
                        <span style="font-size: 14px; color: #666; font-weight: bold;">100%</span>
                    </div>

                    <div style="border-top: 1px solid #e0e0e0; padding-top: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 11px; color: #555; font-weight: 600;">Down:</span>
                            <span id="price_breakdown_down" style="font-size: 13px; font-weight: bold; color: #2c3e50;">
                                $<?php echo number_format($down_payment, 0); ?>
                                <span style="font-size: 10px; color: #888; margin-left: 8px;"><?php echo number_format($pct_down_payment, 1); ?>%</span>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 11px; color: #555; font-weight: 600;">SBA Loan:</span>
                            <span id="price_breakdown_sba" style="font-size: 13px; font-weight: bold; color: #2c3e50;">
                                $<?php echo number_format($loan, 0); ?>
                                <span style="font-size: 10px; color: #888; margin-left: 8px;"><?php echo number_format(($loan / $price) * 100, 1); ?>%</span>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 11px; color: #555; font-weight: 600;">Junior Debt:</span>
                            <span id="price_breakdown_junior" style="font-size: 13px; font-weight: bold; color: #2c3e50;">
                                $<?php echo number_format($junior_debt, 0); ?>
                                <span style="font-size: 10px; color: #888; margin-left: 8px;"><?php echo number_format($pct_junior_debt, 1); ?>%</span>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 11px; color: #555; font-weight: 600;">Seller Carry:</span>
                            <span id="price_breakdown_seller" style="font-size: 13px; font-weight: bold; color: #2c3e50;">
                                $<?php echo number_format($seller_carry, 0); ?>
                                <span style="font-size: 10px; color: #888; margin-left: 8px;"><?php echo number_format($pct_seller_carry, 1); ?>%</span>
                            </span>
                        </div>
                    </div>
                </div>

                <h2 style="margin-top: 20px;">Payment to Seller</h2>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="background-color: white; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
                        <div style="font-size: 10px; color: #666; margin-bottom: 4px;">TOTAL TO SELLER (5 YEARS with balloon)</div>
                        <div id="total_seller_5yr_value" style="font-size: 18px; font-weight: bold; color: #5d4037;">
                            $<?php echo number_format($total_seller_5yr, 0); ?>
                        </div>
                        <div id="total_seller_5yr_details" style="font-size: 9px; color: #888; margin-top: 6px;">
                            Down: $<?php echo number_format($down_payment, 0); ?><br>
                            SBA Loan (Full): $<?php echo number_format($loan, 0); ?><br>
                            Junior Debt (Full): $<?php echo number_format($junior_debt, 0); ?><br>
                            Seller Carry Principal: $<?php echo number_format(($seller_monthly_payment * 60) - $seller_5yr_interest, 0); ?><br>
                            Seller Carry Interest: $<?php echo number_format($seller_5yr_interest, 0); ?><br>
                            Seller Carry Balloon: $<?php echo number_format($seller_balloon_5yr, 0); ?><br>
                            Consulting: $<?php echo number_format($consulting_fee, 0); ?>
                        </div>
                    </div>

                    <div style="background-color: white; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
                        <div style="font-size: 10px; color: #666; margin-bottom: 4px;">TOTAL TO SELLER (10 YEARS)</div>
                        <div id="total_seller_10yr_value" style="font-size: 18px; font-weight: bold; color: #5d4037;">
                            $<?php echo number_format($total_seller_10yr, 0); ?>
                        </div>
                        <div id="total_seller_10yr_details" style="font-size: 9px; color: #888; margin-top: 6px;">
                            Down: $<?php echo number_format($down_payment, 0); ?><br>
                            SBA Loan (Full): $<?php echo number_format($loan, 0); ?><br>
                            Junior Debt (Full): $<?php echo number_format($junior_debt, 0); ?><br>
                            Seller Carry Principal: $<?php echo number_format(($seller_monthly_payment * min(120, $seller_duration)) - $seller_10yr_interest, 0); ?><br>
                            Seller Carry Interest: $<?php echo number_format($seller_10yr_interest, 0); ?><br>
                            Consulting: $<?php echo number_format($consulting_fee, 0); ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Update the displayed calculated values
                document.getElementById('multiple').value = '<?php echo number_format($multiple, 2); ?>';
                document.getElementById('down_payment').value = '$<?php echo number_format($down_payment, 2); ?>';
                document.getElementById('seller_carry').value = '$<?php echo number_format($seller_carry, 2); ?>';
                document.getElementById('junior_debt').value = '$<?php echo number_format($junior_debt, 2); ?>';
                document.getElementById('seller_loan_amount').value = '$<?php echo number_format($seller_carry, 2); ?>';
                document.getElementById('junior_loan_amount').value = '$<?php echo number_format($junior_debt, 2); ?>';
                document.getElementById('sba_loan_base').value = '$<?php echo number_format($loan, 2); ?>';
                document.getElementById('sba_loan_amount').value = '$<?php echo number_format($sba_loan_amount, 2); ?>';
                document.getElementById('seller_monthly_payment').value = '$<?php echo number_format($seller_monthly_payment, 2); ?>';
                document.getElementById('seller_5yr_interest').value = '$<?php echo number_format($seller_5yr_interest, 2); ?>';
                document.getElementById('seller_10yr_interest').value = '$<?php echo number_format($seller_10yr_interest, 2); ?>';
                document.getElementById('junior_monthly_payment').value = '$<?php echo number_format($junior_monthly_payment, 2); ?>';
                document.getElementById('junior_5yr_interest').value = '$<?php echo number_format($junior_5yr_interest, 2); ?>';
                document.getElementById('junior_10yr_interest').value = '$<?php echo number_format($junior_10yr_interest, 2); ?>';
                document.getElementById('sba_monthly_payment').value = '$<?php echo number_format($sba_monthly_payment, 2); ?>';
                document.getElementById('sba_5yr_interest').value = '$<?php echo number_format($sba_5yr_interest, 2); ?>';
                document.getElementById('sba_10yr_interest').value = '$<?php echo number_format($sba_10yr_interest, 2); ?>';

                document.getElementById('validation').textContent = 'Validation: Loan + Seller Carry + Junior Debt + Down Payment = $<?php echo number_format($total_check, 2); ?> <?php echo $validation_pass ? '✓ Equals Price' : '✗ Does NOT equal Price ($' . number_format($price, 2) . ')'; ?>';
                document.getElementById('validation').className = 'validation-check <?php echo $validation_pass ? 'validation-pass' : 'validation-fail'; ?>';
            </script>
            <?php
        }
        ?>
            </div>
        </div>
    </div>
</body>
</html>