--@ Script ServerScriptService RmaServer
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local ServerStorage = game:GetService("ServerStorage")
local MarketplaceService = game:GetService("MarketplaceService")
local InsertService = game:GetService("InsertService")
local Chat = game:GetService("Chat")

local Config = require(ReplicatedStorage:WaitForChild("RmaConfig"))
local Data = require(script.Parent:WaitForChild("RmaData"))

local Remotes = Instance.new("Folder")
Remotes.Name = "RmaRemotes"
local Event = Instance.new("RemoteEvent")
Event.Name = "Event"
Event.Parent = Remotes
local Request = Instance.new("RemoteFunction")
Request.Name = "Request"
Request.Parent = Remotes
Remotes.Parent = ReplicatedStorage

local World = workspace:WaitForChild("Default"):WaitForChild("World")
local BoothFolder = World:WaitForChild("Booths")
local ToolFolder = ReplicatedStorage:WaitForChild("Tools")

local PassOwned = {}
local NextPayout = {}
local LastAction = {}
local LastModCall = {}
local Booths = {}
local BoothOf = {}
local StyleTemplates = {}

local function notify(player, text, kind)
	Event:FireClient(player, "Notify", text, kind or "info")
end

local function isAdmin(player)
	for _, id in ipairs(Config.Admins) do
		if player.UserId == id then
			return true
		end
	end
	local ok, creator = pcall(function()
		return game.CreatorId
	end)
	return ok and creator == player.UserId and player.UserId > 0
end

local function throttle(player, seconds, key)
	local now = tick()
	local t = LastAction[player]
	if not t then
		t = {}
		LastAction[player] = t
	end
	key = key or "any"
	if t[key] and now - t[key] < (seconds or 0.4) then
		return false
	end
	t[key] = now
	return true
end

local function filter(text, player)
	text = tostring(text or "")
	local ok, out = pcall(function()
		return Chat:FilterStringForBroadcast(text, player)
	end)
	if ok and type(out) == "string" then
		return out
	end
	return text
end

local function owns(player, key)
	return PassOwned[player] and PassOwned[player][key] == true
end

local function minutesOf(player)
	local d = Data.Get(player)
	return d and d.minutes or 0
end

local function average(d)
	if not d or d.ratingCount == 0 then
		return 0
	end
	return d.ratingSum / d.ratingCount
end

local function unlocked(player, title)
	local d = Data.Get(player)
	if not d then
		return false
	end
	if d.titles[title.Id] then
		return true
	end
	local u = title.Unlock
	if u.Kind == "default" then
		return true
	elseif u.Kind == "minutes" then
		return d.minutes >= u.Value
	elseif u.Kind == "given" then
		return d.given >= u.Value
	elseif u.Kind == "received" then
		return d.received >= u.Value
	elseif u.Kind == "streak" then
		return d.streak >= u.Value
	elseif u.Kind == "minesweeper" then
		return d.minesweeper >= u.Value
	elseif u.Kind == "uno" then
		return d.uno >= u.Value
	elseif u.Kind == "spent" then
		return d.spent >= u.Value
	elseif u.Kind == "pass" then
		return owns(player, u.Value)
	elseif u.Kind == "admin" then
		return isAdmin(player)
	end
	return false
end

local function refreshTitles(player)
	local d = Data.Get(player)
	if not d then
		return
	end
	for _, t in ipairs(Config.Titles) do
		if not d.titles[t.Id] and unlocked(player, t) then
			d.titles[t.Id] = true
			notify(player, "New title unlocked: " .. t.Text, "good")
		end
	end
	if not d.titles[d.equippedTitle or ""] then
		d.equippedTitle = "newbie"
	end
end

local function boothState(booth)
	return {
		id = booth.Index,
		name = booth.Model.Name,
		owner = booth.Owner and booth.Owner.UserId or nil,
		style = booth.Style,
	}
end

