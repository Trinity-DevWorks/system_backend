<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            /** asset | liability | equity | revenue | expense; null on groups whose children mix types */
            $table->string('type', 16)->nullable();
            /** debit | credit */
            $table->string('normal_balance', 8)->nullable();
            $table->boolean('is_group')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('parent_id');
            $table->index(['type', 'is_active']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('currency_id')->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
        Schema::dropIfExists('accounts');
    }
};
