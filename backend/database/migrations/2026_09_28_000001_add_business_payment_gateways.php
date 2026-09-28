<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->string('name'); $t->string('code', 50)->unique();
            $t->string('status', 20)->default('active'); $t->timestamps();
        });
        Schema::create('payment_gateway_accounts', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('provider', 30)->index(); $t->text('api_key_encrypted')->nullable();
            $t->text('webhook_secret_encrypted')->nullable(); $t->string('base_url'); $t->string('webhook_url', 500);
            $t->string('payment_profile_id')->nullable(); $t->boolean('active')->default(false); $t->timestamps();
            $t->index(['business_id', 'provider', 'active']);
        });
        Schema::table('orders', function (Blueprint $t) { $t->foreignId('business_id')->nullable()->constrained()->restrictOnDelete(); });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('payment_gateway_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('session_reference')->nullable(); $t->text('checkout_url')->nullable();
            $t->unique(['payment_gateway_account_id', 'session_reference'], 'payments_gateway_session_unique');
            $t->dropUnique('payments_reference_unique');
            $t->unique(['provider', 'payment_gateway_account_id', 'reference'], 'payments_gateway_reference_unique');
        });
        // Existing records belong to this installation; no merchant credentials are invented or imported.
        $id = DB::table('businesses')->insertGetId(['uuid'=>(string) Str::uuid(), 'name'=>'RJAY WiFi', 'code'=>'RJAY_WIFI', 'status'=>'active', 'created_at'=>now(), 'updated_at'=>now()]);
        DB::table('orders')->whereNull('business_id')->update(['business_id'=>$id]);
        DB::table('payments')->whereNull('business_id')->update(['business_id'=>$id]);
    }
    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('payment_gateway_account_id')->exists()) {
            throw new RuntimeException('Merchant payments exist. Restore a reviewed backup instead of dropping merchant mappings.');
        }
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique('payments_gateway_session_unique'); $t->dropUnique('payments_gateway_reference_unique');
            $t->dropConstrainedForeignId('payment_gateway_account_id'); $t->dropConstrainedForeignId('business_id');
            $t->dropColumn(['session_reference','checkout_url']); $t->unique('reference');
        });
        Schema::table('orders', function (Blueprint $t) { $t->dropConstrainedForeignId('business_id'); });
        Schema::dropIfExists('payment_gateway_accounts'); Schema::dropIfExists('businesses');
    }
};
