<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes the FreeRADIUS-compatible schema started in
     * create_freeradius_tables. A real freeradius daemon (rlm_sql) writes
     * to radacct on every accounting packet and radpostauth after every
     * auth attempt, and reads radgroupcheck/radgroupreply for group-level
     * attributes — without these, a live daemon logs SQL errors on real
     * router traffic even though radcheck/radreply/radusergroup alone are
     * enough for this app's own ProvisioningService writes.
     */
    public function up(): void
    {
        if (!Schema::hasTable('radgroupcheck')) {
            Schema::create('radgroupcheck', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('==');
                $table->string('value', 253)->default('');
                $table->index('groupname');
            });
        }

        if (!Schema::hasTable('radgroupreply')) {
            Schema::create('radgroupreply', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('=');
                $table->string('value', 253)->default('');
                $table->index('groupname');
            });
        }

        if (!Schema::hasTable('radpostauth')) {
            Schema::create('radpostauth', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->default('');
                $table->string('pass', 64)->default('');
                $table->string('reply', 32)->default('');
                $table->string('authdate')->nullable();
                $table->string('class', 64)->nullable();
            });
        }

        if (!Schema::hasTable('radacct')) {
            Schema::create('radacct', function (Blueprint $table) {
                $table->id('radacctid');
                $table->string('acctsessionid', 64)->default('');
                $table->string('acctuniqueid', 32)->default('')->unique();
                $table->string('username', 64)->default('');
                $table->string('groupname', 64)->default('');
                $table->string('realm', 64)->nullable();
                $table->string('nasipaddress', 15)->default('');
                $table->string('nasportid', 15)->nullable();
                $table->string('nasporttype', 32)->nullable();
                $table->timestamp('acctstarttime')->nullable();
                $table->timestamp('acctupdatetime')->nullable();
                $table->timestamp('acctstoptime')->nullable();
                $table->bigInteger('acctinterval')->nullable();
                $table->bigInteger('acctsessiontime')->nullable();
                $table->string('acctauthentic', 32)->nullable();
                $table->string('connectinfo_start', 50)->nullable();
                $table->string('connectinfo_stop', 50)->nullable();
                $table->bigInteger('acctinputoctets')->nullable();
                $table->bigInteger('acctoutputoctets')->nullable();
                $table->string('calledstationid', 50)->default('');
                $table->string('callingstationid', 50)->default('');
                $table->string('acctterminatecause', 32)->default('');
                $table->string('servicetype', 32)->nullable();
                $table->string('framedprotocol', 32)->nullable();
                $table->string('framedipaddress', 15)->default('');
                $table->index('username');
                $table->index('nasipaddress');
                $table->index(['acctstarttime', 'acctstoptime']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('radacct');
        Schema::dropIfExists('radpostauth');
        Schema::dropIfExists('radgroupreply');
        Schema::dropIfExists('radgroupcheck');
    }
};
