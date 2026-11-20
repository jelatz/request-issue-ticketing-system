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
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });

        $now = now();
        $maxByKey = [];

        foreach (DB::table('tickets')->select('ticket_number')->cursor() as $row) {
            if (preg_match('/^([A-Z]+)-(\d{4})-(\d+)$/', (string) $row->ticket_number, $matches) === 1) {
                $prefix = $matches[1];
                $year = (int) $matches[2];
                $sequence = (int) $matches[3];
                $key = "{$prefix}:{$year}";

                if (! isset($maxByKey[$key]) || $sequence > $maxByKey[$key]['last_number']) {
                    $maxByKey[$key] = [
                        'prefix' => $prefix,
                        'year' => $year,
                        'last_number' => $sequence,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($maxByKey !== []) {
            DB::table('ticket_sequences')->insert(array_values($maxByKey));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_sequences');
    }
};
