<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('wallet_type', 16)->nullable()->after('wallet_address');
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('wallet_type_anvil', 16)->nullable()->after('wallet_address_anvil');
            $table->string('wallet_type_sepolia', 16)->nullable()->after('wallet_address_sepolia');
        });

        Schema::table('invoice_verifiers', function (Blueprint $table) {
            $table->string('wallet_type', 16)->default('wallet')->after('wallet_address');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_verifiers', function (Blueprint $table) {
            $table->dropColumn('wallet_type');
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['wallet_type_anvil', 'wallet_type_sepolia']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('wallet_type');
        });
    }
};
