<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortcut_name', 50);
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('manager_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['name']);
            $table->unique(['shortcut_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
