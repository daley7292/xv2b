<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;

class ResetTraffic extends Command
{
    protected $builder;

    protected $signature = 'reset:traffic';
    protected $description = '流量清空';

    public function __construct()
    {
        parent::__construct();
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
        ->get()
        ->toArray();

        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod['plan_ids']);
            $method = $resetMethod['method'];
            $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);

            if ($method === null) {
                $method = (int)config('v2board.reset_traffic_method', 0);
            }

            switch ((int)$method) {
                case 0:
                    $this->resetByMonthFirstDay($builder);
                    break;
                case 1:
                    $this->resetByExpireDay($builder);
                    break;
                case 2:
                    break;
                case 3:
                    $this->resetByYearFirstDay($builder);
                    break;
                case 4:
                    $this->resetByExpireYear($builder);
                    break;
            }
        }
    }

    private function resetByExpireYear($builder): void
    {
        $today = date('m-d');

        $users = $builder->get()->filter(function ($user) use ($today) {
            return date('m-d', $user->expired_at) === $today;
        });

        $this->retryTransaction(function () use ($users) {
            $this->resetUserTraffic($users);
        });
    }

    private function resetByYearFirstDay($builder): void
    {
        if (date('md') === '0101') {
            $users = $builder->get();
            $this->retryTransaction(function () use ($users) {
                $this->resetUserTraffic($users);
            });
        }
    }

    private function resetByMonthFirstDay($builder): void
    {
        if (date('d') === '01') {
            $users = $builder->get();
            $this->retryTransaction(function () use ($users) {
                $this->resetUserTraffic($users);
            });
        }
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('t');
        $today = date('d');

        $users = $builder->get()->filter(function ($user) use ($today, $lastDay) {
            $expireDay = date('d', $user->expired_at);
            return (
                ($expireDay === $today || ($today === $lastDay && $expireDay >= $lastDay)) &&
                (time() < $user->expired_at - 2160000)
            );
        });

        $this->retryTransaction(function () use ($users) {
            $this->resetUserTraffic($users);
        });
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
                'transfer_enable' => $plan->transfer_enable * 1024 * 1024 * 1024
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

                if ($attempts >= $maxAttempts || (
                    strpos($e->getMessage(), '40001') === false &&
                    strpos(strtolower($e->getMessage()), 'deadlock') === false
                )) {
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
