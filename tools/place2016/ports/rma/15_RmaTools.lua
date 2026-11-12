--@ Script ServerScriptService RmaTools
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Tools = ReplicatedStorage:WaitForChild("Tools")
local Event = ReplicatedStorage:WaitForChild("RmaRemotes"):WaitForChild("Event")

local function weldTo(handle, part)
	part.Anchored = false
	part.CanCollide = false
	local w = Instance.new("Weld")
	w.Part0 = handle
	w.Part1 = part
	w.C0 = handle.CFrame:inverse() * part.CFrame
	w.Parent = handle
end

local function prepare(tool)
	local handle = tool:FindFirstChild("Handle")
	if tool.Name == "TextSign" and not handle then
		handle = Instance.new("Part")
		handle.Name = "Handle"
		handle.Size = Vector3.new(0.3, 5, 0.3)
		handle.BrickColor = BrickColor.new("Reddish brown")
		handle.Material = Enum.Material.Wood
		handle.CanCollide = false
		local board = Instance.new("Part")
		board.Name = "Board"
		board.Size = Vector3.new(4, 2.4, 0.2)
		board.BrickColor = BrickColor.new("Institutional white")
		board.CanCollide = false
		board.CFrame = handle.CFrame * CFrame.new(0, 3.4, 0)
		local sg = Instance.new("SurfaceGui")
		sg.Name = "SignGui"
		sg.Face = Enum.NormalId.Front
		sg.CanvasSize = Vector2.new(400, 240)
		local label = Instance.new("TextLabel")
		label.Name = "Text"
		label.Size = UDim2.new(1, -20, 1, -20)
		label.Position = UDim2.new(0, 10, 0, 10)
		label.BackgroundTransparency = 1
		label.Font = Enum.Font.SourceSansBold
		label.TextScaled = true
		label.TextWrapped = true
		label.TextColor3 = Color3.new(0, 0, 0)
		label.Text = "Rate my avatar!"
		label.Parent = sg
		sg.Parent = board
		local back = sg:Clone()
		back.Face = Enum.NormalId.Back
		back.Parent = board
		board.Parent = tool
		handle.Parent = tool
		weldTo(handle, board)
		tool.Grip = CFrame.new(0, -1.2, 0)
	elseif tool.Name == "Petition" and handle then
		local paper = tool:FindFirstChild("Paper")
		if paper then
			weldTo(handle, paper)
		end
	end
	for _, d in ipairs(tool:GetDescendants()) do
		if d:IsA("BasePart") then
			d.Anchored = false
			if d ~= handle then
				d.CanCollide = false
			end
		end
	end
end

for _, t in ipairs(Tools:GetChildren()) do
	if t:IsA("Tool") then
		pcall(prepare, t)
	end
end

local FOOD = { Pizza = true, Burger = true, ["Halloween Candy"] = true, BloxyCola = true }
local SIGNS = { Approval = true, Disapproval = true }
local COILS = { SpeedCoil = 24, GoldenSpeedCoil = 32 }

local function humanoidOf(tool)
	local char = tool.Parent
	return char and char:FindFirstChild("Humanoid"), char
end

local function play(handle, name)
	local s = handle and handle:FindFirstChild(name)
	if s and s:IsA("Sound") then
		s:Play()
		return s
	end
end

local hooked = setmetatable({}, { __mode = "k" })

local function hook(tool)
	if hooked[tool] then
		return
	end
	hooked[tool] = true
	local handle = tool:FindFirstChild("Handle")
	local baseGrip = tool.Grip
	local busy = false

	if FOOD[tool.Name] then
		tool.Equipped:Connect(function()
			play(handle, "OpenSound")
		end)
		tool.Activated:Connect(function()
			if busy then
				return
			end
			busy = true
			tool.Grip = baseGrip * CFrame.Angles(math.rad(-70), 0, 0) * CFrame.new(0, 0.6, -0.5)
			play(handle, "DrinkSound")
			local h = humanoidOf(tool)
			if h and h.Health > 0 then
				h.Health = math.min(h.MaxHealth, h.Health + 5)
			end
			wait(1.2)
			tool.Grip = baseGrip
			wait(0.3)
			busy = false
		end)
	elseif SIGNS[tool.Name] then
		tool.Activated:Connect(function()
			if busy then
				return
			end
			busy = true
			play(handle, "AngrySound" .. math.random(1, 3))
			for _ = 1, 3 do
				tool.Grip = baseGrip * CFrame.new(0, -0.8, 0)
				wait(0.15)
				tool.Grip = baseGrip
				wait(0.15)
			end
			busy = false
		end)
	elseif COILS[tool.Name] then
		local speed = COILS[tool.Name]
		tool.Equipped:Connect(function()
			local h = humanoidOf(tool)
			if h then
				h.WalkSpeed = speed
			end
			play(handle, "CoilSound")
		end)
		tool.Unequipped:Connect(function()
			for _, pl in ipairs(Players:GetPlayers()) do
				if tool:IsDescendantOf(pl) and pl.Character then
					local h = pl.Character:FindFirstChild("Humanoid")
					if h then
						h.WalkSpeed = 16
					end
				end
			end
		end)
	elseif tool.Name == "Crescendo" then
		local fire = handle and handle:FindFirstChild("Fire", true)
		local cooldown = 0.6
		local settings = tool:FindFirstChild("Settings")
		local cd = settings and settings:FindFirstChild("Cooldown")
		if cd then
			cooldown = math.max(0.2, cd.Value)
		end
		tool.Activated:Connect(function()
			if busy then
				return
			end
			busy = true
			if fire then
				fire.Size = 10
				fire.Heat = 20
			end
			tool.Grip = baseGrip * CFrame.Angles(math.rad(90), 0, 0)
			wait(0.25)
			tool.Grip = baseGrip
			if fire then
				fire.Size = 5
				fire.Heat = 9
			end
			wait(cooldown)
			busy = false
		end)
	end
