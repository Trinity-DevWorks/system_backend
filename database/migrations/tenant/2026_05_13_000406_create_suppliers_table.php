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
            $table->uuid('id')->primary();
            $table->foreignId('supplier_group_id')->nullable()->constrained('supplier_groups')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->string('supplier_code')->nullable()->unique();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_vat_registered')->default(false);
            $table->boolean('is_exempted')->default(false);
            $table->text('exemption_reason')->nullable();
            $table->date('exempted_from')->nullable();
            $table->date('exempted_to')->nullable();
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
