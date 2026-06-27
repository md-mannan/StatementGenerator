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
        Schema::table('client_annexure_cheques', function (Blueprint $table) {
            $table->date('cheque_date')->nullable()->after('month');
        });

        DB::table('client_annexure_cheques')
            ->select(['id', 'year', 'month'])
            ->orderBy('id')
            ->get()
            ->each(function (object $cheque): void {
                DB::table('client_annexure_cheques')
                    ->where('id', $cheque->id)
                    ->update([
                        'cheque_date' => sprintf('%04d-%02d-01', $cheque->year, $cheque->month),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_annexure_cheques', function (Blueprint $table) {
            $table->dropColumn('cheque_date');
        });
    }
};
