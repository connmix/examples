# Laravel + connmix 开发分布式 WebSocket 聊天室

> `Star` https://github.com/connmix/examples 获取最新版本的示例

[connmix](https://connmix.com) 是一个基于 go + lua 开发面向消息编程的分布式长连接引擎，可用于互联网、即时通讯、APP开发、网络游戏、硬件通讯、智能家居、物联网等领域的开发，支持
java,php,go,nodejs 等各种语言的客户端。

[Laravel](https://laravel.com) 是 PHP 业界公认最优雅的传统框架，当然你也可以选择 thinkphp 等其他框架。

两者结合可快速开发出性能强劲的分布式 `websocket` 长连接服务，非常适合开发 IM、聊天室、客服系统、直播弹幕、页游等需求。

## 安装

1. 安装 `CONNMIX` 引擎：https://connmix.com/docs/1.0/#/zh-cn/install-engine

2. 安装最新版本的 `Laravel` 框架

```
composer create-project laravel/laravel laravel-chat
```

3. 然后安装 [connmix-php](https://packagist.org/packages/connmix/connmix) 客户端

```
cd laravel-chat
composer require connmix/connmix
```

## 解决方案

- 在命令行中使用 `connmix` 客户端消费内存队列 (前端发送的 WebSocket 消息)。
- 我们选择 Laravel 的命令行模式，也就是 `console` 来编写业务逻辑，这样就可以使用 Laravel 的 DB、Redis 等各种生态库。

## API 设计

作为一个聊天室，在动手之前我们需要先设计 WebSocket API 接口，我们采用最广泛的 `json` 格式来传递数据，交互采用经典的 `pubsub` 模式。

| 功能      | 格式                                                                 |
|---------|--------------------------------------------------------------------|
| 用户登录    | {"op":"auth","args":["name","pwd"]}                                |
| 订阅房间频道  | {"op":"subscribe","args":["room_101"]}                             | 
| 订阅用户频道  | {"op":"subscribe","args":["user_10001"]}                           | 
| 订阅广播频道  | {"op":"subscribe","args":["broadcast"]}                            | 
| 取消订阅频道  | {"op":"unsubscribe","args":["room_101"]}                           | 
| 接收房间消息  | {"event":"subscribe","channel":"room_101","data":"hello,world!"}   |
| 接收用户消息  | {"event":"subscribe","channel":"user_10001","data":"hello,world!"} |
| 接收广播消息  | {"event":"subscribe","channel":"broadcast","data":"hello,world!"}  |
| 发送消息到房间 | {"op":"sendtoroom","args":["room_101","hello,world!"]}             | 
| 发送消息到用户 | {"op":"sendtouser","args":["user_10001","hello,world!"]}           | 
| 发送广播    | {"op":"sendbroadcast","args":["hello,world!"]}                     | 
| 成功      | {"op":"***","success":true}                                        |
| 错误      | {"op":"\*\*\*","error":"***"}                                      |

## 数据库设计

我们需要做登录，因此需要一个 `users` 表来处理鉴权，这里只是为了演示因此表设计特意简化。

- 文件路径：[users.sql](users.sql)

```sql
CREATE TABLE `users` (
     `id` int NOT NULL AUTO_INCREMENT,
     `name` varchar(255) NOT NULL,
     `email` varchar(255) NOT NULL,
     `password` varchar(255) NOT NULL,
     `online` tinyint NOT NULL DEFAULT '0',
     `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` timestamp NULL DEFAULT NULL,
     PRIMARY KEY (`id`),
     UNIQUE KEY `idx_n` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

房间 table 这里暂时不做设计，大家自行扩展。

## 修改 `entry.lua`

用户登录需要在 lua 协议增加 `conn:wait_context_value` 来完成，我们修改 `entry.lua` 如下：

- 文件路径：[entry.lua](entry.lua)
- `protocol_input` 修改绑定的 url 路径
- `on_message` 增加阻塞等待上下文

```lua
require("prettyprint")
local mix_log = mix.log
local mix_ERROR = mix.ERROR
local websocket = require("protocols/websocket")
local queue_chat = "chat"
local queue_conn = "conn"
local auth_op = "auth"
local auth_key = "uid"

function init()
    mix.queue.new(queue_chat, 100)
    mix.queue.new(queue_conn, 100)
end

function on_connect(conn)
    --print(conn:client_id())
end

function on_close(err, conn)
    --print(err)
    local n, err = mix.queue.push(queue_conn, { event = "close", uid = conn:context()[auth_key] })
    if err then
       mix_log(mix_ERROR, "queue push error: " .. err)
       conn:close()
       return
    end
end

function on_handshake(headers, conn)
    --print(headers)
    local n, err = mix.queue.push(queue_conn, { event = "handshake", headers = headers })
    if err then
       mix_log(mix_ERROR, "queue push error: " .. err)
       conn:close()
       return
    end
end

--buf为一个对象，是一个副本
--返回值必须为int, 返回包截止的长度 0=继续等待,-1=断开连接
function protocol_input(buf, conn)
    return websocket.input(buf, conn, "/chat", on_handshake)
end

--返回值支持任意类型, 当返回数据为nil时，on_message将不会被触发
function protocol_decode(str, conn)
    return websocket.decode(conn)
end

--返回值必须为string, 当返回数据不是string, 或者为空, 发送消息时将返回失败错误
function protocol_encode(str, conn)
    return websocket.encode(str)
end

--data为任意类型, 值等于protocol_decode返回值
function on_message(data, conn)
    --print(data)
    if data["type"] ~= "text" then
        return
    end

    local msg, err = mix.json_decode(data["data"])
    if err then
       mix_log(mix_ERROR, "json_decode error: " .. err)
       return
    end

    local ctx = conn:context()
    local tb = { frame = data, uid = ctx[auth_key] }
    if msg["op"] == auth_op then
        tb["headers"] = ctx["headers"]
    end
    local n, err = mix.queue.push(queue_chat, tb)
    if err then
       mix_log(mix_ERROR, "queue push error: " .. err)
       conn:close()
       return
    end

    if msg["op"] == auth_op then
       conn:wait_context_value(auth_key)
    end
end
```

## 编写业务逻辑

然后我们在 `console` 编写代码，生成一个命令行 `class`

```
php artisan make:command Chat
```

- 文件路径：[Console/Commands/Chat.php](app/Console/Commands/Chat.php)

我们使用 `connmix-php` 客户端来处理内存队列的消费。

```php
<?php

namespace App\Console\Commands;

use Connmix\MessageInterface;
use Illuminate\Console\Command;

class Chat extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:chat';

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
        $onConnect = function (\Connmix\AsyncNodeInterface $node) {
            // 消费内存队列
            $node->consume('chat', 'conn');
        };
        $onMessage = function (\Connmix\AsyncNodeInterface $node) {
            $message = $node->message();
            switch ($message->type()) {
                case "consume":
                    $this->handleConsume($message, $node);
                    break;
                case "result":
                    $success = $message->success();
                    $fail = $message->fail();
                    $total = $message->total();
                    break;
                case "error":
                    $error = $message->error();
                    break;
                default:
                    $payload = $message->payload();
            }
        };
        $onError = function (\Throwable $e) {
            // handle error
            print 'ERROR: ' . $e->getMessage() . PHP_EOL;
        };
        $client->on($onConnect, $onMessage, $onError);
        $client->run();
        return 0;
    }

    /**
     * @param MessageInterface $message
     * @param \Connmix\AsyncNodeInterface $node
     * @return void
     */
    protected function handleConsume(MessageInterface $message, \Connmix\AsyncNodeInterface $node): void
    {
        switch ($message->queue()) {
            case 'chat':
                $this->handleQueueChat($message, $node);
                break;
            case 'conn':
                $this->handleQueueConn($message, $node);
                break;
        }
    }

    /**
     * @param MessageInterface $message
     * @param \Connmix\AsyncNodeInterface $node
     * @return void
     */
    protected function handleQueueChat(MessageInterface $message, \Connmix\AsyncNodeInterface $node): void
    {
        $clientID = $message->clientID();
        $data = $message->data();
        $uid = $data['uid'] ?? 0;
        $headers = $data['headers'] ?? [];

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
     * @param \Connmix\AsyncNodeInterface $node
     * @return void
     */
    protected function handleQueueConn(MessageInterface $message, \Connmix\AsyncNodeInterface $node): void
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param array $headers
     * @return void
     */
    protected function auth(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, array $headers): void
    {
        list($name, $password) = $args;
        $row = \App\Models\User::query()->where('name', '=', $name)->where('password', '=', $password)->first();
        if (empty($row)) {
            // 验证失败，设置一个特殊值解除 lua 代码阻塞
            $node->setContextValue($clientID, 'user_id', 0);
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function subscribe(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid): void
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function unsubscribe(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid): void
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendToRoom(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid): void
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendToUser(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid): void
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
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @param int $uid
     * @return void
     */
    protected function sendBroadcast(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid): void
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
```

## 调试

### 启动服务

- 启动 `connmix` 引擎

```
% bin/connmix dev -f conf/connmix.yaml 
```

- 启动 `Laravel` 命令行 (可以启动多个来增加性能)

```
% php artisan command:chat
```

### WebSocket Client 1

连接：`ws://127.0.0.1:6790/chat`

- 登录

```
send: {"op":"auth","args":["user1","123456"]}
receive: {"op":"auth","success":true}
```

- 加入房间

```
send: {"op":"subscribe","args":["room_101"]}
receive: {"op":"subscribe","success":true}
```

- 发送消息

```
send: {"op":"sendtoroom","args":["room_101","hello,world!"]}
receive: {"event":"subscribe","channel":"room_101","data":"uid:1,message:hello,world!"}
receive: {"op":"sendtoroom","success":true}
```

### WebSocket Client 2

连接：`ws://127.0.0.1:6790/chat`

- 登录

```
send: {"op":"auth","args":["user2","123456"]}
receive: {"op":"auth","success":true}
```

- 加入房间

```
send: {"op":"subscribe","args":["room_101"]}
receive: {"op":"subscribe","success":true}
```

- 接收消息

```
receive: {"event":"subscribe","channel":"room_101","data":"uid:1,message:hello,world!"}
```

## 结语

基于 `connmix` 客户端我们只需很少的代码就可以快速打造一个分布式长连接服务。
