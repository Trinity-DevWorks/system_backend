<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->foreignUuid('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->string('customer_code')->nullable()->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable()->index();
            /** individual | business */
            $table->string('type', 32)->index();
            /** active | suspended | blacklisted */
            $table->string('status', 32)->default('active')->index();
            $table->text('blacklist_reason')->nullable();
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
        Schema::dropIfExists('customers');
    }
};
