<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('currency_id')->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
