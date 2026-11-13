pcall(function() game:SetPlaceID({placeId}, false) end)
pcall(function() game:SetUniverseId({placeId}) end)
pcall(function() game:SetCreatorID({creatorId}, Enum.CreatorType.User) end)

pcall(function() game:GetService("ScriptInformationProvider"):SetAssetUrl("http://www.finobe.wtf/asset/") end)
pcall(function() game:GetService("ContentProvider"):SetThreadPool(16) end)
pcall(function() game:GetService("InsertService"):SetFreeModelUrl("http://www.finobe.wtf/game/Tools/InsertAsset.ashx?type=fm&q=%s&pg=%d&rs=%d") end)
pcall(function() game:GetService("InsertService"):SetFreeDecalUrl("http://www.finobe.wtf/game/Tools/InsertAsset.ashx?type=fd&q=%s&pg=%d&rs=%d") end)
pcall(function() game:GetService("InsertService"):SetBaseSetsUrl("http://www.finobe.wtf/game/Tools/InsertAsset.ashx?nsets=10&type=base") end)
pcall(function() game:GetService("InsertService"):SetUserSetsUrl("http://www.finobe.wtf/game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d") end)
pcall(function() game:GetService("InsertService"):SetCollectionUrl("http://www.finobe.wtf/game/Tools/InsertAsset.ashx?sid=%d") end)
pcall(function() game:GetService("InsertService"):SetAssetUrl("http://www.finobe.wtf/Asset/?id=%d") end)
pcall(function() game:GetService("InsertService"):SetAssetVersionUrl("http://www.finobe.wtf/Asset/?assetversionid=%d") end)

pcall(function() game:GetService("SocialService"):SetFriendUrl("http://www.finobe.wtf/game/LuaWebService/HandleSocialRequest.ashx?method=IsFriendsWith&playerid=%d&userid=%d") end)
pcall(function() game:GetService("SocialService"):SetBestFriendUrl("http://www.finobe.wtf/game/LuaWebService/HandleSocialRequest.ashx?method=IsBestFriendsWith&playerid=%d&userid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupUrl("http://www.finobe.wtf/game/LuaWebService/HandleSocialRequest.ashx?method=IsInGroup&playerid=%d&groupid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupRankUrl("http://www.finobe.wtf/game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRank&playerid=%d&groupid=%d") end)
pcall(function() game:GetService("SocialService"):SetGroupRoleUrl("http://www.finobe.wtf/game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRole&playerid=%d&groupid=%d") end)
pcall(function() game:GetService("GamePassService"):SetPlayerHasPassUrl("http://www.finobe.wtf/game/GamePass/GamePassHandler.ashx?Action=HasPass&UserID=%d&PassID=%d") end)
pcall(function() game:GetService("MarketplaceService"):SetProductInfoUrl("https://api.finobe.wtf/marketplace/productinfo?assetId=%d") end)
pcall(function() game:GetService("MarketplaceService"):SetDevProductInfoUrl("https://api.finobe.wtf/marketplace/productDetails?productId=%d") end)
pcall(function() game:GetService("MarketplaceService"):SetPlayerOwnsAssetUrl("https://api.finobe.wtf/ownership/hasasset?userId=%d&assetId=%d") end)

local placeUrl = "{placeUrl}"
if placeUrl ~= "" then
	game:Load(placeUrl)
end

local uploadUrl = "{uploadUrl}"
if uploadUrl ~= "" then
	pcall(function() game:GetService("Visit"):SetUploadUrl(uploadUrl) end)
end

pcall(function() game:GetService("ChangeHistoryService"):SetEnabled(true) end)
