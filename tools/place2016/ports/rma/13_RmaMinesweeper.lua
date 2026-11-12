--@ Script ServerScriptService RmaMinesweeper
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local Data = require(script.Parent:WaitForChild("RmaData"))

local Folder = workspace:WaitForChild("Default"):WaitForChild("World"):WaitForChild("Minigames"):WaitForChild("Minesweeper")
local Remotes = ReplicatedStorage:WaitForChild("RmaRemotes")
local Event = Remotes:WaitForChild("Event")

local MINE_RATIO = 0.15
local WIN_CREDITS = 150
local NUMBER_COLORS = {
	Color3.fromRGB(40, 120, 255), Color3.fromRGB(40, 160, 60), Color3.fromRGB(230, 50, 50), Color3.fromRGB(30, 30, 160),
	Color3.fromRGB(140, 30, 30), Color3.fromRGB(30, 150, 150), Color3.fromRGB(20, 20, 20), Color3.fromRGB(120, 120, 120),
}

local tiles = {}
for _, c in ipairs(Folder:GetChildren()) do
	if c:IsA("BasePart") and c.Name == "Tile" then
		table.insert(tiles, c)
	end
end
if #tiles == 0 then
	return
end

local basis = tiles[1].CFrame - tiles[1].Position
local function localPos(p)
	return basis:pointToObjectSpace(p.Position)
end
local function uniqueSorted(values)
	table.sort(values)
	local out = {}
	for _, v in ipairs(values) do
		if #out == 0 or math.abs(v - out[#out]) > 1 then
			table.insert(out, v)
		end
	end
	return out
end
local xs, zs = {}, {}
for _, t in ipairs(tiles) do
	local l = localPos(t)
	table.insert(xs, l.X)
	table.insert(zs, l.Z)
end
xs, zs = uniqueSorted(xs), uniqueSorted(zs)
local function indexOf(list, v)
	local best, bestD = 1, math.huge
	for i, x in ipairs(list) do
		local d = math.abs(x - v)
		if d < bestD then
			best, bestD = i, d
		end
	end
	return best
end

local W, H = #xs, #zs
local grid = {}
local cellOf = {}
for x = 1, W do
	grid[x] = {}
end
for _, t in ipairs(tiles) do
	local l = localPos(t)
	local x, z = indexOf(xs, l.X), indexOf(zs, l.Z)
	local cell = { Part = t, X = x, Z = z, Mine = false, Open = false, Flag = false, Count = 0 }
	grid[x][z] = cell
	cellOf[t] = cell
end

local state = { Running = false, Started = false, Opened = 0, Safe = 0, Contrib = {}, Lost = false }

local function gui(cell)
	return cell.Part:FindFirstChild("SurfaceGui")
end

local function show(cell, reveal)
	local g = gui(cell)
	if not g then
		return
	end
	local function vis(name, on)
		local o = g:FindFirstChild(name)
		if o then
			o.Visible = on
		end
	end
	if cell.Open or reveal then
		vis("Unopened", false)
		vis("Opened", true)
		vis("Flag", false)
		if cell.Mine then
			vis("Mine", true)
			vis("Mines", false)
		else
			vis("Mine", false)
			local label = g:FindFirstChild("Mines")
			if label then
				label.Visible = cell.Count > 0
				label.Text = cell.Count > 0 and tostring(cell.Count) or ""
				label.TextColor3 = NUMBER_COLORS[cell.Count] or Color3.new(1, 1, 1)
				label.BackgroundTransparency = 1
				label.TextScaled = true
				label.Font = Enum.Font.SourceSansBold
			end
		end
	else
		vis("Unopened", true)
		vis("Opened", false)
		vis("Mine", false)
		vis("Mines", false)
		vis("OwnerMine", false)
		vis("Flag", cell.Flag)
	end
end

local function neighbours(cell)
	local out = {}
	for dx = -1, 1 do
		for dz = -1, 1 do
			if not (dx == 0 and dz == 0) then
				local col = grid[cell.X + dx]
				local n = col and col[cell.Z + dz]
				if n then
					table.insert(out, n)
				end
			end
		end
	end
	return out
end

local function reset()
	for x = 1, W do
		for z = 1, H do
			local c = grid[x][z]
			if c then
				c.Mine, c.Open, c.Flag, c.Count = false, false, false, 0
				show(c)
			end
		end
	end
	state.Running = true
	state.Started = false
	state.Opened = 0
	state.Contrib = {}
	state.Lost = false
	local total = 0
	for x = 1, W do
		for z = 1, H do
			if grid[x][z] then
				total = total + 1
			end
		end
	end
	state.Total = total
	state.MineCount = math.floor(total * MINE_RATIO)
	state.Safe = total - state.MineCount
