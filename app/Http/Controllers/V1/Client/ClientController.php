<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use App\Models\SubscribeLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;

        $ip = $request->getClientIp();
        $location = $this->getLocationFromIp($ip);

        SubscribeLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
            'as' => $location['as'] ?? null,
            'isp' => $location['isp'] ?? null,
            'country' => $location['country'] ?? null,
            'city' => $location['city'] ?? null,
            'user_agent' => $request->userAgent(),
            'created_at' => now()
        ]);
        $userAgent = strtolower($request->userAgent() ?? '');
        $blockedKeywords = ["mail", "qq", "wechat"];
        foreach ($blockedKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                \Log::warning("检测到非法访问关键字: {$keyword}, 用户ID: {$user->id}, UserAgent: {$userAgent}");
                $user->token = Helper::guid();
                $user->uuid = Helper::guid(true);
                $user->save();
                abort(401, '非法访问,已重置安全信息');
            }
        }
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $servers = $this->filterServers($servers, $request);
            $this->replaceServerHostByUaRule($servers, $request->userAgent() ?? '');
            $servers = array_values($servers);
            if ($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0]))
            return;
        if (!(int) config('v2board.show_info_to_server_enable', 0))
            return;
        $userid = $user['id'];
        $url = config('v2board.app_url');
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余:{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "到期:{$expiredDate};剩余:{$remainingTraffic}",
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "{$userid};官网:{$url}",
        ]));
    }

    private function filterServers(&$servers, Request $request)
    {
        // 获取输入
        $include = $request->input('include');
        $exclude = $request->input('exclude');

        // 将输入字符串转换为数组
        $includeArray = preg_split('/[,|]/', $include, -1, PREG_SPLIT_NO_EMPTY);
        $excludeArray = preg_split('/[,|]/', $exclude, -1, PREG_SPLIT_NO_EMPTY);

        // 过滤 servers 数组
        $servers = array_filter($servers, function ($item) use ($includeArray, $excludeArray) {
            // 检查是否包含任何 include 词
            $includeMatch = empty($includeArray) || array_reduce($includeArray, function ($carry, $word) use ($item) {
                return $carry || (stripos($item['name'], $word) !== false);
            }, false);

            // 检查是否不包含所有 exclude 词
            $excludeMatch = empty($excludeArray) || array_reduce($excludeArray, function ($carry, $word) use ($item) {
                return $carry && (stripos($item['name'], $word) === false);
            }, true);

            return $includeMatch && $excludeMatch;
        });
        return $servers;
    }

    public function getuuidSubscribe(Request $request)
    {
        $user = User::where([
            'email' => $request->query('email'),
            'uuid' => $request->query('uuid')
        ])->first();

        if (!$user) {
            return response()->json([
                'message' => '用户不存在'
            ], 404);
        }
        $user = User::where('id', $user->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user['token']}");
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        return response([
            'data' => $user
        ]);
    }
    public static function getClientIp()
    {
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    private function getLocationFromIp(string $ip): array 
    {
        // IP格式验证
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            \Log::warning("无效IP地址", ['ip' => $ip]);
            return $this->getEmptyLocation();
        }
        
        $cacheKey = "IP_GEO_DATA:{$ip}";  // 与第一个函数使用相同的缓存键
        
        // 尝试从缓存获取
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                // 如果是API数据，解析返回
                if (is_array($cached) && !empty($cached)) {
                    return $this->parseLocationData($cached);
                }
                // 如果是空数据（之前失败的结果），返回空位置
                if (is_array($cached) && empty($cached)) {
                    return $this->getEmptyLocation();
                }
            }
        } catch (\Exception $e) {
            \Log::warning("缓存读取失败", ['ip' => $ip, 'error' => $e->getMessage()]);
        }
        
        // 请求外部API
        try {
            $response = Http::timeout(5)
                ->retry(2, 1000)
                ->get("https://ip.bt3.one/{$ip}");
                
            if (!$response->successful()) {
                \Log::warning("IP归属地API请求失败", [
                    'ip' => $ip,
                    'status' => $response->status()
                ]);
                // 缓存失败结果5分钟
                Cache::put($cacheKey, [], 5);
                return $this->getEmptyLocation();
            }
            
            $data = $response->json();
            if (!is_array($data)) {
                \Log::warning("IP归属地API返回数据格式错误", ['ip' => $ip]);
                Cache::put($cacheKey, [], 5);
                return $this->getEmptyLocation();
            }
            
            // 缓存API原始数据24小时
            Cache::put($cacheKey, $data, 1440);
            
            // 解析并返回标准化数据
            return $this->parseLocationData($data);
            
        } catch (\Exception $e) {
            \Log::error("IP归属地获取异常", [
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // 缓存失败结果5分钟
            Cache::put($cacheKey, [], 5);
            return $this->getEmptyLocation();
        }
    }
    
    private function parseLocationData(array $data): array
    {
        return [
            'as' => $data['as']['number'] ?? null,
            'isp' => $data['as']['name'] ?? null,
            'country' => $data['country']['name'] ?? null,
            'city' => !empty($data['regions']) 
                ? implode(', ', array_filter($data['regions'])) 
                : null,
        ];
    }
        
    private function getEmptyLocation(): array
    {
        return [
            'as' => null,
            'isp' => null,
            'country' => null,
            'city' => null
        ];
    }
    private function replaceServerHostByUaRule(array &$servers, string $userAgent)
    {
        $uaRules = config('v2board.ua_rule', '');
        $uaRules = str_replace(["\r", "\n"], '', $uaRules); // 移除换行符
        $uaRuleLines = explode(';', $uaRules); // 分号分割

        $userAgent = strtolower($userAgent);

        foreach ($uaRuleLines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 3) {
                continue;
            }

            [$uaKeyword, $nameKeyword, $newHost] = $parts;

            if (strpos($userAgent, strtolower($uaKeyword)) !== false) {
                foreach ($servers as &$server) {
                    if (isset($server['name']) && stripos($server['name'], $nameKeyword) !== false) {
                        if (isset($server['host'])) {
                            $server['host'] = $newHost;
                        }
                    }
                }
                break;
            }
        }
    }
}
