--@ LocalScript StarterPlayer/StarterPlayerScripts RmaBoothClient
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local UserInputService = game:GetService("UserInputService")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local Config = Client.Config
local player = Client.Player

local Gui = Client.PlayerGui:WaitForChild("BoothCustomizer")
local Outer = Gui:WaitForChild("Main")
local Edit = Outer:WaitForChild("Main")
local Swap = Outer:FindFirstChild("BoothSwap")
local SideButtons = Outer:FindFirstChild("Buttons")

local descBox = Edit:FindFirstChild("descBox", true)
local charsLeft = Edit:FindFirstChild("CharactersLeft", true)
local imageChanger = Edit:FindFirstChild("ImageChanger")
local idInput = imageChanger and imageChanger:FindFirstChild("IDInput")
local idLabel = imageChanger and imageChanger:FindFirstChild("Label")
local picker = Edit:FindFirstChild("ColorPicker")
local primaryButton = Edit:FindFirstChild("PrimaryColorButton")
local accentButton = Edit:FindFirstChild("AccentColorButton")
local updateButton = Edit:FindFirstChild("UpdateButton")
local abandonButton = Edit:FindFirstChild("AbandonButton")
local blacklist = Edit:FindFirstChild("Blacklist")
local blMain = blacklist and blacklist:FindFirstChild("Main")
local blAdd = blMain and blMain:FindFirstChild("Add")
local blTemplate = blMain and blMain:FindFirstChild("Blacklist_Copy")
local closeButton = Outer:FindFirstChild("CloseButton")

local colors = { primary = "", accent = "" }
local editing = "primary"

local function hexOf(c)
	return string.format("%02X%02X%02X", math.floor(c.r * 255 + 0.5), math.floor(c.g * 255 + 0.5), math.floor(c.b * 255 + 0.5))
end

local function colorOf(hex)
	hex = tostring(hex or ""):gsub("#", "")
	if not hex:match("^%x%x%x%x%x%x$") then
		return nil
	end
	return Color3.new(tonumber(hex:sub(1, 2), 16) / 255, tonumber(hex:sub(3, 4), 16) / 255, tonumber(hex:sub(5, 6), 16) / 255)
end

local function hsv(h, s, v)
	local ok, c = pcall(function()
		return Color3.fromHSV(h, s, v)
	end)
	if ok then
		return c
	end
	local i = math.floor(h * 6)
	local f = h * 6 - i
	local p, q, t = v * (1 - s), v * (1 - f * s), v * (1 - (1 - f) * s)
	i = i % 6
	if i == 0 then
		return Color3.new(v, t, p)
	elseif i == 1 then
		return Color3.new(q, v, p)
	elseif i == 2 then
		return Color3.new(p, v, t)
	elseif i == 3 then
		return Color3.new(p, q, v)
	elseif i == 4 then
		return Color3.new(t, p, v)
	end
	return Color3.new(v, p, q)
end

local function orbs()
	for key, button in pairs({ primary = primaryButton, accent = accentButton }) do
		local orb = button and button:FindFirstChild("ColorOrb")
		local c = colorOf(colors[key])
		if orb then
			orb.BackgroundColor3 = c or Color3.fromRGB(163, 162, 165)
			orb.BackgroundTransparency = c and 0 or 0.5
		end
	end
end

local hue, sat, val = 0, 0, 1
local function pickerChanged()
	local c = hsv(hue, sat, val)
	colors[editing] = hexOf(c)
	if picker then
		local swatch = picker:FindFirstChild("Color")
		if swatch then
			swatch.BackgroundColor3 = c
		end
		local hex = picker:FindFirstChild("hex")
		if hex then
			hex.Text = "#" .. colors[editing]
		end
		local wheel = picker:FindFirstChild("Wheel")
		local sel = wheel and wheel:FindFirstChild("Select")
		if sel then
			local angle = hue * math.pi * 2
			sel.Position = UDim2.new(0.5 + math.cos(angle) * sat * 0.5, 0, 0.5 - math.sin(angle) * sat * 0.5, 0)
		end
		local value = picker:FindFirstChild("Value")
		local vsel = value and value:FindFirstChild("Select")
		if vsel then
			vsel.Position = UDim2.new(0.5, 0, 1 - val, 0)
		end
	end
	orbs()
end

