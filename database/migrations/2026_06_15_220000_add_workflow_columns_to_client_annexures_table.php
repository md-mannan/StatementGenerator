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
        Schema::table('client_annexures', function (Blueprint $table) {
            $table->boolean('review_completed')->default(false)->after('rebate');
            $table->boolean('payment_saved')->default(false)->after('review_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_annexures', function (Blueprint $table) {
            $table->dropColumn(['review_completed', 'payment_saved']);
        });
    }
};
