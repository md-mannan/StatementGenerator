<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_entries', function (Blueprint $table): void {
            $table->index('user_id');
            $table->index(['statement_year', 'statement_month']);
        });

        Schema::table('incoming_statement_entries', function (Blueprint $table): void {
            $table->index('user_id');
            $table->index(['client_id', 'branch_id']);
        });

        Schema::table('client_annexure_entries', function (Blueprint $table): void {
            $table->index('user_id');
            $table->index('client_annexure_cheque_id');
            $table->index(['client_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('statement_entries', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['statement_year', 'statement_month']);
        });

        Schema::table('incoming_statement_entries', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['client_id', 'branch_id']);
        });

        Schema::table('client_annexure_entries', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['client_annexure_cheque_id']);
            $table->dropIndex(['client_id', 'branch_id']);
        });
    }
};
