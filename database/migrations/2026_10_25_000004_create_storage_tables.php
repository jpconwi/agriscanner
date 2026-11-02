<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quota_bytes');
            $table->timestamps();
        });

        // used_bytes counts only confirmed, available files (each logical file once, regardless of replicas).
        // reserved_bytes covers uploads in flight so concurrent requests cannot jointly exceed the quota.
        Schema::create('storage_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('object_key')->unique();
            $table->string('mime', 60);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // pending | available | failed | deleting
            $table->string('status', 20)->default('pending')->index();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // One row per physical copy. Ownership always follows stored_files.user_id.
        Schema::create('stored_file_replicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stored_file_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('object_key');
            // available | failed | deleted
            $table->string('status', 20)->default('available');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['stored_file_id', 'provider']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_file_replicas');
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('storage_usage');
        Schema::dropIfExists('storage_quotas');
    }
};
