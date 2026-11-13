local placeId, port, url, creatorid, accessKey, forceFiltering = ...

local auth = "?accesskey=" .. tostring(accessKey or "")

pcall(function() game:GetService("ScriptInformationProvider"):SetAccessKey(tostring(accessKey or "")) end)

local Players = game:GetService("Players")
local HttpService = game:GetService("HttpService")

function waitForChild(parent, childName)
	while true do
		local child = parent:findFirstChild(childName)
		if child then
			return child
		end
		parent.ChildAdded:wait()
	end
end

function reportPlayers()
    local success, message = pcall(function()
        local PlayerList = {}
        for _, player in pairs(Players:GetChildren()) do
            if player:IsA("Player") then
                table.insert(PlayerList, player.UserId)
            end
        end
        local MessagePayload = HttpService:JSONEncode({
            ["jobId"] = game.JobId,
            ["players"] = PlayerList
        })
        local ResponseData = game:HttpPost(url.."/api/gameserver/update" .. auth, MessagePayload, true, "application/json")
    end)

    if not success then
        warn("Error reporting players: ".. tostring(message))
    end
end

pcall(function() settings().Network.UseInstancePacketCache = true end)
pcall(function() settings().Network.UsePhysicsPacketCache = true end)
pcall(function() settings()["Task Scheduler"].PriorityMethod = Enum.PriorityMethod.AccumulatedError end)

settings().Network.PhysicsSend = Enum.PhysicsSendMethod.TopNErrors
settings().Network.ExperimentalPhysicsEnabled = true
settings().Network.WaitingForCharacterLogRate = 100
pcall(function() settings().Diagnostics:LegacyScriptMode() end)

local assetId = placeId

local scriptContext = game:GetService('ScriptContext')
pcall(function() scriptContext:AddStarterScript(37801172) end)
scriptContext.ScriptsDisabled = true

game:SetPlaceID(assetId, false)
game:SetUniverseId(assetId)
game:SetCreatorID(creatorid, Enum.CreatorType.User)
game:GetService("ChangeHistoryService"):SetEnabled(false)

local ns = game:GetService("NetworkServer")

if url~=nil then
	pcall(function() game:GetService("Players"):SetAbuseReportUrl(url .. "/AbuseReport/InGameChatHandler.ashx") end)
	pcall(function() game:GetService("ScriptInformationProvider"):SetAssetUrl(url .. "/Asset/") end)
	pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url .. "/") end)

	game:GetService("BadgeService"):SetPlaceId(placeId)
    game:GetService("Players"):SetSysStatsUrl(url.. "/api/gameserver/sysstats-report?placeId=".. placeId)

	game:GetService("BadgeService"):SetAwardBadgeUrl(url .. "/Game/Badge/AwardBadge.ashx?UserID=%d&BadgeID=%d&PlaceID=%d&accesskey=" .. tostring(accessKey or ""))
	game:GetService("GamePassService"):SetPlayerHasPassUrl(url .. "/Game/GamePass/GamePassHandler.ashx?Action=HasPass&UserID=%d&PassID=%d")
	game:GetService("BadgeService"):SetHasBadgeUrl(url .. "/Game/Badge/HasBadge.ashx?UserID=%d&BadgeID=%d")
	game:GetService("BadgeService"):SetIsBadgeDisabledUrl(url .. "/Game/Badge/IsBadgeDisabled.ashx?BadgeID=%d&PlaceID=%d")
	game:GetService("BadgeService"):SetIsBadgeLegalUrl(url .. "/Game/Badge/IsBadgeLegal.ashx?BadgeID=%d&PlaceID=%d")
	game:GetService("InsertService"):SetBaseSetsUrl(url .. "/Game/Tools/InsertAsset.ashx?nsets=10&type=base")
	game:GetService("InsertService"):SetUserSetsUrl(url .. "/Game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d")
	game:GetService("InsertService"):SetCollectionUrl(url .. "/Game/Tools/InsertAsset.ashx?sid=%d")
	game:GetService("InsertService"):SetAssetUrl(url .. "/Asset/?id=%d")
	game:GetService("InsertService"):SetAssetVersionUrl(url .. "/Asset/?assetversionid=%d")

	pcall(function() loadfile(url .. "/Game/LoadPlaceInfo.ashx?PlaceId=" .. placeId)() end)
end

pcall(function() game:GetService("NetworkServer"):SetIsPlayerAuthenticationRequired(false) end)
settings().Diagnostics.LuaRamLimit = 0

