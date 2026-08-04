<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * clients.email/phone were globally unique — in a multi-tenant world
     * two different ISPs could legitimately have a client with the same
     * phone number (most likely: the same real person is a customer of
     * two unrelated ISPs). Same fix as settings.key and vouchers.code.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['email']);
            $blueprint->dropUnique(['phone']);
            $blueprint->unique(['tenant_id', 'email']);
            $blueprint->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['tenant_id', 'email']);
            $blueprint->dropUnique(['tenant_id', 'phone']);
            $blueprint->unique(['email']);
            $blueprint->unique(['phone']);
        });
    }
};