local function stateFor(player)
	local d = Data.Get(player)
	if not d then
		return nil
	end
	local b = BoothOf[player]
	local passes = {}
	for k, v in pairs(PassOwned[player] or {}) do
		passes[k] = v
	end
	local styles = {}
	for _, s in ipairs(Config.BoothStyles) do
		styles[s.Name] = d.styles[s.Name] == true or s.Price == 0
	end
	return {
		credits = d.credits,
		nextPayout = math.max(0, (NextPayout[player] or tick()) - tick()),
		minutes = d.minutes,
		streak = d.streak,
		spent = d.spent,
		given = d.given,
		received = d.received,
		average = average(d),
		ratingCount = d.ratingCount,
		minesweeper = d.minesweeper,
		uno = d.uno,
		tools = d.tools,
		styles = styles,
		titles = d.titles,
		equippedTitle = d.equippedTitle,
		bio = d.bio,
		booth = d.booth,
		boothId = b and b.Index or nil,
		settings = d.settings,
		rulesAgreed = d.rulesAgreed,
		passes = passes,
		admin = isAdmin(player),
		canDecal = owns(player, "Decals") or isAdmin(player) or d.minutes >= Config.DecalMinutes,
	}
end

local function push(player)
	local s = stateFor(player)
	if s then
		Event:FireClient(player, "State", s)
	end
end

local function rarityColor(id)
	local t = Config.Title(id)
	if not t then
		return Color3.new(1, 1, 1)
	end
	return Config.Rarity(t.Rarity).Color
end

local function updateTag(player)
	local character = player.Character
	local head = character and character:FindFirstChild("Head")
	if not head then
		return
	end
	local d = Data.Get(player)
	local gui = head:FindFirstChild("RmaTag")
	if not gui then
		gui = Instance.new("BillboardGui")
		gui.Name = "RmaTag"
		gui.Size = UDim2.new(0, 220, 0, 64)
		gui.StudsOffset = Vector3.new(0, 2.6, 0)
		gui.AlwaysOnTop = false
		gui.MaxDistance = 80
		local nameLabel = Instance.new("TextLabel")
		nameLabel.Name = "PlayerName"
		nameLabel.BackgroundTransparency = 1
		nameLabel.Size = UDim2.new(1, 0, 0.45, 0)
		nameLabel.Font = Enum.Font.SourceSansBold
		nameLabel.TextScaled = true
		nameLabel.TextStrokeTransparency = 0.4
		nameLabel.Parent = gui
		local titleLabel = Instance.new("TextLabel")
		titleLabel.Name = "Title"
		titleLabel.BackgroundTransparency = 1
		titleLabel.Position = UDim2.new(0, 0, 0.45, 0)
		titleLabel.Size = UDim2.new(1, 0, 0.3, 0)
		titleLabel.Font = Enum.Font.SourceSansBold
		titleLabel.TextScaled = true
		titleLabel.TextStrokeTransparency = 0.5
		titleLabel.Parent = gui
		local ratingLabel = Instance.new("TextLabel")
		ratingLabel.Name = "Rating"
		ratingLabel.BackgroundTransparency = 1
		ratingLabel.Position = UDim2.new(0, 0, 0.75, 0)
		ratingLabel.Size = UDim2.new(1, 0, 0.25, 0)
		ratingLabel.Font = Enum.Font.SourceSans
		ratingLabel.TextScaled = true
		ratingLabel.TextColor3 = Color3.fromRGB(255, 215, 90)
		ratingLabel.TextStrokeTransparency = 0.5
		ratingLabel.Parent = gui
		gui.Parent = head
	end
	gui.PlayerName.Text = player.Name
	gui.PlayerName.TextColor3 = owns(player, "VIP") and Color3.fromRGB(255, 210, 60) or Color3.new(1, 1, 1)
	local t = d and Config.Title(d.equippedTitle)
	gui.Title.Text = t and ("[" .. t.Text .. "]") or ""
	gui.Title.TextColor3 = rarityColor(d and d.equippedTitle)
	if d and d.ratingCount > 0 then
		gui.Rating.Text = string.format("%.1f / 5  (%d)", average(d), d.ratingCount)
	else
		gui.Rating.Text = "not rated yet"
	end
	local h = character:FindFirstChild("Humanoid")
	if h then
		pcall(function()
			h.DisplayDistanceType = Enum.HumanoidDisplayDistanceType.None
		end)
	end
end

local function giveTools(player)
	local d = Data.Get(player)
	if not d then
		return
	end
	local backpack = player:FindFirstChild("Backpack")
	local gear = player:FindFirstChild("StarterGear")
	for toolName, info in pairs(d.tools) do
		local wanted = type(info) == "table" and info.equipped
		local template = ToolFolder:FindFirstChild(toolName)
		if template then
			for _, holder in ipairs({ backpack, gear, player.Character }) do
				if holder then
					local existing = holder:FindFirstChild(toolName)
					if wanted and not existing and holder ~= player.Character then
						template:Clone().Parent = holder
					elseif not wanted and existing and existing:IsA("Tool") then
						existing:Destroy()
					end
				end
			end
		end
	end
