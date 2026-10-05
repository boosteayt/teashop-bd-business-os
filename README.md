# Tea Shop BD Business OS

Tea-centric operating system for Tea Shop BD procurement, blending, quality, packaging, inventory, franchise retail, POS, settlement and finance.

## Production boundaries
- Live domain: `https://teashop.bd`
- cPanel repository: `/home/teashopc/repositories/teashop-bd-business-os`
- Live document root: `/home/teashopc/teashop.bd`
- Existing ecommerce `teashop.com.bd` is a separate system and must remain untouched.

## Core business chain
```
Tea purchase
  -> raw tea stock / lot
  -> blend & production batch
  -> QC / wastage / yield
  -> packaging / finished SKU
  -> central warehouse
  -> outlet transfer
  -> verified POS sale
  -> 25% / 27% / 30% or approved custom franchise margin
  -> monthly settlement
  -> Finance / P&L
  -> role-based performance share
  -> Tea Shop BD net
```

Stock received does not create franchise profit. Franchise margin is earned only on verified eligible POS sales.

## Tea master
The `Tea` workspace is the lifecycle hub. The authoritative 99 Tea Shop BD tea names and purchase-cost baseline live in `database/seed_products.sql`.

Opening a tea can trace:
- master/category/current purchase-cost baseline
- packs/SKUs and MRP
- purchase history
- blend / production batch and components
- QC / wastage
- packaging jobs
- warehouse/outlet stock movement
- POS sale history
- franchise margin context
- MRP history and lifecycle events

## Role model
The application uses role names rather than personal names:
- Founder / Owner / CEO
- Franchise & Retail Operations
- Finance & Accounts
- Warehouse & Inventory
- Production & Blending
- Quality Control
- Packaging
- Regional Operations
- Franchise Owner
- Outlet Manager
- POS / Cashier
- Auditor / Read Only

Manufacturing, sourcing, pricing-policy and other restricted controls remain Founder-controlled. Access to an operational module does not imply ownership.

## Main workspaces
Dashboard, Tea, Purchase & Suppliers, Blending & Production, QC & Wastage, Packaging, Products / SKU, Pricing Engine, Inventory / Warehouse, Outlets / Franchise, Franchise & Retail Operations, POS / Sales, Margin & Settlement, Finance & Accounts, Profit & Loss, Performance & Incentives, Customers, Corporate / B2B, Logistics, Reports, Approvals, Documents, Notifications, Users & Roles, Audit Log and Settings.

## Franchise margin
Standard outlet tiers:
- Starter: 25%
- Growth: 27%
- Elite: 30%
- Founder-approved custom percentage

The Founder can update an existing outlet's margin. Margin changes are recorded in `franchise_margin_history`.

## Financial control
Performance share is calculated on positive distributable Franchise Division profit, not MRP, stock issued or gross sales. The policy is stored using role-based management settings rather than a person's identity.

VAT/tax is an editable accounting policy provision; statutory treatment and rates must be confirmed by the company's accountant.

## Fast deployment model
Normal production deployments do not depend on GitHub Actions minutes.

Workflow:
1. Commit/push source to GitHub.
2. cPanel **Update from Remote**.
3. cPanel **Deploy HEAD Commit**.
4. `.cpanel.yml` copies `deploy/` to `/home/teashopc/teashop.bd/`.

The GitHub Actions workflow is manual-only.

## Backend
Production uses the same-origin PHP API at `deploy/api/index.php` with MySQL, PHP sessions, CSRF checks, password hashing and audit logs.

Production database configuration lives outside the repository:
`/home/teashopc/teashop-os-config.php`

Do not commit database credentials or first-login passwords.

## Database
Base files:
- `database/schema.sql`
- `database/seed_core.sql`
- `database/seed_products.sql`

Current lifecycle migration:
- `database/migrations/2026_10_05_business_os_full_roles_modules.sql`

The migration adds role/control foundations for QC, wastage, price history, margin history, outlet health, customers, B2B, logistics, approvals, documents, notifications and performance records.

