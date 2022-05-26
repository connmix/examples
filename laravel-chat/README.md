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
| ping    | {"op":"ping"}                                                      |
| pong    | {"op":"pong"}                                                      |

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

## 编写业务逻辑

然后我们在 `console` 编写代码，生成一个命令行 `class`

```
php artisan make:command ChatMem
```

- 内存队列：[ChatMem.php](app/Console/Commands/ChatMem.php)

```
% php artisan command:chat:mem
```

- Redis队列：[ChatRds.php](app/Console/Commands/ChatRds.php) 
  - 修改 entry.lua 为 `mix.redis.push`
  - 完善 connmix.yaml 的 redis 信息
  - 完善 .env 的 redis 信息

```
% php artisan command:chat:rds
```

## 调试

### 启动服务

- 启动 `connmix` 引擎

```
% bin/connmix dev -f conf/connmix.yaml 
```

- 启动 `Laravel` 命令行

可以启动多个来增加性能，并不是越多越好，和 connmix cpus 核数量差不多即可

```
% php artisan command:chat:mem
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