end

local function topRated()
	local best, bestAvg = nil, -1
	for _, p in ipairs(Players:GetPlayers()) do
		local d = Data.Get(p)
		if d and d.ratingCount >= 1 then
			local a = average(d) + math.min(d.ratingCount, 50) / 1000
			if a > bestAvg then
				best, bestAvg = p, a
			end
		end
	end
	return best
end

local function updateTopRated()
	local model = workspace:FindFirstChild("TopRated")
	local screen = model and model:FindFirstChild("Screen")
	local gui = screen and screen:FindFirstChild("TopRatedDisplay")
	if not gui then
		return
	end
	local bg = gui:FindFirstChild("BG") or gui
	local label = bg:FindFirstChild("RmaTop")
	if not label then
		label = Instance.new("TextLabel")
		label.Name = "RmaTop"
		label.BackgroundTransparency = 1
		label.Size = UDim2.new(1, 0, 0.3, 0)
		label.Position = UDim2.new(0, 0, 0.7, 0)
		label.Font = Enum.Font.SourceSansBold
		label.TextScaled = true
		label.TextColor3 = Color3.new(1, 1, 1)
		label.TextStrokeTransparency = 0.3
		label.ZIndex = 5
		label.Parent = bg
		local img = Instance.new("ImageLabel")
		img.Name = "RmaHead"
		img.BackgroundTransparency = 1
		img.Size = UDim2.new(0.6, 0, 0.6, 0)
		img.Position = UDim2.new(0.2, 0, 0.05, 0)
		img.ZIndex = 5
		img.Parent = bg
	end
	local p = topRated()
	local head = bg:FindFirstChild("RmaHead")
	if p then
		local d = Data.Get(p)
		label.Text = "Top rated: " .. p.Name .. string.format("  %.1f / 5", average(d))
		if head then
			local ok, url = pcall(function()
				return Players:GetUserThumbnailAsync(p.UserId, Enum.ThumbnailType.HeadShot, Enum.ThumbnailSize.Size180x180)
			end)
			head.Image = ok and url or ""
		end
	else
		label.Text = "Nobody has been rated yet"
		if head then
			head.Image = ""
		end
	end
	Event:FireAllClients("TopRated", p and p.UserId or nil)
end

local function partsOf(booth)
	local list = {}
	local visuals = booth.Model:FindFirstChild("Visuals")
	if visuals then
		for _, d in ipairs(visuals:GetDescendants()) do
			if d:IsA("BasePart") then
				table.insert(list, d)
			end
		end
	end
	local root = booth.Model:FindFirstChild("Root")
	if root then
		table.insert(list, root)
	end
	return list
end

local function hexColor(hex)
	hex = tostring(hex or ""):gsub("#", "")
	if not hex:match("^%x%x%x%x%x%x$") then
		return nil
	end
	return Color3.new(tonumber(hex:sub(1, 2), 16) / 255, tonumber(hex:sub(3, 4), 16) / 255, tonumber(hex:sub(5, 6), 16) / 255)
end

local function bannerOf(booth)
	for _, d in ipairs(booth.Model:GetDescendants()) do
		if d:IsA("BasePart") and d.Name == "Banner" then
			return d
		end
	end
	return nil
end

local function bannerLabels(booth)
	local banner = bannerOf(booth)
	if not banner then
		return nil, nil
	end
	local desc, img
	for _, d in ipairs(banner:GetDescendants()) do
		if d:IsA("TextLabel") and d.Name == "Description" then
			desc = d
		elseif d:IsA("ImageLabel") and (d.Name == "DefaultImage" or d.Name == "Icon") then
			img = d
		end
	end
	return desc, img
end

local function ownerSign(booth)
	local root = booth.Model:FindFirstChild("Root")
	if not root then
		return nil
	end
	local sign = root:FindFirstChild("RmaOwner")
	if not sign then
		sign = Instance.new("BillboardGui")
		sign.Name = "RmaOwner"
		sign.Size = UDim2.new(0, 200, 0, 36)
		sign.StudsOffset = Vector3.new(0, 9, 0)
		sign.MaxDistance = 60
		local label = Instance.new("TextLabel")
		label.Name = "Label"
		label.BackgroundTransparency = 1
		label.Size = UDim2.new(1, 0, 1, 0)
		label.Font = Enum.Font.SourceSansBold
		label.TextScaled = true
		label.TextColor3 = Color3.new(1, 1, 1)
		label.TextStrokeTransparency = 0.3
		label.Parent = sign
		sign.Parent = root
	end
	return sign
