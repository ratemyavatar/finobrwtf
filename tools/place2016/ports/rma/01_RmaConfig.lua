--@ ModuleScript ReplicatedStorage RmaConfig
local Config = {}

Config.CreditsPerPayout = 50
Config.PayoutSeconds = 300
Config.StartingCredits = 250
Config.DecalMinutes = 180
Config.DescriptionMax = 250
Config.BlacklistMax = 10
Config.RateCooldownHours = 24
Config.ModCallCooldown = 120
Config.CaseCost = 750

Config.Admins = { 1 }

Config.Items = {
	{ Tool = "Pizza", Name = "Pizza", Price = 150, Icon = "http://www.roblox.com/asset/?id=22595979", Description = "A hot slice. Eat it to get a little health back." },
	{ Tool = "Burger", Name = "Burger", Price = 150, Icon = "http://www.roblox.com/asset/?id=16646570", Description = "A juicy burger. Eat it to get a little health back." },
	{ Tool = "BloxyCola", Name = "Bloxy Cola", Price = 200, Icon = "http://www.roblox.com/asset/?id=10472127", Description = "The classic fizzy drink." },
	{ Tool = "Halloween Candy", Name = "Halloween Candy", Price = 250, Icon = "http://www.roblox.com/asset/?id=17361508", Description = "A bucket full of spooky sweets." },
	{ Tool = "Approval", Name = "Approval Sign", Price = 300, Icon = "rbxassetid://118158930827886", Description = "Let everyone know you love their avatar." },
	{ Tool = "Disapproval", Name = "Disapproval Sign", Price = 300, Icon = "rbxassetid://16767648425", Description = "Let everyone know you do not." },
	{ Tool = "Petition", Name = "Petition", Price = 400, Icon = "", Description = "Ask people to sign your petition." },
	{ Tool = "TextSign", Name = "Text Sign", Price = 500, Icon = "rbxassetid://140378694663711", Description = "Hold up a sign with your own text." },
	{ Tool = "SpeedCoil", Name = "Speed Coil", Price = 750, Icon = "rbxassetid://99170415", Description = "A gear to give you speed." },
	{ Tool = "Crescendo", Name = "Crescendo", Price = 1000, Icon = "http://www.roblox.com/asset/?id=94840368", Description = "A blazing sword that only looks dangerous." },
	{ Tool = "BoomBox", Name = "Boombox", Price = 1500, Icon = "http://www.roblox.com/asset/?id=212303004", Description = "Play your own music for everyone around you." },
	{ Tool = "GoldenSpeedCoil", Name = "Golden Speed Coil", Price = 3000, Icon = "rbxassetid://86594069335160", Description = "The faster, shinier speed coil." },
}

Config.BoothStyles = {
	{ Name = "Default Booth", Price = 0 },
	{ Name = "Simple Booth", Price = 500 },
	{ Name = "Rating Booth", Price = 1000 },
	{ Name = "Gothic Booth", Price = 2500 },
}

Config.Gamepasses = {
	{ Key = "VIP", Name = "VIP", PassId = 0, Description = "A VIP title, a gold name and double credits." },
	{ Key = "Decals", Name = "Instant Decals", PassId = 0, Description = "Use decals on your booth without waiting for the playtime requirement." },
	{ Key = "Boombox", Name = "Boombox", PassId = 0, Description = "Get the boombox for free, forever." },
}

Config.Donations = {
	Small = 0,
	Normal = 0,
	Big = 0,
	VeryBig = 0,
	Huge = 0,
	Mega = 0,
}

Config.Rarities = {
	{ Name = "Common", Color = Color3.fromRGB(200, 200, 200), Weight = 60 },
	{ Name = "Rare", Color = Color3.fromRGB(80, 160, 255), Weight = 25 },
	{ Name = "Epic", Color = Color3.fromRGB(180, 90, 255), Weight = 11 },
	{ Name = "Legendary", Color = Color3.fromRGB(255, 190, 40), Weight = 3.5 },
	{ Name = "Mythic", Color = Color3.fromRGB(255, 60, 90), Weight = 0.5 },
}

