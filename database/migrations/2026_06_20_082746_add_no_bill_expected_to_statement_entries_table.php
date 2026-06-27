<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_entries', function (Blueprint $table): void {
            $table->boolean('no_bill_expected')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('statement_entries', function (Blueprint $table): void {
            $table->dropColumn('no_bill_expected');
        });
    }
};
