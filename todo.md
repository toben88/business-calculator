# TODO

## Now
- [ ] Add a balloon option after "New Owner Salary" in `index.php`:
  - [ ] Toggle to enable balloon.
  - [ ] Default to 5 years when enabled.
  - [ ] Allow range: 1 year up to (SBA loan years - 1).
  - [ ] Balloon input should validate against current SBA loan term.

## Completed in v1.21
- [x] Renamed `new.php` to `index.php` (modern UI is now production)
- [x] Renamed old `index.php` to `index.v117.php` (backup)
- [x] Fixed record loading - buttons and record selector work correctly
- [x] Fixed side margins to match visual appearance across devices
- [x] Modern light theme design with responsive layout
- [x] Mobile-optimized spacing and typography (reduced margins for phones)
- [x] Three-column loan section layout (desktop) / stacked (mobile)
- [x] Side-by-side annual cashflow display
- [x] Consistent shadow styling throughout
- [x] Real-time AJAX-based calculations
- [x] CSRF protection and secure sessions

## Notes
- `index.php` now uses modern UI (v1.21) with full mobile responsiveness
- `index.v117.php` is available as backup of previous version
- Mobile breakpoint set at 900px for optimal phone/tablet detection
