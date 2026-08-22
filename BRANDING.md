# PRIMEBILL ISP PLATFORM — Branding

This document defines the official product naming and usage rules. The same
rules apply to the frontend (`primebill-frontend`) and backend
(`primebill-api`) repositories.

## Official terminology

| Term | Usage | Examples |
|------|-------|----------|
| **PrimeBill** | Short brand name. Safe for space-limited surfaces (SMS, MFA issuer, PDF footers, mail signatures). | Sidebar brand, WhatsApp sign-off |
| **PrimeBill ISP Platform** | Official full product name (title case). Use in prose, emails/subjects, reports and documentation when referring to the product. | READMEs, invoice email subjects |
| **PRIMEBILL ISP PLATFORM** | All-caps display form. Use in UI chrome, browser/page titles, login screens, dashboard headers and report mastheads. | `<title>`, login screen, header bars |

## Source of truth

The product name must **never** be hard-coded in user-facing code.

- **Backend:** `config/brand.php` (env overrides `PRIMEBILL_BRAND_NAME`,
  `PRIMEBILL_PRODUCT_NAME`, `PRIMEBILL_DISPLAY_NAME`, `PRIMEBILL_DEFAULT_COMPANY`).
  Access via `config('brand.brand')`, `config('brand.product')`,
  `config('brand.display')`, `config('brand.company')`.
- **Frontend:** `src/config/brand.js` (env overrides `VITE_BRAND_NAME`,
  `VITE_PRODUCT_NAME`, `VITE_DISPLAY_NAME`, `VITE_DEFAULT_COMPANY`).
  Import the `BRAND` object and use `BRAND.brand`, `BRAND.product`,
  `BRAND.display`, `BRAND.company`.

`Laravel`'s general `APP_NAME` should be left at the default
`PrimeBill ISP Platform`; it feeds mail From-name identities.

## What NOT to rename

"Platform" has an architectural meaning in this codebase and must keep its
meaning in these contexts (unless a branding change genuinely requires it):

- Platform routes (`/platform/*`, `platform:` artisan commands)
- Platform middleware, platform users, platform settings
- Platform IDs, database tables/columns (`platform_invoices`, `platform_admin`, ...)
- Internal architecture terms: Platform Console, Platform Billing, tenants

Only user-facing product presentation changes.

## Do

- Use `PrimeBill` whenever space is tight or a filler brand is needed.
- Use `PrimeBill ISP Platform` for the first full mention of the product in
  any email, document or screen.
- Use `PRIMEBILL ISP PLATFORM` for titles, headers and hero/brand chrome.
- Use the central config values instead of literal brand strings.

## Don't

- Don't invent variants: `PrimeBilling`, `Prime Billing`, `PrimeBill ISP`,
  `PrimeBill Platform`, `Primebill` are all deprecated.
- Don't hard-code the brand in new UI strings — import from `config`
  (`backend`) or `src/config/brand.js` (frontend).
- Don't rename architectural `platform` terms that describe the multi-tenant
  platform engine, IDs, tables or scopes.