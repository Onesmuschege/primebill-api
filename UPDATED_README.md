# UPDATED_README.md

This README replaces sensitive example credentials with placeholders and aligns project metadata.

## Project Overview
PrimeBill API is the backend engine powering the PrimeBill ISP Billing System. It provides a comprehensive REST API for subscriber management, automated billing, M-Pesa Daraja payment processing, MikroTik RouterOS integration, FreeRADIUS synchronization, SMS notifications, and real-time network monitoring tailored for the Kenyan ISP market.

## Security note
- Do NOT store real credentials in the repository. Use `.env` for runtime secrets and `.env.example` for placeholders.
- The default credentials shown in earlier versions have been removed. Seeders use environment variables `SEED_ADMIN_PASSWORD` and `SEED_STAFF_PASSWORD` to set initial passwords in development only.

## Changes
- Redacted example passwords from README and replaced with `SEED_ADMIN_PASSWORD` and `SEED_STAFF_PASSWORD` placeholders. Documented secure setup steps.

