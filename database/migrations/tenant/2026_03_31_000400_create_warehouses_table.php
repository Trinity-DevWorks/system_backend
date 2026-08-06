<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortcut_name', 50);
            /** Built-in for now: branch | central | distribution. Custom types later via catalog. */
            $table->string('type', 32)->default('central');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_default_production')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_storage')->default(false);
            $table->timestamps();

            $table->unique(['name']);
            $table->unique(['shortcut_name']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