end

local function plant(safeCell)
	local avoid = { [safeCell] = true }
	for _, n in ipairs(neighbours(safeCell)) do
		avoid[n] = true
	end
	local pool = {}
	for x = 1, W do
		for z = 1, H do
			local c = grid[x][z]
			if c and not avoid[c] then
				table.insert(pool, c)
			end
		end
	end
	for i = 1, math.min(state.MineCount, #pool) do
		local j = math.random(i, #pool)
		pool[i], pool[j] = pool[j], pool[i]
		pool[i].Mine = true
	end
	state.Safe = state.Total - math.min(state.MineCount, #pool)
	for x = 1, W do
		for z = 1, H do
			local c = grid[x][z]
			if c then
				local n = 0
				for _, nb in ipairs(neighbours(c)) do
					if nb.Mine then
						n = n + 1
					end
				end
				c.Count = n
			end
		end
	end
	state.Started = true
end

local function broadcast(text, kind)
	for _, p in ipairs(Players:GetPlayers()) do
		local char = p.Character
		local hrp = char and char:FindFirstChild("HumanoidRootPart")
		if hrp and (hrp.Position - tiles[1].Position).Magnitude < 250 then
			Event:FireClient(p, "Notify", text, kind)
		end
	end
end

local function finish(won, player)
	state.Running = false
	for x = 1, W do
		for z = 1, H do
			local c = grid[x][z]
			if c and c.Mine then
				show(c, true)
				if not won and player and c.LostBy then
					local g = gui(c)
					local own = g and g:FindFirstChild("OwnerMine")
					if own then
						own.Visible = true
					end
				end
			end
		end
	end
	if won then
		local names = {}
		for p, n in pairs(state.Contrib) do
			if p.Parent and n >= 3 then
				local d = Data.Get(p)
				if d then
					d.minesweeper = d.minesweeper + 1
					Data.AddCredits(p, WIN_CREDITS)
					Event:FireClient(p, "Payout", WIN_CREDITS)
				end
				table.insert(names, p.Name)
			end
		end
		broadcast("Minesweeper cleared! Winners: " .. table.concat(names, ", "), "good")
	else
		broadcast((player and player.Name or "Someone") .. " hit a mine! New board in 6 seconds.", "bad")
	end
	wait(6)
	reset()
end

local function flood(start, player)
	local stack = { start }
	while #stack > 0 do
		local c = table.remove(stack)
		if not c.Open and not c.Flag then
			c.Open = true
			state.Opened = state.Opened + 1
			state.Contrib[player] = (state.Contrib[player] or 0) + 1
			show(c)
			if c.Count == 0 and not c.Mine then
				for _, n in ipairs(neighbours(c)) do
					if not n.Open and not n.Mine then
						table.insert(stack, n)
					end
				end
			end
		end
	end
end

local function reveal(player, cell)
	if not state.Running or cell.Open or cell.Flag then
		return
	end
	if not state.Started then
		plant(cell)
	end
	if cell.Mine then
		cell.LostBy = true
		cell.Open = true
		show(cell)
		local char = player.Character
		local hrp = char and char:FindFirstChild("HumanoidRootPart")
		if hrp then
			local e = Instance.new("Explosion")
			e.BlastPressure = 0
			e.DestroyJointRadiusPercent = 0
			e.Position = cell.Part.Position
			e.Parent = workspace
		end
		spawn(function()
			finish(false, player)
		end)
		return
	end
	flood(cell, player)
	if state.Opened >= state.Safe then
		spawn(function()
			finish(true, player)
		end)
	end
end

local function toggleFlag(cell)
	if not state.Running or cell.Open then
		return
	end
	cell.Flag = not cell.Flag
	show(cell)
end

for _, t in ipairs(tiles) do
	local cd = t:FindFirstChildOfClass("ClickDetector")
	if not cd then
		cd = Instance.new("ClickDetector")
		cd.Parent = t
	end
	cd.MaxActivationDistance = 60
	cd.MouseClick:Connect(function(player)
		reveal(player, cellOf[t])
	end)
end

Event.OnServerEvent:Connect(function(player, action, part)
	if action == "MineFlag" and typeof(part) == "Instance" and cellOf[part] then
		toggleFlag(cellOf[part])
	end
end)

reset()
