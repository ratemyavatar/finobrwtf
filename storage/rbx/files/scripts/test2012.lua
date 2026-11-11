
game:Load('rbxasset://12312312321.rbxl')
print("Loading map")
local scriptContext = game:GetService('ScriptContext')
scriptContext.ScriptsDisabled = false
game:SetPlaceID(763, false)
game:GetService("ChangeHistoryService"):SetEnabled(false)
pcall(function() settings().Network.UseInstancePacketCache = true end)
pcall(function() settings().Network.UsePhysicsPacketCache = true end)

pcall(function() settings()["Task Scheduler"].PriorityMethod = Enum.PriorityMethod.AccumulatedError end)

settings().Network.PhysicsSend = Enum.PhysicsSendMethod.ErrorComputation2
settings().Network.ExperimentalPhysicsEnabled = true
settings().Network.WaitingForCharacterLogRate = 100
pcall(function() settings().Diagnostics:LegacyScriptMode() end)
game:GetService("RunService"):Run()
game:GetService("NetworkServer"):Start(340)
pcall(function() game:GetService("Players"):SetChatStyle(Enum.ChatStyle.ClassicAndBubble) end)