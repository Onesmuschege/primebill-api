<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Core lead info
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('alt_phone')->nullable();

            // Location
            $table->string('address')->nullable();
            $table->string('town')->nullable();
            $table->string('county')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();

            // Lead tracking
            $table->string('source')->default('walk_in'); // walk_in, referral, social_media, website, call, sms, other
            $table->enum('status', ['new', 'contacted', 'qualified', 'survey_required', 'converted', 'lost'])->default('new');
            $table->string('interest_plan')->nullable(); // what plan they're interested in
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();

            // Conversion tracking
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_to_client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('alt_phone')->nullable();

            // Location
            $table->string('address')->nullable();
            $table->string('town')->nullable();
            $table->string('county')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();

            // Interest & feasibility
            $table->string('interested_package')->nullable();
            $table->string('installation_type')->nullable(); // fiber, wireless, pppoe
            $table->boolean('installation_feasible')->nullable();
            $table->text('feasibility_notes')->nullable();
            $table->decimal('installation_fee_quoted', 10, 2)->nullable();

            // Sales pipeline
            $table->enum('pipeline_stage', ['new', 'negotiation', 'survey_scheduled', 'survey_completed', 'installation_scheduled', 'won', 'lost'])->default('new');
            $table->enum('status', ['active', 'converted', 'lost'])->default('active');
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();

            // Conversion
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_to_client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'pipeline_stage']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('alt_phone')->nullable();
            $table->string('relationship')->nullable(); // spouse, parent, child, business_partner, etc.
            $table->enum('type', ['billing', 'technical', 'emergency', 'general'])->default('general');
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'type']);
            $table->index(['tenant_id', 'client_id']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Polymorphic owner (client, prospect, lead)
            $table->string('addressable_type');
            $table->unsignedBigInteger('addressable_id');

            $table->enum('type', ['installation', 'billing', 'home', 'business', 'other'])->default('other');
            $table->string('label')->nullable(); // e.g. "Home", "Office", "Parents' House"
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('town');
            $table->string('county')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Kenya');
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->text('directions')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['addressable_type', 'addressable_id']);
            $table->index(['tenant_id', 'town']);
        });

        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', [
                'id_card', 'passport', 'kra_pin', 'contract', 'installation_form',
                'receipt', 'invoice_copy', 'survey_form', 'other'
            ])->default('other');
            $table->string('label')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->text('description')->nullable();
            $table->date('expiry_date')->nullable(); // for ID expiry tracking
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'type']);
            $table->index(['expiry_date']);
        });

        Schema::create('client_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who performed the action

            $table->string('event_type'); // created, activated, payment, suspension, installation, ticket, plan_change, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // extra data about the event
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['client_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_timeline');
        Schema::dropIfExists('client_documents');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('leads');
    }
};
