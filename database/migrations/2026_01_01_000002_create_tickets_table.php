<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->string('type')->index();
            $table->foreignId('internal_system_id')
                ->nullable()
                ->constrained('internal_systems')
                ->nullOnDelete();
            $table->foreignId('requester_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('lead_engineer_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('priority')->index();
            $table->string('status')->index();

            // Issue-specific fields
            $table->string('environment')->nullable();
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_behavior')->nullable();
            $table->text('actual_behavior')->nullable();
            $table->string('impact_scope')->nullable();

            // Engineering Request-specific fields
            $table->text('business_justification')->nullable();
            $table->text('proposed_functionality')->nullable();
            $table->text('expected_benefits')->nullable();
            $table->date('requested_deadline')->nullable();
            $table->text('review_decision_notes')->nullable();

            // Tracking & SLA fields
            $table->date('target_due_date')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_summary')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
