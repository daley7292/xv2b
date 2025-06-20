<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = '通过订阅链接解绑 Telegram，例如：/unbind 订阅地址';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;

        if (!isset($message->args[0])) {
            abort(500, '参数有误，请携带订阅地址发送');
        }

        $subscribeUrl = $message->args[0];
        $subscribeUrl = parse_url($subscribeUrl);
        parse_str($subscribeUrl['query'], $query);
        $token = $query['token'] ?? null;

        if (!$token) {
            abort(500, '订阅地址无效');
        }

        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }

        $user->telegram_id = null;
        if (!$user->save()) {
            abort(500, '解绑失败');
        }

        $this->telegramService->sendMessage($message->chat_id, '解绑成功', 'markdown');
    }
}