local everyoneLeftAt = nil

local ecCommands = { [";ec"] = true, [";energycell"] = true, [";bleach"] = true, [";suicide"] = true, [";cut"] = true, [";finobe"] = true, [";kms"] = true, [";raymonf"] = true, [";kyle"] = true }
local ecSounds = { url .. "/s/sounds/ec_sound_1.mp3", url .. "/s/sounds/ec_sound_2.mp3" }
local ecCooldown = {}
math.randomseed(tick())

local danceList = {}
local danceTitles = {}
local danceIds = {}
local danceTracks = {}
local danceRecent = {}
local flingCooldown = {}
local danceRemote = nil
local danceClient = nil

local function stopDance(player)
	local state = danceTracks[player]
	danceTracks[player] = nil
	if state == nil then return end
	for _, connection in pairs(state.connections) do pcall(function() connection:disconnect() end) end
	pcall(function() state.track:Stop(0.2) end)
	pcall(function() state.animation:Destroy() end)
end

local function playDance(player, slug)
	if danceTitles[slug] == nil then return end
	local recent = danceRecent[player]
	if recent and (tick() - recent.at < 0.3 or (recent.slug == slug and tick() - recent.at < 1.5)) then return end
	danceRecent[player] = { slug = slug, at = tick() }
	local character = player.Character
	local humanoid = character and character:FindFirstChild("Humanoid")
	if humanoid == nil or humanoid.Health <= 0 or character:FindFirstChild("Torso") == nil then return end
	stopDance(player)
	local animation = Instance.new("Animation")
	animation.Name = "FinobeDance"
	animation.AnimationId = url .. "/asset/?id=" .. danceIds[slug]
	animation.Parent = character
	local track = humanoid:LoadAnimation(animation)
	local state = { track = track, animation = animation, connections = {} }
	danceTracks[player] = state
	track:Play(0.2)
	local startedAt = tick()
	table.insert(state.connections, humanoid.Running:connect(function(speed)
		if speed > 1 and tick() - startedAt > 0.3 and danceTracks[player] == state then stopDance(player) end
	end))
	table.insert(state.connections, humanoid.Jumping:connect(function(active)
		if active and danceTracks[player] == state then stopDance(player) end
	end))
	table.insert(state.connections, humanoid.Died:connect(function()
		if danceTracks[player] == state then stopDance(player) end
	end))
end

local function flingPlayer(player)
	if flingCooldown[player] and tick() - flingCooldown[player] < 2 then return end
	local character = player.Character
	local humanoid = character and character:FindFirstChild("Humanoid")
	local root = character and (character:FindFirstChild("HumanoidRootPart") or character:FindFirstChild("Torso"))
	if humanoid == nil or root == nil or humanoid.Health <= 0 then return end
	flingCooldown[player] = tick()
	stopDance(player)
	local angle = math.random() * math.pi * 2
	local push = Instance.new("BodyVelocity")
	push.Name = "FinobeFling"
	push.MaxForce = Vector3.new(1e7, 1e7, 1e7)
	push.P = 1e5
	push.Velocity = Vector3.new(math.cos(angle) * 160, 120, math.sin(angle) * 160)
	local spin = Instance.new("BodyAngularVelocity")
	spin.Name = "FinobeFlingSpin"
	spin.MaxTorque = Vector3.new(1e7, 1e7, 1e7)
	spin.P = 1e5
	spin.AngularVelocity = Vector3.new(math.random(-40, 40), math.random(-40, 40), math.random(-40, 40))
	pcall(function() humanoid.PlatformStand = true end)
	push.Parent = root
	spin.Parent = root
	game:GetService("Debris"):AddItem(push, 0.25)
	game:GetService("Debris"):AddItem(spin, 0.4)
	delay(2.5, function()
		pcall(function() if humanoid.Parent ~= nil then humanoid.PlatformStand = false end end)
	end)
end

local function giveDanceClient(player)
	if danceClient == nil or player.Parent == nil then return end
	local gui = player:FindFirstChild("PlayerGui")
	if gui == nil or gui:FindFirstChild("FinobeDance") ~= nil then return end
	danceClient:Clone().Parent = gui
	return true
end

