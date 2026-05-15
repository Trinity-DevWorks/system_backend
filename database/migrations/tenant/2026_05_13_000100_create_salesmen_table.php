<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->string('salesman_code')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');
            $table->string('phone', 32)->nullable()->index();
            $table->string('email')->nullable()->unique();
            $table->text('address')->nullable();
            /** none | percent | fixed */
            $table->string('commission_type', 32)->index();
            $table->decimal('commission_value', 20, 4)->nullable();
            $table->decimal('target_amount', 20, 4)->nullable();
            $table->date('hire_date')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesmen');
    }
};
