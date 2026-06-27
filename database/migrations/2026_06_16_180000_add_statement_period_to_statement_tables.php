<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('statement_year')->nullable()->after('transaction_date');
            $table->unsignedTinyInteger('statement_month')->nullable()->after('statement_year');

            $table->index(['branch_id', 'statement_year', 'statement_month'], 'statement_entries_branch_period_index');
        });

        Schema::table('incoming_statement_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('statement_year')->nullable()->after('transaction_date');
            $table->unsignedTinyInteger('statement_month')->nullable()->after('statement_year');

            $table->index(
                ['client_id', 'statement_year', 'statement_month'],
                'incoming_statement_entries_client_period_index',
            );
        });

        DB::table('statement_entries')->update([
            'statement_year' => $this->yearExpression('transaction_date'),
            'statement_month' => $this->monthExpression('transaction_date'),
        ]);

        DB::table('incoming_statement_entries')->update([
            'statement_year' => $this->yearExpression('transaction_date'),
            'statement_month' => $this->monthExpression('transaction_date'),
        ]);
    }

    private function yearExpression(string $column): \Illuminate\Contracts\Database\Query\Expression
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlite' => DB::raw("CAST(strftime('%Y', {$column}) AS INTEGER)"),
            default => DB::raw("YEAR({$column})"),
        };
    }

    private function monthExpression(string $column): \Illuminate\Contracts\Database\Query\Expression
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlite' => DB::raw("CAST(strftime('%m', {$column}) AS INTEGER)"),
            default => DB::raw("MONTH({$column})"),
        };
    }

    public function down(): void
    {
        Schema::table('statement_entries', function (Blueprint $table) {
            $table->dropIndex('statement_entries_branch_period_index');
            $table->dropColumn(['statement_year', 'statement_month']);
        });

        Schema::table('incoming_statement_entries', function (Blueprint $table) {
            $table->dropIndex('incoming_statement_entries_client_period_index');
            $table->dropColumn(['statement_year', 'statement_month']);
        });
    }
};
