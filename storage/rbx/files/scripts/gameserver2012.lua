
local placeId = {placeId}
local port = {port}
local jobId = "{jobId}"
local creatorId = {creatorId}
local url = "{baseUrl}"
local accessKey = "{accessKey}"
local auth = "?accesskey=" .. accessKey

local Players = game:GetService("Players")

local function get(path)
	return game:HttpGet(url .. path .. auth, true)
end

local function reportPlayers()
	pcall(function()
		local ids = {}
		for _, player in pairs(Players:GetChildren()) do
			if player:IsA("Player") then
				table.insert(ids, tostring(player.userId))
			end
		end
		local body = '{"jobId":"' .. jobId .. '","players":[' .. table.concat(ids, ",") .. ']}'
		game:HttpPost(url .. "/api/gameserver/update" .. auth, body, true)
	end)
end

pcall(function() settings().Network.UseInstancePacketCache = true end)
pcall(function() settings().Network.UsePhysicsPacketCache = true end)
pcall(function() settings()["Task Scheduler"].PriorityMethod = Enum.PriorityMethod.AccumulatedError end)
pcall(function() settings().Network.WaitingForCharacterLogRate = 100 end)
pcall(function() settings().Diagnostics:LegacyScriptMode() end)
pcall(function() settings().Diagnostics.LuaRamLimit = 0 end)

local scriptContext = game:GetService("ScriptContext")
scriptContext.ScriptsDisabled = true

pcall(function() game:SetPlaceID(placeId, false) end)
pcall(function() game:SetCreatorID(creatorId, Enum.CreatorType.User) end)
pcall(function() game:GetService("ChangeHistoryService"):SetEnabled(false) end)

pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url .. "/") end)
pcall(function() game:GetService("ScriptInformationProvider"):SetAssetUrl(url .. "/Asset/") end)
pcall(function() game:GetService("ScriptInformationProvider"):SetAccessKey(accessKey) end)
pcall(function()
	local badges = game:GetService("BadgeService")
	badges:SetPlaceId(placeId)
	badges:SetAwardBadgeUrl(url .. "/Game/Badge/AwardBadge.ashx?UserID=%d&BadgeID=%d&PlaceID=%d&accesskey=" .. accessKey)
	badges:SetHasBadgeUrl(url .. "/Game/Badge/HasBadge.ashx?UserID=%d&BadgeID=%d")
	badges:SetIsBadgeDisabledUrl(url .. "/Game/Badge/IsBadgeDisabled.ashx?BadgeID=%d&PlaceID=%d")
	badges:SetIsBadgeLegalUrl(url .. "/Game/Badge/IsBadgeLegal.ashx?BadgeID=%d&PlaceID=%d")
end)
pcall(function()
	local insert = game:GetService("InsertService")
	insert:SetBaseSetsUrl(url .. "/Game/Tools/InsertAsset.ashx?nsets=10&type=base")
	insert:SetUserSetsUrl(url .. "/Game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d")
	insert:SetCollectionUrl(url .. "/Game/Tools/InsertAsset.ashx?sid=%d")
	insert:SetAssetUrl(url .. "/Asset/?id=%d")
	insert:SetAssetVersionUrl(url .. "/Asset/?assetversionid=%d")
end)

local everyoneLeftAt = nil
local networkServer = game:GetService("NetworkServer")

pcall(function() Players.CharacterAutoLoads = false end)

local function removePlayer(player, reason)
	print("Removing player " .. tostring(player.userId) .. ": " .. reason)
	pcall(function()
		for _, replicator in pairs(networkServer:GetChildren()) do
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
			return game:HttpGet(url .. "/api/gameserver/verify/" .. jobId .. "/" .. player.userId .. auth .. "&key=" .. key, true)
		end)
		if ok then
			return string.match(tostring(reply or ""), "^1|(.+)$")
		end
		wait(1)
	end
	return nil
