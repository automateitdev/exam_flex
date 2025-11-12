<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExamService
{
    public function authenticateRequest(HttpRequest $request)
    {
        $auth = $request->header('Authorization');

        // Log::channel('pay_flow')->info('Authorization header:', ['auth' => $auth ?? 'none']);

        if (!$auth || !Str::startsWith($auth, 'Basic ')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $decoded = base64_decode(Str::replaceFirst('Basic ', '', $auth));
        [$username, $password] = explode(':', $decoded, 2);

        $client = DB::table('client_domains')
            ->where('username', $username)
            ->first();

        if (!$client || !Hash::check($password, $client->password_hash)) {
            Log::channel('pay_flow')->error("Exit: Authentication failed!");
            return response()->json(['error' => 'Invalid credentials'], 403);
        }

        // Authenticated successfully
        return $client;
    }
}
