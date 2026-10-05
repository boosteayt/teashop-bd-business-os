# Tea Shop BD Business OS — Architecture Baseline

## Control model
- Founder / Owner / CEO: full system, sourcing/manufacturing confidentiality, pricing policy, finance, permissions and overrides.
- Franchise & Retail Operations: network operations, outlet sales, stock follow-up, settlement follow-up, territory and performance. No ownership/equity and no manufacturing/sourcing entitlement.
- Finance & Accounts: settlement, ledger, P&L, VAT/tax provision and approved payable workflows.
- Warehouse & Inventory: raw tea, packaging, finished goods, transfers and reconciliation.
- Production & Blending: blend recipes/batches, input/output and yield.
- Quality Control: incoming/blend/finished QC and wastage review.
- Packaging: material/BOM/packing jobs and finished SKU creation.
- Regional Operations: assigned outlet operations.
- Franchise Owner / Outlet Manager: own authorized outlet data.
- POS / Cashier: retail sale workflow.
- Auditor: read-only control/report/audit access.

## Primary domain object: Tea
The `Tea` workspace is the master lifecycle view for the 99 Tea Shop BD tea names.

A tea's traceability chain is:
purchase -> raw tea / lot -> blend / production -> QC / wastage -> packaging -> finished SKU -> warehouse -> outlet transfer -> POS sale -> franchise margin -> settlement -> finance / profit.

## Franchise economics
Stock received is full account/MRP value. Margin is earned only on verified eligible POS sales.

Standard tiers:
- Starter 25%
- Growth 27%
- Elite 30%
- Founder-approved manual percentage

Margin changes are historical, effective-dated and audited.

## Performance share
The Franchise & Retail Operations performance share is calculated on positive distributable Franchise Division operating profit after agreed costs/expenses, never on MRP, stock issued or gross sales. Policy uses role-based keys so personnel can change without changing the financial model.

## Separation
- `teashop.bd`: Business OS
- `teashop.com.bd`: existing ecommerce, untouched
- Production secrets and database credentials remain outside Git.

## Deployment
GitHub is source of truth. Routine deployment is cPanel Update from Remote -> Deploy HEAD Commit. The committed `deploy/` runtime is copied to `/home/teashopc/teashop.bd`. GitHub Actions is manual-only and is not required for ordinary production releases.

## Git discipline
Never commit node_modules, .env, credentials, first-login passwords, logs, uploads, backups, DB dumps or runtime storage.