## Secure first-login accounts
Server-side bootstrap/install scripts create role-based accounts such as:
`owner@teashop.bd`, `operations@teashop.bd`, `finance@teashop.bd`, `warehouse@teashop.bd`, `production@teashop.bd`, `qc@teashop.bd`, `packaging@teashop.bd`, `regional@teashop.bd`, `franchise@teashop.bd`, `manager@teashop.bd`, `cashier@teashop.bd`, `auditor@teashop.bd`.

Temporary passwords are generated on the server, are not committed to Git, and must be changed after first login.


## Operations Patch 1 — Outlet Network Core
Patch 1 adds the first complete franchise-network operating layer without changing Founder-only pricing or finance authority.

Implemented:
- Outlet 360° profile
- Lead -> Verification -> Agreement -> Shop Ready -> Training -> Stock Ready -> POS Ready -> Launch -> Live pipeline
- Division -> District -> Upazila territory hierarchy
- 10-step opening checklist
- 8-step suspension/closure checklist
- Owner/franchisee profile and premises/agreement fields
- Outlet staff registry
- Staff/outlet training records
- Outlet document registry
- Outlet Health foundation with Sales 35%, Stock 25%, Settlement 25%, Compliance 15%
- Stock, sales and settlement summary inside Outlet 360°
- Full outlet timeline combining pipeline/profile/checklist/staff/training/health events with POS sales, stock movements and settlements
- Owner-only final Suspended / Closed pipeline states
- Operations/Regional field-work permissions with audit logging

Existing production databases must run:
`php server/upgrade-operations-patch1.php`

Read-only production DB gate:
`php server/verify-operations-patch1.php`

Fresh installers automatically apply:
`database/migrations/2026_10_05_operations_patch1_outlet_network_core.sql`


## Operations Patch 2 — Daily Operations & Support
Patch 2 turns Franchise & Retail Operations into a daily execution workboard while keeping Founder-only commercial and finance authority intact.

Implemented:
- Daily task / follow-up center
- Outlet-linked and network-level tasks
- Low / Medium / High / Critical priority
- Assignee control for Owner / Operations / Regional roles
- Due date and default SLA windows
  - Critical: 4 hours
  - High: 12 hours
  - Medium: 48 hours
  - Low: 96 hours
- Task status flow: Open -> In Progress / Waiting -> Done / Cancelled
- Task escalation level and escalation timestamp
- Field visit scheduling, completion and follow-up
- Field inspection scores for cleanliness, branding, display, pricing, POS usage and stock handling
- Corrective action, photo/evidence reference and next-visit tracking
- Support ticket / issue management
- Ticket categories for Stock, POS, Delivery, Customer, Branding, Payment, Staff/Training and Other
- Ticket SLA, assignment, resolution and escalation history
- Communication timeline for Call, WhatsApp, Email, Meeting, Visit and Internal Note
- Promise date and automatic follow-up task creation
- Training attention list plus direct outlet training schedule/record control using existing training records
- Compliance checks for Branding, Pricing, POS Usage, Stock Handling and Customer Service
- Automatic corrective-action task for Watch / Non-Compliant checks
- Compliance resolution workflow
- Marketing execution tracker with assignment, priority, due/SLA, assets readiness, execution verification, before/during sales and escalation
- Patch 2 activity visible inside Outlet 360° Daily Ops
- Operations dashboard workload KPIs
- Central audit log + Outlet timeline integration for mutations

Existing production databases must run:
`php server/upgrade-operations-patch2.php`

Read-only Patch 2 DB gate:
`php server/verify-operations-patch2.php`

Fresh installers automatically apply:
`database/migrations/2026_10_05_operations_patch2_daily_support.sql`


## Operations Patch 3 — Sales, Stock & Settlement Intelligence
Implemented:
- 7-day / 30-day outlet sales velocity
- Monthly sales and receipt targets
- Target achievement %
- POS inactivity / no-sale signals
- SKU movement classes: Fast / Steady / Slow / Dead
- Stock states: Out of Stock / Low / Healthy / Overstock / Dead
- Days-cover calculation
- Outlet/SKU reorder suggestions
- Editable days-cover policy
- Safe physical stock-count mismatch capture without automatic ledger adjustment
- Settlement aging buckets: Current, 1–7, 8–15, 16–30, 30+
- Company receivable vs franchise payable direction
- Healthy-business leaderboard using Sales Target 40%, Outlet Health 25%, Inventory 20%, Settlement Discipline 15%
- Top / Bottom outlet ranking

