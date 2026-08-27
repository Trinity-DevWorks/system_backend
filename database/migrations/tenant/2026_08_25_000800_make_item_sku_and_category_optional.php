<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->change();
            $table->unsignedBigInteger('category_id')->nullable()->change();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('sku', 100)->nullable(false)->change();
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
    }
};
