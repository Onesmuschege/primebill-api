<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('key_id')->unique();
            $table->string('key_hash');
            $table->text('key_secret')->nullable(); // encrypted
            $table->string('last_used_ip')->nullable();
            $table->text('last_used_user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('revoked')->default(false);
            $table->text('scopes')->nullable(); // JSON array of allowed scopes
            $table->timestamps();

            $table->index(['user_id', 'revoked']);
            $table->index(['key_id', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
