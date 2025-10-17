-- Business Valuation Calculator Database Schema
-- SQLite Database for storing business analysis records

CREATE TABLE IF NOT EXISTS businesses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    business_name TEXT NOT NULL,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    modified_date DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Financial Data
    sde REAL DEFAULT 500000,
    price REAL DEFAULT 1750000,
    optional_salary REAL DEFAULT 125000,
    extra_costs REAL DEFAULT 0,
    capex REAL DEFAULT 0,
    consulting_fee REAL DEFAULT 0,

    -- Financing Structure
    pct_down_payment REAL DEFAULT 10,
    pct_seller_carry REAL DEFAULT 10,
    loan_fee REAL DEFAULT 13485,
    closing_costs REAL DEFAULT 15000,
    other_fees REAL DEFAULT 15000,

    -- Loan Terms
    seller_duration INTEGER DEFAULT 120,
    seller_interest REAL DEFAULT 7,
    sba_duration INTEGER DEFAULT 120,
    sba_interest REAL DEFAULT 10
);

-- Index for faster lookups by business name
CREATE INDEX IF NOT EXISTS idx_business_name ON businesses(business_name);

-- Index for sorting by date
CREATE INDEX IF NOT EXISTS idx_modified_date ON businesses(modified_date DESC);
