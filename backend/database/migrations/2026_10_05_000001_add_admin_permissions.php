<?php
use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_super_user')->default(false);
        });
        // All users predating this feature historically had unrestricted admin access.
        DB::table('users')->update(['is_active' => true, 'is_super_user' => true]);
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'permission_id']);
        });
        foreach (AdminPermissions::catalog() as $permission) {
            DB::table('permissions')->updateOrInsert(['key' => $permission['key']], $permission + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('permissions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_active', 'is_super_user']));
    }
};
