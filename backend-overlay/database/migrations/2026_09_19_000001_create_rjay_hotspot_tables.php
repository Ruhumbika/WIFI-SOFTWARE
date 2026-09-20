<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_api_tokens', function(Blueprint $t){
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash',64)->unique(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('plans', function(Blueprint $t){
            $t->id(); $t->uuid('uuid')->unique(); $t->string('name'); $t->string('code',30)->unique();
            $t->text('description')->nullable(); $t->unsignedBigInteger('price'); $t->string('currency',3)->default('TZS');
            $t->unsignedInteger('duration_seconds'); $t->string('rate_limit',30); $t->unsignedBigInteger('data_limit_bytes')->nullable();
            $t->string('mikrotik_profile_name')->unique(); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('orders', function(Blueprint $t){
            $t->id(); $t->uuid('uuid')->unique(); $t->string('order_number')->unique(); $t->foreignId('plan_id')->constrained();
            $t->string('customer_phone',20); $t->string('customer_name')->nullable(); $t->string('customer_email')->nullable();
            $t->string('device_mac',17)->nullable(); $t->unsignedBigInteger('amount'); $t->string('currency',3)->default('TZS');
            $t->string('status',30)->index(); $t->timestamp('paid_at')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->text('failed_reason')->nullable(); $t->timestamps();
        });
        Schema::create('payments', function(Blueprint $t){
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('provider')->default('snippe'); $t->string('reference')->nullable()->unique(); $t->string('external_reference')->nullable();
            $t->string('status',30)->index(); $t->unsignedBigInteger('amount'); $t->string('currency',3)->default('TZS');
            $t->string('idempotency_key',30)->unique(); $t->json('provider_payload')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->text('failed_reason')->nullable(); $t->timestamps();
        });
        Schema::create('payment_events', function(Blueprint $t){
            $t->id(); $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); $t->string('event_id')->unique();
            $t->string('event_type'); $t->string('reference')->nullable()->index(); $t->json('payload');
            $t->timestamp('processed_at')->nullable(); $t->timestamps();
        });
        Schema::create('vouchers', function(Blueprint $t){
            $t->id(); $t->uuid('uuid')->unique(); $t->string('code')->unique(); $t->text('secret'); $t->foreignId('plan_id')->constrained();
            $t->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete(); $t->string('customer_phone',20)->nullable();
            $t->string('device_mac',17)->nullable()->index(); $t->string('status',30)->index(); $t->string('mikrotik_id')->nullable();
            $t->timestamp('activated_at')->nullable(); $t->timestamp('expires_at')->nullable()->index(); $t->timestamp('provisioned_at')->nullable();
            $t->timestamp('last_synced_at')->nullable(); $t->text('provision_error')->nullable(); $t->timestamps();
        });
        Schema::create('hotspot_sessions', function(Blueprint $t){
            $t->id(); $t->foreignId('voucher_id')->constrained()->cascadeOnDelete(); $t->string('mikrotik_id')->unique();
            $t->string('mac_address',17); $t->string('ip_address')->nullable(); $t->string('login_by')->nullable();
            $t->timestamp('started_at'); $t->timestamp('last_seen_at')->index(); $t->timestamp('ended_at')->nullable();
            $t->unsignedBigInteger('bytes_in')->default(0); $t->unsignedBigInteger('bytes_out')->default(0); $t->string('uptime')->nullable(); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('hotspot_sessions'); Schema::dropIfExists('vouchers'); Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments'); Schema::dropIfExists('orders'); Schema::dropIfExists('plans'); Schema::dropIfExists('admin_api_tokens');
    }
};
