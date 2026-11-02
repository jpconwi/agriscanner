<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never stores credentials, image bytes or provider secrets.
        Schema::create('ai_provider_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40)->index();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('outcome', 20); // success | failure
            $table->string('error_category', 60)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('system_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('service', 60)->index(); // database_primary, database_secondary, storage_r2, ai_huggingface...
            $table->string('status', 20); // healthy | degraded | down | unconfigured | disabled | unknown
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at')->useCurrent()->index();
        });

        // Durable retry/recovery queue. Payloads never contain image bytes or secrets.
        Schema::create('pending_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60)->index(); // delete_replica | replicate_file | save_scan | cleanup_object
            $table->string('idempotency_key', 120)->unique();
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index(); // pending | processing | completed | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('pending_operations');
        Schema::dropIfExists('system_health_checks');
        Schema::dropIfExists('ai_provider_logs');
    }
};
