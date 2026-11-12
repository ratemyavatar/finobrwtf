local C = {}
local G = {}
C.globals = G

local realEnum = Enum
local realInstance = Instance
local realColor3 = Color3
local realCFrame = CFrame
local realVector3 = Vector3
local realVector2 = Vector2
local realUDim2 = UDim2
local realUDim = UDim

local RunService = game:GetService("RunService")
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local IS_SERVER = RunService:IsServer() and not rawget(_G, "__COMPAT_CLIENT")
local IS_CLIENT = not IS_SERVER
C.IS_SERVER = IS_SERVER

local function idx(o, k) return o[k] end
local function setidx(o, k, v) o[k] = v end
local function weak() return setmetatable({}, { __mode = "k" }) end
C.weak = weak
C.idx = idx
C.setidx = setidx

local HIDDEN = "^__compat"
C.HIDDEN = HIDDEN
local function hidden(o)
	local ok, n = pcall(idx, o, "Name")
	return ok and type(n) == "string" and n:find(HIDDEN) ~= nil
end
C.hidden = hidden

local instCache = weak()
local function isInstance(o)
	if type(o) ~= "userdata" then return false end
	local c = instCache[o]
	if c ~= nil then return c end
	local ok, r = pcall(game.IsA, o, "Instance")
	c = ok and r == true
	instCache[o] = c
	return c
end
C.isInstance = isInstance

local function has(o, k)
	local ok, v = pcall(idx, o, k)
	return ok and v ~= nil, v
end

local typeCache = weak()
local function typeof(v)
	local t = type(v)
	if t == "table" then
		local ct = rawget(v, "__type")
		if ct then return ct end
		local mt = getmetatable(v)
		if type(mt) == "table" and rawget(mt, "__type") then return rawget(mt, "__type") end
		return "table"
	end
	if t ~= "userdata" then return t end
	local c = typeCache[v]
	if c then return c end
	if isInstance(v) then
		c = "Instance"
	elseif has(v, "lookVector") then
		c = "CFrame"
	elseif has(v, "Number") and has(v, "Color") then
		c = "BrickColor"
	elseif has(v, "Z") then
		if has(v, "unit") then c = "Vector3" else c = "Vector3int16" end
	elseif has(v, "r") and has(v, "g") and has(v, "b") then
		c = "Color3"
	elseif has(v, "Scale") and has(v, "Offset") then
		c = "UDim"
	elseif has(v, "X") and has(v, "Y") then
		local _, x = has(v, "X")
		if type(x) == "number" then
			if has(v, "unit") then c = "Vector2" else c = "Vector2int16" end
		else
			c = "UDim2"
		end
	elseif has(v, "Keypoints") then
		local _, kp = has(v, "Keypoints")
		local first = kp and kp[1]
		if first and type(first.Value) == "number" then c = "NumberSequence" else c = "ColorSequence" end
	elseif has(v, "Envelope") then
		c = "NumberSequenceKeypoint"
	elseif has(v, "Time") and has(v, "Value") then
		c = "ColorSequenceKeypoint"
	elseif has(v, "Origin") and has(v, "Direction") then
		c = "Ray"
	elseif has(v, "Min") and has(v, "Max") then
		local _, mn = has(v, "Min")
		if type(mn) == "number" then c = "NumberRange" else c = "Rect" end
	elseif has(v, "Density") then
		c = "PhysicalProperties"
	elseif has(v, "CFrame") and has(v, "Size") then
		c = "Region3"
	elseif has(v, "connect") and has(v, "wait") then
		c = "RBXScriptSignal"
	elseif has(v, "disconnect") then
		c = "RBXScriptConnection"
	elseif has(v, "GetEnumItems") then
		c = "Enum"
	elseif has(v, "Name") and has(v, "Value") then
		c = "EnumItem"
	else
		c = "userdata"
	end
	typeCache[v] = c
	return c
end
C.typeof = typeof
G.typeof = typeof

