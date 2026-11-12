--@ Script ServerScriptService RmaLeaderboards
local Players = game:GetService("Players")
local Data = require(script.Parent:WaitForChild("RmaData"))

local Boards = workspace:WaitForChild("Default"):WaitForChild("Map"):WaitForChild("Boards")

local BOARDS = {
	{ Part = "TimeLeaderboard", Key = "minutes", Label = "Time:", Title = "Time Leaderboard", Format = function(v)
		if v >= 60 then
			return string.format("%dh %dm", math.floor(v / 60), v % 60)
		end
		return v .. "m"
	end },
	{ Part = "StreakLeaderboard", Key = "streak", Label = "Days:", Title = "Daily Login Leaderboard" },
	{ Part = "MinesweeperLeaderboard", Key = "minesweeper", Label = "Wins:", Title = "Minesweeper Wins" },
	{ Part = "UnoLeaderboard", Key = "uno", Label = "Wins:", Title = "UNO Wins Leaderboard" },
	{ Part = "SpentLeaderboard", Key = "spent", Label = "Spent:", Title = "Total Credits Spent" },
}

local names = {}
local function nameOf(userId)
	if names[userId] then
		return names[userId]
	end
	local p = Players:GetPlayerByUserId(userId)
	if p then
		names[userId] = p.Name
		return p.Name
	end
	local ok, n = pcall(function()
		return Players:GetNameFromUserIdAsync(userId)
	end)
	names[userId] = ok and n or ("User " .. userId)
	return names[userId]
end

local heads = {}
local function headOf(userId)
	if heads[userId] then
		return heads[userId]
	end
	local ok, url = pcall(function()
		return Players:GetUserThumbnailAsync(userId, Enum.ThumbnailType.HeadShot, Enum.ThumbnailSize.Size100x100)
	end)
	heads[userId] = ok and url or ""
	return heads[userId]
end

local function comma(n)
	local s = tostring(math.floor(n or 0))
	while true do
		local k
		s, k = string.gsub(s, "^(-?%d+)(%d%d%d)", "%1,%2")
		if k == 0 then
			break
		end
	end
	return s
end

local function setup(def)
	local part = Boards:FindFirstChild(def.Part)
	local sg = part and part:FindFirstChild("SG")
	local list = sg and sg:FindFirstChildOfClass("ScrollingFrame")
	if not list then
		return nil
	end
	local handler = part:FindFirstChildOfClass("Script")
	local template = handler and handler:FindFirstChild("Template")
	if not template then
		template = list:FindFirstChild("Row1")
	end
	if not template then
		return nil
	end
	template = template:Clone()
	local title = sg:FindFirstChild("Title")
	if title then
		title.Text = def.Title
	end
	return { Def = def, List = list, Template = template }
end

local boards = {}
for _, def in ipairs(BOARDS) do
	local b = setup(def)
	if b then
		table.insert(boards, b)
	end
end

local function fill(b)
	local entries = Data.Top(b.Def.Key, 20)
	local live = {}
	for _, p in ipairs(Players:GetPlayers()) do
		local d = Data.Get(p)
		if d then
			live[p.UserId] = d[b.Def.Key] or 0
		end
	end
	local seen = {}
	for _, e in ipairs(entries) do
		seen[e.userId] = true
		if live[e.userId] then
			e.value = math.max(e.value, live[e.userId])
		end
	end
	for id, v in pairs(live) do
		if not seen[id] and v > 0 then
			table.insert(entries, { userId = id, value = v })
		end
	end
	table.sort(entries, function(x, y)
		return x.value > y.value
	end)
	for _, c in ipairs(b.List:GetChildren()) do
		if c:IsA("Frame") and (c.Name:match("^Row") or c.Name == "Template") then
			c:Destroy()
		end
	end
	local empty = b.List:FindFirstChild("EmptyState")
	local shown = 0
	for i, e in ipairs(entries) do
		if i > 20 or e.value <= 0 then
			break
		end
		shown = shown + 1
		local row = b.Template:Clone()
		row.Name = "Row" .. i
		row.LayoutOrder = i
		row.Visible = true
		local fmt = b.Def.Format or comma
		local set = function(n, v)
			local l = row:FindFirstChild(n)
			if l then
				l.Text = v
			end
		end
		set("R", "#" .. i)
		set("RS", "#" .. i)
		set("RShine", "#" .. i)
		set("U", nameOf(e.userId))
		set("T", b.Def.Label)
		set("TV", fmt(e.value))
		local shine = row:FindFirstChild("RShine")
		if shine then
			shine.Visible = i <= 3
		end
		local pic = row:FindFirstChild("P")
		if pic then
			pic.Image = headOf(e.userId)
		end
		row.Parent = b.List
	end
	if empty then
		empty.Visible = shown == 0
	end
	b.List.CanvasSize = UDim2.new(0, 0, 0, shown * 84)
end

while true do
	for _, b in ipairs(boards) do
		pcall(fill, b)
	end
	wait(60)
end