local function setupDances()
	local ok, list = pcall(function() return game:HttpGetAsync(url .. "/asset/?id=dance&name=list") end)
	if not ok or type(list) ~= "string" then return end
	for line in string.gmatch(list, "[^\n]+") do
		local id, slug, title = string.match(line, "^(%d+)\t([a-z0-9]+)\t(.+)$")
		if slug ~= nil then
			table.insert(danceList, slug)
			danceTitles[slug] = title
			danceIds[slug] = id
		end
	end
	local storage = game:GetService("ReplicatedStorage")
	local listValue = Instance.new("StringValue")
	listValue.Name = "FinobeDances"
	listValue.Value = list
	listValue.Parent = storage
	danceRemote = Instance.new("RemoteEvent")
	danceRemote.Name = "FinobeDance"
	danceRemote.Parent = storage
	danceRemote.OnServerEvent:connect(function(player, action, slug)
		if action == "dance" and type(slug) == "string" then
			playDance(player, slug)
		elseif action == "stop" then
			stopDance(player)
		end
	end)
	pcall(function() danceClient = game:GetObjects(url .. "/asset/?id=dance&name=client")[1] end)
	for _, player in pairs(Players:GetPlayers()) do pcall(giveDanceClient, player) end
end

local function danceCommand(player, msg)
	local text = string.lower(tostring(msg or ""))
	text = string.gsub(text, "^%s+", "")
	text = string.gsub(text, "%s+$", "")
	if text == "/dance" or text == "/dances" then
		if danceRemote ~= nil then
			if giveDanceClient(player) then wait(1) end
			danceRemote:FireClient(player, "open")
		end
		return true
	end
	if text == "/wheel" or text == "/emotes" then
		if danceRemote ~= nil then
			if giveDanceClient(player) then wait(1) end
			danceRemote:FireClient(player, "wheel")
		end
		return true
	end
	if text == "/fling" then
		flingPlayer(player)
		return true
	end
	local emote = string.match(text, "^/e%s+(.+)$")
	if emote ~= nil then
		local slug = string.gsub(emote, "[^a-z0-9]", "")
		if danceTitles[slug] ~= nil then
			playDance(player, slug)
			return true
		end
	end
	return false
end

pcall(function() Players.CharacterAutoLoads = false end)
Players.Changed:connect(function(property)
	if property == "CharacterAutoLoads" and Players.CharacterAutoLoads then
		Players.CharacterAutoLoads = false
	end
end)

local function removePlayer(player, reason)
	pcall(function() player:Kick(reason) end)
	pcall(function()
		for _, replicator in pairs(ns:GetChildren()) do
			local ok, owner = pcall(function() return replicator:GetPlayer() end)
			if ok and owner == player then
				pcall(function() replicator:CloseConnection() end)
			end
		end
	end)
	pcall(function() player:Remove() end)
end

local function joinKey(player)
	for i = 1, 150 do
		if player.Parent == nil then return nil end
		local key = string.match(tostring(player.CharacterAppearance or ""), "[?&]jk=(%x+)")
		if key ~= nil then return key end
		wait(0.1)
	end
	return nil
end

local function verifyPlayer(player)
	local key = joinKey(player)
	if key == nil then return nil end
	for attempt = 1, 3 do
		local ok, reply = pcall(function()
			return game:HttpGet(url .. "/api/gameserver/verify/" .. game.JobId .. "/" .. player.userId .. auth .. "&key=" .. key, true)
		end)
		if ok then
			return string.match(tostring(reply or ""), "^1|(.+)$")
		end
		wait(1)
	end
	return nil
end

local playerAnims = {}

local function animLinesFor(player)
	if playerAnims[player] == nil then
		local lines = ""
		pcall(function() lines = game:HttpGet(url .. "/api/gameserver/animations/" .. player.userId .. auth, true) end)
		playerAnims[player] = {}
		for line in string.gmatch(tostring(lines or ""), "[^\r\n]+") do
			local config, child, id, weight = string.match(line, "^(%S+) (%S+) (%S+) ?(%S*)$")
			if config ~= nil then
				table.insert(playerAnims[player], { config = config, child = child, id = id, weight = tonumber(weight) })
			end
		end
	end
	return playerAnims[player]
end

local function wantsR15(player)
	for _, anim in pairs(animLinesFor(player)) do
		if anim.config == "rig" and anim.child == "R15" then return true end
	end
	return false
end

local R15_MESHES = {
	Head = 419776358, UpperTorso = 419776040, LowerTorso = 419776201,
	LeftUpperArm = 419769794, LeftLowerArm = 419770884, LeftHand = 419771232,
	RightUpperArm = 419771918, RightLowerArm = 419772046, RightHand = 419774811,
	LeftUpperLeg = 419771598, LeftLowerLeg = 419771410, LeftFoot = 419771742,
	RightUpperLeg = 419775117, RightLowerLeg = 419775579, RightFoot = 419775789
}

