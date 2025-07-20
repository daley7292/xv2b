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
        $msg = $this->msg;
        $user = User::where('telegram_id', $msg->from->id ?? 0)
            ->where('banned', 0)
            ->first();
        if (!$user && !$msg->is_private) {
            if (isset($msg->chat_id, $msg->from->id)) {
                try {
                    $this->kickUser($msg->chat_id, $msg->from->id, 3600, true); // 1小时封禁并撤回消息

                    $username = $msg->from->username ?? '无用户名';
                    $text = "⚠️ 用户 <a href=\"tg://user?id={$msg->from->id}\">@{$username}</a> 未绑定账户，已被移出群组。";
                    $this->telegramService->sendMessage($msg->chat_id, $text, 'HTML');

                } catch (\Exception $e) {
                    \Log::warning("[Telegram] 踢出用户失败：" . $e->getMessage());
                }
            }

            return;
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
                        $this->telegramService->deleteMessage($msg->chat_id, $msg->message_id, 15);
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
