<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nette\Utils\ArrayHash;
use phpDocumentor\Reflection\DocBlock\Tags\BaseTag;

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
            $node->consume('chat');
        };
        $onReceive = function (\Connmix\AsyncNodeInterface $node) {
            $message = $node->message();
            switch ($message->type()) {
                case "consume":
                    $clientID = $message->clientID();
                    $data = $message->data();

                    // 解析
                    $json = json_decode($data['frame']['data'], true);
                    if (empty($json)) {
                        $node->meshSend($clientID, '{"error":"Json format error"}');
                        return;
                    }
                    $op = $json['op'] ?? '';
                    $args = $json['args'] ?? [];
                    $uid = $data['uid'] ?? 0;

                    // 业务逻辑
                    switch ($op) {
                        case 'auth':
                            $this->auth($node, $clientID, $args);
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
                        default:
                            return;
                    }
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
        $client->do($onConnect, $onReceive, $onError);
        return 0;
    }

    /**
     * @param \Connmix\AsyncNodeInterface $node
     * @param int $clientID
     * @param array $args
     * @return void
     */
    protected function auth(\Connmix\AsyncNodeInterface $node, int $clientID, array $args)
    {
        list($name, $password) = $args;
        $row = \App\Models\User::query()->where('name', '=', $name)->where('password', '=', $password)->first();
        if (empty($row)) {
            // 验证失败，设置一个特殊值解除 lua 代码阻塞
            $node->setContextValue($clientID, 'user_id', 0);
            $node->meshSend($clientID, '{"op":"auth","error":"Invalid name or password"}');
            return;
        }

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
    protected function subscribe(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid)
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
    protected function unsubscribe(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid)
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
    protected function sendToRoom(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid)
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
    protected function sendToUser(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid)
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
    protected function sendBroadcast(\Connmix\AsyncNodeInterface $node, int $clientID, array $args, int $uid)
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
}
