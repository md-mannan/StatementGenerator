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
        Schema::create('client_annexure_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('check_number', 20)->default('');
            $table->decimal('amount', 15, 3)->default(0);
            $table->decimal('rebate', 15, 3)->default(0);
            $table->boolean('review_completed')->default(false);
            $table->boolean('payment_saved')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_annexure_cheques');
    }
};
