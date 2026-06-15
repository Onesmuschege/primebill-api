<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FreeRADIUS-compatible tables for local dev and SQL-backed RADIUS sync.
     * In production, point RADIUS_DB_CONNECTION at your existing FreeRADIUS schema.
     */
    public function up(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->index('username');
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->index('username');
        });

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->integer('priority')->default(1);
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radusergroup');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
    }
};
