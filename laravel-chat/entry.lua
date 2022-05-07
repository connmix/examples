require("prettyprint")
local mix_log = mix.log
local mix_DEBUG = mix.DEBUG
local websocket = require("protocols/websocket")
local chat_queue = "chat"
local conn_queue = "conn"

function init()
    mix.queue.new(chat_queue, 100)
    mix.queue.new(conn_queue, 100)
end

function on_connect(conn)
    local s, err = mix.json_encode({ event = "connect" })
    if err then
       mix_log(mix_DEBUG, "json_encode error: " .. err)
       return
    end
    local n, err = mix.queue.push(conn_queue, s)
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end
end

function on_close(err, conn)
    --print(err)
    local s, err = mix.json_encode({ event = "close", uid = conn:context()[auth_key] })
    if err then
       mix_log(mix_DEBUG, "json_encode error: " .. err)
       return
    end
    local n, err = mix.queue.push(conn_queue, s)
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end
end

--buf为一个对象，是一个副本
--返回值必须为int, 返回包截止的长度 0=继续等待,-1=断开连接
function protocol_input(buf, conn)
    return websocket.input(buf, conn, "/chat")
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

    local auth_op = "auth"
    local auth_key = "uid"

    local s, err = mix.json_encode({ frame = data, uid = conn:context()[auth_key] })
    if err then
       mix_log(mix_DEBUG, "json_encode error: " .. err)
       return
    end

    local tb, err = mix.json_decode(data["data"])
    if err then
       mix_log(mix_DEBUG, "json_decode error: " .. err)
       return
    end

    local n, err = mix.queue.push(chat_queue, s)
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end

    if tb["op"] == auth_op then
       conn:wait_context_value(auth_key)
    end
end