end

local function decalImage(id)
	id = tonumber(id)
	if not id then
		return ""
	end
	local image = "rbxassetid://" .. id
	local ok, model = pcall(function()
		return InsertService:LoadAsset(id)
	end)
	if ok and model then
		for _, d in ipairs(model:GetDescendants()) do
			if d:IsA("Decal") or d:IsA("Texture") then
				image = d.Texture
				break
			end
		end
		model:Destroy()
	end
	return image
end

local function applyStyle(booth, styleName)
	local template = StyleTemplates[styleName]
	local root = booth.Model:FindFirstChild("Root")
	if not template or not root or booth.Style == styleName then
		return
	end
	local old = booth.Model:FindFirstChild("Visuals")
	local fresh = template.Visuals:Clone()
	local offset = root.CFrame * template.RootCFrame:inverse()
	for _, d in ipairs(fresh:GetDescendants()) do
		if d:IsA("BasePart") then
			d.CFrame = offset * d.CFrame
		end
	end
	if old then
		old:Destroy()
	end
	fresh.Parent = booth.Model
	booth.Style = styleName
	booth.Colors = {}
	for _, p in ipairs(partsOf(booth)) do
		booth.Colors[p] = p.Color
	end
	for _, d in ipairs(fresh:GetDescendants()) do
		if d:IsA("ClickDetector") then
			d.MouseClick:Connect(function(who)
				if booth.Owner == who then
					Event:FireClient(who, "OpenBooth")
				elseif booth.Owner then
					Event:FireClient(who, "OpenProfile", booth.Owner.UserId)
				end
			end)
		end
	end
end

local function applyBooth(booth)
	local desc, img = bannerLabels(booth)
	local sign = ownerSign(booth)
	local owner = booth.Owner
	local d = owner and Data.Get(owner)
	local style = d and d.booth.style or booth.DefaultStyle
	applyStyle(booth, style)
	desc, img = bannerLabels(booth)
	if owner and d then
		if desc then
			desc.Text = d.booth.description ~= "" and d.booth.description or (owner.Name .. "'s booth")
		end
		if img then
			if d.booth.image ~= "" then
				img.Image = d.booth.image
			else
				local ok, url = pcall(function()
					return Players:GetUserThumbnailAsync(owner.UserId, Enum.ThumbnailType.HeadShot, Enum.ThumbnailSize.Size420x420)
				end)
				img.Image = ok and url or ""
			end
		end
		if sign then
			sign.Label.Text = owner.Name .. "'s booth"
			sign.Enabled = true
		end
		local primary = hexColor(d.booth.primary)
		local accent = hexColor(d.booth.accent)
		for _, p in ipairs(partsOf(booth)) do
			local isAccent = p.Name == "Tabletop" or p.Name == "Carpet"
			if isAccent and accent then
				p.Color = accent
			elseif not isAccent and primary then
				p.Color = primary
			elseif booth.Colors[p] then
				p.Color = booth.Colors[p]
			end
		end
	else
		if desc then
			desc.Text = "Claim this booth!"
		end
		if img then
			img.Image = booth.DefaultImage or ""
		end
		if sign then
			sign.Label.Text = ""
			sign.Enabled = false
		end
		for _, p in ipairs(partsOf(booth)) do
			if booth.Colors[p] then
				p.Color = booth.Colors[p]
			end
		end
	end
	if booth.Prompt then
		booth.Prompt.Enabled = owner == nil
	end
	for _, p in ipairs(Players:GetPlayers()) do
		Event:FireClient(p, "Booth", boothState(booth))
	end
end

local function unclaim(player)
	local booth = BoothOf[player]
	if not booth then
		return
	end
	BoothOf[player] = nil
	booth.Owner = nil
	applyBooth(booth)
end

local function claim(player, booth)
	if booth.Owner then
		notify(player, "This booth is already claimed.", "bad")
		return
	end
	if BoothOf[player] then
		notify(player, "You already have a booth. Abandon it first.", "bad")
		return
	end
	if not Data.Get(player) then
		return
	end
	booth.Owner = player
	BoothOf[player] = booth
	applyBooth(booth)
	push(player)
	Event:FireClient(player, "Claimed", booth.Index)
	notify(player, "You claimed a booth! Click it or press the Booth button to edit it.", "good")
