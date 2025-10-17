<?php
/**
 * Data Viewer - Browse SQLite database records
 * Simple web interface to view all business data
 */

require_once __DIR__ . '/Database.php';

$db = new Database();
$businesses = $db->getAllBusinesses();
$stats = $db->getStats();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Viewer - Business Valuation Calculator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            font-size: 14px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .stats {
            background-color: #e3f2fd;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 30px;
            border-left: 4px solid #2196F3;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
        }

        .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }

        .actions {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #2196F3;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0b7dda;
        }

        .btn-secondary {
            background-color: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #7f8c8d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .business-card {
            background-color: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: box-shadow 0.2s;
        }

        .business-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .business-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }

        .business-name {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
        }

        .business-id {
            font-size: 12px;
            color: #95a5a6;
            background-color: #ecf0f1;
            padding: 4px 10px;
            border-radius: 12px;
        }

        .business-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .field-group {
            background-color: #fafafa;
            padding: 12px;
            border-radius: 5px;
        }

        .field-label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .field-value {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }

        .money {
            color: #27ae60;
        }

        .percentage {
            color: #e74c3c;
        }

        .date {
            font-size: 12px;
            color: #95a5a6;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }

        .toggle-view {
            margin-bottom: 20px;
            display: flex;
            gap: 5px;
            background-color: #ecf0f1;
            padding: 5px;
            border-radius: 6px;
            width: fit-content;
        }

        .toggle-btn {
            padding: 8px 16px;
            border: none;
            background-color: transparent;
            cursor: pointer;
            border-radius: 4px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .toggle-btn.active {
            background-color: white;
            color: #2196F3;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .view-section {
            display: none;
        }

        .view-section.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Database Viewer</h1>
        <p class="subtitle">Browse all business valuation records</p>

        <div class="stats">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-label">Total Records</div>
                    <div class="stat-value"><?php echo $stats['total_businesses']; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Database Size</div>
                    <div class="stat-value"><?php echo number_format($stats['database_size'] / 1024, 1); ?> KB</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Database Type</div>
                    <div class="stat-value">SQLite</div>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="index.php" class="btn btn-primary">← Back to Calculator</a>
            <button onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
        </div>

        <div class="toggle-view">
            <button class="toggle-btn active" onclick="switchView('cards')">Card View</button>
            <button class="toggle-btn" onclick="switchView('table')">Table View</button>
        </div>

        <?php if (empty($businesses)): ?>
            <div class="no-data">
                <h2>No businesses found</h2>
                <p>Create your first business record in the calculator.</p>
            </div>
        <?php else: ?>

            <!-- Card View -->
            <div id="cards-view" class="view-section active">
                <?php foreach ($businesses as $business): ?>
                    <div class="business-card">
                        <div class="business-header">
                            <div class="business-name"><?php echo htmlspecialchars($business['business_name']); ?></div>
                            <div class="business-id">ID: <?php echo $business['id']; ?></div>
                        </div>

                        <div class="business-grid">
                            <div class="field-group">
                                <div class="field-label">Price</div>
                                <div class="field-value money">$<?php echo number_format($business['price']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">SDE</div>
                                <div class="field-value money">$<?php echo number_format($business['sde']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Multiple</div>
                                <div class="field-value"><?php echo number_format($business['price'] / $business['sde'], 2); ?>x</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Optional Salary</div>
                                <div class="field-value">$<?php echo number_format($business['optional_salary']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Down Payment</div>
                                <div class="field-value percentage"><?php echo $business['pct_down_payment']; ?>%</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Seller Carry</div>
                                <div class="field-value percentage"><?php echo $business['pct_seller_carry']; ?>%</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">SBA Interest</div>
                                <div class="field-value"><?php echo $business['sba_interest']; ?>%</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">SBA Duration</div>
                                <div class="field-value"><?php echo $business['sba_duration']; ?> months</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Seller Interest</div>
                                <div class="field-value"><?php echo $business['seller_interest']; ?>%</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Seller Duration</div>
                                <div class="field-value"><?php echo $business['seller_duration']; ?> months</div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Extra Costs</div>
                                <div class="field-value">$<?php echo number_format((float)$business['extra_costs']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">CapEx</div>
                                <div class="field-value">$<?php echo number_format((float)$business['capex']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Consulting Fee</div>
                                <div class="field-value">$<?php echo number_format((float)$business['consulting_fee']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Loan Fee</div>
                                <div class="field-value">$<?php echo number_format((float)$business['loan_fee']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Closing Costs</div>
                                <div class="field-value">$<?php echo number_format((float)$business['closing_costs']); ?></div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Other Fees</div>
                                <div class="field-value">$<?php echo number_format((float)$business['other_fees']); ?></div>
                            </div>
                        </div>

                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between;">
                            <span class="date">Created: <?php echo $business['created_date']; ?></span>
                            <span class="date">Modified: <?php echo $business['modified_date']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Table View -->
            <div id="table-view" class="view-section">
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Business Name</th>
                                <th>Price</th>
                                <th>SDE</th>
                                <th>Multiple</th>
                                <th>Down %</th>
                                <th>Seller %</th>
                                <th>SBA Rate</th>
                                <th>Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($businesses as $business): ?>
                                <tr>
                                    <td><?php echo $business['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($business['business_name']); ?></strong></td>
                                    <td>$<?php echo number_format($business['price']); ?></td>
                                    <td>$<?php echo number_format($business['sde']); ?></td>
                                    <td><?php echo number_format($business['price'] / $business['sde'], 2); ?>x</td>
                                    <td><?php echo $business['pct_down_payment']; ?>%</td>
                                    <td><?php echo $business['pct_seller_carry']; ?>%</td>
                                    <td><?php echo $business['sba_interest']; ?>%</td>
                                    <td class="date"><?php echo date('M d, Y', strtotime($business['modified_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        function switchView(view) {
            // Hide all views
            document.querySelectorAll('.view-section').forEach(section => {
                section.classList.remove('active');
            });

            // Remove active from all buttons
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected view
            document.getElementById(view + '-view').classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
