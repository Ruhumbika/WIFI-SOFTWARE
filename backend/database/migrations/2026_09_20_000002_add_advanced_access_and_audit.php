<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('access_advanced_network_tools')->default(true);
        });
        \Illuminate\Support\Facades\DB::table('users')->update(['access_advanced_network_tools' => true]);
        Schema::create('advanced_router_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('router_address', 255)->nullable();
            $table->string('action', 64);
            $table->string('client_platform', 32);
            $table->string('result', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_router_audit_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('access_advanced_network_tools'));
    }
};
