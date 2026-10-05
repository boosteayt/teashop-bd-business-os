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


## Operations Patch 1 — Outlet Network Core
The network layer now treats each franchise outlet as a traceable operating entity.

### Outlet 360
A single outlet view combines:
- outlet/franchisee profile and territory
- current margin tier/percentage (read-only for Operations; Founder override only)
- opening pipeline and next action/blocker
- opening and closure checklist progress
- current stock value and pack lines
- 30-day POS sales/receipts
- recent settlement history
- staff and training
- document registry
- latest and historical health scores
- lifecycle timeline combining operational events, POS sales, stock movements and settlements

### Opening pipeline
Lead -> Verification -> Agreement -> Shop Ready -> Training -> Stock Ready -> POS Ready -> Launch -> Live.

Operations can progress normal opening stages. Suspended and Closed are Founder-final states.

### Territory
Operational hierarchy is stored as Division -> District -> Upazila -> Outlet, with territory-level outlet counts, pipeline/attention counts and 30-day sales.

### Health foundation
Outlet Health is a separate operational signal from lifecycle status:
- Sales: 35%
- Stock: 25%
- Settlement discipline: 25%
- Compliance: 15%

Result:
- 75–100: Healthy
- 50–74.99: Watch
- below 50: Critical

### Checklists
Opening checklist has 10 required controls. Closure checklist has 8 controls covering approval, sales stop, stock count/return, dues, POS access, brand assets and final handover.

### Audit
Profile, pipeline, checklist, staff, training, health and document mutations are recorded through the central audit log. Outlet timeline separately preserves the operational story of the outlet.


## Operations Patch 2 — Daily Operations & Support

### Workboard
The Franchise & Retail Operations workspace now has role-controlled tabs for:
- Network
- Daily Tasks
- Field Visits
- Support Tickets
- Compliance & Training
- Marketing
- Communications

### SLA
Operational tasks, support tickets and marketing executions carry priority, assignee and due-time control. If no custom due time is supplied:
- Critical = 4 hours
- High = 12 hours
- Medium = 48 hours
- Low = 96 hours

Open records are calculated as On Time, Due Soon (within 24 hours), or Overdue. Escalation increments an explicit escalation level and stores the escalation time.

### Field operations
Field visits store scheduling, responsible visitor, inspection scores, findings, corrective action and next visit. Compliance is tracked independently so a site visit and an SOP check can exist together.

### Communications
Call / WhatsApp / email / meeting / visit / internal notes are outlet-linked. A communication with a follow-up date automatically creates a daily operations follow-up task.

### Compliance
Compliance score is the average of:
- branding
- pricing
- POS usage
- stock handling
- customer service

80+ = Compliant, 60–79.99 = Watch, below 60 = Non-Compliant. Corrective actions can generate a linked task and have an explicit resolution timestamp.

### Marketing execution
Marketing policy remains HQ-controlled. Operations tracks outlet execution only: readiness, assigned operator, priority, due date, campaign dates, execution confirmation and sales before/during the campaign.

### Outlet 360 integration
Patch 2 records are surfaced under Outlet 360° -> Daily Ops, and every major mutation writes both the central audit log and outlet operational timeline.


## Operations Patch 3 — Intelligence
Patch 3 adds operational intelligence without giving Operations pricing, company-finance or inventory-adjustment authority.

Sales intelligence uses verified POS records for 7-day, 30-day and month-to-date performance. Outlet targets are stored separately and do not alter MRP or franchise margin.

Stock intelligence derives on-hand quantity from the inventory ledger and combines it with POS item velocity. Default policy is 7 minimum days cover, 21 target days cover, 60 maximum days cover and 30 dead-stock days. Policies can be overridden by outlet/SKU.

Physical counts create mismatch records only; they never auto-post inventory adjustments.

Settlement aging is computed from non-paid settlement periods. Positive net payable is shown as franchise payable and negative net payable as company receivable.

Healthy-business score is a management ranking signal: Sales Target Achievement 40%, Outlet Health 25%, Inventory Health 20%, Settlement Discipline 15%.


## Operations Patch 4 — Final Closure
Patch 4 completes the franchise-network operating layer.

### Alerts and escalation
Automatic alert generation covers:
- 5+ day POS inactivity (critical at 7+ days)
- settlement overdue more than 7 days
- overdue daily tasks
- overdue support tickets
- Watch / Non-Compliant SOP checks
- unresolved physical stock mismatches
- expired training
- out-of-stock / under-7-day stock cover
- 3+ customer tickets in 7 days

Each signal has a stable alert key, severity, outlet/source linkage, Operations assignment and Open -> Acknowledged -> Resolved lifecycle. Newly detected or recurring alerts also create an Operations notification.

### Performance
Operations scorecard is fixed at:
- Network Sales Growth: 30%
- Stock Rotation: 20%
- Outlet Health: 15%
- Settlement Discipline: 15%
- Outlet Retention: 10%
- SOP Compliance: 10%

The weighted score is separate from the performance-share rate. Share rate remains Owner-configured through the management-share tier/manual setting. The share base is distributable Franchise Division profit: verified POS sales less franchise earned margin and Finance-approved Franchise Division ledger expenses. Operations can generate a review but cannot approve it. Owner approves; Owner/Finance can mark an approved share paid.

### Reports
Regional/network reporting aggregates Division -> District outlet count, active/attention outlets, verified sales, targets, target achievement, health, alerts and overdue settlements. Month-end snapshots freeze the management summary without altering finance records.

### Security closure
Operations role is explicitly constrained to its approved network-management menu. Owner/specialist controls remain outside that role, including pricing, margin override, company finance administration, users/settings, final outlet suspension/closure and performance approval.

The final verifier checks 99 Tea master rows, 12+ roles, every Patch 1–4 table, required source markers, scorecard weights, role menu boundary, business rules and forbidden personal-name/legacy-key leakage.