Config.Titles = {
	{ Id = "newbie", Text = "Newbie", Rarity = "Common", Unlock = { Kind = "default" } },
	{ Id = "regular", Text = "Regular", Rarity = "Common", Unlock = { Kind = "minutes", Value = 60 } },
	{ Id = "dedicated", Text = "Dedicated", Rarity = "Rare", Unlock = { Kind = "minutes", Value = 600 } },
	{ Id = "nolife", Text = "No Life", Rarity = "Epic", Unlock = { Kind = "minutes", Value = 3000 } },
	{ Id = "critic", Text = "Critic", Rarity = "Common", Unlock = { Kind = "given", Value = 10 } },
	{ Id = "judge", Text = "Judge", Rarity = "Rare", Unlock = { Kind = "given", Value = 100 } },
	{ Id = "rated", Text = "Rated", Rarity = "Common", Unlock = { Kind = "received", Value = 10 } },
	{ Id = "fanfave", Text = "Fan Favourite", Rarity = "Epic", Unlock = { Kind = "received", Value = 250 } },
	{ Id = "streak7", Text = "Weekly", Rarity = "Rare", Unlock = { Kind = "streak", Value = 7 } },
	{ Id = "streak30", Text = "Monthly", Rarity = "Legendary", Unlock = { Kind = "streak", Value = 30 } },
	{ Id = "sweeper", Text = "Sweeper", Rarity = "Rare", Unlock = { Kind = "minesweeper", Value = 5 } },
	{ Id = "unoking", Text = "UNO King", Rarity = "Epic", Unlock = { Kind = "uno", Value = 10 } },
	{ Id = "bigspender", Text = "Big Spender", Rarity = "Legendary", Unlock = { Kind = "spent", Value = 20000 } },
	{ Id = "vip", Text = "VIP", Rarity = "Legendary", Unlock = { Kind = "pass", Value = "VIP" } },
	{ Id = "cozy", Text = "Cozy", Rarity = "Common", Unlock = { Kind = "case" } },
	{ Id = "sparkly", Text = "Sparkly", Rarity = "Common", Unlock = { Kind = "case" } },
	{ Id = "drip", Text = "Drip", Rarity = "Rare", Unlock = { Kind = "case" } },
	{ Id = "iconic", Text = "Iconic", Rarity = "Rare", Unlock = { Kind = "case" } },
	{ Id = "stylish", Text = "Stylish", Rarity = "Epic", Unlock = { Kind = "case" } },
	{ Id = "fashionista", Text = "Fashionista", Rarity = "Epic", Unlock = { Kind = "case" } },
	{ Id = "runway", Text = "Runway Star", Rarity = "Legendary", Unlock = { Kind = "case" } },
	{ Id = "divine", Text = "Divine", Rarity = "Mythic", Unlock = { Kind = "case" } },
	{ Id = "owner", Text = "Owner", Rarity = "Mythic", Unlock = { Kind = "admin" } },
}

function Config.Rarity(name)
	for _, r in ipairs(Config.Rarities) do
		if r.Name == name then
			return r
		end
	end
	return Config.Rarities[1]
end

function Config.Title(id)
	for _, t in ipairs(Config.Titles) do
		if t.Id == id then
			return t
		end
	end
	return nil
end

function Config.Item(toolName)
	for _, it in ipairs(Config.Items) do
		if it.Tool == toolName then
			return it
		end
	end
	return nil
end

function Config.Style(name)
	for _, s in ipairs(Config.BoothStyles) do
		if s.Name == name then
			return s
		end
	end
	return nil
end

function Config.Comma(n)
	local s = tostring(math.floor(n or 0))
	local out = s
	while true do
		local k
		out, k = string.gsub(out, "^(-?%d+)(%d%d%d)", "%1,%2")
		if k == 0 then
			break
		end
	end
	return out
end

function Config.Clock(seconds)
	seconds = math.max(0, math.floor(seconds))
	return string.format("%d:%02d", math.floor(seconds / 60), seconds % 60)
end

return Config
