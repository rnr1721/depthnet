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
        Schema::create('sandbox_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id')->unique()->index();
            $table->string('type'); // create, destroy, reset, cleanup
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->string('sandbox_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->json('metadata')->nullable();
            $table->text('message')->nullable();
            $table->json('logs')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandbox_operations');
    }
};
