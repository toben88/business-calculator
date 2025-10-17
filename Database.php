<?php
/**
 * Database Helper Class
 * Manages SQLite database connection and operations for Business Valuation Calculator
 */

class Database {
    private $pdo;
    private $dbFile;

    /**
     * Constructor - Initialize database connection
     * @param string $dbFile Path to SQLite database file
     */
    public function __construct($dbFile = null) {
        $this->dbFile = $dbFile ?? __DIR__ . '/data/businesses.db';
        $this->connect();
        $this->initializeSchema();
    }

    /**
     * Establish PDO connection to SQLite database
     */
    private function connect() {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbFile);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize database schema if tables don't exist
     */
    private function initializeSchema() {
        $schemaFile = __DIR__ . '/schema.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            $this->pdo->exec($schema);
        }
    }

    /**
     * Get all businesses ordered by modified date (most recent first)
     * @return array Array of business records
     */
    public function getAllBusinesses() {
        $stmt = $this->pdo->query('SELECT * FROM businesses ORDER BY modified_date DESC');
        return $stmt->fetchAll();
    }

    /**
     * Get a single business by ID
     * @param int $id Business ID
     * @return array|null Business record or null if not found
     */
    public function getBusinessById($id) {
        $stmt = $this->pdo->prepare('SELECT * FROM businesses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create a new business record
     * @param array $data Business data
     * @return int ID of newly created business
     */
    public function createBusiness($data) {
        $sql = "INSERT INTO businesses (
            business_name, sde, price, optional_salary, extra_costs, capex, consulting_fee,
            pct_down_payment, pct_seller_carry, loan_fee, closing_costs, other_fees,
            seller_duration, seller_interest, sba_duration, sba_interest
        ) VALUES (
            :business_name, :sde, :price, :optional_salary, :extra_costs, :capex, :consulting_fee,
            :pct_down_payment, :pct_seller_carry, :loan_fee, :closing_costs, :other_fees,
            :seller_duration, :seller_interest, :sba_duration, :sba_interest
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':business_name' => $data['business_name'],
            ':sde' => $data['sde'] ?? 500000,
            ':price' => $data['price'] ?? 1750000,
            ':optional_salary' => $data['optional_salary'] ?? 125000,
            ':extra_costs' => $data['extra_costs'] ?? 0,
            ':capex' => $data['capex'] ?? 0,
            ':consulting_fee' => $data['consulting_fee'] ?? 0,
            ':pct_down_payment' => $data['pct_down_payment'] ?? 10,
            ':pct_seller_carry' => $data['pct_seller_carry'] ?? 10,
            ':loan_fee' => $data['loan_fee'] ?? 13485,
            ':closing_costs' => $data['closing_costs'] ?? 15000,
            ':other_fees' => $data['other_fees'] ?? 15000,
            ':seller_duration' => $data['seller_duration'] ?? 120,
            ':seller_interest' => $data['seller_interest'] ?? 7,
            ':sba_duration' => $data['sba_duration'] ?? 120,
            ':sba_interest' => $data['sba_interest'] ?? 10
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update an existing business record
     * @param int $id Business ID
     * @param array $data Updated business data
     * @return bool Success status
     */
    public function updateBusiness($id, $data) {
        $sql = "UPDATE businesses SET
            business_name = :business_name,
            sde = :sde,
            price = :price,
            optional_salary = :optional_salary,
            extra_costs = :extra_costs,
            capex = :capex,
            consulting_fee = :consulting_fee,
            pct_down_payment = :pct_down_payment,
            pct_seller_carry = :pct_seller_carry,
            loan_fee = :loan_fee,
            closing_costs = :closing_costs,
            other_fees = :other_fees,
            seller_duration = :seller_duration,
            seller_interest = :seller_interest,
            sba_duration = :sba_duration,
            sba_interest = :sba_interest,
            modified_date = CURRENT_TIMESTAMP
        WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':business_name' => $data['business_name'],
            ':sde' => $data['sde'] ?? 500000,
            ':price' => $data['price'] ?? 1750000,
            ':optional_salary' => $data['optional_salary'] ?? 125000,
            ':extra_costs' => $data['extra_costs'] ?? 0,
            ':capex' => $data['capex'] ?? 0,
            ':consulting_fee' => $data['consulting_fee'] ?? 0,
            ':pct_down_payment' => $data['pct_down_payment'] ?? 10,
            ':pct_seller_carry' => $data['pct_seller_carry'] ?? 10,
            ':loan_fee' => $data['loan_fee'] ?? 13485,
            ':closing_costs' => $data['closing_costs'] ?? 15000,
            ':other_fees' => $data['other_fees'] ?? 15000,
            ':seller_duration' => $data['seller_duration'] ?? 120,
            ':seller_interest' => $data['seller_interest'] ?? 7,
            ':sba_duration' => $data['sba_duration'] ?? 120,
            ':sba_interest' => $data['sba_interest'] ?? 10
        ]);
    }

    /**
     * Delete a business record
     * @param int $id Business ID
     * @return bool Success status
     */
    public function deleteBusiness($id) {
        $stmt = $this->pdo->prepare('DELETE FROM businesses WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /**
     * Get database statistics
     * @return array Statistics about the database
     */
    public function getStats() {
        $stmt = $this->pdo->query('SELECT COUNT(*) as total FROM businesses');
        $result = $stmt->fetch();

        return [
            'total_businesses' => $result['total'],
            'database_size' => file_exists($this->dbFile) ? filesize($this->dbFile) : 0
        ];
    }

    /**
     * Get PDO instance for advanced queries
     * @return PDO
     */
    public function getPdo() {
        return $this->pdo;
    }
}
