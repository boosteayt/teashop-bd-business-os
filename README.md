# Tea Shop BD Business OS

Founder-controlled franchise, retail, production, inventory, POS and finance operating system.

## Production
- Live domain: `https://teashop.bd`
- cPanel repository: `/home/teashopc/repositories/teashop-bd-business-os`
- Live document root: `/home/teashopc/teashop.bd`
- Existing ecommerce `teashop.com.bd` is a separate system and must remain untouched.

## Fast deployment model
The live app uses React 18 UMD from `deploy/`, so cPanel does not need Node/npm and normal deployments do not require GitHub Actions.

Workflow:
1. GitHub source commit
2. cPanel **Update from Remote**
3. cPanel **Deploy HEAD Commit**
4. `.cpanel.yml` copies `deploy/` to `/home/teashopc/teashop.bd/`

GitHub Actions is manual-only to avoid consuming build minutes on every commit.

## Implemented foundation
- Premium responsive login/dashboard UI
- Role-scoped navigation: Owner, Omar Faruk Operations, Finance, Warehouse, Regional, Franchise, POS/Cashier
- Authoritative 99-SKU tea purchase master
- A–Z pricing engine with editable packaging/labour/wastage/overhead/logistics assumptions
- CTC and non-CTC pack rules
- Franchise 25% / 27% / 30% / Founder manual override
- Omar Faruk Base 15% / Growth 20% / Elite 25% / Owner manual override
- Purchase & Raw Tea workflow
- Production / QC / wastage workflow
- Packaging & Rebuild workflow
- Inventory summary
- Franchise onboarding
- POS verified-sale workflow
- Settlement summary
- Finance/P&L and Faruk performance-share calculation
- Reports and role/access UI
- PWA/offline app-shell support
- MySQL production schema
- PHP same-origin API foundation with secure sessions, CSRF and audit logging
- One-time secure user bootstrap mechanism

## Security
Passwords, database credentials and setup tokens are never committed to this public repository.
Production database configuration is loaded from:
`/home/teashopc/teashop-os-config.php`

See `server/teashop-os-config.sample.php`.

## Business rules
- Stock received is full account/MRP value.
- Franchise profit is earned only on verified eligible POS sales.
- Standard franchise tiers: 25%, 27%, 30%, with Founder-controlled manual override.
- Omar Faruk performance share is based on positive distributable Franchise Division profit, not MRP, stock issued or gross sales.
- Manufacturing/sourcing confidentiality remains Founder-controlled.
