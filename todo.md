# TODO

## Now
- [ ] Add a balloon option after "New Owner Salary" in `index.php`:
  - [ ] Toggle to enable balloon.
  - [ ] Default to 5 years when enabled.
  - [ ] Allow range: 1 year up to (SBA loan years - 1).
  - [ ] Balloon input should validate against current SBA loan term.

## Completed in v1.23
- [x] Auto-calculated field styling system (reusable CSS classes)
- [x] Applied auto-calc styling to all readonly fields (Multiple, Down Payment, Seller Carry, Junior Debt, SBA fields)
- [x] Light blue tinted background with AUTO badges for calculated fields
- [x] Dynamic validation display with real-time color-coded feedback
- [x] Validation shows actual values and difference when totals don't match price
- [x] Fixed Content Security Policy to allow Google Fonts
- [x] Removed duplicate footer section
- [x] Updated version to v1.23

## Completed in v1.21
- [x] Renamed `new.php` to `index.php` (modern UI is now production)
- [x] Renamed old `index.php` to `index.v117.php` (backup)
- [x] Fixed record loading - buttons and record selector work correctly
- [x] Fixed side margins to match visual appearance across devices
- [x] Modern light theme design with responsive layout
- [x] Mobile-optimized spacing and typography (reduced margins for phones: 13px vs 25px desktop)
- [x] Three-column loan section layout (desktop) / stacked (mobile)
- [x] Side-by-side annual cashflow display
- [x] Consistent shadow styling throughout
- [x] Real-time AJAX-based calculations
- [x] CSRF protection and secure sessions
- [x] Percentage fields support 2 decimal places
- [x] Section reordering (Payment to Seller before Price Breakdown)
- [x] Loan sections ordered as SBA → Seller → Junior
- [x] Consolidated duplicate SBA Loan sections

## Notes
- `index.php` now uses modern UI (v1.23) with full mobile responsiveness
- `index.v117.php` is available as backup of previous version
- Mobile breakpoint set at 900px for optimal phone/tablet detection
- Auto-calculated fields use `.auto-calc-wrapper`, `.auto-calc-input`, `.auto-calc-badge` CSS classes
- Validation display updates in real-time with color-coded success/error states
