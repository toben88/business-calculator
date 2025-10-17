<?php
require_once 'Database.php';

$db = new Database();
$businesses = $db->getAllBusinesses();

echo "=== SQLite Database Verification ===\n\n";
echo "Total businesses: " . count($businesses) . "\n\n";

foreach ($businesses as $business) {
    echo "Business #" . $business['id'] . "\n";
    echo "  Name: " . $business['business_name'] . "\n";
    echo "  Price: $" . number_format($business['price']) . "\n";
    echo "  SDE: $" . number_format($business['sde']) . "\n";
    echo "  Down Payment %: " . $business['pct_down_payment'] . "%\n";
    echo "  Seller Carry %: " . $business['pct_seller_carry'] . "%\n";
    echo "  SBA Interest: " . $business['sba_interest'] . "%\n";
    echo "  Created: " . $business['created_date'] . "\n";
    echo "  Modified: " . $business['modified_date'] . "\n";
    echo "\n";
}

$stats = $db->getStats();
echo "Database Stats:\n";
echo "  Total Records: " . $stats['total_businesses'] . "\n";
echo "  Database Size: " . number_format($stats['database_size']) . " bytes\n";
