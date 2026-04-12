<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_group_id')->nullable()->constrained('supplier_groups')->nullOnDelete();
            $table->string('supplier_code')->nullable()->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable()->index();
            $table->decimal('credit_limit', 20, 4)->default(0);
            /** Snapshot at creation / migration only; live balance comes from ledger */
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_vat_registered')->default(false);
            $table->string('vat_number', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
