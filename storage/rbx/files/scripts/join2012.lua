-- MultiplayerSharedScript.lua inserted here ------ Prepended to GroupBuild.lua and Join.lua --
-- the 2012 client can run its -Script twice: the second run must leave the first one's connection alone
if game:GetService("Players").LocalPlayer ~= nil then return end

-- functions --------------------------
function onPlayerAdded(player)
-- override
end

pcall(function() game:SetPlaceID({placeId}, false) end)

local startTime = tick()
local loadResolved = false
local joinResolved = false
local playResolved = true
local playStartTime = 0

local cdnSuccess = 0
local cdnFailure = 0

settings()["Game Options"].CollisionSoundEnabled = true
pcall(function() settings().Rendering.EnableFRM = true end)
pcall(function() settings().Physics.Is30FpsThrottleEnabled = false end)
pcall(function() settings()["Task Scheduler"].PriorityMethod = Enum.PriorityMethod.AccumulatedError end)

function reportContentProvider(time, queueLength, blocking)
pcall(function()
game:HttpGet("http://assetgame.finobe.wtf/Analytics/ContentProvider.ashx?t=" .. time .. "&ql=" .. queueLength, blocking)
end)
end

function reportCdn(blocking)
pcall(function()
local newCdnSuccess = settings().Diagnostics.CdnSuccessCount
local newCdnFailure = settings().Diagnostics.CdnFailureCount
local successDelta = newCdnSuccess - cdnSuccess
local failureDelta = newCdnFailure - cdnFailure
cdnSuccess = newCdnSuccess
cdnFailure = newCdnFailure
if successDelta > 0 or failureDelta > 0 then
game:HttpGet("http://assetgame.finobe.wtf/Game/Cdn.ashx?source=client&success=" .. successDelta .. "&failure=" .. failureDelta, blocking)
end
end)
end

function reportDuration(category, result, duration, blocking, errorType)
if not errorType then
errorType = ''
end
local platform = settings().Diagnostics.OsPlatform
pcall(function() game:HttpGet("http://assetgame.finobe.wtf/Game/JoinRate.ashx?st=0&i=0&p=-1&c=" .. category .. "&r=" .. result .. "&d=" .. (math.floor(duration*1000)) .. "&ip=localhost&errorType=" .. errorType .. "&platform=" .. platform, blocking) end)
end

-- arguments ---------------------------------------
local threadSleepTime = ...

if threadSleepTime==nil then
threadSleepTime = 15
end

local test = true

print("! Joining game '{jobId}' place {placeId} at {serverip}")

local closeConnection = game.Close:connect(function()
if 0 then
reportCdn(true)
if (not loadResolved) or (not joinResolved) then
local duration = tick() - startTime;
if not loadResolved then
loadResolved = true
reportDuration("GameLoad","Cancel", duration, true)
end
if not joinResolved then
joinResolved = true
reportDuration("GameJoin","Cancel", duration, true)
end
elseif not playResolved then
local duration = tick() - playStartTime;
playResolved = true
reportDuration("GameDuration","Success", duration, true)
end
end
end)

game:GetService("ChangeHistoryService"):SetEnabled(false)
game:GetService("ContentProvider"):SetThreadPool(16)
game:GetService("InsertService"):SetBaseSetsUrl("http://assetgame.finobe.wtf/Game/Tools/InsertAsset.ashx?nsets=10&type=base")
game:GetService("InsertService"):SetUserSetsUrl("http://assetgame.finobe.wtf/Game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d")
game:GetService("InsertService"):SetCollectionUrl("http://assetgame.finobe.wtf/Game/Tools/InsertAsset.ashx?sid=%d")
game:GetService("InsertService"):SetAssetUrl("http://assetgame.finobe.wtf/Asset/?id=%d")
game:GetService("InsertService"):SetAssetVersionUrl("http://assetgame.finobe.wtf/Asset/?assetversionid=%d")