end

local function setupBooth(model, index)
	local root = model:FindFirstChild("Root")
	if not root then
		return
	end
	local booth = {
		Model = model,
		Index = index,
		Owner = nil,
		Style = model.Name,
		DefaultStyle = model.Name,
		Colors = {},
	}
	local _, img = bannerLabels(booth)
	booth.DefaultImage = img and img.Image or ""
	for _, p in ipairs(partsOf(booth)) do
		booth.Colors[p] = p.Color
	end
	if not StyleTemplates[model.Name] and model:FindFirstChild("Visuals") then
		StyleTemplates[model.Name] = { Visuals = model.Visuals:Clone(), RootCFrame = root.CFrame }
	end
	local prompt = root:FindFirstChildOfClass("ProximityPrompt")
	if not prompt then
		prompt = Instance.new("ProximityPrompt")
		prompt.Parent = root
	end
	prompt.ActionText = "Claim"
	prompt.ObjectText = "Booth"
	prompt.MaxActivationDistance = 10
	prompt.RequiresLineOfSight = false
	prompt.Enabled = true
	booth.Prompt = prompt
	prompt.Triggered:Connect(function(player)
		claim(player, booth)
	end)
	local banner = bannerOf(booth)
	local cd = banner and banner:FindFirstChildOfClass("ClickDetector")
	if cd then
		cd.MouseClick:Connect(function(who)
			if booth.Owner == who then
				Event:FireClient(who, "OpenBooth")
			elseif booth.Owner then
				Event:FireClient(who, "OpenProfile", booth.Owner.UserId)
			else
				claim(who, booth)
			end
		end)
	end
	booth.Block = model:FindFirstChild("BlockCheck")
	if booth.Block then
		booth.Block.Transparency = 1
		booth.Block.CanCollide = false
	end
	Booths[index] = booth
	applyBooth(booth)
end

local ordered = BoothFolder:GetChildren()
table.sort(ordered, function(a, b)
	local ra, rb = a:FindFirstChild("Root"), b:FindFirstChild("Root")
	if ra and rb then
		if math.abs(ra.Position.X - rb.Position.X) > 1 then
			return ra.Position.X < rb.Position.X
		end
		return ra.Position.Z < rb.Position.Z
	end
	return a.Name < b.Name
end)
for i, model in ipairs(ordered) do
	setupBooth(model, i)
end

local function pushOut(booth, character)
	local hrp = character and character:FindFirstChild("HumanoidRootPart")
	local block = booth.Block
	if not hrp or not block then
		return
	end
	local rel = block.CFrame:pointToObjectSpace(hrp.Position)
	local half = block.Size / 2
	if math.abs(rel.X) > half.X or math.abs(rel.Y) > half.Y or math.abs(rel.Z) > half.Z then
		return
	end
	local dx = half.X - math.abs(rel.X)
	local dz = half.Z - math.abs(rel.Z)
	local target
	if dx < dz then
		target = Vector3.new((rel.X >= 0 and 1 or -1) * (half.X + 3), rel.Y, rel.Z)
	else
		target = Vector3.new(rel.X, rel.Y, (rel.Z >= 0 and 1 or -1) * (half.Z + 3))
	end
	hrp.CFrame = CFrame.new(block.CFrame:pointToWorldSpace(target)) * (hrp.CFrame - hrp.Position)
end

spawn(function()
	while true do
		wait(0.5)
		for _, booth in pairs(Booths) do
			local owner = booth.Owner
			local d = owner and Data.Get(owner)
			if d and #d.booth.blacklist > 0 and booth.Block then
				for _, name in ipairs(d.booth.blacklist) do
					local p = Players:FindFirstChild(name)
					if p and p ~= owner and not isAdmin(p) then
						pushOut(booth, p.Character)
					end
				end
			end
		end
	end
end)

local function checkPasses(player)
	PassOwned[player] = PassOwned[player] or {}
	for _, gp in ipairs(Config.Gamepasses) do
		if gp.PassId and gp.PassId > 0 then
			local ok, has = pcall(function()
				return MarketplaceService:UserOwnsGamePassAsync(player.UserId, gp.PassId)
			end)
			PassOwned[player][gp.Key] = ok and has == true
		else
			PassOwned[player][gp.Key] = false
		end
	end
	local d = Data.Get(player)
	if d and owns(player, "Boombox") and not d.tools.BoomBox then
		d.tools.BoomBox = { equipped = true }
	end
