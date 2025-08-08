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
    protected $builder;

    protected $signature = 'reset:traffic';
    protected $description = '流量清空';

    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();

        $this->userService = $userService;

        $this->builder = User::whereNotNull('expired_at')
            ->where('expired_at', '>', time());
    }

    public function handle()
    {
        ini_set('memory_limit', -1);

        $resetMethods = Plan::select(
            DB::raw("GROUP_CONCAT(`id`) as plan_ids"),
            DB::raw("reset_traffic_method as method")
        )
            ->groupBy('reset_traffic_method')
            ->get();

        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod->plan_ids);
            $builder = clone $this->builder;
            $builder->whereIn('plan_id', $planIds);

            $users = $builder->get()->filter(function ($user) use ($resetMethod) {
                if ($resetMethod->method === null) {
                    $user->plan->reset_traffic_method = (int) config('v2board.reset_traffic_method', 0);
                }
                return $this->userService->isResetDay($user);
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
            $plan = Plan::find($user->plan_id);
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
