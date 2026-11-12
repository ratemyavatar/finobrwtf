--@ LocalScript StarterPlayer/StarterPlayerScripts RmaPanels
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local Config = Client.Config
local player = Client.Player
local C = Client.Colors

local function scroller(parent, top)
	local s = Client.Make("ScrollingFrame", {
		Size = UDim2.new(1, 0, 1, -(top or 0)),
		Position = UDim2.new(0, 0, 0, top or 0),
		BackgroundTransparency = 1,
		ScrollBarThickness = 6,
		CanvasSize = UDim2.new(0, 0, 0, 0),
		ZIndex = 2,
	}, parent)
	return s
end

local function clear(s)
	for _, c in ipairs(s:GetChildren()) do
		if c:IsA("GuiObject") then
			c:Destroy()
		end
	end
end

local function row(s, i, h)
	local f = Client.Make("Frame", {
		Size = UDim2.new(1, -10, 0, h),
		Position = UDim2.new(0, 0, 0, (i - 1) * (h + 6)),
		BackgroundColor3 = C.Panel2,
		ZIndex = 2,
	}, s)
	Client.Round(f, 8)
	s.CanvasSize = UDim2.new(0, 0, 0, i * (h + 6))
	return f
end

local rating = Client.Panel("Rating", "Rate Avatars", 560, 420)
local rateHint = Client.Make("TextLabel", {
	Text = "Rate other players 1 to 5 stars. You get 5 credits per rating.",
	Size = UDim2.new(1, 0, 0, 22),
	TextColor3 = C.Muted,
	ZIndex = 3,
}, rating.Body)
local rateList = scroller(rating.Body, 28)

local function drawRating()
	clear(rateList)
	local i = 0
	for _, p in ipairs(Players:GetPlayers()) do
		if p ~= player then
			i = i + 1
			local f = row(rateList, i, 64)
			Client.Make("ImageLabel", {
				Size = UDim2.new(0, 52, 0, 52),
				Position = UDim2.new(0, 6, 0, 6),
				BackgroundColor3 = Color3.new(0, 0, 0),
				BackgroundTransparency = 0.5,
				Image = Client.Headshot(p.UserId),
				ZIndex = 3,
			}, f)
			local name = Client.Make("TextButton", {
				Text = p.Name,
				Size = UDim2.new(0, 170, 0, 30),
				Position = UDim2.new(0, 66, 0, 17),
				TextXAlignment = Enum.TextXAlignment.Left,
				ZIndex = 3,
			}, f)
			name.MouseButton1Click:Connect(function()
				Client.Open("Profile", p.UserId)
			end)
			for star = 1, 5 do
				local b = Client.Make("TextButton", {
					Text = tostring(star),
					Size = UDim2.new(0, 40, 0, 40),
					Position = UDim2.new(1, -(6 - star) * 46 - 6, 0, 12),
					BackgroundTransparency = 0,
					BackgroundColor3 = C.Gold,
					TextColor3 = Color3.new(0, 0, 0),
					ZIndex = 3,
				}, f)
				Client.Round(b, 20)
				b.MouseButton1Click:Connect(function()
					local ok, msg = Client.Ask("Rate", p.UserId, star)
					Client.Notify(msg or "", ok and "good" or "bad")
					if ok then
						Client.Sound("ShopChime")
					end
				end)
			end
		end
	end
	if i == 0 then
		local f = row(rateList, 1, 50)
		Client.Make("TextLabel", {
			Text = "Nobody else is here yet.",
			Size = UDim2.new(1, 0, 1, 0),
			TextColor3 = C.Muted,
			ZIndex = 3,
		}, f)
	end
end