pcall(function() game:GetService("SocialService"):SetFriendUrl("http://assetgame.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsFriendsWith&playerid=%d&userid=%d") end)
pcall(function() game:GetService("SocialService"):SetBestFriendUrl("http://assetgame.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsBestFriendsWith&playerid=%d&userid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupUrl("http://assetgame.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsInGroup&playerid=%d&groupid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupRankUrl("http://assetgame.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRank&playerid=%d&groupid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupRoleUrl("http://assetgame.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRole&playerid=%d&groupid=%d") end)
pcall(function() game:SetCreatorID({creatorId}, Enum.CreatorType.User) end)

-- Bubble chat.  This is all-encapsulated to allow us to turn it off with a config setting
pcall(function() game:GetService("Players"):SetChatStyle(Enum.ChatStyle.{chatstyle}) end)

local waitingForCharacter = false
local waitingForCharacterGuid = "0c4727e6-6f3f-4526-948f-efcca5ae1f51";
pcall( function()
if settings().Network.MtuOverride == 0 then
  settings().Network.MtuOverride = 1400
end
end)

-- globals -----------------------------------------

client = game:GetService("NetworkClient")
visit = game:GetService("Visit")

-- functions ---------------------------------------
function ifSeleniumThenSetCookie(key, value)
if false then
game:GetService("CookiesService"):SetCookieValue(key, value)
end
end

function setMessage(message)
-- todo: animated "..."
if not false then
game:SetMessage(message)
else
-- hack, good enought for now
game:SetMessage("Teleporting ...")
end
end

function showErrorWindow(message, errorType, errorCategory)
if 0 then
if (not loadResolved) or (not joinResolved) then
local duration = tick() - startTime;
if not loadResolved then
loadResolved = true
reportDuration("GameLoad","Failure", duration, false,errorType)
end
if not joinResolved then
joinResolved = true
reportDuration("GameJoin",errorCategory, duration, false,errorType)
end

pcall(function() game:HttpGet("?FilterName=Type&FilterValue=" .. errorType .. "&Type=JoinFailure", false) end)
elseif not playResolved then
local duration = tick() - playStartTime;
playResolved = true
reportDuration("GameDuration",errorCategory, duration, false,errorType)

pcall(function() game:HttpGet("?FilterName=Type&FilterValue=" .. errorType .. "&Type=GameDisconnect", false) end)
end
end

game:SetMessage(message)
end

function registerPlay(key)
if true and game:GetService("CookiesService"):GetCookieValue(key) == "" then
game:GetService("CookiesService"):SetCookieValue(key, "{ \"userId\" : {userid}, \"placeId\" : {placeId}, \"os\" : \"" .. settings().Diagnostics.OsPlatform .. "\" }")
end
end

function analytics(name)
if not test and false then
pcall(function() game:HttpGet("?IPFilter=Primary&SecondaryFilterName=UserId&SecondaryFilterValue=0&Type=" .. name, false) end)
end
end

function analyticsGuid(name, guid)
if not test and false then
pcall(function() game:HttpGet("?IPFilter=Primary&SecondaryFilterName=guid&SecondaryFilterValue=" .. guid .. "&Type=" .. name, false) end)
end
end

function reportError(err, message)
print("***ERROR*** " .. err)
if not test then visit:SetUploadUrl("") end
client:Disconnect()
wait(4)
showErrorWindow("Error: " .. err, message, "Other")
end

-- called when the client connection closes
function onDisconnection(peer, lostConnection)
if lostConnection then
    if waitingForCharacter then analyticsGuid("Waiting for Character Lost Connection",waitingForCharacterGuid) end
showErrorWindow("You have lost the connection to the game", "LostConnection", "LostConnection")
else
    if waitingForCharacter then analyticsGuid("Waiting for Character Game Shutdown",waitingForCharacterGuid) end
showErrorWindow("This game has shut down", "Kick", "Kick")
end
end

function requestCharacter(replicator)

-- prepare code for when the Character appears
local connection
connection = player.Changed:connect(function (property)
if property=="Character" then
game:ClearMessage()
waitingForCharacter = false
analyticsGuid("Waiting for Character Success", waitingForCharacterGuid)

connection:disconnect()

if 0 then
if not joinResolved then
local duration = tick() - startTime;
joinResolved = true
reportDuration("GameJoin","Success", duration, false)

playStartTime = tick()
playResolved = false
end
end
end
end)

setMessage("Requesting character")

if 0 and not loadResolved then
local duration = tick() - startTime;
loadResolved = true
reportDuration("GameLoad","Success", duration, false)
end

local success, err = pcall(function()
replicator:RequestCharacter()
setMessage("Waiting for character")
waitingForCharacter = true
analyticsGuid("Waiting for Character Begin",waitingForCharacterGuid);
end)
if not success then
reportError(err,"W4C")
return
end
end

-- called when the client connection is established
function onConnectionAccepted(url, replicator)

local waitingForMarker = true

local success, err = pcall(function()
if not test then
    visit:SetPing("", 300)
end

if not false then
game:SetMessageBrickCount()
else
setMessage("Teleporting ...")
end

replicator.Disconnection:connect(onDisconnection)

-- Wait for a marker to return before creating the Player
local marker = replicator:SendMarker()

marker.Received:connect(function()
waitingForMarker = false
requestCharacter(replicator)
end)
end)

if not success then
reportError(err,"ConnectionAccepted")
return
end

-- TODO: report marker progress

while waitingForMarker do
workspace:ZoomToExtents()
wait(0.5)
end
end

-- called when the client connection fails
function onConnectionFailed(_, error)
showErrorWindow("Failed to connect to the Game. (ID=" .. error .. ")", "ID" .. error, "Other")
end

-- called when the client connection is rejected
function onConnectionRejected()
connectionFailed:disconnect()
showErrorWindow("This game is not available. Please try another", "WrongVersion", "WrongVersion")
end

idled = false
function onPlayerIdled(time)
if time > 20*60 then
showErrorWindow(string.format("You were disconnected for being idle %d minutes", time/60), "Idle", "Idle")
client:Disconnect()
if not idled then
idled = true
end
end
end

-- main ------------------------------------------------------------

analytics("Start Join Script")

ifSeleniumThenSetCookie("SeleniumTest1", "Started join script")

pcall(function() settings().Diagnostics:LegacyScriptMode() end)

-- core gui (player list, health bar, chat, backpack, menu): our 2012 client does not ship the core
-- scripts, so the starter script loads them from the site (storage/rbx/files/2012CoreGui)
print("! DBG: core gui load start")
local starterOk = pcall(function() game:GetService("ScriptContext"):AddStarterScript(37801172) end)
if not starterOk then
pcall(function() game:GetService("ScriptContext"):AddCoreScript(37801172, game:GetService("ScriptContext"), "StarterScript") end)
end
print("! DBG: core gui load done")

local success, err = pcall(function()

game:SetRemoteBuildMode(true)

setMessage("Connecting to Server")
client.ConnectionAccepted:connect(onConnectionAccepted)
client.ConnectionRejected:connect(onConnectionRejected)
connectionFailed = client.ConnectionFailed:connect(onConnectionFailed)
client.Ticket = ""
ifSeleniumThenSetCookie("SeleniumTest2", "Successfully connected to server")

--PlayerConnect creates the local player and starts connecting, but on this build it then throws
--"Unknown exception", so pcall's second value is the error message rather than a player. Take the player
--from Players instead - never create another one, that fails with "Local player already exists".
print("! DBG: connect ip={serverip} port={serverport} uid={userid} sleep=" .. tostring(threadSleepTime))
pcall(function() print("! DBG: client.Ticket=" .. tostring(client.Ticket)) end)
local pcOk, pcErr = pcall(function() return client:PlayerConnect({userid}, "{serverip}", {serverport}, 0, threadSleepTime) end)
print("! DBG: PlayerConnect ok=" .. tostring(pcOk) .. " err=" .. tostring(pcErr))
player = game:GetService("Players").LocalPlayer
if not player then
for i = 1, 100 do
player = game:GetService("Players").LocalPlayer
if player then break end
wait(0.1)
end
end
if player then
analytics("Created Player")
end

pcall(function()
registerPlay("rbx_evt_ftp")
delay(60*5, function() registerPlay("rbx_evt_fmp") end)
end)

pcall(function() player:SetSuperSafeChat(false) end)
pcall(function() player:SetMembershipType(Enum.MembershipType.membership) end)
pcall(function() player:SetAccountAge(365) end)
player.Idled:connect(onPlayerIdled)

-- Overriden
onPlayerAdded(player)

pcall(function() player.Name = [========[{username}]========] end)
player.CharacterAppearance = "{charapp}"
if not test then visit:SetUploadUrl("") end

analytics("Connect Client")
end)

if not success then
--the connect itself already happened; do not reportError here, that disconnects the live connection
Game:SetMessage("Something went wrong")
end

ifSeleniumThenSetCookie("SeleniumTest3", "Successfully created player")

if 0 then
 delay(60*5, function()
while true do
reportCdn(false)
wait(60*5)
end
 end)
 local cpTime = 30
 delay(cpTime, function()
    while cpTime <= 480 do
   reportContentProvider(cpTime, game:GetService("ContentProvider").RequestQueueSize, false)
       wait(cpTime)
       cpTime = cpTime * 2
    end
 end)
end

pcall(function() game:SetScreenshotInfo("") end)
pcall(function() game:SetVideoInfo('<?xml version="1.0"?><entry xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/" xmlns:yt="http://gdata.youtube.com/schemas/2007"><media:group><media:title type="plain"><![CDATA[ROBLOX Place]]></media:title><media:description type="plain"><![CDATA[ For more games visit http://assetgame.finobe.wtf]]></media:description><media:category scheme="http://gdata.youtube.com/schemas/2007/categories.cat">Games</media:category><media:keywords>ROBLOX, video, free game, online virtual world</media:keywords></media:group></entry>') end)
-- use single quotes here because the video info string may have unescaped double quotes

-- Rewrite hardcoded roblox.com asset links in the place to this site: the 2012 client fails Roblox's
-- trust check on them, so decals/sounds/meshes would never load.
pcall(function()
local assetPropertyNames = {"Texture","TextureId","SoundId","MeshId","SkyboxUp","SkyboxLf","SkyboxBk","SkyboxRt","SkyboxFt","SkyboxDn","PantsTemplate","ShirtTemplate","Graphic","Image","LinkedSource","AnimationId"}
local variations = {"http://www%.roblox%.com/asset/%?id=","http://www%.roblox%.com/asset%?id=","http://%.roblox%.com/asset/%?id=","http://%.roblox%.com/asset%?id="}
local function GetDescendants(o)
local allObjects = {}
local function FindChildren(Object)
for _,v in pairs(Object:GetChildren()) do
table.insert(allObjects,v)
FindChildren(v)
end
end
FindChildren(o)
return allObjects
end
local replaced = 0
for _, v in pairs(GetDescendants(game)) do
for _, property in pairs(assetPropertyNames) do
pcall(function()
if v[property] and not v:FindFirstChild(property) then
local assetText = string.lower(v[property])
for _, variation in pairs(variations) do
local newText, matches = string.gsub(assetText, variation, "http://assetgame.finobe.wtf/asset?id=")
if matches > 0 then
v[property] = newText
replaced = replaced + 1
break
end
end
end
end)
end
end
end)

analytics("Join Finished")

ifSeleniumThenSetCookie("SeleniumTest4", "Finished join")
