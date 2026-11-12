--@ LocalScript StarterPlayer/StarterPlayerScripts RmaStoreClient
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local Config = Client.Config

local Main = Client.PlayerGui:WaitForChild("Main")
local Store = Main:WaitForChild("Store")
Store.Parent = Client.PlayerGui
Store.DisplayOrder = 120
Store.ResetOnSpawn = false

local StoreFrame = Store:WaitForChild("StoreFrame")
local MainFrame = StoreFrame:WaitForChild("MainFrame")
local Holder = MainFrame:WaitForChild("Holder")
local Tabs = MainFrame:FindFirstChild("Tabs")
local Copy = Store:FindFirstChild("Copy")
local Display = StoreFrame:FindFirstChild("ItemDisplay")
local look = Display and Display:FindFirstChild("itemlook")
local cancelDisplay = StoreFrame:FindFirstChild("CancelDisplay")
local creditsLabel = MainFrame:FindFirstChild("Credits") and MainFrame.Credits:FindFirstChild("Credits")

local function playSound(name)
	local s = Store:FindFirstChild(name)
	if s and s:IsA("Sound") then
		s:Play()
	end
end

local pages = {}
for _, f in ipairs(Holder:GetChildren()) do
	if f:IsA("Frame") then
		pages[f.Name] = f
	end
end

if Tabs and pages.Crates and not Tabs:FindFirstChild("Crates") then
	local t = Tabs:FindFirstChild("Credits")
	if t then
		local c = t:Clone()
		c.Name = "Crates"
		local l = c:FindFirstChild("Label")
		if l then
			l.Text = "Cases"
		end
		c.Parent = Tabs
	end
end

local function showPage(name)
	for n, f in pairs(pages) do
		f.Visible = n == name
	end
	if Tabs then
		for _, b in ipairs(Tabs:GetChildren()) do
			if b:IsA("TextButton") then
				b.BackgroundTransparency = b.Name == name and 0.2 or 0.7
			end
		end
	end
end

if Tabs then
	for _, b in ipairs(Tabs:GetChildren()) do
		if b:IsA("TextButton") then
			b.MouseButton1Click:Connect(function()
				showPage(b.Name)
				playSound("Click2")
			end)
		end
	end
end

local closeFrame = MainFrame:FindFirstChild("CloseButton")
local closeX = closeFrame and closeFrame:FindFirstChild("X")
if closeX then
	closeX.MouseButton1Click:Connect(function()
		Client.Close("Store")
	end)
end
local add = MainFrame:FindFirstChild("Credits") and MainFrame.Credits:FindFirstChild("Add")
if add then
	add.MouseButton1Click:Connect(function()
		showPage("Credits")
	end)
end

local currentAction
local function hideDisplay()
	if Display then
		Display.Visible = false
	end
	if cancelDisplay then
		cancelDisplay.Visible = false
	end
	currentAction = nil
end

local function openDisplay(entry)
	if not look then
		if entry.Action then
			entry.Action()
		end
		return
	end
	Display.Visible = true
	if cancelDisplay then
		cancelDisplay.Visible = true
	end
	local item = look:FindFirstChild("Item")
	local icon = item and item:FindFirstChild("ToolIcon")
	if icon then
		icon.Image = entry.Icon or ""
		icon.Visible = (entry.Icon or "") ~= ""
	end
	local desc = look:FindFirstChild("Descriptor")
	if desc then
		local n = desc:FindFirstChild("ItemName")
		if n then
			n.Text = entry.Name
		end
		local d = desc:FindFirstChild("Description", true)
		if d then
			d.Text = entry.Description or ""
		end
	end
	local normal = look:FindFirstChild("ActionNormal")
	local robux = look:FindFirstChild("ActionRobux")
	if normal then
		normal.Visible = not entry.Robux
		normal.Text = entry.ActionText
	end
	if robux then
		robux.Visible = entry.Robux and true or false
		robux.Text = entry.ActionText
	end
	currentAction = entry.Action
