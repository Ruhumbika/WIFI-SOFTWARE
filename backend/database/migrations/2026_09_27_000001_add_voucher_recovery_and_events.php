<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $t) {
            $t->string('recovery_pin_hash')->nullable();
            $t->timestamp('recovery_pin_created_at')->nullable();
            $t->timestamp('recovery_pin_issued_at')->nullable();
            $t->unsignedInteger('recovery_token_version')->default(0);
            $t->unsignedInteger('transfer_count')->default(0);
            $t->timestamp('compromised_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->index('customer_phone');
        });
        Schema::create('voucher_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $t->string('event_type', 64);
            $t->string('actor_type', 24)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('expected_mac', 17)->nullable();
            $t->string('attempted_mac', 17)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['voucher_id', 'event_type', 'occurred_at']);
        });
        Schema::create('voucher_device_transfer_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $t->string('customer_phone', 20)->nullable();
            $t->string('requested_mac', 17)->nullable();
            $t->string('current_mac', 17)->nullable();
            $t->string('status', 20)->default('pending');
            $t->string('reason', 500)->nullable();
            $t->timestamp('requested_at');
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['voucher_id', 'status']);
        });
        // Durable operation identity lets retries resume after a router response is lost.
        Schema::create('voucher_device_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('action', 20);
            $t->string('state', 32);
            $t->text('target_secret')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('transfer_request_id')->nullable();
            $t->timestamps();
            $t->index(['voucher_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_device_operations');
        Schema::dropIfExists('voucher_device_transfer_requests');
        Schema::dropIfExists('voucher_events');
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropIndex(['customer_phone']);
            $t->dropColumn(['recovery_pin_hash', 'recovery_pin_created_at', 'recovery_pin_issued_at', 'recovery_token_version', 'transfer_count', 'compromised_at', 'claimed_at']);
        });
    }
};
