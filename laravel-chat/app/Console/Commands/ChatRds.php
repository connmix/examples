<?php

namespace App\Console\Commands;

use Connmix\Client;
use Connmix\MessageInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ChatRds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:chat:rds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $client = \Connmix\ClientBuilder::create()
            ->setHost('127.0.0.1:6787')
            ->build();
        while (true) {
            // 修改.env增加REDIS_PREFIX为空
            $result = Redis::connection()->command('brpop', ['chat', 10]);
            if (!$result) {
                continue;
            }

            try {
                $message = $client->parse($result[1]);
                $this->handleEvent($message, $client);
            } catch (\Throwable $e) {
                print 'ERROR: ' . $e->getMessage() . PHP_EOL;
            }
        }
    }

    /**
     * @param MessageInterface $message
     * @param Client $client
     * @return void
     */
    protected function handleEvent(MessageInterface $message, Client $client): void
    {
        $event = $message->data()['event'] ?? '';
        switch ($event) {
            case 'close':
            case 'handshake':
                $this->handleQueueConn($message, $client);
                break;
            case 'message':
                $this->handleQueueChat($message, $client);
                break;
        }
    }

    /**
     * @param MessageInterface $message
     * @param Client $client
     * @return void
     */
    protected function handleQueueChat(MessageInterface $message, Client $client): void
    {
        $nodeID = $message->nodeID();
        $clientID = $message->clientID();
        $data = $message->data();
        $uid = $data['uid'] ?? 0;
        $headers = $data['headers'] ?? [];
        $node = $client->node($nodeID);

        // 解析
        $json = json_decode($data['frame']['data'], true);
        if (empty($json)) {
            $node->meshSend($clientID, '{"error":"Json format error"}');
            return;
        }
        $op = $json['op'] ?? '';
        $args = $json['args'] ?? [];

        // 业务逻辑
        switch ($op) {
            case 'ping':
                $this->ping($node, $clientID);
                break;
            case 'auth':
                $this->auth($node, $clientID, $args, $headers);
                break;
            case 'subscribe':
                $this->subscribe($node, $clientID, $args, $uid);
                break;
            case 'unsubscribe':
                $this->unsubscribe($node, $clientID, $args, $uid);
                break;
            case 'sendtoroom':
                $this->sendToRoom($node, $clientID, $args, $uid);
                break;
            case 'sendtouser':
                $this->sendToUser($node, $clientID, $args, $uid);
                break;
            case 'sendbroadcast':
                $this->sendBroadcast($node, $clientID, $args, $uid);
                break;
        }
    }

    /**
     * @param MessageInterface $message
     * @param Client $client
     * @return void
     */
    protected function handleQueueConn(MessageInterface $message, Client $client): void
    {
        $clientID = $message->clientID();
        $data = $message->data();

        // 解析
        $event = $data['event'] ?? '';
        $uid = $data['uid'] ?? 0;
        $headers = $data['headers'] ?? [];

        // 业务逻辑
        switch ($event) {
            case 'handshake':
                $this->handshake($clientID, $headers);
                break;
            case 'close':
                $this->close($clientID, $uid);
                break;
        }
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @return void
     */
    protected function ping(\Connmix\SyncNodeInterface $node, int $clientID): void
    {
        $node->meshSend($clientID, '{"op":"pong"');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param array $headers
     * @return void
     */
    protected function auth(\Connmix\SyncNodeInterface $node, int $clientID, array $args, array $headers): void
    {
        list($name, $password) = $args;
        $row = \App\Models\User::query()->where('name', '=', $name)->where('password', '=', $password)->first();
        if (empty($row)) {
            // 验证失败，设置一个特殊值解除 lua 代码阻塞
            $node->setContextValue($clientID, 'uid', 0);
            $node->meshSend($clientID, '{"op":"auth","error":"Invalid name or password"}');
            return;
        }

        // 开启用户在线状态
        \App\Models\User::query()->where('id', '=', $row['id'])->update([
            'online' => 1,
        ]);

        // 设置上下文解除 lua 代码阻塞
        $node->setContextValue($clientID, 'uid', $row['id']);
        $node->meshSend($clientID, '{"op":"auth","success":true}');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function subscribe(\Connmix\SyncNodeInterface $node, int $clientID, array $args, int $uid): void
    {
        // 登录判断
        if (empty($uid)) {
            $node->meshSend($clientID, '{"op":"subscribe","error":"No access"}');
            return;
        }

        // 此处省略业务权限效验
        // ...

        $node->subscribe($clientID, ...$args);
        $node->meshSend($clientID, '{"op":"subscribe","success":true}');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function unsubscribe(\Connmix\SyncNodeInterface $node, int $clientID, array $args, int $uid): void
    {
        // 登录判断
        if (empty($uid)) {
            $node->meshSend($clientID, '{"op":"unsubscribe","error":"No access"}');
            return;
        }

        $node->unsubscribe($clientID, ...$args);
        $node->meshSend($clientID, '{"op":"unsubscribe","success":true}');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendToRoom(\Connmix\SyncNodeInterface $node, int $clientID, array $args, int $uid): void
    {
        // 登录判断
        if (empty($uid)) {
            $node->meshSend($clientID, '{"op":"sendtoroom","error":"No access"}');
            return;
        }

        // 此处省略业务权限效验
        // ...

        list($channel, $message) = $args;
        $message = sprintf('uid:%d,message:%s', $uid, $message);
        $node->meshPublish($channel, sprintf('{"event":"subscribe","channel":"%s","data":"%s"}', $channel, $message));
        $node->meshSend($clientID, '{"op":"sendtoroom","success":true}');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendToUser(\Connmix\SyncNodeInterface $node, int $clientID, array $args, int $uid): void
    {
        // 登录判断
        if (empty($uid)) {
            $node->meshSend($clientID, '{"op":"sendtouser","error":"No access"}');
            return;
        }

        // 此处省略业务权限效验
        // ...

        list($channel, $message) = $args;
        $message = sprintf('uid:%d,message:%s', $uid, $message);
        $node->meshPublish($channel, sprintf('{"event":"subscribe","channel":"%s","data":"%s"}', $channel, $message));
        $node->meshSend($clientID, '{"op":"sendtouser","success":true}');
    }

    /**
     * @param \Connmix\SyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendBroadcast(\Connmix\SyncNodeInterface $node, int $clientID, array $args, int $uid): void
    {
        // 登录判断
        if (empty($uid)) {
            $node->meshSend($clientID, '{"op":"sendbroadcast","error":"No access"}');
            return;
        }

        // 此处省略业务权限效验
        // ...

        $channel = 'broadcast';
        list($message) = $args;
        $message = sprintf('uid:%d,message:%s', $uid, $message);
        $node->meshPublish($channel, sprintf('{"event":"subscribe","channel":"%s","data":"%s"}', $channel, $message));
        $node->meshSend($clientID, '{"op":"sendbroadcast","success":true}');
    }

    /**
     * @param int $clientID
     * @param array $headers
     * @return void
     */
    protected function handshake(int $clientID, array $headers): void
    {
        // 使用 redis incr 增加在线人数
        // ...
    }

    /**
     * @param int $clientID
     * @param int $uid
     * @return void
     */
    protected function close(int $clientID, int $uid): void
    {
        // 关闭用户在线状态
        if (!empty($uid)) {
            \App\Models\User::query()->where('id', '=', $uid)->update([
                'online' => 0,
            ]);
        }

        // 使用 redis decr 减少在线人数
        // ...
    }
}