local R15_JOINTS = {
	{ "HumanoidRootPart", "LowerTorso", "RootRigAttachment", "Root" },
	{ "LowerTorso", "UpperTorso", "WaistRigAttachment", "Waist" },
	{ "UpperTorso", "Head", "NeckRigAttachment", "Neck" },
	{ "UpperTorso", "LeftUpperArm", "LeftShoulderRigAttachment", "LeftShoulder" },
	{ "LeftUpperArm", "LeftLowerArm", "LeftElbowRigAttachment", "LeftElbow" },
	{ "LeftLowerArm", "LeftHand", "LeftWristRigAttachment", "LeftWrist" },
	{ "UpperTorso", "RightUpperArm", "RightShoulderRigAttachment", "RightShoulder" },
	{ "RightUpperArm", "RightLowerArm", "RightElbowRigAttachment", "RightElbow" },
	{ "RightLowerArm", "RightHand", "RightWristRigAttachment", "RightWrist" },
	{ "LowerTorso", "LeftUpperLeg", "LeftHipRigAttachment", "LeftHip" },
	{ "LeftUpperLeg", "LeftLowerLeg", "LeftKneeRigAttachment", "LeftKnee" },
	{ "LeftLowerLeg", "LeftFoot", "LeftAnkleRigAttachment", "LeftAnkle" },
	{ "LowerTorso", "RightUpperLeg", "RightHipRigAttachment", "RightHip" },
	{ "RightUpperLeg", "RightLowerLeg", "RightKneeRigAttachment", "RightKnee" },
	{ "RightLowerLeg", "RightFoot", "RightAnkleRigAttachment", "RightAnkle" }
}

local R15_COLORS = {
	HeadColor = { "Head" }, TorsoColor = { "UpperTorso", "LowerTorso" },
	LeftArmColor = { "LeftUpperArm", "LeftLowerArm", "LeftHand" },
	RightArmColor = { "RightUpperArm", "RightLowerArm", "RightHand" },
	LeftLegColor = { "LeftUpperLeg", "LeftLowerLeg", "LeftFoot" },
	RightLegColor = { "RightUpperLeg", "RightLowerLeg", "RightFoot" }
}

local r15Rig, r15Animate = nil, nil

