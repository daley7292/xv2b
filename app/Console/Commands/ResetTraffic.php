<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;
use App\Services\UserService;

class ResetTraffic extends Command
{
    protected $signature = 'reset:traffic';
    protected $description = '流量清空';

    protected $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();

        $this->userService = $userService;
    }

    public function handle()
    {
        ini_set('memory_limit', -1);

        $builder = User::whereNotNull('expired_at')->where('expired_at', '>', time());
        $resetMethods = Plan::selectRaw('GROUP_CONCAT(id) as plan_ids, reset_traffic_method as method')
            ->groupBy('reset_traffic_method')
            ->get();

        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod->plan_ids);
            $usersQuery = (clone $builder)->whereIn('plan_id', $planIds);

            $users = $usersQuery->get()->filter(function ($user) use ($resetMethod) {
                $method = $resetMethod->method;

                if ($method === null && isset($user->plan)) {
                    $method = $user->plan->reset_traffic_method;
                }

                if ($method === null) {
                    $method = (int) config('v2board.reset_traffic_method', 0);
                }

                if ($method === 2) { // 不重置
                    return false;
                }

                return $this->userService->isResetDay($user, (int)$method);
            });

            if ($users->isNotEmpty()) {
                $this->retryTransaction(function () use ($users) {
                    $this->resetUserTraffic($users);
                });
            }
        }
    }

    private function resetUserTraffic($users)
    {
        foreach ($users as $user) {
            if (!isset($user->plan)) {
                if ($user->plan_id === null) {
                    continue;
                }
                $plan = Plan::find($user->plan_id);
            } else {
                $plan = $user->plan;
            }

            if (!$plan || $plan->transfer_enable === null) {
                continue;
            }

            $user->update([
                'u' => 0,
                'd' => 0,
                'transfer_enable' => $plan->transfer_enable * 1024 * 1024 * 1024,
            ]);
        }
    }

    private function retryTransaction($callback)
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                DB::transaction($callback);
                return;
            } catch (\Exception $e) {
                $attempts++;

                if (
                    $attempts >= $maxAttempts || (
                        strpos($e->getMessage(), '40001') === false &&
                        strpos(strtolower($e->getMessage()), 'deadlock') === false
                    )
                ) {
                    (new TelegramService())->sendMessageWithAdmin(
                        now()->format('Y/m/d H:i:s') . ' 用户流量重置失败：' . $e->getMessage()
                    );
                    abort(500, '用户流量重置失败：' . $e->getMessage());
                }

                sleep(5);
            }
        }
    }
}
