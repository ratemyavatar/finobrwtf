--@ ModuleScript ServerScriptService RmaData
local Players = game:GetService("Players")
local DataStoreService = game:GetService("DataStoreService")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local Config = require(ReplicatedStorage:WaitForChild("RmaConfig"))

local Data = {}
Data.Loaded = {}
Data.Changed = Instance.new("BindableEvent")

local store
pcall(function()
	store = DataStoreService:GetDataStore("RmaPlayers_v1")
end)

local ordered = {}
local ORDERED_KEYS = { "minutes", "streak", "spent", "minesweeper", "uno", "received" }
for _, k in ipairs(ORDERED_KEYS) do
	pcall(function()
		ordered[k] = DataStoreService:GetOrderedDataStore("RmaTop_" .. k)
	end)
end

local function defaults()
	return {
		credits = Config.StartingCredits,
		minutes = 0,
		streak = 0,
		lastDay = 0,
		spent = 0,
		minesweeper = 0,
		uno = 0,
		given = 0,
		received = 0,
		ratingSum = 0,
		ratingCount = 0,
		rated = {},
		tools = {},
		styles = { ["Default Booth"] = true },
		titles = { newbie = true },
		equippedTitle = "newbie",
		bio = "",
		booth = { description = "", image = "", primary = "", accent = "", blacklist = {}, style = "Default Booth" },
		settings = {},
		rulesAgreed = false,
	}
end

local function merge(into, from)
	for k, v in pairs(from) do
		if type(v) == "table" and type(into[k]) == "table" then
			merge(into[k], v)
		else
			into[k] = v
		end
	end
	return into
end

function Data.Load(player)
	local d = defaults()
	if store then
		local ok, saved = pcall(function()
			return store:GetAsync("p_" .. player.UserId)
		end)
		if ok and type(saved) == "table" then
			merge(d, saved)
		end
	end
	local today = math.floor(os.time() / 86400)
	if d.lastDay == today - 1 then
		d.streak = d.streak + 1
	elseif d.lastDay ~= today then
		d.streak = 1
	end
	d.lastDay = today
	Data.Loaded[player] = d
	Data.Changed:Fire(player)
	return d
end

function Data.Get(player)
	return Data.Loaded[player]
end

function Data.Save(player)
	local d = Data.Loaded[player]
	if not d or not store then
		return
	end
	pcall(function()
		store:SetAsync("p_" .. player.UserId, d)
	end)
	for _, k in ipairs(ORDERED_KEYS) do
		local os_ = ordered[k]
		if os_ then
			pcall(function()
				os_:SetAsync(tostring(player.UserId), math.floor(d[k] or 0))
			end)
		end
	end
end

function Data.Top(key, count)
	local os_ = ordered[key]
	if not os_ then
		return {}
	end
	local out = {}
	pcall(function()
		local pages = os_:GetSortedAsync(false, count or 10)
		for _, entry in ipairs(pages:GetCurrentPage()) do
			table.insert(out, { userId = tonumber(entry.key), value = entry.value })
		end
	end)
	return out
end

function Data.AddCredits(player, amount)
	local d = Data.Loaded[player]
	if not d then
		return false
	end
	if amount < 0 and d.credits + amount < 0 then
		return false
	end
	d.credits = d.credits + amount
	if amount < 0 then
		d.spent = d.spent - amount
	end
	Data.Changed:Fire(player)
	return true
end

function Data.Release(player)
	Data.Save(player)
	Data.Loaded[player] = nil
end

Players.PlayerRemoving:Connect(function(player)
	Data.Release(player)
end)

game:BindToClose(function()
	for player in pairs(Data.Loaded) do
		Data.Save(player)
	end
end)

spawn(function()
	while true do
		wait(120)
		for player in pairs(Data.Loaded) do
			if player.Parent then
				Data.Save(player)
			end
		end
	end
end)

return Data
