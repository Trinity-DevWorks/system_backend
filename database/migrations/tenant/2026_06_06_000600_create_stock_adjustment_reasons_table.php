<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('direction', 16);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        $now = now();
        DB::table('stock_adjustment_reasons')->insert([
            ['code' => 'COUNT_UP', 'name' => 'Stock count increase', 'direction' => 'increase', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COUNT_DOWN', 'name' => 'Stock count decrease', 'direction' => 'decrease', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DAMAGE', 'name' => 'Damage', 'direction' => 'decrease', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EXPIRED', 'name' => 'Expired', 'direction' => 'decrease', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FOUND', 'name' => 'Found stock', 'direction' => 'increase', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OTHER', 'name' => 'Other', 'direction' => 'both', 'is_active' => true, 'is_system' => true, 'notes' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_reasons');
    }
};
