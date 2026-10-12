<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_consumers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('return_origin', 255);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('payments', function (Blueprint $table) {
            // External payments never require a WiFi order, plan, or voucher.
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->foreignId('payment_consumer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_return_path', 500)->nullable();
            $table->string('source_kind', 20)->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->unsignedInteger('source_attempt')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->text('customer_phone_encrypted')->nullable();
            $table->unique(['payment_consumer_id', 'source_reference', 'source_attempt'], 'payments_consumer_attempt_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('payment_consumer_id')->exists()) {
            throw new RuntimeException('External payment records must be retained; rollback is blocked.');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_consumer_attempt_unique');
            $table->dropConstrainedForeignId('payment_consumer_id');
            $table->dropColumn(['source_return_path', 'source_kind', 'source_reference', 'source_attempt', 'request_fingerprint', 'customer_phone_encrypted']);
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
        Schema::dropIfExists('payment_consumers');
    }
};