local function buildR15(player)
	if r15Rig == nil then
		r15Rig = game:GetObjects("rbxasset://fonts/characterR15.rbxm")[1]
		r15Animate = game:GetObjects("rbxasset://fonts/humanoidAnimateR15_2.rbxm")[1]
	end
	local model = r15Rig:Clone()
	model.Name = player.Name
	local humanoid = model:FindFirstChild("Humanoid")
	humanoid.RigType = Enum.HumanoidRigType.R15

	for name, id in pairs(R15_MESHES) do
		local part = model:FindFirstChild(name)
		local mesh = part and part:FindFirstChild("Mesh")
		if mesh ~= nil then mesh.MeshId = url .. "/asset/?id=" .. id end
	end

	local root = model:FindFirstChild("HumanoidRootPart")
	local lowest = nil
	for _, name in pairs({ "LeftFoot", "RightFoot" }) do
		local foot = model:FindFirstChild(name)
		if foot ~= nil then
			local bottom = foot.Position.Y - foot.Size.Y / 2
			if lowest == nil or bottom < lowest then lowest = bottom end
		end
	end
	if root ~= nil and lowest ~= nil then
		local hip = (root.Position.Y - root.Size.Y / 2) - lowest
		local cf = root.CFrame
		root.FormFactor = Enum.FormFactor.Custom
		root.Size = Vector3.new(root.Size.X, root.Size.Y + 2 * hip, root.Size.Z)
		root.CFrame = cf
		root.CanCollide = false
		humanoid.HipHeight = 0
	end

	for _, joint in pairs(R15_JOINTS) do
		local part0, part1 = model:FindFirstChild(joint[1]), model:FindFirstChild(joint[2])
		local a0 = part0 and part0:FindFirstChild(joint[3])
		local a1 = part1 and part1:FindFirstChild(joint[3])
		if a0 ~= nil and a1 ~= nil then
			local motor = Instance.new("Motor6D")
			motor.Name = joint[4]
			motor.Part0 = part0
			motor.Part1 = part1
			motor.C0 = a0.CFrame
			motor.C1 = a1.CFrame
			motor.Parent = part1
		end
	end

	local items = tostring(player.CharacterAppearance or "")
	local list = ""
	pcall(function() list = game:HttpGet(items, true) end)
	for itemUrl in string.gmatch(tostring(list or ""), "[^;]+") do
		pcall(function()
			for _, object in pairs(game:GetObjects(itemUrl)) do
				if object:IsA("BodyColors") then
					for property, parts in pairs(R15_COLORS) do
						for _, name in pairs(parts) do
							local part = model:FindFirstChild(name)
							if part ~= nil then part.BrickColor = object[property] end
						end
					end
					object.Parent = model
				elseif object:IsA("Hat") or object:IsA("Shirt") or object:IsA("Pants") or object:IsA("ShirtGraphic") then
					object.Parent = model
				elseif object:IsA("Decal") then
					local head = model:FindFirstChild("Head")
					local old = head and head:FindFirstChild("face") or (head and head:FindFirstChild("Decal"))
					if old ~= nil then old:Destroy() end
					object.Name = "face"
					object.Parent = head
				elseif object:IsA("Tool") or object:IsA("HopperBin") then
					local backpack = player:FindFirstChild("Backpack")
					if backpack ~= nil then object.Parent = backpack end
				end
			end
		end)
	end

	local animate = r15Animate:Clone()
	local cleared = {}
	for _, anim in pairs(animLinesFor(player)) do
		local value = animate:FindFirstChild(anim.config)
		if value ~= nil then
			if not cleared[anim.config] then
				cleared[anim.config] = true
				for _, old in pairs(value:GetChildren()) do old:Destroy() end
			end
			local animation = Instance.new("Animation")
			animation.Name = anim.child
			animation.AnimationId = anim.id
			if anim.weight ~= nil then
				local weight = Instance.new("NumberValue")
				weight.Name = "Weight"
				weight.Value = anim.weight
				weight.Parent = animation
			end
			animation.Parent = value
		end
	end
	animate.Parent = model

	local spawns = {}
	local function findSpawns(parent)
		for _, child in pairs(parent:GetChildren()) do
			if child:IsA("SpawnLocation") and child.Enabled then table.insert(spawns, child) end
			findSpawns(child)
		end
	end
	pcall(function() findSpawns(workspace) end)
	local spawnAt = CFrame.new(0, 100, 0)
	local spawnPart = nil
	if #spawns > 0 then
		spawnPart = spawns[math.random(1, #spawns)]
		spawnAt = CFrame.new(spawnPart.Position + Vector3.new(0, spawnPart.Size.Y / 2 + humanoid.HipHeight + model.HumanoidRootPart.Size.Y / 2 + 0.5, 0))
	end
	model.PrimaryPart = model.HumanoidRootPart
	model:SetPrimaryPartCFrame(spawnAt)

	if spawnPart ~= nil and spawnPart.Duration > 0 then
		local field = Instance.new("ForceField")
		field.Parent = model
		game:GetService("Debris"):AddItem(field, spawnPart.Duration)
	end

	return model
end

local function spawnPlayer(player)
	if player.Parent == nil then return end
	if wantsR15(player) then
		local ok, model = pcall(buildR15, player)
		if ok and model ~= nil then
			pcall(function() local old = player.Character if old ~= nil then old:Destroy() end end)
			player.Character = model
			model.Parent = workspace
			return
		end
		print("R15 spawn failed: " .. tostring(model))
	end
	pcall(function() player:LoadCharacter() end)
end

game:GetService("Players").PlayerAdded:connect(function(player)
	print("Player " .. player.userId .. " added")
	everyoneLeftAt = nil

	for _, other in pairs(Players:GetPlayers()) do
		if other ~= player and other.userId == player.userId then
			removePlayer(player, "You're already in this server.")
			return
		end
	end

	local name = verifyPlayer(player)
	if name == nil then
		removePlayer(player, "Couldn't verify you, rejoin from the website.")
		return
	end

	pcall(function() player.Name = name end)
	pcall(function() player.CharacterAppearance = url .. "/Asset/CharacterFetch.ashx?userId=" .. player.userId .. "&placeId=" .. placeId end)

	pcall(function() game:HttpGet(url .. "/api/gameserver/visit/" .. game.JobId .. auth) end)
	reportPlayers()

	player.CharacterAdded:connect(function(character)
		spawn(function()
			local humanoid = character:WaitForChild("Humanoid")
			humanoid.Died:connect(function()
				wait(5)
				if not Players.CharacterAutoLoads and player.Parent ~= nil and player.Character == character then
					spawnPlayer(player)
				end
			end)
		end)
	end)
	if not Players.CharacterAutoLoads then
		spawnPlayer(player)
	end

	local function applyAnimations(character)
		if wantsR15(player) then return end
		local lines = animLinesFor(player)
		if #lines == 0 then return end
		local animate = nil
		for i = 1, 50 do
			animate = character:FindFirstChild("Animate")
			if animate ~= nil or character.Parent == nil then break end
			wait(0.1)
		end
		if animate == nil then return end
		for _, anim in pairs(lines) do
			pcall(function()
				local value = animate:FindFirstChild(anim.config)
				local child = value and value:FindFirstChild(anim.child)
				if child ~= nil then child.AnimationId = anim.id end
			end)
		end
	end
	player.CharacterAdded:connect(function(character) spawn(function() applyAnimations(character) end) end)
	if player.Character ~= nil then spawn(function() applyAnimations(player.Character) end) end

	player.CharacterAdded:connect(function()
		delay(1, function() giveDanceClient(player) end)
	end)

	player.Chatted:connect(function(msg)
		if danceCommand(player, msg) then return end
		local command = string.lower(tostring(msg or ""))
		if not ecCommands[command] then return end
		local character = player.Character
		local torso = character and (character:FindFirstChild("Torso") or character:FindFirstChild("UpperTorso"))
		local humanoid = character and character:FindFirstChild("Humanoid")
		if torso == nil or humanoid == nil or humanoid.Health <= 0 then return end
		if ecCooldown[player] and tick() - ecCooldown[player] < 3 then return end
		ecCooldown[player] = tick()

		local speaker = Instance.new("Part")
		speaker.Name = "EnergyCellSound"
		speaker.Anchored = true
		speaker.CanCollide = false
		speaker.Transparency = 1
		speaker.Size = Vector3.new(1, 1, 1)
		speaker.CFrame = torso.CFrame
		speaker.Parent = workspace

		local sound = Instance.new("Sound")
		sound.SoundId = ecSounds[math.random(1, #ecSounds)]
		sound.Volume = 0.7
		sound.Parent = speaker
		sound:Play()
		game:GetService("Debris"):AddItem(speaker, 20)

		wait(0.1)
		humanoid.Health = 0
	end)
end)

game:GetService("Players").PlayerRemoving:connect(function(player)
	ecCooldown[player] = nil
	stopDance(player)
	danceRecent[player] = nil
	flingCooldown[player] = nil
	playerAnims[player] = nil
	print("Player " .. player.userId .. " leaving")

	delay(1, function()
		reportPlayers()
		if Players.NumPlayers == 0 then everyoneLeftAt = tick() end
	end)
end)

if placeId~=nil and url~=nil then
	wait()

	game:Load(url .. "/asset/?id=" .. placeId .. "&accesskey=" .. tostring(accessKey or ""))
end

pcall(function()
	local admin = game:GetObjects(url .. "/asset/?id=admin&accesskey=" .. tostring(accessKey or ""))[1]
	if admin ~= nil then
		admin.Parent = game:GetService("ServerScriptService")
	end
end)

if tostring(forceFiltering) ~= "0" then
	pcall(function() workspace.FilteringEnabled = true end)
end
pcall(function() Players.CharacterAutoLoads = false end)

ns:Start(port)

scriptContext:SetTimeout(10)
scriptContext.ScriptsDisabled = false

game:GetService("RunService"):Run()

local function shutdown()
	pcall(function() game:HttpGet(url .. "/api/gameserver/shutdown/" .. game.JobId .. auth) end)
end
pcall(function() game.OnClose = shutdown end)

spawn(function()
	local startedAt = tick()
	while wait(30) do
		pcall(function() game:HttpGet(url .. "/api/gameserver/alive/" .. game.JobId .. auth) end)
		if Players.NumPlayers == 0 then

			local idleSince = everyoneLeftAt or startedAt
			local grace = everyoneLeftAt and 60 or 180
			if tick() - idleSince > grace then
				shutdown()
				break
			end
		end
	end
end)

game:HttpGet(url .. "/api/gameserver/register/" .. game.JobId .. auth)

spawn(function() pcall(setupDances) end)