local profile = Client.Panel("Profile", "Profile", 480, 440)
local pBody = profile.Body
local pHead = Client.Make("ImageLabel", {
	Size = UDim2.new(0, 110, 0, 110),
	BackgroundColor3 = Color3.new(0, 0, 0),
	BackgroundTransparency = 0.5,
	ZIndex = 3,
}, pBody)
Client.Round(pHead, 55)
local pName = Client.Make("TextLabel", {
	Size = UDim2.new(1, -124, 0, 34),
	Position = UDim2.new(0, 124, 0, 6),
	TextXAlignment = Enum.TextXAlignment.Left,
	ZIndex = 3,
}, pBody)
local pTitle = Client.Make("TextLabel", {
	Size = UDim2.new(1, -124, 0, 24),
	Position = UDim2.new(0, 124, 0, 42),
	TextXAlignment = Enum.TextXAlignment.Left,
	ZIndex = 3,
}, pBody)
local pRating = Client.Make("TextLabel", {
	Size = UDim2.new(1, -124, 0, 24),
	Position = UDim2.new(0, 124, 0, 70),
	TextXAlignment = Enum.TextXAlignment.Left,
	TextColor3 = C.Gold,
	ZIndex = 3,
}, pBody)
local pStats = Client.Make("TextLabel", {
	Size = UDim2.new(1, 0, 0, 120),
	Position = UDim2.new(0, 0, 0, 122),
	TextXAlignment = Enum.TextXAlignment.Left,
	TextYAlignment = Enum.TextYAlignment.Top,
	TextScaled = false,
	TextSize = 18,
	TextWrapped = true,
	ZIndex = 3,
}, pBody)
local pBio = Client.Make("TextBox", {
	Size = UDim2.new(1, -90, 0, 80),
	Position = UDim2.new(0, 0, 1, -84),
	BackgroundTransparency = 0.3,
	BackgroundColor3 = C.Panel2,
	TextScaled = false,
	TextSize = 17,
	TextWrapped = true,
	ClearTextOnFocus = false,
	TextXAlignment = Enum.TextXAlignment.Left,
	TextYAlignment = Enum.TextYAlignment.Top,
	ZIndex = 3,
}, pBody)
Client.Round(pBio, 8)
local pSave = Client.Make("TextButton", {
	Text = "Save Bio",
	Size = UDim2.new(0, 80, 0, 80),
	Position = UDim2.new(1, -80, 1, -84),
	BackgroundTransparency = 0,
	BackgroundColor3 = C.Good,
	ZIndex = 3,
}, pBody)
Client.Round(pSave, 8)
pSave.MouseButton1Click:Connect(function()
	Client.Fire("SetBio", pBio.Text)
end)

local function drawProfile(userId)
	userId = userId or player.UserId
	local p = Client.Ask("Profile", userId)
	if type(p) ~= "table" then
		pName.Text = "Player left"
		pTitle.Text = ""
		pRating.Text = ""
		pStats.Text = ""
		pBio.Text = ""
		return
	end
	local mine = p.userId == player.UserId
	pHead.Image = Client.Headshot(p.userId)
	pName.Text = p.name .. (p.vip and "  [VIP]" or "")
	local t = Config.Title(p.title)
	pTitle.Text = t and t.Text or ""
	pTitle.TextColor3 = t and Config.Rarity(t.Rarity).Color or C.Text
	pRating.Text = string.format("%.1f stars from %d ratings", p.average or 0, p.ratingCount or 0)
	pStats.Text = "Playtime: " .. Config.Comma(p.minutes) .. " minutes\n"
		.. "Daily streak: " .. p.streak .. " days\n"
		.. "Ratings given: " .. Config.Comma(p.given) .. "\n"
		.. "Minesweeper wins: " .. p.minesweeper .. "   UNO wins: " .. p.uno .. "\n"
		.. "Titles: " .. p.titleCount .. " / " .. #Config.Titles .. (p.booth and ("\nBooth #" .. p.booth) or "")
	pBio.Text = p.bio ~= "" and p.bio or (mine and "" or "No bio.")
	pBio.PlaceholderText = mine and "Write something about yourself" or ""
	pBio.TextEditable = mine
	pSave.Visible = mine
	pBio.Size = mine and UDim2.new(1, -90, 0, 80) or UDim2.new(1, 0, 0, 80)
	profile.Title.Text = mine and "Your Profile" or (p.name .. "'s Profile")
