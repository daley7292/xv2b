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
    
    // 未绑定用户发言限制相关常量
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
    
        // 非频道身份发言，跳过
        if (!$msg->is_channel_message) {
            return false;
        }
    
        // 私聊跳过
        if ($msg->is_private) {
            return false;
        }
    
        try {
            $isLinkedChannel = false;
    
            // 判断是否是群组的关联频道
            if ($msg->sender_chat_id) {
                $chatInfo = $this->telegramService->getChat($msg->chat_id);
                $linkedChatId = $chatInfo->result->linked_chat_id ?? null;
    
                if ($linkedChatId && $linkedChatId == $msg->sender_chat_id) {
                    // 是关联频道
                    \Log::info("[Telegram] 检测到关联频道发言，跳过删除与封禁: {$msg->sender_chat_id}");
                    return true;
                }
            }
    
            // 删除消息
            $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id);
    
            // 封禁频道身份
            if ($msg->sender_chat_id) {
                $this->telegramService->banChatSenderChat($msg->chat_id, $msg->sender_chat_id);
            }
    
            // 提示消息
            $channelUsername = $msg->sender_chat_username ?? '未知频道';
            $text = "⚠️ 检测到频道 @{$channelUsername} 身份发言，消息已删除并已封禁该频道发言权限。";
            $this->telegramService->sendMessage($msg->chat_id, $text, 'HTML');
    
            \Log::info("[Telegram] 删除并封禁频道身份消息: {$channelUsername}");
    
            return true;
    
        } catch (\Exception $e) {
            \Log::warning("[Telegram] 处理频道消息失败：" . $e->getMessage());
            return false;
        }
    }

    protected function kickUser(int $chatId, int $userId, ?int $banSeconds = null, bool $revokeMessages = true)
    {
        $untilDate = null;
        if ($banSeconds !== null && $banSeconds > 0) {
            $untilDate = time() + $banSeconds;
        }

        return $this->telegramService->banChatMember($chatId, $userId, $untilDate, $revokeMessages);
    }

    public function handle()
    {
        if (!$this->msg)
            return;
        
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);
        
        $user = User::where('telegram_id', $msg->from->id ?? 0)
            ->where('banned', 0)
            ->first();
        
        if (!$user && !$msg->is_private) {
            // 检查未绑定用户的发言限制
            if (!$this->checkUnboundUserLimit($msg)) {
                return; // 如果超出限制，直接返回
            }
        }

        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName) {
                $msg->command = $commandName[0];
            }
        }
        
        try {
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class))
                    continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command))
                        continue;
                    if ($msg->command !== $instance->command)
                        continue;
                    if (isset($msg->command) && substr($msg->command, 0, 1) === '/') {
                        $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id, 60);
                    }
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex))
                        continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match))
                        continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    /**
     * 检查未绑定用户的发言限制
     */
    private function checkUnboundUserLimit($msg): bool
    {
        if (!isset($msg->from->id)) {
            return false;
        }

        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $cacheKey = self::CACHE_PREFIX . $userId;
        
        // 获取当前小时的发言次数
        $currentCount = \Cache::get($cacheKey, 0);
        
        if ($currentCount >= self::UNBOUND_USER_HOURLY_LIMIT) {
            // 超出限制，踢出用户
            try {
                $this->kickUser($chatId, $userId, 3600, true); // 1小时封禁并撤回消息
                
                $username = $msg->from->username ?? '无用户名';
                $text = "⚠️ 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 未绑定账户且超出发言限制，已被移出群组。";
                $this->telegramService->sendMessage($chatId, $text, 'HTML');
                
                \Log::info("[Telegram] 用户 {$userId} 超出发言限制，已被踢出");
                
            } catch (\Exception $e) {
                \Log::warning("[Telegram] 踢出超限用户失败：" . $e->getMessage());
            }
            
            return false;
        }
        
        // 增加发言次数
        $newCount = $currentCount + 1;
        \Cache::put($cacheKey, $newCount, now()->endOfHour());
        
        // 发送绑定提醒
        $this->sendBindReminder($msg, $newCount);
        
        return true;
    }

    /**
     * 发送绑定提醒消息
     */
    private function sendBindReminder($msg, int $currentCount)
    {
        $userId = $msg->from->id;
        $chatId = $msg->chat_id;
        $username = $msg->from->username ?? '无用户名';
        $remaining = self::UNBOUND_USER_HOURLY_LIMIT - $currentCount;
        
        $botName = $this->getBotName();
        
        $limit = self::UNBOUND_USER_HOURLY_LIMIT;
        
        if ($remaining > 0) {
            // 还有剩余次数
            $text = "⚠️ 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 您尚未绑定账户！\n\n";
            $text .= "📊 本小时剩余发言次数：<b>{$remaining}/{$limit}</b>\n\n";
            $text .= "🔗 请发送 /bind 订阅链接 到 @{$botName} 绑定\n";
            $text .= "⏰ 超出限制将被移出群组";
        } else {
            // 最后一次发言
            $text = "🚨 用户 <a href=\"tg://user?id={$userId}\">@{$username}</a> 这是您本小时的最后一次发言机会！\n\n";
            $text .= "🔗 请立即发送 /bind 订阅链接 到 @{$botName} 绑定\n";
            $text .= "⚠️ 下次发言将被移出群组！";
        }
        
        // 发送提醒消息，以回复的形式，30秒后自动删除
        $extra = [
            'reply_to_message_id' => $msg->message_id
        ];
        $this->telegramService->sendMessage($chatId, $text, 'HTML', $extra, 30);
        
        \Log::info("[Telegram] 向用户 {$userId} 发送绑定提醒，剩余次数：{$remaining}");
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message']))
            return;
        if (!isset($data['message']['text']))
            return;
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

            if (isset($senderChat['type']) && $senderChat['type'] === 'channel') {
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
            $obj->from = (object) [
                'id' => $data['message']['from']['id'] ?? null,
                'username' => $data['message']['from']['username'] ?? null,
                'first_name' => $data['message']['from']['first_name'] ?? null,
            ];
        }
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }
}
