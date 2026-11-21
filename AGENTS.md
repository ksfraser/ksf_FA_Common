# AGENTS.md — KSF FrontAccounting Architecture Notes

## ksf_FA_StockReservations — Development Tree Status

**Repository**: ~/Documents/ksf_FA_StockReservations/
**Deployed**: ksf_Infrastructure/fa_modules/ksf_FA_StockReservations/

## Module Overview

Stock Reservations module for FrontAccounting. Manages stock reservations and reservations linked to stock items.

## Architecture

- **Tables**: `0_ksf_stock_reservations`, `0_ksf_stock_reservations_items` (verify actual table names in sql/)
- **Security Areas**: Check hooks.php for SA_* constants
- **Security Area**: SS value to be determined

## Source Files

- `src/Ksfraser/FrontAccounting/FA/StockReservations/` — App, Controller, DTO, Repository, Service
- `pages/` — Page controllers
- `sql/*.sql` — Schema files
- `tests/Unit/` — Unit tests

## TODO

- `src/Ksfraser/FrontAccounting/FA/StockReservations/hooks.php:288` - TODO: Implement actual reservation logic
- `src/Ksfraser/FrontAccounting/FA/StockReservations/hooks.php:301` - TODO: Implement actual release logic
- `src/Ksfraser/FrontAccounting/FA/StockReservations/hooks.php:313` - TODO: Implement actual release logic
- `src/Ksfraser/FrontAccounting/FA/StockReservations/hooks.php:325` - TODO: Implement actual qty check
- Document business rules for stock reservations
- Document state transitions for reservation lifecycle