end

local function onCharacter(player, character)
	wait(0.5)
	updateTag(player)
	giveTools(player)
end

local function onPlayer(player)
	Data.Load(player)
	checkPasses(player)
	refreshTitles(player)
	NextPayout[player] = tick() + Config.PayoutSeconds
	player.CharacterAdded:Connect(function(character)
		onCharacter(player, character)
	end)
	if player.Character then
		spawn(function()
			onCharacter(player, player.Character)
		end)
	end
	push(player)
	for _, booth in pairs(Booths) do
		Event:FireClient(player, "Booth", boothState(booth))
	end
	updateTopRated()
end

Players.PlayerAdded:Connect(onPlayer)
for _, p in ipairs(Players:GetPlayers()) do
	spawn(function()
		onPlayer(p)
	end)
end

Players.PlayerRemoving:Connect(function(player)
	unclaim(player)
	PassOwned[player] = nil
	NextPayout[player] = nil
	LastAction[player] = nil
	LastModCall[player] = nil
	wait(0.1)
	updateTopRated()
end)

spawn(function()
	local minuteTick = 0
	while true do
		wait(1)
		minuteTick = minuteTick + 1
		local now = tick()
		for _, player in ipairs(Players:GetPlayers()) do
			local d = Data.Get(player)
			if d then
				if minuteTick % 60 == 0 then
					d.minutes = d.minutes + 1
					refreshTitles(player)
					push(player)
				end
				if NextPayout[player] and now >= NextPayout[player] then
					NextPayout[player] = now + Config.PayoutSeconds
					local amount = Config.CreditsPerPayout * (owns(player, "VIP") and 2 or 1)
					Data.AddCredits(player, amount)
					Event:FireClient(player, "Payout", amount)
					push(player)
				end
			end
		end
	end
end)

local function buyItem(player, toolName)
	local item = Config.Item(toolName)
	local d = Data.Get(player)
	if not item or not d or not ToolFolder:FindFirstChild(toolName) then
		return false, "That item does not exist."
	end
	if d.tools[toolName] then
		return false, "You already own this."
	end
	if not Data.AddCredits(player, -item.Price) then
		return false, "You need " .. Config.Comma(item.Price - d.credits) .. " more credits."
	end
	d.tools[toolName] = { equipped = true }
	giveTools(player)
	refreshTitles(player)
	push(player)
	return true, "You bought " .. item.Name .. "!"
end

local function equipItem(player, toolName, on)
	local d = Data.Get(player)
	if not d or not d.tools[toolName] then
		return false, "You do not own this."
	end
	d.tools[toolName] = { equipped = on and true or false }
	giveTools(player)
	push(player)
	return true, on and "Equipped." or "Unequipped."
end

local function buyStyle(player, styleName)
	local style = Config.Style(styleName)
	local d = Data.Get(player)
	if not style or not d or not StyleTemplates[styleName] then
		return false, "That booth style is not available."
	end
	if d.styles[styleName] or style.Price == 0 then
		return true, "You already own this style."
	end
	if not Data.AddCredits(player, -style.Price) then
		return false, "You need " .. Config.Comma(style.Price - d.credits) .. " more credits."
	end
	d.styles[styleName] = true
	push(player)
	return true, "Unlocked " .. styleName .. "!"
end

