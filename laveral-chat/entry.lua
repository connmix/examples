require("prettyprint")
local mix_log = mix.log
local mix_DEBUG = mix.DEBUG
local websocket = require("protocols/websocket")
local queue_name = "chat"

function init()
    mix.queue.new(queue_name, 100)
end

function on_connect(conn)
end

function on_close(err, conn)
    --print(err)
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

    local n, err = mix.queue.push(queue_name, s)
    if err then
       mix_log(mix_DEBUG, "queue push error: " .. err)
       return
    end

    local op = tb["op"]
    if op == "auth" then
       conn:wait_context_value(auth_key)
    end
end
