<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;

class ResetTraffic extends Command
{
    protected $signature = 'reset:traffic {--dry-run : 预览模式} {--batch-size=1000 : 批处理大小}';
    protected $description = '流量清空';

    private $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    public function handle()
    {
        ini_set('memory_limit', -1);
        
        $isDryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        
        if ($isDryRun) {
            $this->info('🔍 预览模式');
        }

        $this->info('开始执行流量重置...');
        $startTime = microtime(true);

        try {
            $resetCount = $this->processUsers($isDryRun, $batchSize);
            
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("完成！共处理 {$resetCount} 个用户，耗时 {$executionTime} 秒");
            
        } catch (\Exception $e) {
            $this->error("执行失败: " . $e->getMessage());
            $this->sendTelegramNotification("流量重置失败：" . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function processUsers(bool $isDryRun, int $batchSize): int
    {
        $resetCount = 0;
        $batchNumber = 0;
        
        // 分批处理用户，避免内存溢出
        User::with('plan')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', time())
            ->whereNotNull('plan_id')
            ->chunk($batchSize, function ($users) use (&$resetCount, &$batchNumber, $isDryRun, $batchSize) {
                $batchNumber++;
                
                $usersToReset = [];
               	
                foreach ($users as $user) {
                    $resetDay = $this->userService->getResetDay($user);
                    if ($resetDay === 0) {  // 今天重置
                        $usersToReset[] = $user;
                    }
                }
                
                if (empty($usersToReset)) {
                    $this->line("批次 {$batchNumber}: 检查了 {$users->count()} 个用户，无需重置");
                    return true; // 继续下一批
                }
                
                $this->info("批次 {$batchNumber}: 找到 " . count($usersToReset) . " 个用户需要重置 (检查了 {$users->count()} 个用户)");
                
                // 打印套餐信息
                $this->printPlanInfo($usersToReset);
                
                if (!$isDryRun) {
                    $this->resetUsers($usersToReset);
                }
                
                $resetCount += count($usersToReset);
                return true;
            });
            
        return $resetCount;
    }

    private function resetUsers(array $users): void
    {
        $this->retryTransaction(function () use ($users) {
            foreach ($users as $user) {
                if (!$user->plan || $user->plan->transfer_enable === null) {
                    continue;
                }

                $user->update([
                    'u' => 0,
                    'd' => 0,
                    'transfer_enable' => $user->plan->transfer_enable * 1024 * 1024 * 1024
                ]);
            }
        });
    }

    private function retryTransaction($callback): void
    {
        $maxAttempts = 3;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                DB::transaction($callback);
                return;
            } catch (\Exception $e) {
                if ($attempt >= $maxAttempts || 
                    (strpos($e->getMessage(), '40001') === false && 
                     strpos(strtolower($e->getMessage()), 'deadlock') === false)) {
                    throw $e;
                }
                
                $this->warn("事务失败，正在重试 ({$attempt}/{$maxAttempts})");
                sleep(2);
            }
        }
    }

    private function sendTelegramNotification(string $message): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin(
                now()->format('Y/m/d H:i:s') . ' ' . $message
            );
        } catch (\Exception $e) {
            $this->error("发送通知失败: " . $e->getMessage());
        }
    }

    private function printPlanInfo(array $users): void
    {
        // 按套餐分组统计
        $planStats = [];
        foreach ($users as $user) {
            if ($user->plan) {
                $planName = $user->plan->name;
                if (!isset($planStats[$planName])) {
                    $planStats[$planName] = 0;
                }
                $planStats[$planName]++;
            } else {
                if (!isset($planStats['无套餐'])) {
                    $planStats['无套餐'] = 0;
                }
                $planStats['无套餐']++;
            }
        }

        // 打印套餐统计
        foreach ($planStats as $planName => $count) {
            $this->line("  - {$planName}: {$count} 个用户");
        }
    }
}
