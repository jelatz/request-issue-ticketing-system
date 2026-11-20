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
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('target_due_date');
            $table->index(['lead_engineer_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index('internal_system_id');
        });

        Schema::table('ticket_collaborators', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::table('ticket_subtasks', function (Blueprint $table) {
            $table->index(['ticket_id', 'is_completed']);
            $table->index('assigned_engineer_id');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['user_id', 'is_read']);
        });

        Schema::table('ticket_subtasks', function (Blueprint $table) {
            $table->dropIndex(['assigned_engineer_id']);
            $table->dropIndex(['ticket_id', 'is_completed']);
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'created_at']);
        });

        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['ticket_id', 'created_at']);
        });

        Schema::table('ticket_collaborators', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['internal_system_id']);
            $table->dropIndex(['requester_id', 'status']);
            $table->dropIndex(['lead_engineer_id', 'status']);
            $table->dropIndex(['target_due_date']);
            $table->dropIndex(['created_at']);
        });
    }
};