end

if Display then
	Display.Visible = false
	local normal = look and look:FindFirstChild("ActionNormal")
	local robux = look and look:FindFirstChild("ActionRobux")
	local cancel = look and look:FindFirstChild("Cancel")
	for _, b in ipairs({ normal, robux }) do
		if b then
			b.MouseButton1Click:Connect(function()
				local act = currentAction
				hideDisplay()
				if act then
					act()
				end
			end)
		end
	end
	if cancel then
		cancel.MouseButton1Click:Connect(hideDisplay)
	end
end
if cancelDisplay then
	cancelDisplay.MouseButton1Click:Connect(hideDisplay)
end

local function scrollerOf(page)
	local objects = page:FindFirstChild("Objects", true)
	return objects and objects:FindFirstChild("Scroller")
end

local function clear(scroller)
	for _, c in ipairs(scroller:GetChildren()) do
		if c:IsA("Frame") then
			c:Destroy()
		end
	end
end

local function card(scroller, entry, robux, order)
	local template = Copy and Copy:FindFirstChild(robux and "RobuxPurchase" or "NormalPurchase")
	if not template then
		return
	end
	local c = template:Clone()
	c.Visible = true
	c.Name = entry.Name
	c.LayoutOrder = order
	local icon = c:FindFirstChild("ToolIcon")
	if icon then
		icon.Image = entry.Icon or ""
	end
	local name = c:FindFirstChild("ToolName")
	if name then
		name.Text = entry.Name
	end
	local action = c:FindFirstChild("Action")
	if action then
		action.Text = entry.ActionText
		action.MouseButton1Click:Connect(function()
			openDisplay(entry)
			playSound("Click")
		end)
	end
	local state = c:FindFirstChild("State")
	if state then
		state.Visible = entry.Owned and true or false
		state.BackgroundTransparency = 0.6
	end
	c.Parent = scroller
	return c
end

local function buy(item)
	local ok, msg = Client.Ask("Buy", item.Tool)
	Client.Notify(msg or "Could not buy that.", ok and "good" or "bad")
	if ok then
		playSound("Open" .. math.random(1, 5))
		Client.Sound("ShopBuy")
	else
		Client.Sound("ShopError")
	end
end

local function filterOf(page)
	local box = page:FindFirstChild("Search", true)
	return box and box.Text:lower() or ""
end

local function drawItems(s)
	local page = pages.Items
	local scroller = page and scrollerOf(page)
	if not scroller then
		return
	end
	clear(scroller)
	local q = filterOf(page)
	for i, item in ipairs(Config.Items) do
		if q == "" or item.Name:lower():find(q, 1, true) then
			local info = s.tools and s.tools[item.Tool]
			local entry = {
				Name = item.Name,
				Icon = item.Icon,
				Description = item.Description,
				Owned = info ~= nil,
			}
			if info then
				local equipped = type(info) == "table" and info.equipped
				entry.ActionText = equipped and "Unequip" or "Equip"
				entry.Action = function()
					local ok, msg = Client.Ask("Equip", item.Tool, not equipped)
					Client.Notify(msg or "", ok and "good" or "bad")
					playSound("Equip")
				end
			else
				entry.ActionText = Config.Comma(item.Price) .. "c"
				entry.Action = function()
					buy(item)
				end
			end
			card(scroller, entry, false, i)
		end
	end
end

local function drawBooths(s)
	local page = pages.Booths
	local scroller = page and scrollerOf(page)
	if not scroller then
		return
	end
	clear(scroller)
	for i, style in ipairs(Config.BoothStyles) do
		local owned = s.styles and s.styles[style.Name]
		local current = s.booth and s.booth.style == style.Name
		card(scroller, {
			Name = style.Name,
			Icon = "",
			Description = "A different look for your booth. Pick it from the Swap Booth tab in the booth menu.",
			Owned = owned,
			ActionText = current and "Using" or owned and "Use" or (Config.Comma(style.Price) .. "c"),
			Action = function()
				Client.Fire("SetStyle", style.Name)
			end,
		}, false, i)
	end
