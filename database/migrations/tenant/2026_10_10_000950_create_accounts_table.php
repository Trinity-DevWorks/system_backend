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
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
