<?php
/**
 * Migration Script: JSON to SQLite
 * Converts existing businesses.json data to SQLite database
 *
 * Run this script ONCE to migrate your data:
 * php migrate_to_sqlite.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/Database.php';

$jsonFile = __DIR__ . '/data/businesses.json';
$backupFile = __DIR__ . '/data/businesses.json.backup';

echo "=== Business Valuation Calculator - JSON to SQLite Migration ===\n\n";

// Step 1: Check if JSON file exists
if (!file_exists($jsonFile)) {
    die("Error: JSON file not found at: $jsonFile\n");
}

// Step 2: Backup existing JSON file
echo "Step 1: Creating backup of JSON file...\n";
if (copy($jsonFile, $backupFile)) {
    echo "✓ Backup created: $backupFile\n\n";
} else {
    die("Error: Could not create backup file\n");
}

// Step 3: Load JSON data
echo "Step 2: Loading JSON data...\n";
$jsonContent = file_get_contents($jsonFile);
$jsonData = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON file - " . json_last_error_msg() . "\n");
}

$businesses = $jsonData['businesses'] ?? [];
echo "✓ Found " . count($businesses) . " business record(s)\n\n";

// Step 4: Initialize SQLite database
echo "Step 3: Initializing SQLite database...\n";
$db = new Database();
echo "✓ Database initialized\n\n";

// Step 5: Migrate data
echo "Step 4: Migrating businesses to SQLite...\n";
$migrated = 0;
$errors = 0;

foreach ($businesses as $business) {
    try {
        // Extract data fields
        $data = $business['data'] ?? [];

        // Create business record
        $newId = $db->createBusiness([
            'business_name' => $data['business_name'] ?? $business['business_name'] ?? 'Untitled Business',
            'sde' => $data['sde'] ?? 500000,
            'price' => $data['price'] ?? 1750000,
            'optional_salary' => $data['optional_salary'] ?? 125000,
            'extra_costs' => $data['extra_costs'] ?? 0,
            'capex' => $data['capex'] ?? 0,
            'consulting_fee' => $data['consulting_fee'] ?? 0,
            'pct_down_payment' => $data['pct_down_payment'] ?? 10,
            'pct_seller_carry' => $data['pct_seller_carry'] ?? 10,
            'loan_fee' => $data['loan_fee'] ?? 13485,
            'closing_costs' => $data['closing_costs'] ?? 15000,
            'other_fees' => $data['other_fees'] ?? 15000,
            'seller_duration' => $data['seller_duration'] ?? 120,
            'seller_interest' => $data['seller_interest'] ?? 7,
            'sba_duration' => $data['sba_duration'] ?? 120,
            'sba_interest' => $data['sba_interest'] ?? 10
        ]);

        // Update timestamps to match original
        if (isset($business['created_date']) || isset($business['modified_date'])) {
            $pdo = $db->getPdo();
            $stmt = $pdo->prepare('UPDATE businesses SET created_date = ?, modified_date = ? WHERE id = ?');
            $stmt->execute([
                $business['created_date'] ?? date('Y-m-d H:i:s'),
                $business['modified_date'] ?? date('Y-m-d H:i:s'),
                $newId
            ]);
        }

        $businessName = $data['business_name'] ?? $business['business_name'] ?? 'Untitled';
        echo "  ✓ Migrated: $businessName (ID: {$business['id']} → $newId)\n";
        $migrated++;

    } catch (Exception $e) {
        echo "  ✗ Error migrating business ID {$business['id']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n";

// Step 6: Verify migration
echo "Step 5: Verifying migration...\n";
$allBusinesses = $db->getAllBusinesses();
echo "✓ SQLite database contains " . count($allBusinesses) . " record(s)\n\n";

// Step 7: Display statistics
$stats = $db->getStats();
echo "=== Migration Summary ===\n";
echo "Successfully migrated: $migrated record(s)\n";
echo "Errors: $errors\n";
echo "Database size: " . number_format($stats['database_size']) . " bytes\n";
echo "Backup location: $backupFile\n\n";

if ($migrated > 0 && $errors === 0) {
    echo "✓ Migration completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Test the application to ensure everything works\n";
    echo "2. If successful, the old JSON file is backed up and can be removed\n";
    echo "3. The application will now use SQLite (data/businesses.db)\n";
} else {
    echo "⚠ Migration completed with errors. Please review the output above.\n";
}

echo "\n";