end

local function drawCredits(s)
	local page = pages.Credits
	local scroller = page and scrollerOf(page)
	if not scroller then
		return
	end
	clear(scroller)
	card(scroller, {
		Name = "Playtime",
		Icon = "",
		Description = "You get " .. Config.CreditsPerPayout .. " credits every " .. math.floor(Config.PayoutSeconds / 60) .. " minutes you play. VIP doubles it.",
		ActionText = "+" .. Config.CreditsPerPayout,
		Action = function() end,
	}, false, 1)
	card(scroller, {
		Name = "Rate avatars",
		Icon = "",
		Description = "Every rating you give earns you 5 credits.",
		ActionText = "+5",
		Action = function()
			Client.Open("Rating")
		end,
	}, false, 2)
	card(scroller, {
		Name = "Minigames",
		Icon = "",
		Description = "Win UNO for 200 credits or help clear Minesweeper for 150.",
		ActionText = "+200",
		Action = function() end,
	}, false, 3)
end

local function drawPasses(s)
	local page = pages.Gamepasses
	local scroller = page and scrollerOf(page)
	if not scroller then
		return
	end
	clear(scroller)
	for i, gp in ipairs(Config.Gamepasses) do
		local owned = s.passes and s.passes[gp.Key]
		card(scroller, {
			Name = gp.Name,
			Icon = "",
			Description = gp.Description,
			Owned = owned,
			Robux = not owned,
			ActionText = owned and "Owned" or "Buy",
			Action = function()
				if not owned then
					Client.Fire("PromptPass", gp.Key)
				end
			end,
		}, true, i)
	end
end

local function drawCases(s)
	local page = pages.Crates
	local scroller = page and scrollerOf(page)
	if not scroller then
		return
	end
	clear(scroller)
	card(scroller, {
		Name = "Title Case",
		Icon = "",
		Description = "Unbox a random title. Common 60%, Rare 25%, Epic 11%, Legendary 3.5%, Mythic 0.5%. Duplicates refund half.",
		ActionText = Config.Comma(Config.CaseCost) .. "c",
		Action = function()
			local ok, res = Client.Ask("OpenCase")
			if not ok then
				Client.Notify(res or "Could not open the case.", "bad")
				Client.Sound("CaseError")
				return
			end
			Client.Sound("CaseLatchOpen")
			if type(res) == "table" and res.duplicate then
				Client.Notify("You already own every title. Refunded " .. res.refund .. " credits.", "info")
			elseif type(res) == "table" then
				local r = Config.Rarity(res.rarity)
				Client.Notify("You unboxed " .. res.rarity .. " title: " .. res.text .. "!", "good")
				if res.rarity == "Mythic" or res.rarity == "Legendary" then
					Client.Sound("CaseRareStinger")
				else
					Client.Sound("CaseRewardReveal")
				end
			end
		end,
	}, false, 1)
end

local function redraw()
	local s = Client.State
	if not s then
		return
	end
	if creditsLabel then
		creditsLabel.Text = Config.Comma(s.credits) .. "c"
	end
	drawItems(s)
	drawBooths(s)
	drawCredits(s)
	drawPasses(s)
	drawCases(s)
end

for _, page in pairs(pages) do
	local box = page:FindFirstChild("Search", true)
	if box then
		box.Changed:Connect(function(prop)
			if prop == "Text" then
				redraw()
			end
		end)
	end
end

Client.StateChanged.Event:Connect(redraw)

Client.RegisterExternal("Store", function()
	Store.Enabled = true
	hideDisplay()
	showPage("Items")
	redraw()
	Client.Sound("Woosh")
end, function()
	Store.Enabled = false
end)

Store.Enabled = false
showPage("Items")
redraw()