end

local knownSources = {}
local loadingPlayers = {}

local function readSource(object)
	local ok, source = pcall(function() return object.Source end)
	if ok and type(source) == "string" then return source end
	return nil
end

local function insideLoadingPlayer(object)
	local parent = object.Parent
	while parent ~= nil do
		for player in pairs(loadingPlayers) do
			if parent == player or parent == player.Character then
				return true
			end
		end
		parent = parent.Parent
	end
	return false
end

local function guardScript(object)
	local ok, isScript = pcall(function() return object.ClassName == "Script" end)
	if not ok or not isScript then return end
	local source = readSource(object)
	if source == nil or knownSources[source] then return end
	if insideLoadingPlayer(object) then
		knownSources[source] = true
		return
	end
	pcall(function() object.Disabled = true end)
	pcall(function() object:Remove() end)
	print("Removed a script the server didn't load")
end

local function rememberScripts(root)
	local ok, children = pcall(function() return root:GetChildren() end)
	if not ok then return end
	for _, child in pairs(children) do
		pcall(function()
			if child.ClassName == "Script" then
				local source = readSource(child)
				if source ~= nil then knownSources[source] = true end
			end
		end)
		rememberScripts(child)
	end
end

local function spawnPlayer(player)
	if player.Parent == nil then return end
	loadingPlayers[player] = true
	pcall(function() player:LoadCharacter() end)
	delay(3, function() loadingPlayers[player] = nil end)
end

Players.PlayerAdded:connect(function(player)
	print("Player " .. player.userId .. " added")
	everyoneLeftAt = nil

	for _, other in pairs(Players:GetChildren()) do
		if other ~= player and other:IsA("Player") and other.userId == player.userId then
			removePlayer(player, "already in this server")
			return
		end
	end

	local name = verifyPlayer(player)
	if name == nil then
		removePlayer(player, "couldn't be verified")
		return
	end

	pcall(function() player.Name = name end)
	pcall(function() player.CharacterAppearance = url .. "/Asset/CharacterFetch.ashx?userId=" .. player.userId .. "&placeId=" .. placeId end)

	pcall(function() get("/api/gameserver/visit/" .. jobId) end)
	reportPlayers()

	player.CharacterAdded:connect(function(character)
		delay(0, function()
			local humanoid = nil
			for i = 1, 100 do
				humanoid = character:FindFirstChild("Humanoid")
				if humanoid ~= nil or character.Parent == nil then break end
				wait(0.1)
			end
			if humanoid == nil then return end
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
end)

Players.PlayerRemoving:connect(function(player)
	print("Player " .. player.userId .. " leaving")
	loadingPlayers[player] = nil
	delay(1, function()
		reportPlayers()
		if Players.NumPlayers == 0 then everyoneLeftAt = tick() end
	end)
end)

game:Load(url .. "/asset/?id=" .. placeId .. "&accesskey=" .. accessKey)

pcall(function() Players.CharacterAutoLoads = false end)
Players.Changed:connect(function(property)
	if property == "CharacterAutoLoads" and Players.CharacterAutoLoads then
		Players.CharacterAutoLoads = false
	end
end)
rememberScripts(game)
game.DescendantAdded:connect(guardScript)

networkServer:Start(port)

pcall(function() scriptContext:SetTimeout(10) end)
scriptContext.ScriptsDisabled = false

game:GetService("RunService"):Run()

local closed = false
local function shutdown()
	if closed then return end
	closed = true

	pcall(function() get("/api/gameserver/shutdown/" .. jobId) end)
end
pcall(function() game.OnClose = shutdown end)

delay(0, function()
	local startedAt = tick()
	while wait(30) do
		pcall(function() get("/api/gameserver/alive/" .. jobId) end)
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

get("/api/gameserver/register/" .. jobId)
print("Finobe 2012 server " .. jobId .. " running place " .. placeId .. " on port " .. port)