local function openPicker(which)
	editing = which
	if not picker then
		return
	end
	picker.Visible = true
	local title = picker:FindFirstChild("Title")
	if title then
		title.Text = which == "primary" and "Primary Color" or "Accent Color"
	end
	local c = colorOf(colors[which]) or Color3.new(1, 1, 1)
	local r, g, b = c.r, c.g, c.b
	local mx, mn = math.max(r, g, b), math.min(r, g, b)
	val = mx
	sat = mx == 0 and 0 or (mx - mn) / mx
	if mx == mn then
		hue = 0
	elseif mx == r then
		hue = ((g - b) / (mx - mn)) % 6 / 6
	elseif mx == g then
		hue = ((b - r) / (mx - mn) + 2) / 6
	else
		hue = ((r - g) / (mx - mn) + 4) / 6
	end
	pickerChanged()
end

if picker then
	local wheel = picker:FindFirstChild("Wheel")
	local wheelButton = wheel and wheel:FindFirstChild("Button")
	local value = picker:FindFirstChild("Value")
	local valueButton = value and value:FindFirstChild("Button")
	local dragging
	local mouse = player:GetMouse()
	local function update()
		if dragging == "wheel" and wheel then
			local center = wheel.AbsolutePosition + wheel.AbsoluteSize / 2
			local dx = mouse.X - center.X
			local dy = center.Y - mouse.Y
			local radius = wheel.AbsoluteSize.X / 2
			sat = math.min(1, math.sqrt(dx * dx + dy * dy) / math.max(radius, 1))
			hue = (math.atan2(dy, dx) / (math.pi * 2)) % 1
			pickerChanged()
		elseif dragging == "value" and value then
			val = 1 - math.min(1, math.max(0, (mouse.Y - value.AbsolutePosition.Y) / math.max(value.AbsoluteSize.Y, 1)))
			pickerChanged()
		end
	end
	if wheelButton then
		wheelButton.MouseButton1Down:Connect(function()
			dragging = "wheel"
			update()
		end)
	end
	if valueButton then
		valueButton.MouseButton1Down:Connect(function()
			dragging = "value"
			update()
		end)
	end
	mouse.Move:Connect(update)
	mouse.Button1Up:Connect(function()
		dragging = nil
	end)
	local hex = picker:FindFirstChild("hex")
	if hex then
		hex.FocusLost:Connect(function()
			local c = colorOf(hex.Text)
			if c then
				colors[editing] = hexOf(c)
				openPicker(editing)
			end
		end)
	end
	local close = picker:FindFirstChild("CloseButton")
	if close then
		close.MouseButton1Click:Connect(function()
			picker.Visible = false
		end)
	end
end

if primaryButton then
	primaryButton.MouseButton1Click:Connect(function()
		openPicker("primary")
	end)
end
if accentButton then
	accentButton.MouseButton1Click:Connect(function()
		openPicker("accent")
	end)
end

