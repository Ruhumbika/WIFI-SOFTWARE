<?php

namespace App\Http\Controllers;

use App\Models\AdminApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        $plain = Str::random(80);
        AdminApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(12),
        ]);

        return response()->json(['token' => $plain, 'user' => ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email]]);
    }

    public function logout(Request $request)
    {
        $request->attributes->get('admin_api_token')?->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
