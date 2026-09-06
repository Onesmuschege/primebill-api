<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level operator settings for PrimeBill itself.
 *
 * Deliberately SEPARATE from the tenant ISP `settings` table: those rows are
 * what an ISP configures for its own business (SMS keys, radius settings…),
 * while this table holds PrimeBill-operator preferences that drive the
 * platform console (security thresholds, billing terms). No tenant scope —
 * these are global operator constants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};