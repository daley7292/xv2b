<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;


class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $now = time();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
            'iat' => $now,
            'exp' => $now + 6 * 3600
        ], config('app.key'), 'HS256');

        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => $now,
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ]);

        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }


    public static function decryptAuthData($jwt)
    {
        try {
            $cacheKey = 'DECRYPT_AUTH_DATA:' . $jwt;

            if (!Cache::has($cacheKey)) {
                $data = (array) JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) {
                    return false;
                }

                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])->find($data['id']);

                if (!$user) {
                    return false;
                }

                Cache::put($cacheKey, $user->toArray(), 6 * 3600);
            }

            return Cache::get($cacheKey);
        } catch (ExpiredException $e) {
            return false; // Token 已过期
        } catch (SignatureInvalidException $e) {
            return false; // 签名无效
        } catch (\Exception $e) {
            return false; // 其他错误
        }
    }



    private static function checkSession($userId, $session)
    {
        $sessions = (array) Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions)))
            return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array) Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (
            !Cache::put(
                $cacheKey,
                $sessions,
                6 * 3600
            )
        )
            return false;
        return true;
    }

    public function getSessions()
    {
        return (array) Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array) Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        if (
            !Cache::put(
                $cacheKey,
                $sessions,
                6 * 3600
            )
        )
            return false;
        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array) Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }
}
