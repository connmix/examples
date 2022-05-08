require("prettyprint")
local mix_log = mix.log
local mix_DEBUG = mix.DEBUG
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
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end
end

function on_handshake(headers, conn)
    --print(headers)
    local n, err = mix.queue.push(queue_conn, { event = "handshake", headers = headers })
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
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
       mix_log(mix_DEBUG, "json_decode error: " .. err)
       return
    end

    local ctx = conn:context()
    local tb = { frame = data, uid = ctx[auth_key] }
    if msg["op"] == auth_op then
        tb["headers"] = ctx["headers"]
    end
    local n, err = mix.queue.push(queue_chat, tb)
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end

    if msg["op"] == auth_op then
       conn:wait_context_value(auth_key)
    end
end
