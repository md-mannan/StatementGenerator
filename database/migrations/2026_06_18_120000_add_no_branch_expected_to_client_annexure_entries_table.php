<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_annexure_entries', function (Blueprint $table): void {
            $table->boolean('no_branch_expected')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('client_annexure_entries', function (Blueprint $table): void {
            $table->dropColumn('no_branch_expected');
        });
    }
};