local function openCase(player)
	local d = Data.Get(player)
	if not d then
		return false, "Try again in a moment."
	end
	if not Data.AddCredits(player, -Config.CaseCost) then
		return false, "A case costs " .. Config.Comma(Config.CaseCost) .. " credits."
	end
	local total = 0
	for _, r in ipairs(Config.Rarities) do
		total = total + r.Weight
	end
	local roll = math.random() * total
	local rarity = Config.Rarities[1].Name
	for _, r in ipairs(Config.Rarities) do
		roll = roll - r.Weight
		if roll <= 0 then
			rarity = r.Name
			break
		end
	end
	local pool, fallback = {}, {}
	for _, t in ipairs(Config.Titles) do
		if t.Unlock.Kind == "case" then
			if t.Rarity == rarity and not d.titles[t.Id] then
				table.insert(pool, t)
			end
			if not d.titles[t.Id] then
				table.insert(fallback, t)
			end
		end
	end
	if #pool == 0 then
		pool = fallback
	end
	if #pool == 0 then
		local refund = math.floor(Config.CaseCost / 2)
		Data.AddCredits(player, refund)
		push(player)
		return true, { duplicate = true, refund = refund }
	end
	local won = pool[math.random(1, #pool)]
	d.titles[won.Id] = true
	push(player)
	if won.Rarity == "Mythic" or won.Rarity == "Legendary" then
		Event:FireAllClients("Announce", player.Name .. " unboxed the " .. won.Rarity .. " title " .. won.Text .. "!")
	end
	return true, { id = won.Id, text = won.Text, rarity = won.Rarity }
end

local function rate(player, targetId, stars)
	stars = math.floor(tonumber(stars) or 0)
	targetId = tonumber(targetId)
	if stars < 1 or stars > 5 or not targetId then
		return false, "Pick between 1 and 5 stars."
	end
	if targetId == player.UserId then
		return false, "You cannot rate yourself."
	end
	local target = Players:GetPlayerByUserId(targetId)
	local d, td = Data.Get(player), target and Data.Get(target)
	if not d or not td then
		return false, "That player is not here anymore."
	end
	local key = "u" .. targetId
	local last = d.rated[key]
	if last and os.time() - last < Config.RateCooldownHours * 3600 then
		local left = Config.RateCooldownHours * 3600 - (os.time() - last)
		return false, "You can rate " .. target.Name .. " again in " .. math.ceil(left / 3600) .. "h."
	end
	d.rated[key] = os.time()
	d.given = d.given + 1
	td.ratingSum = td.ratingSum + stars
	td.ratingCount = td.ratingCount + 1
	td.received = td.received + 1
	Data.AddCredits(player, 5)
	refreshTitles(player)
	refreshTitles(target)
	updateTag(target)
	push(player)
	push(target)
	Event:FireClient(target, "Rated", player.Name, stars)
	updateTopRated()
	return true, "You gave " .. target.Name .. " " .. stars .. " star" .. (stars == 1 and "" or "s") .. ". +5 credits"
end

local function profileOf(userId)
	local target = Players:GetPlayerByUserId(tonumber(userId) or 0)
	local d = target and Data.Get(target)
	if not d then
		return nil
	end
	local owned = 0
	for _ in pairs(d.titles) do
		owned = owned + 1
	end
	return {
		userId = target.UserId,
		name = target.Name,
		bio = d.bio,
		minutes = d.minutes,
		streak = d.streak,
		average = average(d),
		ratingCount = d.ratingCount,
		given = d.given,
		minesweeper = d.minesweeper,
		uno = d.uno,
		title = d.equippedTitle,
		titleCount = owned,
		vip = owns(target, "VIP"),
		booth = BoothOf[target] and BoothOf[target].Index or nil,
	}
end

Event.OnServerEvent:Connect(function(player, action, a, b, c)
	if type(action) ~= "string" then
		return
	end
	local d = Data.Get(player)
	if not d then
		return
	end
	if action == "UpdateBooth" then
		if not throttle(player, 1, "UpdateBooth") then
			return
		end
		local booth = BoothOf[player]
		if type(a) ~= "table" then
			return
		end
		local text = tostring(a.description or ""):sub(1, Config.DescriptionMax)
		d.booth.description = filter(text, player)
		local hexP, hexA = hexColor(a.primary), hexColor(a.accent)
		d.booth.primary = hexP and tostring(a.primary):gsub("#", "") or ""
		d.booth.accent = hexA and tostring(a.accent):gsub("#", "") or ""
		local imageId = tostring(a.image or ""):match("(%d+)")
		if imageId and imageId ~= "" then
			if owns(player, "Decals") or isAdmin(player) or d.minutes >= Config.DecalMinutes then
				d.booth.image = decalImage(imageId)
			else
				notify(player, "You need " .. Config.DecalMinutes .. " minutes of playtime to use decals.", "bad")
			end
		elseif a.image == "" then
			d.booth.image = ""
		end
		if booth then
			applyBooth(booth)
		end
		push(player)
		notify(player, "Booth updated!", "good")
	elseif action == "Blacklist" then
		if not throttle(player, 0.5, "Blacklist") then
			return
		end
		local name = tostring(a or ""):sub(1, 30)
		if b == "remove" then
			for i, n in ipairs(d.booth.blacklist) do
				if n:lower() == name:lower() then
					table.remove(d.booth.blacklist, i)
					break
				end
			end
		elseif name ~= "" and name:lower() ~= player.Name:lower() then
			if #d.booth.blacklist >= Config.BlacklistMax then
				notify(player, "Your blacklist is full.", "bad")
			else
				local exists = false
				for _, n in ipairs(d.booth.blacklist) do
					if n:lower() == name:lower() then
						exists = true
					end
				end
				if not exists then
					local p = Players:FindFirstChild(name)
					table.insert(d.booth.blacklist, p and p.Name or name)
				end
			end
		end
		push(player)
	elseif action == "Abandon" then
		if BoothOf[player] then
			unclaim(player)
			push(player)
			notify(player, "You abandoned your booth.", "info")
			Event:FireClient(player, "Abandoned")
		end
	elseif action == "SetStyle" then
		local name = tostring(a or "")
		local style = Config.Style(name)
		if not style or not StyleTemplates[name] then
			return
		end
		if not (d.styles[name] or style.Price == 0) then
			local ok, msg = buyStyle(player, name)
			notify(player, msg, ok and "good" or "bad")
			if not ok then
				return
			end
		end
		d.booth.style = name
		if BoothOf[player] then
			applyBooth(BoothOf[player])
		end
		push(player)
	elseif action == "EquipTitle" then
		local id = tostring(a or "")
		if d.titles[id] then
			d.equippedTitle = id
			updateTag(player)
			push(player)
		end
	elseif action == "SetBio" then
		if not throttle(player, 2, "SetBio") then
			return
		end
		d.bio = filter(tostring(a or ""):sub(1, 200), player)
		push(player)
		notify(player, "Bio saved.", "good")
	elseif action == "Setting" then
		if type(a) == "string" and #a < 40 then
			d.settings[a] = b and true or false
		end
	elseif action == "AgreeRules" then
		d.rulesAgreed = true
	elseif action == "ModCall" then
		local reason = tostring(a or "")
		if #reason < 10 then
			notify(player, "Please give a reason of at least 10 characters.", "bad")
			return
		end
		if LastModCall[player] and tick() - LastModCall[player] < Config.ModCallCooldown then
			notify(player, "You already called a mod recently.", "bad")
			return
		end
		LastModCall[player] = tick()
		local sent = 0
		for _, p in ipairs(Players:GetPlayers()) do
			if isAdmin(p) then
				sent = sent + 1
				Event:FireClient(p, "ModCall", player.Name, filter(reason:sub(1, 200), player))
			end
		end
		notify(player, sent > 0 and "A moderator has been notified." or "Your mod call was logged. No moderators are online right now.", "good")
	elseif action == "PromptPass" then
		for _, gp in ipairs(Config.Gamepasses) do
			if gp.Key == a and gp.PassId > 0 then
				MarketplaceService:PromptGamePassPurchase(player, gp.PassId)
				return
			end
		end
		notify(player, "This gamepass is not on sale yet.", "bad")
	elseif action == "Donate" then
		local id = Config.Donations[tostring(a or "")]
		if id and id > 0 then
			MarketplaceService:PromptGamePassPurchase(player, id)
		else
			notify(player, "Donations are not set up yet. Thank you anyway!", "info")
		end
	elseif action == "Respawn" then
		if throttle(player, 3, "Respawn") then
			player:LoadCharacter()
		end
	end
end)

Request.OnServerInvoke = function(player, action, a, b)
	if type(action) ~= "string" then
		return nil
	end
	if action == "State" then
		return stateFor(player)
	elseif action == "Buy" then
		if not throttle(player, 0.5, "Buy") then
			return false, "Slow down."
		end
		return buyItem(player, tostring(a or ""))
	elseif action == "Equip" then
		return equipItem(player, tostring(a or ""), b)
	elseif action == "BuyStyle" then
		return buyStyle(player, tostring(a or ""))
	elseif action == "OpenCase" then
		if not throttle(player, 1.5, "OpenCase") then
			return false, "Slow down."
		end
		return openCase(player)
	elseif action == "Rate" then
		if not throttle(player, 1, "Rate") then
			return false, "Slow down."
		end
		return rate(player, a, b)
	elseif action == "Profile" then
		return profileOf(a)
	elseif action == "Booths" then
		local list = {}
		for i, booth in pairs(Booths) do
			list[i] = boothState(booth)
		end
		return list
	end
	return nil
end

MarketplaceService.PromptGamePassPurchaseFinished:Connect(function(player, passId, bought)
	if bought then
		checkPasses(player)
		refreshTitles(player)
		giveTools(player)
		updateTag(player)
		push(player)
		notify(player, "Thank you for your purchase!", "good")
	end
end)
