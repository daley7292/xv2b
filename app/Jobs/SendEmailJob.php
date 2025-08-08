<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\MailLog;
use Carbon\Carbon;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $params;
    public $tries = 3;
    public $timeout = 30;
    public $maxExceptions = 3;

    private $rateLimitKey = 'email_rate_limit';
    private $maxEmailsPerMinute = 60;
    private $maxEmailsPerHour = 3600;
    private $verifyQueueKey = 'verify_email_queue_count';

    public function __construct($params, $queue = 'send_email')
    {
        $templateName = $params['template_name'] ?? '';
        $isVerifyEmail = strpos($templateName, 'verify') !== false;
        
        if ($isVerifyEmail) {
            // 验证邮件使用高优先级队列
            $queue = 'high_priority_email';
            // 增加验证邮件队列计数
            Redis::incr($this->verifyQueueKey);
        }
        
        $this->onQueue($queue);
        $this->params = $params;
        $this->maxEmailsPerMinute = config('v2board.email_rate_limit.per_minute', 60);
        $this->maxEmailsPerHour = config('v2board.email_rate_limit.per_hour', 3600);
    }

    public function handle()
    {
        $templateName = $this->params['template_name'] ?? '';
        $isVerifyEmail = strpos($templateName, 'verify') !== false;
        
        // 如果不是验证邮件，检查是否还有验证邮件待发送
        if (!$isVerifyEmail && $this->hasVerifyEmailsInQueue()) {
            $this->release(30); // 延迟30秒后重试
            Log::info('Normal email delayed due to pending verify emails', ['email' => $this->params['email']]);
            return;
        }

        if (!$this->checkRateLimit()) {
            $this->release(60);
            Log::info('Email job delayed due to rate limit', ['email' => $this->params['email']]);
            return;
        }

        $this->configureMailSettings();
        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];

        $error = null;
        
        try {
            $this->incrementRateLimit();
            
            // 验证邮件立即发送，普通邮件使用自适应延迟
            if (!$isVerifyEmail) {
                $this->adaptiveDelay();
            }
            
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
            
            Log::info('Email sent successfully', [
                'email' => $email, 
                'subject' => $subject,
                'type' => $isVerifyEmail ? 'verify' : 'normal'
            ]);
            
            // 如果是验证邮件，减少队列计数
            if ($isVerifyEmail) {
                $this->decrementVerifyQueueCount();
            }
            
        } catch (\Exception $e) {
            $error = $e->getMessage();
            
            if ($this->isRateLimitError($e)) {
                $this->decrementRateLimit();
                
                $releaseTime = $isVerifyEmail ? 60 : 120;
                $this->release($releaseTime);
                
                Log::warning('SMTP rate limit hit, job released', [
                    'email' => $email, 
                    'error' => $error,
                    'type' => $isVerifyEmail ? 'verify' : 'normal'
                ]);
                return;
            }
            
            // 如果验证邮件发送失败，也要减少队列计数
            if ($isVerifyEmail) {
                $this->decrementVerifyQueueCount();
            }
            
            Log::error('Email sending failed', [
                'email' => $email, 
                'error' => $error,
                'type' => $isVerifyEmail ? 'verify' : 'normal'
            ]);
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => $error,
            'sent_at' => Carbon::now(),
            'is_verify_email' => $isVerifyEmail
        ];
        
        MailLog::create($log);
        
        $log['config'] = config('mail');
        return $log;
    }

    private function configureMailSettings()
    {
        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', env('mail.host')));
            Config::set('mail.port', config('v2board.email_port', env('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', env('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', env('mail.username')));
            Config::set('mail.password', config('v2board.email_password', env('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
    }

    private function checkRateLimit(): bool
    {
        $now = Carbon::now();
        
        $minuteKey = $this->rateLimitKey . ':minute:' . $now->format('Y-m-d-H-i');
        $minuteCount = Redis::get($minuteKey) ?: 0;
        if ($minuteCount >= $this->maxEmailsPerMinute) {
            return false;
        }
        
        $hourKey = $this->rateLimitKey . ':hour:' . $now->format('Y-m-d-H');
        $hourCount = Redis::get($hourKey) ?: 0;
        if ($hourCount >= $this->maxEmailsPerHour) {
            return false;
        }
        
        return true;
    }

    private function incrementRateLimit()
    {
        $now = Carbon::now();
        
        $minuteKey = $this->rateLimitKey . ':minute:' . $now->format('Y-m-d-H-i');
        Redis::incr($minuteKey);
        Redis::expire($minuteKey, 120);
    
        $hourKey = $this->rateLimitKey . ':hour:' . $now->format('Y-m-d-H');
        Redis::incr($hourKey);
        Redis::expire($hourKey, 7200);
    }

    private function decrementRateLimit()
    {
        $now = Carbon::now();
        
        $minuteKey = $this->rateLimitKey . ':minute:' . $now->format('Y-m-d-H-i');
        $hourKey = $this->rateLimitKey . ':hour:' . $now->format('Y-m-d-H');
        
        $minuteCount = Redis::get($minuteKey) ?: 0;
        if ($minuteCount > 0) {
            Redis::decr($minuteKey);
        }
        
        $hourCount = Redis::get($hourKey) ?: 0;
        if ($hourCount > 0) {
            Redis::decr($hourKey);
        }
    }

    private function adaptiveDelay()
    {
        $now = Carbon::now();
        $minuteKey = $this->rateLimitKey . ':minute:' . $now->format('Y-m-d-H-i');
        $minuteCount = Redis::get($minuteKey) ?: 0;
        if ($minuteCount > $this->maxEmailsPerMinute * 0.8) {
            sleep(3);
        } elseif ($minuteCount > $this->maxEmailsPerMinute * 0.6) {
            sleep(2);
        } elseif ($minuteCount > $this->maxEmailsPerMinute * 0.3) {
            sleep(1);
        }
    }

    private function isRateLimitError(\Exception $e): bool
    {
        $errorMessage = strtolower($e->getMessage());
        $rateLimitKeywords = [
            'rate limit',
            'too many',
            'quota exceeded',
            'throttling',
            'temporarily blocked',
            '421',
            '450',
            '451'
        ];
        
        foreach ($rateLimitKeywords as $keyword) {
            if (strpos($errorMessage, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 检查是否还有验证邮件在队列中待发送
     */
    private function hasVerifyEmailsInQueue(): bool
    {
        $count = Redis::get($this->verifyQueueKey) ?: 0;
        return $count > 0;
    }

    /**
     * 减少验证邮件队列计数
     */
    private function decrementVerifyQueueCount()
    {
        $count = Redis::get($this->verifyQueueKey) ?: 0;
        if ($count > 0) {
            Redis::decr($this->verifyQueueKey);
        }
    }

    public function getRateLimitStatus(): array
    {
        $now = Carbon::now();
        
        $minuteKey = $this->rateLimitKey . ':minute:' . $now->format('Y-m-d-H-i');
        $hourKey = $this->rateLimitKey . ':hour:' . $now->format('Y-m-d-H');
        
        return [
            'minute' => [
                'count' => Redis::get($minuteKey) ?: 0,
                'limit' => $this->maxEmailsPerMinute,
                'remaining' => $this->maxEmailsPerMinute - (Redis::get($minuteKey) ?: 0)
            ],
            'hour' => [
                'count' => Redis::get($hourKey) ?: 0,
                'limit' => $this->maxEmailsPerHour,
                'remaining' => $this->maxEmailsPerHour - (Redis::get($hourKey) ?: 0)
            ],
            'verify_queue_count' => Redis::get($this->verifyQueueKey) ?: 0
        ];
    }
}
