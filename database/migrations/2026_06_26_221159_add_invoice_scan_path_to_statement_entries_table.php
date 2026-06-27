<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_entries', function (Blueprint $table) {
            $table->string('invoice_scan_path')->nullable()->after('no_bill_expected');
        });
    }

    public function down(): void
    {
        Schema::table('statement_entries', function (Blueprint $table) {
            $table->dropColumn('invoice_scan_path');
        });
    }
};
