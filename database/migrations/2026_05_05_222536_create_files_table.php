<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores files attached to presets or conversations in DepthNet.
 *
 * Supports two storage drivers:
 *   - laravel: file lives in Laravel storage (read-only for the agent)
 *   - sandbox: file lives in the preset's sandbox home directory (full agent access)
 *
 * Storage paths:
 *   laravel  → storage/app/presets/{preset_id}/files/{filename}
 *   sandbox  → /shared/{sandbox_name}/workspace/presets/{preset_id}/files/{filename}
 *              /shared/{sandbox_name}/projects/{project_slug}/files/{filename}
 *
 * Scope controls visibility across presets:
 *   private → only the owning preset
 *   global  → all presets
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // Owning preset — nullable for globally scoped files
            $table->foreignId('preset_id')
                ->nullable()
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // Original filename as uploaded or created
            $table->string('original_name', 512);

            // MIME type, e.g. application/pdf, text/plain, application/vnd.ms-excel
            $table->string('mime_type', 128);

            // Where the file physically lives
            $table->enum('storage_driver', ['laravel', 'sandbox'])->default('laravel');

            // Relative path within the driver's root
            // laravel: presets/3/files/report.pdf
            // sandbox: workspace/presets/3/files/report.pdf
            //          projects/my-project/files/report.pdf
            $table->string('storage_path', 1024);

            // File size in bytes
            $table->unsignedBigInteger('size')->default(0);

            // Who can see this file
            $table->enum('scope', ['private', 'global'])->default('private');

            // Processing pipeline status
            $table->enum('processing_status', ['pending', 'processing', 'processed', 'failed'])
                ->default('pending');

            // Extra data: page count, language, sheet names, encoding, error message, etc.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['preset_id', 'scope'], 'idx_files_preset_scope');
            $table->index(['preset_id', 'processing_status'], 'idx_files_preset_status');
            $table->index('storage_driver', 'idx_files_driver');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
