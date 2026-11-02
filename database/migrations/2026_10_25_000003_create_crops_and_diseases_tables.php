<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('scientific_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('diseases', function (Blueprint $table) {
            $table->id();
            // Deleting a crop is blocked while diseases reference it.
            $table->foreignId('crop_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('moderate');
            $table->text('follow_up')->nullable();
            $table->timestamps();
            $table->unique(['crop_id', 'slug']);
            $table->index('slug');
        });

        // kind: symptom | factor
        Schema::create('disease_symptoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disease_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('symptom');
            $table->text('text');
            $table->unsignedSmallInteger('position')->default(0);
            $table->index(['disease_id', 'kind', 'position']);
        });

        // kind: treatment | prevention. Dosages only appear if an admin entered a verified one.
        Schema::create('disease_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disease_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('treatment');
            $table->text('text');
            $table->unsignedSmallInteger('position')->default(0);
            $table->index(['disease_id', 'kind', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disease_treatments');
        Schema::dropIfExists('disease_symptoms');
        Schema::dropIfExists('diseases');
        Schema::dropIfExists('crops');
    }
};