Existing production DB:
`php server/upgrade-operations-patch3.php`

Read-only DB gate:
`php server/verify-operations-patch3.php`


## Operations Patch 4 — Alerts, Performance & Final Closure
Final Operations scope:
- Automatic alert scan for POS inactivity, overdue settlements/tasks/tickets, compliance failures, stock mismatches, expired training, stock risk and customer-complaint spikes
- Alerts inbox with Open / Acknowledged / Resolved lifecycle
- Operations notification generation for new/reopened alert conditions
- Exact role scorecard weights:
  - Sales Growth 30%
  - Stock Rotation 20%
  - Outlet Health 15%
  - Settlement Discipline 15%
  - Retention 10%
  - SOP Compliance 10%
- P&L-based performance-share preview on verified POS sales less franchise earned margin and Finance-approved Franchise Division ledger expenses
- Owner approval required before performance share becomes payable
- Finance/Owner payment closure after approval
- Regional / network report by Division + District
- Month-end Operations report snapshot
- Runtime role/control audit
- Final source + database verifier
- Operations remains blocked from sourcing, production/QC final authority, pricing, margin override, company finance administration, users/settings, final outlet suspension/closure and self-approval of performance

Existing production DB:
`php server/upgrade-operations-patch4.php`

Patch 4 DB gate:
`php server/verify-operations-patch4.php`

Full Patch 1–4 closure gate:
`php server/verify-operations-final.php`


## Final Operations deployment
For an existing production database, use the combined idempotent upgrader after Update from Remote and before deploying the final HEAD:

`php server/upgrade-operations-final.php`

It reconciles Operations Patch 1–4 schemas, preserves existing Tea/business data, and seeds missing outlet profile/pipeline/checklist foundations for any existing franchise records.

After deployment, run:

`php server/verify-operations-final.php`


## Operations Round 2 production E2E
Run after Round 1 is GREEN:

`php server/e2e-operations-round2.php`

The harness uses temporary identities, one temporary outlet and one temporary sellable pack while preserving the 99 Tea master count. It validates:
- stock transfer and Stock Received != Profit
- verified POS sales at 25%, 27%, 30% and approved custom margin
- physical stock-count mismatch without automatic ledger adjustment
- sales targets, stock velocity, low-stock classification and reorder suggestion
- franchise-scoped settlement generation, formula, aging, Owner lock and Finance paid closure
- overdue task/ticket escalation and franchise-scoped automatic alert refresh
- leaderboard / healthy-business score
- isolated P&L-based Operations performance review, Owner approval and Finance payment
- regional report, month-end snapshot and CSV export source marker
- Operations security rules
- 8/8 closure checklist, Owner-only final close and post-close sale/transfer denial
- automatic cleanup and residue verification

For production safety, `settlement.generate` now accepts an optional `franchise_id` to generate only one outlet settlement; omitting it preserves the existing all-outlet behavior. `operations.alerts.refresh` likewise accepts an optional `franchise_id` for outlet-scoped refresh.


## Operations Command Center V2 — Round 1

Round 1 adds the Daily Operations Core to the existing Franchise & Retail Operations workspace:

- Regional Command Center with Division → District → Upazila heat view, sales, alerts and overdue work.
- Daily Outlet Check-in with opening/closing status, timestamps, evidence refs and manager note.
- Field Visit Planner using the existing field-visit workflow and Operations/Regional assignees.
- Contract & Renewal Control for franchise agreement, lease, trade/food/fire/tax documents and configurable reminder windows.
- Contract-expiry alerts integrated into the existing Operations alert/notification engine.
- Outlet Launch War Room with readiness score, blockers and one-click blocker task creation.
- Existing Owner-only margin, finance, performance approval and final close boundaries remain unchanged.

Production upgrade:

`php server/upgrade-operations-v2-round1.php`

Static/database verifier:

`php server/verify-operations-v2-round1.php`

Production-safe live E2E:

`php server/e2e-operations-v2-round1.php`

Expected final E2E gate:

`OPERATIONS_V2_ROUND1_FINAL_GREEN`
