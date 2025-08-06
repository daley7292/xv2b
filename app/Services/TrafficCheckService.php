<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;
use App\Services\TelegramService;

class TrafficCheckService
{
    public function checkAndLimitTrialUsersSpeed()
    {
        $tryOutPlanId = (int) config('v2board.try_out_plan_id', 0);
        $todayStart = Carbon::today()->timestamp;
        $now = Carbon::now()->timestamp;
    
        $telegramService = new TelegramService();
    
        $trialUsers = DB::table('v2_user as u')
            ->leftJoin('v2_order as o', function ($join) {
                $join->on('u.id', '=', 'o.user_id')
                     ->where('o.status', 3);
            })
            ->where('u.plan_id', $tryOutPlanId)
            ->whereNull('o.id')
            ->select('u.id', 'u.transfer_enable', 'u.speed_limit')
            ->get();
    
        foreach ($trialUsers as $user) {
            $cacheKey = "trial_speed_limited:{$user->id}:" . date('Y-m-d');
            if (Redis::get($cacheKey)) {
                continue;
            }

            $trafficStat = DB::table('v2_stat_user')
                ->where('user_id', $user->id)
                ->whereBetween('record_at', [$todayStart, $now])
                ->select(DB::raw('SUM(u + d) as total_traffic'))
                ->first();
    
            $totalTraffic = $trafficStat->total_traffic ?? 0;
            $threshold = $user->transfer_enable / 3;
    
            if ($totalTraffic > $threshold) {
                $limitMbps = 30;
    
                DB::table('v2_user')
                    ->where('id', $user->id)
                    ->update(['speed_limit' => $limitMbps]);
    
                Redis::setex($cacheKey, 86400, 1);
    
                $msg = "试用用户 ID: {$user->id}\n"
                     . "当日总流量：" . $this->formatBytesToGB($totalTraffic) . " GB\n"
                     . "超过限制阈值：" . $this->formatBytesToGB($threshold) . " GB\n"
                     . "已限制速度为：{$limitMbps} Mbps";
    
                $telegramService->sendMessageWithAdmin($msg, true);
            }
        }
    }

    private function formatBytesToGB($bytes, $precision = 2)
    {
        return round($bytes / (1024 ** 3), $precision);
    }
}
