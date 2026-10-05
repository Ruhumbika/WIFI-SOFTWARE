<?php
namespace App\Http\Controllers;

use App\Models\AdminApiToken;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function catalog(): array
    {
        return ['data' => AdminPermissions::catalog()];
    }

    public function index(Request $request)
    {
        $users = User::select(['id', 'name', 'email', 'is_active', 'is_super_user'])
            ->with('permissions')->orderBy('id')->paginate(25);
        $users->setCollection($users->getCollection()->map(fn ($user) => $user->adminAccess()));
        return response()->json($users)->header('Cache-Control', 'no-store');
    }

    public function show(User $user)
    {
        return response()->json(['user' => $user->adminAccess()])->header('Cache-Control', 'no-store');
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'is_active' => ['sometimes', 'boolean'],
            'is_super_user' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(AdminPermissions::KEYS), Rule::exists('permissions', 'key')],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $user = $this->transaction($request, function () use ($request, $data) {
            User::where('is_super_user', true)->orderBy('id')->lockForUpdate()->get();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->is_super_user, 403);
            $user = new User();
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = Hash::make($data['password']);
            $user->is_active = $data['is_active'] ?? true;
            $user->is_super_user = $data['is_super_user'] ?? false;
            $user->save();
            $this->assign($user, $data['permissions'] ?? []);
            return $user;
        });
        $this->audit($request, $user, 'created');
        return response()->json(['user' => $user->adminAccess()], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate($this->rules($user));
        $user = $this->transaction($request, function () use ($request, $user, $data) {
            // Serialize security updates by locking all Super Users in a stable order.
            $supers = User::where('is_super_user', true)->orderBy('id')->lockForUpdate()->get();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->is_super_user, 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $active = $data['is_active'] ?? $target->is_active;
            $super = $data['is_super_user'] ?? $target->is_super_user;
            if ($target->is_active && $target->is_super_user && (!$active || !$super)
                && $supers->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['is_super_user' => 'The last active Super User must remain active.']);
            }
            foreach (['name', 'email', 'is_active', 'is_super_user'] as $field) {
                if (array_key_exists($field, $data)) $target->$field = $data[$field];
            }
            $sensitive = $target->isDirty(['email', 'is_active', 'is_super_user']) || array_key_exists('permissions', $data);
            $target->save();
            if (array_key_exists('permissions', $data) || $target->is_super_user) $this->assign($target, $data['permissions'] ?? []);
            if ($sensitive) AdminApiToken::where('user_id', $target->id)->delete();
            return $target;
        });
        $this->audit($request, $user, 'updated', array_keys($data));
        return response()->json(['user' => $user->adminAccess()]);
    }

    public function password(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $this->transaction($request, function () use ($request, $user, $data) {
            User::where('is_super_user', true)->orderBy('id')->lockForUpdate()->get();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->is_super_user, 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target->password = Hash::make($data['password']);
            $target->save();
            AdminApiToken::where('user_id', $user->id)->delete();
        });
        $this->audit($request, $user, 'password_reset');
        return response()->json(['message' => 'Password changed. Existing sessions were revoked.']);
    }

    private function transaction(Request $request, \Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        } catch (QueryException) {
            // SQL exceptions may contain password bindings; never report the original exception.
            Log::error('Admin user storage failed', ['actor_id' => $request->user()->id]);
            throw new HttpResponseException(response()->json(['message' => 'User changes could not be saved. Please retry.'], 503));
        }
    }

    private function assign(User $user, array $keys): void
    {
        $user->permissions()->sync($user->is_super_user ? [] : Permission::whereIn('key', $keys)->pluck('id'));
        $user->unsetRelation('permissions');
    }

    private function audit(Request $request, User $user, string $event, array $fields = []): void
    {
        Log::info('Admin user management', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'event' => $event, 'fields' => $fields]);
    }
}
