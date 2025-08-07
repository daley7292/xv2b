<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use App\Models\User;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    private const UNBOUND_USER_HOURLY_LIMIT = 3;
    private const CACHE_PREFIX = 'telegram_unbound_user_';

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        $this->formatMessage($request->input());
        if ($this->checkAndKickChannelMessage()) {
            return;
        }

        $this->handle();
    }

    private function checkAndKickChannelMessage()
    {
        if (!$this->msg) {
            return false;
        }

        $msg = $this->msg;

        if (!$msg->is_channel_message || $msg->is_private) {
            return false;
        }

        try {
            if ($msg->sender_chat_id) {
                $chatInfo = $this->telegramService->getChat($msg->chat_id);
                $linkedChatId = $chatInfo->result->linked_chat_id ?? null;
                if ($linkedChatId && $linkedChatId == $msg->sender_chat_id) {
                    return true;
                }
            }
            $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id);
            if ($msg->sender_chat_id) {
                $this->telegramService->banChatSenderChat($msg->chat_id, $msg->sender_chat_id);
            }
            $channelUsername = $msg->sender_chat_username ?? '未知频道';
            $text = "⚠️ 检测到频道 @{$channelUsername} 身份发言，消息已删除并已封禁该频道发言权限。";
            $this->telegramService->sendMessage($msg->chat_id, $text, 'HTML');
            return true;

        } catch (\Exception $e) {
            \Log::warning("[Telegram] 处理频道消息失败：" . $e->getMessage());
            return false;
        }
    }

    protected function kickUser(int $chatId, int $userId, ?int $banSeconds = null, bool $revokeMessages = true)
    {
        $untilDate = $banSeconds ? time() + $banSeconds : null;
        return $this->telegramService->banChatMember($chatId, $userId, $untilDate, $revokeMessages);
    }

    public function handle()
    {
        if (!$this->msg) return;

        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        $user = User::where('telegram_id', $msg->from->id ?? 0)
            ->where('banned', 0)
            ->first();

        if (!$user && !$msg->is_private) {
            if (!$this->checkUnboundUserLimit($msg)) {
                return;
            }
        }

        if (count($commandName) === 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName) {
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app/Plugins/Telegram/Commands/*.php')) as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;

                $instance = new $class();

                if ($msg->message_type === 'message') {
                    if (!isset($msg->command)) continue;

                    $input = $msg->command;

                    $matchesCommand = isset($instance->command) && $input === $instance->command;
                    $matchesKeyword = isset($instance->keywords) && in_array($input, $instance->keywords);

                    if (!$matchesCommand && !$matchesKeyword) continue;

                    if (substr($input, 0, 1) === '/') {
                        $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id, 60);
                    }

                    $instance->handle($msg);
                    return;
                }

                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;

                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function checkUnboundUserLimit($msg): bool
    {
        if (!isset($msg->from->id)) return false;

        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $cacheKey = self::CACHE_PREFIX . $userId;

        $currentCount = \Cache::get($cacheKey, 0);

        if ($currentCount >= self::UNBOUND_USER_HOURLY_LIMIT) {
            try {
                $this->kickUser($chatId, $userId, 3600, true);
                $username = $msg->from->username ?? '无用户名';
                $text = "⚠️ 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 未绑定账户且超出发言限制，已被移出群组。";
                $this->telegramService->sendMessage($chatId, $text, 'HTML');
            } catch (\Exception $e) {
                \Log::warning("[Telegram] 踢出超限用户失败：" . $e->getMessage());
            }
            return false;
        }

        $newCount = $currentCount + 1;
        \Cache::put($cacheKey, $newCount, now()->endOfHour());

        $this->sendBindReminder($msg, $newCount);

        return true;
    }

    private function sendBindReminder($msg, int $currentCount)
    {
        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $username = $msg->from->username ?? '无用户名';
        $remaining = self::UNBOUND_USER_HOURLY_LIMIT - $currentCount;

        $botName = $this->getBotName();
        $limit = self::UNBOUND_USER_HOURLY_LIMIT;

        if ($remaining > 0) {
            $text = "⚠️ 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 您尚未绑定账户！\n";
            $text .= "📊 本小时剩余发言次数：<b>{$remaining}/{$limit}</b>\n";
            $text .= "🔗 请私聊 @{$botName} 发送 /bind 订阅链接 绑定\n";
            $text .= "⏰ 超出限制将被移出群组";
        } else {
            $text = "🚨 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 这是您本小时的最后一次发言机会！\n";
            $text .= "🔗 请私聊 @{$botName} 发送 /bind 订阅链接 绑定\n";
            $text .= "⚠️ 下次发言将被移出群组！";
        }

        $extra = ['reply_to_message_id' => $msg->message_id];
        $this->telegramService->sendMessage($chatId, $text, 'HTML', $extra, 60);
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message']) || !isset($data['message']['text'])) return;

        $obj = new \StdClass();
        $text = preg_split('/\s+/', trim($data['message']['text']));
        $obj->command = $text[0] ?? '';
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        $obj->is_channel_message = false;
        $obj->sender_chat_username = null;
        $obj->sender_chat_id = null;

        if (isset($data['message']['sender_chat'])) {
            $senderChat = $data['message']['sender_chat'];
            if (($senderChat['type'] ?? '') === 'channel') {
                $obj->is_channel_message = true;
                $obj->sender_chat_id = $senderChat['id'] ?? null;
                $obj->sender_chat_username = $senderChat['username'] ?? $senderChat['title'] ?? null;
            }
        }

        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }

        if (isset($data['message']['from'])) {
            $obj->from = (object)[
                'id' => $data['message']['from']['id'] ?? null,
                'username' => $data['message']['from']['username'] ?? null,
                'first_name' => $data['message']['from']['first_name'] ?? null,
            ];
        }

        $this->msg = $obj;
    }
}
