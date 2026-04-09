<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('agent_tasks')->nullOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('assigned_role', 50)->nullable();
            $table->enum('status', [
                'pending',
                'in_progress',
                'validating',
                'done',
                'failed',
                'escalated',
            ])->default('pending');
            $table->text('result')->nullable();
            $table->text('validator_notes')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('created_by_role', 50)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['agent_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
    }
};
