# Tea Shop BD Business OS — Architecture Baseline

## Control model
- Founder/Owner: full system, sourcing/manufacturing confidentiality, pricing policy, finance, permissions and overrides.
- Omar Faruk: Franchise & Retail Operations. No ownership/equity and no manufacturing/sourcing entitlement.
- Finance: settlement, ledger, P&L, VAT/tax provision and approved payable workflows.
- Warehouse: raw tea, packaging, production output, transfers and stock reconciliation.
- Regional: assigned outlet operations only.
- Franchise: own outlet data only.
- Cashier: POS only.

## Franchise economics
Stock received is full account/MRP value. Margin is earned only on verified eligible POS sales. Standard tiers: 25%, 27%, 30%; Owner can apply a manual percentage with effective date and audit log.

## Omar Faruk performance share
Working tiers: Base 15%, Growth 20%, Elite 25%, plus Owner-controlled manual override. Share is calculated on positive distributable Franchise Division operating profit after agreed costs/expenses, never on MRP, stock issued or gross sales.

## Operational chain
Purchase/raw tea -> production/blending -> QC/wastage -> packaging/rebuild -> finished goods -> central inventory -> franchise transfer -> POS verified sale -> franchise earned margin -> monthly settlement -> Franchise Division P&L -> Omar Faruk performance share -> Tea Shop BD net.

## Git discipline
Never commit node_modules, dist, .env, logs, uploads, backups, DB dumps or runtime storage. Git is source-of-truth; production builds are generated during deployment.