local spawnEvent = realInstance.new("BindableEvent")
local spawnQueue = {}
spawnEvent.Event:connect(function()
	local job = table.remove(spawnQueue, 1)
	if job and not job.token.cancelled then
		job.token.thread = coroutine.running()
		job.f(unpack(job.a, 1, job.n))
	end
end)
local function fastSpawn(f, ...)
	local token = { __type = "thread" }
	spawnQueue[#spawnQueue + 1] = { f = f, a = { ... }, n = select("#", ...), token = token }
	spawnEvent:Fire()
	return token
end
C.spawn = fastSpawn

local Heartbeat = RunService.Heartbeat
local function waitFor(n)
	n = tonumber(n) or 0
	local start = tick()
	if n < 0.03 then
		Heartbeat:wait()
	else
		wait(n)
	end
	return tick() - start
end

local task = {}
task.wait = waitFor
function task.spawn(f, ...)
	if type(f) == "thread" then
		local ok, err = coroutine.resume(f, ...)
		if not ok then warn(err) end
		return f
	end
	return fastSpawn(f, ...)
end
function task.defer(f, ...)
	local args, n = { ... }, select("#", ...)
	local token = { __type = "thread" }
	delay(0, function()
		if token.cancelled then return end
		if type(f) == "thread" then coroutine.resume(f, unpack(args, 1, n)) else f(unpack(args, 1, n)) end
	end)
	return token
end
function task.delay(t, f, ...)
	local args, n = { ... }, select("#", ...)
	local token = { __type = "thread" }
	delay(tonumber(t) or 0, function()
		if token.cancelled then return end
		if type(f) == "thread" then coroutine.resume(f, unpack(args, 1, n)) else f(unpack(args, 1, n)) end
	end)
	return token
end
function task.cancel(token)
	if type(token) == "table" then token.cancelled = true end
end
function task.synchronize() end
function task.desynchronize() end
G.task = task

local Signal = {}
Signal.__index = Signal
Signal.__type = "RBXScriptSignal"
C.Signal = Signal

local Connection = {}
Connection.__index = Connection
Connection.__type = "RBXScriptConnection"

function Signal.new(onFirst)
	return setmetatable({ list = {}, onFirst = onFirst }, Signal)
end
function Signal:Connect(fn)
	local conn = setmetatable({ Connected = true, fn = fn, signal = self }, Connection)
	self.list[#self.list + 1] = conn
	if self.onFirst then
		local f = self.onFirst
		self.onFirst = nil
		f(self)
	end
	return conn
end
Signal.connect = Signal.Connect
function Signal:Once(fn)
	local conn
	conn = self:Connect(function(...)
		conn:Disconnect()
		fn(...)
	end)
	return conn
end
function Signal:Fire(...)
	local list = self.list
	for i = 1, #list do
		local conn = list[i]
		if conn and conn.Connected then fastSpawn(conn.fn, ...) end
	end
end
function Signal:Wait()
	local be = realInstance.new("BindableEvent")
	local args, n
	local conn
	conn = self:Connect(function(...)
		conn:Disconnect()
		args, n = { ... }, select("#", ...)
		be:Fire()
	end)
	if not args then be.Event:wait() end
	be:Destroy()
	return unpack(args, 1, n)
end
Signal.wait = Signal.Wait
function Signal:DisconnectAll()
	for _, conn in ipairs(self.list) do conn.Connected = false end
	self.list = {}
end
Signal.Destroy = Signal.DisconnectAll
function Connection:Disconnect()
	if not self.Connected then return end
	self.Connected = false
	local list = self.signal.list
	for i = #list, 1, -1 do
		if list[i] == self then table.remove(list, i) break end
	end
	if self.signal.onEmpty and #list == 0 then self.signal.onEmpty(self.signal) end
end
Connection.disconnect = Connection.Disconnect
Connection.Destroy = Connection.Disconnect

local Proxy = {}
Proxy.__index = Proxy
Proxy.__type = "RBXScriptSignal"
function C.proxySignal(connectFn)
	return setmetatable({ connectFn = connectFn }, Proxy)
end
function Proxy:Connect(fn)
	local real = self.connectFn(fn)
	return setmetatable({ Connected = true, real = real }, { __index = {
		Disconnect = function(c)
			if c.Connected then
				c.Connected = false
				if type(c.real) == "table" then c.real:Disconnect() elseif c.real then c.real:disconnect() end
			end
		end,
		disconnect = function(c) c:Disconnect() end,
	}, __type = "RBXScriptConnection" })
end
Proxy.connect = Proxy.Connect
Proxy.Once = Signal.Once
Proxy.Wait = Signal.Wait
Proxy.wait = Signal.Wait

function C.connect(sig, fn)
	if type(sig) == "table" then return sig:Connect(fn) end
	return sig:connect(fn)
end
function C.waitSignal(sig)
	if type(sig) == "table" then return sig:Wait() end
	return sig:wait()
end

