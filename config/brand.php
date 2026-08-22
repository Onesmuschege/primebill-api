<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding Configuration — single source of truth
    |--------------------------------------------------------------------------
    |
    | Every user-facing product name in the platform should be sourced from
    | this config (or the environment overrides below) instead of being
    | hard-coded throughout the codebase.
    |
    |   - brand:    short brand name (marketing), safe for space-limited
    |               surfaces such as SMS sign-offs, MFA issuer strings and
    |               PDF footers.
    |   - product:  official full product name (title case). Use in emails,
    |               mail subjects, documentation and report titles.
    |   - display:  all-caps display form used in browser titles, login
    |               screens, dashboard chrome and report headers.
    |   - company:  default company/business display name used when a tenant
    |               has not configured its own company name.
    |
    | See BRANDING.md for the full terminology rules.
    |
    */

    'brand'   => env('PRIMEBILL_BRAND_NAME', 'PrimeBill'),

    'product' => env('PRIMEBILL_PRODUCT_NAME', 'PrimeBill ISP Platform'),

    'display' => env('PRIMEBILL_DISPLAY_NAME', 'PRIMEBILL ISP PLATFORM'),

    'company' => env('PRIMEBILL_DEFAULT_COMPANY', 'PrimeBill ISP'),

];