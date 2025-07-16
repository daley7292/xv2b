<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
        }
        $filteredServers = collect($servers)->map(function ($server) {
            return [
            'name' => $server['name'] ?? null,
            'tags' => $server['tags'] ?? [],
            'rate' => $server['rate'] ?? 1,
            'sort' => $server['sort'] ?? 0,
            'type' => $server['type'] ?? null,
            'created_at' => $server['created_at'] ?? null,
            'updated_at' => $server['updated_at'] ?? null,
            ];
        })->toArray();
        $eTag = sha1(json_encode(array_column($filteredServers, 'cache_key')));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            abort(304);
        }
        return response([
            'data' => $filteredServers
        ])->header('ETag', "\"{$eTag}\"");
    }
}
