<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('approval_status')->nullable()->after('status')->index();
            $table->foreignId('approval_decided_by_id')
                ->nullable()
                ->after('approval_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approval_decided_at')->nullable()->after('approval_decided_by_id');
        });

        DB::table('tickets')
            ->where('type', 'engineering_request')
            ->where('status', 'rejected')
            ->update(['approval_status' => 'rejected']);

        DB::table('tickets')
            ->where('type', 'engineering_request')
            ->where('status', '!=', 'rejected')
            ->update(['approval_status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_decided_by_id');
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_decided_at']);
        });
    }
};
