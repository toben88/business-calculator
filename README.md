# Business Valuation Calculator

A comprehensive web-based tool for calculating business valuations, loan structures, and cash flow analysis for business acquisitions.

Calculates the cashflow of a business being purchased using multiple financing sources including SBA loans, junior debt, and seller carry financing with balloon payment options.

## Features

- **Business Valuation Analysis**
  - SDE (Seller's Discretionary Earnings) calculations
  - Price multiple analysis
  - Cash flow projections with detailed monthly and annual breakdowns

- **Loan Structure Management**
  - SBA loan calculations with customizable terms
  - Junior debt financing support
  - Seller financing options with balloon payment support
  - Down payment scenarios
  - Real-time AJAX-based calculation updates

- **Financial Metrics**
  - Monthly and annual cash flow projections with detailed breakdowns
  - DSCR (Debt Service Coverage Ratio) calculation with color-coded indicators (includes all loan types)
  - ROI analysis
  - Payment to seller calculations (5-year and 10-year scenarios)
  - Price breakdown showing all financing components

- **Modern UI (v1.24)**
  - Clean, modern light theme design
  - Fully responsive mobile layout (optimized for phones and tablets)
  - Mobile-optimized spacing and typography (13px margins on mobile vs 25px desktop)
  - Consistent shadow styling and visual hierarchy
  - Side-by-side annual cashflow display
  - Three-column loan section layout (desktop) / stacked (mobile)
  - Auto-calculated field styling with light blue background and AUTO badges
  - Dynamic validation display with real-time feedback
  - Content Security Policy configured for Google Fonts
  - DSCR calculation breakdown display
  - Sources and Uses analysis with real-time updates

- **Data Management**
  - SQLite database for persistent storage
  - Save and compare multiple business scenarios
  - Web-based data viewer (viewdata.php)
  - CSRF protection and secure sessions

## Requirements

- PHP 7.4 or higher
- SQLite3 support (PDO_SQLite extension)
- Web server (Apache, Nginx, or PHP built-in server)

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/toben88/business-calculator.git
   cd business-calculator
   ```

2. Ensure PHP SQLite extension is enabled (usually enabled by default)

3. Start a web server:
   ```bash
   php -S localhost:8000
   ```

4. Open your browser to `http://localhost:8000`

## Usage

### Main Calculator (index.php)
- Enter business details (name, price, SDE, etc.)
- Configure loan terms (down payment, SBA loan, seller financing)
- View real-time calculations including monthly payments and DSCR
- Save business scenarios to the database

### Data Viewer (viewdata.php)
- Browse all saved business records
- Toggle between card view and table view
- View statistics and database information

### Verification Tool (verify_data.php)
- Command-line tool to verify database contents
- Run: `php verify_data.php`

## Database

The application uses SQLite for data storage. The database file is located at:
```
data/businesses.db
```

Includes starter data with the "Crowl Mechanical" example for demonstration.

## Project Structure

```
.
├── index.php              # Main calculator application
├── viewdata.php           # Data viewer interface
├── Database.php           # Database helper class
├── schema.sql             # Database schema definition
├── verify_data.php        # CLI verification tool
├── migrate_to_sqlite.php  # Migration script (if upgrading from JSON)
├── data/
│   ├── businesses.db      # SQLite database
│   └── .htaccess          # Protect database from web access
└── LICENSE                # CC BY-NC-SA 4.0
```

## License

This project is licensed under the Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International License.

**For personal and non-commercial use only.** For commercial licensing inquiries, please contact the project owner.

See [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please note that any contributions will be subject to the same CC BY-NC-SA 4.0 license.

## DSCR Color Coding

- 🟢 Green: DSCR ≥ 1.50 (Excellent)
- 🟠 Orange: DSCR ≥ 1.25 (Acceptable)
- 🔴 Red: DSCR < 1.25 (Risky)

## Support

For questions or issues, please open an issue on GitHub.