end

local function watchCharacter(char)
	char.ChildAdded:Connect(function(c)
		if c:IsA("Tool") then
			hook(c)
		end
	end)
	for _, c in ipairs(char:GetChildren()) do
		if c:IsA("Tool") then
			hook(c)
		end
	end
end

local function onPlayer(p)
	p.CharacterAdded:Connect(watchCharacter)
	if p.Character then
		watchCharacter(p.Character)
	end
end
Players.PlayerAdded:Connect(onPlayer)
for _, p in ipairs(Players:GetPlayers()) do
	onPlayer(p)
end

local function heldTool(player, name)
	local char = player.Character
	local t = char and char:FindFirstChild(name)
	if t and t:IsA("Tool") then
		return t
	end
end

local function filter(text, player)
	local ok, out = pcall(function()
		return game:GetService("Chat"):FilterStringForBroadcast(text, player)
	end)
	return ok and out or text
end

local petitions = setmetatable({}, { __mode = "k" })
local lastAsk = {}

Event.OnServerEvent:Connect(function(player, action, a, b)
	if action == "SignText" then
		local t = heldTool(player, "TextSign")
		local board = t and t:FindFirstChild("Board")
		if board then
			local text = filter(tostring(a or ""):sub(1, 100), player)
			for _, g in ipairs(board:GetChildren()) do
				local l = g:IsA("SurfaceGui") and g:FindFirstChild("Text")
				if l then
					l.Text = text
				end
			end
		end
	elseif action == "BoomBox" then
		local t = heldTool(player, "BoomBox")
		local s = t and t.Handle:FindFirstChild("Sound")
		if s then
			local id = tonumber(a)
			s:Stop()
			if id then
				s.SoundId = "rbxassetid://" .. id
				s.Looped = true
				s.Volume = 0.6
				s:Play()
			end
		end
	elseif action == "PetitionAsk" then
		local t = heldTool(player, "Petition")
		local target = typeof(a) == "Instance" and a:IsA("Player") and a or nil
		if not t or not target or target == player or not target.Character or not player.Character then
			return
		end
		local key = player.UserId .. ":" .. target.UserId
		if lastAsk[key] and tick() - lastAsk[key] < 15 then
			Event:FireClient(player, "Notify", "Wait a bit before asking " .. target.Name .. " again.", "bad")
			return
		end
		local d = (player.Character.Head.Position - target.Character.Head.Position).Magnitude
		if d > 20 then
			Event:FireClient(player, "Notify", "Get closer to " .. target.Name .. ".", "bad")
			return
		end
		lastAsk[key] = tick()
		petitions[t] = petitions[t] or { signed = {}, info = filter(tostring(b or "Sign my petition!"):sub(1, 80), player) }
		Event:FireClient(target, "PetitionAsk", player, petitions[t].info)
		Event:FireClient(player, "Notify", "Asked " .. target.Name .. " to sign.", "info")
	elseif action == "PetitionAnswer" then
		local owner = typeof(a) == "Instance" and a:IsA("Player") and a or nil
		local t = owner and heldTool(owner, "Petition") or (owner and owner:FindFirstChild("Backpack") and owner.Backpack:FindFirstChild("Petition"))
		if not t or not petitions[t] or petitions[t].signed[player] then
			return
		end
		if b == true then
			petitions[t].signed[player] = true
			local paper = t:FindFirstChild("Paper")
			local sg = paper and paper:FindFirstChild("PetitionSurfaceGUI")
			local list = sg and sg:FindFirstChild("Frame")
			if list then
				local tmpl = sg:FindFirstChild("NAMEE")
				local l = tmpl and tmpl:Clone() or Instance.new("TextLabel")
				l.Name = "Signed"
				l.Text = player.Name
				l.Visible = true
				l.Parent = list
			end
			Event:FireClient(owner, "Notify", player.Name .. " signed your petition!", "good")
		else
			Event:FireClient(owner, "Notify", player.Name .. " refused to sign.", "bad")
		end
	end
end)