end

local titles = Client.Panel("Titles", "Titles", 520, 440)
local tList = scroller(titles.Body, 0)

local function unlockText(t)
	local u = t.Unlock
	if u.Kind == "default" then
		return "Everyone has it"
	elseif u.Kind == "minutes" then
		return "Play for " .. Config.Comma(u.Value) .. " minutes"
	elseif u.Kind == "given" then
		return "Give " .. u.Value .. " ratings"
	elseif u.Kind == "received" then
		return "Get rated " .. u.Value .. " times"
	elseif u.Kind == "streak" then
		return "Reach a " .. u.Value .. " day streak"
	elseif u.Kind == "minesweeper" then
		return "Win Minesweeper " .. u.Value .. " times"
	elseif u.Kind == "uno" then
		return "Win UNO " .. u.Value .. " times"
	elseif u.Kind == "spent" then
		return "Spend " .. Config.Comma(u.Value) .. " credits"
	elseif u.Kind == "pass" then
		return "Own the " .. u.Value .. " pass"
	elseif u.Kind == "case" then
		return "Found in the Title Case"
	end
	return "Staff only"
end

local function drawTitles()
	clear(tList)
	local s = Client.State
	if not s then
		return
	end
	for i, t in ipairs(Config.Titles) do
		local f = row(tList, i, 48)
		local owned = s.titles and s.titles[t.Id]
		local rarity = Config.Rarity(t.Rarity)
		Client.Make("TextLabel", {
			Text = t.Text,
			Size = UDim2.new(0.4, 0, 0, 26),
			Position = UDim2.new(0, 10, 0, 3),
			TextColor3 = rarity.Color,
			TextXAlignment = Enum.TextXAlignment.Left,
			ZIndex = 3,
		}, f)
		Client.Make("TextLabel", {
			Text = t.Rarity .. " - " .. unlockText(t),
			Size = UDim2.new(0.65, 0, 0, 16),
			Position = UDim2.new(0, 10, 0, 29),
			TextColor3 = C.Muted,
			TextXAlignment = Enum.TextXAlignment.Left,
			ZIndex = 3,
		}, f)
		local equipped = s.equippedTitle == t.Id
		local b = Client.Make("TextButton", {
			Text = equipped and "Equipped" or owned and "Equip" or "Locked",
			Size = UDim2.new(0, 100, 0, 34),
			Position = UDim2.new(1, -110, 0, 7),
			BackgroundTransparency = 0,
			BackgroundColor3 = equipped and C.Info or owned and C.Good or Color3.fromRGB(70, 70, 70),
			ZIndex = 3,
		}, f)
		Client.Round(b, 8)
		if owned and not equipped then
			b.MouseButton1Click:Connect(function()
				Client.Fire("EquipTitle", t.Id)
			end)
		end
	end
end

local profileTarget
Client.PanelOpened.Event:Connect(function(name, arg)
	if name == "Rating" then
		drawRating()
	elseif name == "Profile" then
		profileTarget = arg
		drawProfile(arg)
	elseif name == "Titles" then
		drawTitles()
	end
end)

Client.StateChanged.Event:Connect(function()
	if titles.Frame.Visible then
		drawTitles()
	end
	if profile.Frame.Visible and (profileTarget == nil or profileTarget == player.UserId) then
		drawProfile(player.UserId)
	end
end)

Players.PlayerAdded:Connect(function()
	if rating.Frame.Visible then
		drawRating()
	end
end)
Players.PlayerRemoving:Connect(function()
	if rating.Frame.Visible then
		wait()
		drawRating()
	end
end)
