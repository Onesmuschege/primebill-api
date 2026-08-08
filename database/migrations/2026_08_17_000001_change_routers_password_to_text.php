<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Router model now encrypts its password at rest via the Encryptable
     * trait. Encrypted payloads are longer than VARCHAR(255), so widen the
     * column to TEXT. SQLite doesn't enforce column length, but PostgreSQL
     * and MySQL do — this keeps the schema correct for production.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->text('password')->change();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('password')->change();
        });
    }
};

