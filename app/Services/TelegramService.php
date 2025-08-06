<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Jobs\DeleteTelegramMessage;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Mail\Markdown;

class TelegramService
{
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function deleteMessage(int $chatId, int $messageId, int $delaySeconds = 0)
    {
        if ($delaySeconds > 0) {
            DeleteTelegramMessage::dispatch($chatId, $messageId)->delay(now()->addSeconds($delaySeconds));
        } else {
            $this->request('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        }
    }
    

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $extra = [], int $autoDeleteSeconds = 0)
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }

        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ], $extra);

        $response = $this->request('sendMessage', $params);

        if ($autoDeleteSeconds > 0 && isset($response->result->message_id)) {
            DeleteTelegramMessage::dispatch($chatId, $response->result->message_id)
                ->delay(now()->addSeconds($autoDeleteSeconds));
        }

        return $response;
    }
    public function banChatMember(int $chatId, int $userId, ?int $untilDate = null, bool $revokeMessages = false)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];

        if ($untilDate !== null) {
            $params['until_date'] = $untilDate;
        }

        if ($revokeMessages) {
            $params['revoke_messages'] = true;
        }

        return $this->request('banChatMember', $params);
    }

    public function banChatSenderChat(int $chatId, int $sender_chat_id)
    {
        return $this->request('banChatSenderChat', [
            'chat_id' => $chatId,
            'sender_chat_id' => $sender_chat_id,
        ]);
    }

    public function getChatMember($chatId, $userId)
    {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }


    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function getChat(int $chatId)
    {
        return $this->request('getChat', [
            'chat_id' => $chatId,
        ]);
    }


    public function setWebhook(string $url)
    {
        $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'));
        $this->setMyCommands($commands);
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    private function discoverCommands(string $directory): array
    {
        $commands = [];

        foreach (glob($directory . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);

                if (
                    $ref->hasProperty('command') &&
                    $ref->hasProperty('description')
                ) {
                    $commandProp = $ref->getProperty('command');
                    $descProp = $ref->getProperty('description');

                    $command = $commandProp->isStatic()
                        ? $commandProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->command;

                    $description = $descProp->isStatic()
                        ? $descProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->description;

                    $commands[] = [
                        'command' => $command,
                        'description' => $description,
                    ];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        return $commands;
    }

    public function setMyCommands(array $commands)
    {
        $this->request('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    private function request(string $method, array $params = [])
    {
        $curl = new Curl();
        $curl->get($this->api . $method . '?' . http_build_query($params));
        $response = $curl->response;
        $curl->close();
        if (!isset($response->ok))
            abort(500, '请求失败');
        if (!$response->ok) {
            abort(500, '来自TG的错误：' . $response->description);
        }
        return $response;
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0))
            return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
