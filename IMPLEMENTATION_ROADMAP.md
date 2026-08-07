# PrimeBill OSS/BSS Production Implementation Roadmap

> **Status:** Active Implementation  
> **Goal:** Transform MVP to production-ready multi-tenant ISP billing platform  
> **Approach:** Iterative sprints with zero regression on existing features

---

## Current State Analysis

### ✅ Already Implemented
- Multi-tenant foundation (tenants table, tenant_id on 21 tables)
- User authentication with Sanctum
- Role-based access control (Spatie)
- Core CRM (Leads, Prospects, Clients)
- Basic billing (Invoices, Payments, Ledger)
- RADIUS schema (radcheck, radreply, radacct)
- FreeRADIUS accounting tables
- Vouchers and loyalty points
- Settings and notifications
- MikroTik sync logs

### ⚠️ Partially Implemented
- Tenant isolation (columns exist, global scopes missing)
- Email verification
- RBAC (roles exist, full permission matrix missing)
- Recurring billing
- PDF generation
- Customer portal

### ❌ Missing Core Features
- **Platform:** Tenant domains, quotas, subscription billing, audit logs, notifications system
- **Service Management:** Hotspot zones, packages, static IP management, enterprise services
- **Billing:** Credit/debit notes, refunds, taxes, discounts, usage billing, finance reports
- **Network:** MikroTik automation, RADIUS automation, IPAM, network monitoring, OLT management
- **Operations:** Field work orders, inventory, warehouse management
- **Analytics:** Dashboards, reports, exports
- **Integrations:** Payment gateways, SMS/WhatsApp, Firebase push

---

## Implementation Strategy

### Phase 1: Platform Foundation (Weeks 1-2)
**Priority:** Critical - Required for all other features  
**Risk:** Low - Building on existing foundation

#### Sprint 1.1: Tenant Isolation & Security
- [ ] Implement `BelongsToTenant` global scope trait
- [ ] Create `ResolveTenant` middleware
- [ ] Add tenant verification for subdomains/custom domains
- [ ] Implement cross-tenant access prevention tests
- [ ] Add tenant context to all queries

#### Sprint 1.2: Authentication Hardening
- [ ] Implement MFA/2FA with TOTP
- [ ] Add device tracking (user_agent, ip, login_at)
- [ ] Create login history table and tracking
- [ ] Enhance email verification flow
- [ ] Add password policy enforcement

#### Sprint 1.3: Audit & Notifications
- [ ] Create audit_logs table
- [ ] Implement AuditService with event logging
- [ ] Build notification system (database, email, SMS)
- [ ] Add notification preferences per user
- [ ] Create notification templates

#### Sprint 1.4: Settings & Configuration
- [ ] Implement SettingsService with caching
- [ ] Add structured tenant settings (JSON)
- [ ] Create settings API endpoints
- [ ] Add encrypted storage for API keys

### Phase 2: Service Management (Weeks 3-4)
**Priority:** High - Core business logic  
**Risk:** Medium - Complex state machines

#### Sprint 2.1: Service Architecture
- [ ] Create services table
- [ ] Implement service lifecycle (activate, suspend, resume, terminate)
- [ ] Add service type support (PPPoE, Hotspot, Static, VPN, Fiber)
- [ ] Build service repository pattern

#### Sprint 2.2: Packages & Plans
- [ ] Create packages table
- [ ] Add bandwidth profiles and FUP tiers
- [ ] Implement plan assignment to services
- [ ] Add package pricing and billing integration

#### Sprint 2.3: Hotspot Management
- [ ] Create hotspot_zones table
- [ ] Implement voucher batch generation
- [ ] Add voucher expiry and status tracking
- [ ] Build captive portal integration points

#### Sprint 2.4: MikroTik & RADIUS Automation
- [ ] Create MikroTik API service
- [ ] Implement PPPoE automation (create, update, disable users)
- [ ] Add hotspot voucher automation
- [ ] Build queue management (simple queues, queue trees)
- [ ] Implement RADIUS sync jobs

### Phase 3: Advanced Billing (Weeks 5-6)
**Priority:** High - Revenue critical  
**Risk:** Medium - Financial accuracy required

#### Sprint 3.1: Invoice Enhancements
- [ ] Add invoice templates
- [ ] Implement PDF generation (dompdf)
- [ ] Create recurring billing engine
- [ ] Add pro-rated billing for mid-cycle changes
- [ ] Build invoice line items

#### Sprint 3.2: Payment Features
- [ ] Implement refund workflow
- [ ] Add credit/debit notes
- [ ] Build payment reconciliation
- [ ] Add payment receipt generation
- [ ] Implement Stripe/Pesapal integration

#### Sprint 3.3: Taxes & Discounts
- [ ] Create tax rates management
- [ ] Implement VAT calculation
- [ ] Add discount engine (promo codes, customer discounts)
- [ ] Build coupon system

#### Sprint 3.4: Usage Billing
- [ ] Implement data usage tracking
- [ ] Add bandwidth billing
- [ ] Create overage calculation
- [ ] Build usage reports

### Phase 4: Network & Infrastructure (Weeks 7-8)
**Priority:** Medium - Operational efficiency  
**Risk:** High - External system integration

#### Sprint 4.1: IPAM
- [ ] Create IPv4/IPv6 pools
- [ ] Implement IP allocation engine
- [ ] Add IP reservations and tracking
- [ ] Build DHCP pool management
- [ ] Create VLAN management

#### Sprint 4.2: Network Monitoring
- [ ] Implement SNMP polling service
- [ ] Add ICMP device discovery
- [ ] Create device metrics collection (CPU, memory, traffic)
- [ ] Build alert system for thresholds
- [ ] Implement network topology map

