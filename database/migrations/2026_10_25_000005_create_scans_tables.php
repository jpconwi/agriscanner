<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('idempotency_key', 100);
            // pending | completed | failed
            $table->string('status', 20)->default('pending')->index();
            $table->string('failure_category', 60)->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();
            // Retrying the same upload returns the same scan instead of creating a duplicate.
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('disease_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('predicted_label')->nullable();
            $table->string('crop_label')->nullable();
            $table->boolean('is_healthy')->default(false);
            // Only set when the model genuinely returns a probability.
            $table->decimal('confidence', 6, 5)->nullable();
            $table->string('provider', 40);
            $table->string('model');
            $table->unsignedInteger('latency_ms')->nullable();
            // Snapshot of the reference recommendations at scan time (survives later edits/deletes).
            $table->json('recommendations')->nullable();
            $table->json('alternatives')->nullable();
            $table->timestamps();
            $table->index('disease_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
        Schema::dropIfExists('scans');
    }
};
