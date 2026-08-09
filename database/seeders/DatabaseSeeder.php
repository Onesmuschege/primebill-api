<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application database.
     *
     * Ordering rules:
     *   1. Roles/permissions must run before any user seeder.
     *   2. Tenants must be created before anything that depends on Tenant::current().
     *   3. Subscription plans (global catalog) must exist before TenantSubscriptionSeeder.
     *   4. Tenant subscriptions must be seeded before tenant users (the plan
     *      determines how many users / max_clients are available).
     *   5. Tenant users must be seeded before any seeder that references
     *      created_by / assigned_to user IDs.
     *   6. Core entities (settings, routers, plans, clients, accounts) must be
     *      created before downstream seeders that reference them.
     *   7. Financial downstream entities (invoices -> payments -> ledger ->
     *      wallets -> refunds -> credit/debit notes -> allocations) follow
     *      strict reference chains.
     */
    public function run(): void
    {
        $this->call([
            // --- Phase 0: Platform foundation ---
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,            // no-op (kept for compatibility)
            TenantSeeder::class,
            SubscriptionPlanSeeder::class,
            TenantSubscriptionSeeder::class,
            TenantUserSeeder::class,

            // --- Phase 1: Core settings & infrastructure ---
            SettingsSeeder::class,
            RouterSeeder::class,
            PlanSeeder::class,

            // --- Phase 1: Subscribers ---
            ClientSeeder::class,
            ClientAccountSeeder::class,

            // --- Phase 2: Service Catalog & Network Core ---
            ServiceCatalogSeeder::class,
            RouterManagementSeeder::class,
            RadiusManagementSeeder::class,
            NetworkOperationsSeeder::class,

            // --- Phase 2: RADIUS sessions (depends on accounts) ---
            RadiusSessionSeeder::class,

            // --- Phase 3: IPAM (depends on routers, vlans) ---
            IpamSeeder::class,

            // --- Phase 4: Billing Advanced ---
            TaxSeeder::class,
            DiscountSeeder::class,
            WalletSeeder::class,
            InvoiceSeeder::class,
            PaymentAllocationSeeder::class,
            PaymentSeeder::class,
            LedgerSeeder::class,
            RefundSeeder::class,
            CreditDebitNoteSeeder::class,
            UsageBillingSeeder::class,
            CollectionsSeeder::class,

            // --- Phase 5: Inventory Engine ---
            WarehouseSeeder::class,
            SupplierSeeder::class,
            InventoryItemSeeder::class,
            StockMovementSeeder::class,
            PurchaseOrderSeeder::class,
            InventoryAssignmentSeeder::class,

            // --- Phase 6: Support Catalog ---
            DepartmentSeeder::class,
            TicketQueueSeeder::class,
            TicketCategorySeeder::class,
            SlaPolicySeeder::class,
            KnowledgeBaseSeeder::class,
            MaintenanceNoticeSeeder::class,

            // --- Phase 1: Support & Comms (tickets depend on clients + users) ---
            TicketSeeder::class,
            SmsLogSeeder::class,
            CommunicationTemplateSeeder::class,
            CommunicationLogSeeder::class,
            NotificationPreferenceSeeder::class,
            AnnouncementSeeder::class,
            CampaignSeeder::class,
            WebhookSeeder::class,

            // --- Phase 1: Network data (depends on routers) ---
            NetworkTrafficSeeder::class,

            // --- Phase 1: Finance & inventory data ---
            ExpenditureSeeder::class,

            // --- Phase 8: Customer Experience ---
            ClientEnrichmentSeeder::class,
            LeadSeeder::class,
            CustomerExperienceSeeder::class,

            // --- Phase 9: NOC (depends on routers/devices) ---
            NocSeeder::class,

            // --- Phase 10: Fiber (depends on accounts/routers) ---
            FiberSeeder::class,
            OntSignalHistorySeeder::class,
            OntEventSeeder::class,

            // --- Phase 11: Work Orders (depends on clients, users, equipment) ---
            WorkOrderSeeder::class,
            WorkOrderPartsSeeder::class,

            // --- Phase 12: Reporting ---
            SavedReportSeeder::class,
            ReportScheduleSeeder::class,
            DashboardSeeder::class,

            // --- Phase 13: Security ---
            SecurityEventSeeder::class,
            UserDeviceSeeder::class,
            LoginHistorySeeder::class,
            MfaRecoveryCodeSeeder::class,
        ]);
    }
}