#### Sprint 4.3: OLT Management
- [ ] Add OLT vendor support (Huawei, ZTE, FiberHome)
- [ ] Implement ONU/ONT registration tracking
- [ ] Add signal level monitoring
- [ ] Build PON port management

### Phase 5: Operations & Field Service (Weeks 9-10)
**Priority:** Medium - Customer experience  
**Risk:** Low - CRUD operations

#### Sprint 5.1: Inventory Management
- [ ] Create assets table (routers, ONTs, switches)
- [ ] Implement warehouse management
- [ ] Add stock tracking and transfers
- [ ] Build serial number tracking
- [ ] Add warranty management

#### Sprint 5.2: Work Orders
- [ ] Create work_orders table
- [ ] Implement work order lifecycle
- [ ] Add technician assignment
- [ ] Build scheduling system
- [ ] Create mobile technician endpoints

#### Sprint 5.3: Customer Portal
- [ ] Build customer dashboard
- [ ] Add invoice viewing and payment
- [ ] Implement usage monitoring
- [ ] Add ticket creation
- [ ] Build document download

### Phase 6: Analytics & Reporting (Weeks 11-12)
**Priority:** Medium - Business insights  
**Risk:** Low - Data aggregation

#### Sprint 6.1: Analytics Engine
- [ ] Implement metrics collection
- [ ] Create customer analytics (growth, churn, ARPU)
- [ ] Build revenue analytics
- [ ] Add network analytics (bandwidth, utilization)
- [ ] Implement operations analytics

#### Sprint 6.2: Reporting System
- [ ] Create finance reports (revenue, expenses, invoices)
- [ ] Build network reports (usage, sessions)
- [ ] Add customer reports (growth, churn)
- [ ] Implement export functionality (CSV, Excel, PDF)
- [ ] Build report scheduler

### Phase 7: Integrations & Polish (Weeks 13-14)
**Priority:** Low - Nice to have  
**Risk:** Medium - Third-party dependencies

#### Sprint 7.1: Payment Integrations
- [ ] Implement Stripe integration
- [ ] Add Pesapal integration
- [ ] Build bank transfer reconciliation
- [ ] Add payment webhooks

#### Sprint 7.2: Communication Integrations
- [ ] Implement Africa's Talking SMS
- [ ] Add WhatsApp Business API
- [ ] Build Firebase Cloud Messaging
- [ ] Create email templates

#### Sprint 7.3: Security & Testing
- [ ] Implement rate limiting
- [ ] Add API security headers
- [ ] Build automated security tests
- [ ] Create load testing suite
- [ ] Implement backup strategy

### Phase 8: Documentation & Deployment (Week 15)
**Priority:** Critical - Production readiness  
**Risk:** Low - Documentation

#### Sprint 8.1: Documentation
- [ ] Write API documentation (OpenAPI/Swagger)
- [ ] Create installation guide
- [ ] Write admin user guide
- [ ] Document network setup
- [ ] Create developer guide

#### Sprint 8.2: Deployment Preparation
- [ ] Set up Docker containers
- [ ] Configure CI/CD pipeline
- [ ] Implement database migration strategy
- [ ] Set up queue workers
- [ ] Configure monitoring (logs, alerts, health checks)
- [ ] Create backup and restore procedures

---

## Risk Mitigation

### Technical Risks
| Risk | Mitigation |
|------|------------|
| Tenant isolation bugs | Comprehensive cross-tenant test suite |
| RADIUS/MikroTik integration failures | Mock services for testing, graceful degradation |
| Billing calculation errors | Double-entry accounting, automated reconciliation |
| Performance degradation | Database indexing, query optimization, caching |

### Business Risks
| Risk | Mitigation |
|------|------------|
| Data migration issues | Staged rollout, rollback plan |
| Integration failures | Retry logic, fallback mechanisms |
| Security vulnerabilities | Penetration testing, security audit |

---

## Success Metrics

### Platform Stability
- 99.9% uptime
- <200ms API response time (p95)
- Zero cross-tenant data leaks

### Business Operations
- 100% automated billing accuracy
- <5min RADIUS sync latency
- <1hr work order dispatch time

### Development Velocity
- 2-week sprint cycles
- 80%+ test coverage
- Zero critical bugs in production

---

## Next Steps

1. **Immediate:** Start Phase 1, Sprint 1.1 (Tenant Isolation)
2. **Week 1:** Complete Platform Foundation
3. **Week 2-3:** Complete Service Management
4. **Week 4-5:** Complete Advanced Billing
5. **Week 6-7:** Complete Network & Infrastructure
6. **Week 8-9:** Complete Operations & Field Service
7. **Week 10-11:** Complete Analytics & Reporting
8. **Week 12-13:** Complete Integrations
9. **Week 14:** Complete Testing & Security
10. **Week 15:** Documentation & Production Deployment

---

## Dependencies

### External Services
- M-Pesa Daraja API
- Africa's Talking (SMS/WhatsApp)
- Firebase Cloud Messaging
- Stripe/Pesapal
- SMTP provider

### Infrastructure
- MySQL 8.0+ / PostgreSQL 13+
- Redis 6+ (caching, queues)
- Elasticsearch (optional, for search)
- Prometheus + Grafana (monitoring)

---

## Budget Estimate

### Development
- **Timeline:** 15 weeks
- **Team:** 2-3 developers
- **Effort:** ~600-800 hours

### Infrastructure (Monthly)
- **Hosting:** $100-300 (depends on scale)
- **SMS/WhatsApp:** $50-200 (volume-based)
- **Monitoring:** $20-50
- **Backups:** $10-30

**Total First Year:** ~$15,000-25,000 (development + infrastructure)

---

*Last Updated: August 2026*  
*Version: 1.0*
