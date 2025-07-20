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
        \Log::info('[Telegram Webhook] Received message: ', $request->all());
        $this->formatMessage($request->input());
        $this->handle();
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
        if (!$user) {
            if (!$msg->is_private && isset($msg->chat_id, $msg->from->id)) {
                try {
                    $member = $this->telegramService->getChatMember($msg->chat_id, $msg->from->id);
                    $status = $member->result->status ?? '';
                    $this->telegramService->kickChatMember($msg->chat_id, $msg->from->id);
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
