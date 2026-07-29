<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agent_contracts — per-preset metabolism contracts.
 *
 * Flat scalars that the UI filters on and the engine reads cheaply live in
 * columns (preset_id, name, form, status, vital, source, suspend_when,
 * confidence). Nested structures that are only ever read whole live in JSON
 * (trigger, action, history).
 *
 * `match` is NOT a column: it is a reserved word in MySQL, and per the
 * canonical contract shape it lives inside `trigger` anyway.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_contracts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('preset_id');

            $table->string('name');
            $table->string('form', 16);            // ContractForm value
            $table->string('status', 16)           // ContractStatus value
                  ->default('hypothesis');
            $table->boolean('vital')->default(false);

            $table->string('source')->default('engine');
            $table->string('suspend_when')->nullable();
            $table->float('confidence')->default(1.0);

            $table->boolean('triggered')->default(false);
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();

            $table->json('trigger');               // form-specific; holds `match` for THR_T/THR_C
            $table->json('action');
            $table->json('history')->nullable();   // status-change log, read with the contract

            $table->timestamps();

            $table->foreign('preset_id')
                  ->references('id')->on('ai_presets')
                  ->onDelete('cascade');

            // One contract name per preset.
            $table->unique(['preset_id', 'name']);

            // The engine's hot path: "active contracts for this preset".
            $table->index(['preset_id', 'status', 'triggered']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_contracts');
    }
};
