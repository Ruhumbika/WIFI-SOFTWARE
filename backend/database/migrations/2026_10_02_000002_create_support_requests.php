<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('support_requests', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique();
            $t->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('customer_phone',20)->nullable(); $t->string('device_mac',17)->nullable();
            $t->string('connection_state',30)->nullable(); $t->json('technical_snapshot')->nullable();
            $t->string('status',20)->default('open'); $t->string('open_key',64)->nullable()->unique();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable(); $t->timestamps();
            $t->index(['status','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('support_requests'); }
};
