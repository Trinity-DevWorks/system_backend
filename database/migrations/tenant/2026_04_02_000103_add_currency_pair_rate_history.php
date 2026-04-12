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
        Schema::table('currency_pair_rates', function (Blueprint $table) {
            $table->timestamp('effective_from')->nullable()->after('rate');
        });

        foreach (DB::table('currency_pair_rates')->select(['id', 'created_at'])->get() as $row) {
            DB::table('currency_pair_rates')
                ->where('id', $row->id)
                ->update(['effective_from' => $row->created_at]);
        }

        Schema::create('currency_pair_rate_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->foreignId('to_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('rate', 20, 6);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to');
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->index(['from_currency_id', 'to_currency_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_pair_rate_history');

        Schema::table('currency_pair_rates', function (Blueprint $table) {
            $table->dropColumn('effective_from');
        });
    }
};
