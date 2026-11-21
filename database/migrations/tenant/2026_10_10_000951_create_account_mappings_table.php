<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('role', 64)->unique();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
