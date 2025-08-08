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
use App\Models\MailLog;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $params;
    public $tries = 5;
    public $timeout = 30;
    protected $rateLimits = [
        'send_email' => [
            'per_minute' => 60,
            'per_hour' => 3600,
        ],
        'send_email_mass' => [
            'per_minute' => 60,
            'per_hour' => 3600,
        ],
    ];

    public function __construct(array $params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    public function handle()
    {
        $queueName = $this->queue ?? 'default';
        $limit = $this->rateLimits[$queueName] ?? null;

        if ($limit) {
            $now = now();

            $minuteKey = "rate_limit:{$queueName}:minute:" . $now->format('YmdHi');
            $hourKey = "rate_limit:{$queueName}:hour:" . $now->format('YmdH');
            $minuteCount = Redis::incr($minuteKey);
            if ($minuteCount === 1) {
                Redis::expire($minuteKey, 60);
            }
            $hourCount = Redis::incr($hourKey);
            if ($hourCount === 1) {
                Redis::expire($hourKey, 3600);
            }
            if ($minuteCount > $limit['per_minute'] || $hourCount > $limit['per_hour']) {
                $this->release(5);
                return;
            }
        }

        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', env('mail.host')));
            Config::set('mail.port', config('v2board.email_port', env('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', env('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', env('mail.username')));
            Config::set('mail.password', config('v2board.email_password', env('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }

        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];

        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
            $error = null;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => $error,
        ];

        MailLog::create($log);

        $log['config'] = config('mail');
        return $log;
    }
}