if descBox then
	descBox.Changed:Connect(function(prop)
		if prop == "Text" then
			if #descBox.Text > Config.DescriptionMax then
				descBox.Text = descBox.Text:sub(1, Config.DescriptionMax)
			end
			if charsLeft then
				charsLeft.Text = tostring(Config.DescriptionMax - #descBox.Text)
			end
		end
	end)
end

local blButtons = {}
local function drawBlacklist(list)
	for _, b in ipairs(blButtons) do
		b:Destroy()
	end
	blButtons = {}
	if not blTemplate then
		return
	end
	for i, name in ipairs(list or {}) do
		local b = blTemplate:Clone()
		b.Name = "Entry"
		b.Text = name
		b.Visible = true
		b.LayoutOrder = i + 1
		b.Parent = blMain
		b.MouseButton1Click:Connect(function()
			Client.Fire("Blacklist", name, "remove")
		end)
		table.insert(blButtons, b)
	end
	if blAdd then
		blAdd.LayoutOrder = 999
	end
end

if blAdd then
	blAdd.FocusLost:Connect(function(enter)
		if enter and blAdd.Text ~= "" then
			Client.Fire("Blacklist", blAdd.Text, "add")
			blAdd.Text = ""
		end
	end)
end

local styleCards = {}
local function drawStyles(s)
	if not Swap then
		return
	end
	local scroll = Swap:FindFirstChild("ScrollingFrame", true)
	if not scroll then
		return
	end
	local template
	for _, c in ipairs(scroll:GetChildren()) do
		if c.Name == "Template" and c:IsA("Frame") then
			template = template or c:Clone()
			c:Destroy()
		end
	end
	template = template or styleCards.Template
	styleCards.Template = template
	for _, c in ipairs(styleCards) do
		c:Destroy()
	end
	for i = #styleCards, 1, -1 do
		styleCards[i] = nil
	end
	if not template then
		return
	end
	for i, style in ipairs(Config.BoothStyles) do
		local card = template:Clone()
		card.Name = "Style" .. i
		card.LayoutOrder = i
		card.Visible = true
		local owned = s.styles and s.styles[style.Name]
		local current = s.booth and s.booth.style == style.Name
		local hidden = card:FindFirstChild("Hidden")
		if hidden then
			hidden.Visible = not owned or current
			local check = hidden:FindFirstChild("Checkmark")
			local lock = hidden:FindFirstChild("Locked")
			local price = hidden:FindFirstChild("Price")
			if check then
				check.Visible = current
			end
			if lock then
				lock.Visible = not owned
			end
			if price then
				price.Visible = not owned
				price.Text = "Buy for $" .. Config.Comma(style.Price)
			end
		end
		local img = card:FindFirstChild("BoothImage")
		Client.Make("TextLabel", {
			Name = "StyleName",
			Text = style.Name,
			Size = UDim2.new(1, 0, 0.22, 0),
			Position = UDim2.new(0, 0, 0.78, 0),
			BackgroundTransparency = 0.4,
			BackgroundColor3 = Color3.new(0, 0, 0),
			ZIndex = 7,
		}, card)
		if img then
			img.MouseButton1Click:Connect(function()
				Client.Fire("SetStyle", style.Name)
			end)
		end
		card.Parent = scroll
		table.insert(styleCards, card)
	end
end

local function show(which)
	Edit.Visible = which == "edit"
	if Swap then
		Swap.Visible = which == "swap"
	end
end

if SideButtons then
	local e = SideButtons:FindFirstChild("EditBooth")
	local s = SideButtons:FindFirstChild("SwapBooth")
	if e then
		e.MouseButton1Click:Connect(function()
			show("edit")
		end)
	end
	if s then
		s.MouseButton1Click:Connect(function()
			show("swap")
		end)
	end
end

local loaded = false
local function fill(s)
	if not s or not s.booth then
		return
	end
	if not loaded then
		loaded = true
		if descBox then
			descBox.Text = s.booth.description or ""
		end
		colors.primary = s.booth.primary or ""
		colors.accent = s.booth.accent or ""
		orbs()
	end
	if idLabel then
		if s.canDecal then
			idLabel.Text = "Decal or image ID"
		else
			idLabel.Text = "To use decals you must have '" .. Config.DecalMinutes .. "' minutes of playtime (" .. s.minutes .. " so far)"
		end
	end
	if idInput then
		idInput.TextEditable = s.canDecal and true or false
		idInput.PlaceholderText = s.canDecal and "Image ID" or "Locked"
	end
	if abandonButton then
		abandonButton.Visible = s.boothId ~= nil
	end
	drawBlacklist(s.booth.blacklist)
	drawStyles(s)
end

Client.StateChanged.Event:Connect(fill)
if Client.State then
	fill(Client.State)
end

if updateButton then
	updateButton.MouseButton1Click:Connect(function()
		local s = Client.State
		Client.Fire("UpdateBooth", {
			description = descBox and descBox.Text or "",
			image = idInput and idInput.Text or nil,
			primary = colors.primary,
			accent = colors.accent,
		})
		if not (s and s.boothId) then
			Client.Notify("Saved. Claim a booth to show it off!", "info")
		end
		local snd = Gui:FindFirstChild("Update")
		if snd then
			snd:Play()
		end
	end)
end

if abandonButton then
	abandonButton.MouseButton1Click:Connect(function()
		Client.Fire("Abandon")
		local snd = Gui:FindFirstChild("Abandon")
		if snd then
			snd:Play()
		end
		Client.Close("Booth")
	end)
end

Client.RegisterExternal("Booth", function()
	Outer.Visible = true
	show("edit")
	if picker then
		picker.Visible = false
	end
end, function()
	Outer.Visible = false
end)

if closeButton then
	closeButton.MouseButton1Click:Connect(function()
		Client.Close("Booth")
	end)
end

Client.Event.OnClientEvent:Connect(function(action)
	if action == "Claimed" then
		Client.Open("Booth")
	end
end)
