<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login via API and return Sanctum token.
     * This is a public endpoint (no authentication required).
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
            'business_id' => 'nullable|uuid',
        ]);

        $user = User::withoutGlobalScopes()->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status === 'Inactive') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact administrator.',
            ], 403);
        }

        $businesses=$user->businesses()->wherePivot('status','Active')->where('businesses.status','Active')->orderBy('name')->get(['businesses.id','businesses.name','businesses.slug']);
        $business=$validated['business_id'] ?? ($businesses->count()===1 ? $businesses->first()->id : null);
        if (!$business || !$businesses->contains('id',$business)) return response()->json(['message'=>'Select an active business.','business_selection_required'=>true,'businesses'=>$businesses],409);

        // Create token (device_name is useful for managing multiple sessions)
        $deviceName = $validated['device_name'] ?? $request->userAgent() ?? 'Android App';
        $issued = $user->createToken($deviceName);
        $issued->accessToken->forceFill(['business_id'=>$business])->save();
        $token = $issued->plainTextToken;
        \App\Support\PlatformAudit::record('identity.api_login',$user,$business);

        return response()->json([
            'message' => 'Login successful',
            'business' => $businesses->firstWhere('id',$business)->only(['id','name','slug']),
            'token' => $token,
            'user' => $user->only('id', 'name', 'email'),
        ]);
    }

    /**
     * Logout and revoke current token.
     * Requires valid Sanctum token.
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        \App\Support\PlatformAudit::record('identity.api_logout', $user);
        if ($user->currentAccessToken() instanceof \App\Models\BusinessToken) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current authenticated user (already exists at /api/user)
     * This method can be used if you want a dedicated /api/me endpoint.
     */
    public function me(Request $request)
    {
        return response()->json(
            $request->user()->only('id', 'name', 'email', 'branch_id', 'active_branch_id')
        );
    }
}
