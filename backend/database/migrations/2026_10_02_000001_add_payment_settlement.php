<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('gross_amount')->nullable();
            $t->unsignedBigInteger('fee_amount')->nullable();
            $t->unsignedBigInteger('net_amount')->nullable();
            $t->string('settlement_currency', 3)->nullable();
            $t->index(['status', 'completed_at'], 'payments_status_completed_index');
            $t->index(['status', 'updated_at'], 'payments_status_updated_index');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->index(['plan_id', 'id'], 'orders_plan_id_index'));
    }
    public function down(): void {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex('payments_status_completed_index'); $t->dropIndex('payments_status_updated_index');
            $t->dropColumn(['gross_amount','fee_amount','net_amount','settlement_currency']);
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_plan_id_index'));
    }
};
