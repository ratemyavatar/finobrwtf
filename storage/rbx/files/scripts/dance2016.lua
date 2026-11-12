local Players = game:GetService("Players")
local storage = game:GetService("ReplicatedStorage")
local player = Players.LocalPlayer
while player == nil do wait() player = Players.LocalPlayer end

local remote = storage:WaitForChild("FinobeDance")
local listValue = storage:WaitForChild("FinobeDances")

local primary = Color3.new(0, 0, 0)
local secondary = Color3.new(1, 1, 1)
local textColor = Color3.new(1, 1, 1)
local strokeColor = Color3.new(0, 0, 0)
local strokeTransparency = 0
local transparency = 0.5
local font = "Arial"
local guiName = "FinobeDanceGUI"

local function dances()
	local list = {}
	for line in string.gmatch(listValue.Value, "[^\n]+") do
		local id, slug, title = string.match(line, "^(%d+)\t([^\t]+)\t(.+)$")
		if slug ~= nil then table.insert(list, { id = tonumber(id), slug = slug, title = title }) end
	end
	return list
end

local function choose(slug)
	local message = "/e " .. slug
	local said = pcall(function() Players:Chat(message) end)
	remote:FireServer("dance", slug, said)
end

local function open()
	local playerGui = player:FindFirstChild("PlayerGui")
	if playerGui == nil then return end
	local old = playerGui:FindFirstChild(guiName)
	if old ~= nil then old:Destroy() end

	local scr = Instance.new("ScreenGui")
	scr.Name = guiName

	local info = Instance.new("TextLabel", scr)
	info.BackgroundTransparency = transparency - 0.2
	info.BorderSizePixel = 0
	info.BackgroundColor3 = primary
	info.TextColor3 = textColor
	info.TextStrokeColor3 = strokeColor
	info.TextStrokeTransparency = strokeTransparency
	info.Font = font
	info.Text = ""
	info.FontSize = "Size18"
	info.Size = UDim2.new(0, 5, 0, 20)
	info.Position = UDim2.new(1, 0, 0, 0)
	info.Visible = false
	info.ZIndex = 10

	local drag = Instance.new("Frame", scr)
	drag.Active = false
	drag.BackgroundTransparency = 1
	drag.BorderSizePixel = 0
	drag.Size = UDim2.new(0, 255, 0, 30)
	drag.Name = "Drag"
	drag.Position = UDim2.new(0, -400, 0.5, -160)
	drag.ZIndex = 1

	local main = Instance.new("Frame", drag)
	main.Size = UDim2.new(1, 0, 0, 400)
	main.ClipsDescendants = true
	main.BackgroundTransparency = 1
	main.BackgroundColor3 = primary
	main.BorderSizePixel = 0

	local cmf = Instance.new("ScrollingFrame", main)
	cmf.Position = UDim2.new(0, 0, 0, 30)
	cmf.Size = UDim2.new(1, 0, 1, -40)
	cmf.BorderSizePixel = 0
	cmf.BackgroundColor3 = primary
	cmf.ZIndex = 1
	cmf.ScrollBarThickness = 2
	cmf.BackgroundTransparency = transparency

	local top = Instance.new("TextButton", drag)
	top.AutoButtonColor = false
	top.Style = "Custom"
	top.Position = UDim2.new(0, 0, 0, 0)
	top.Size = UDim2.new(1, 0, 0, 30)
	top.BackgroundColor3 = primary
	top.BackgroundTransparency = transparency
	top.Text = "Dances"
	top.BorderSizePixel = 0
	top.ZIndex = 9
	top.TextColor3 = textColor
	top.TextStrokeTransparency = strokeTransparency
	top.TextStrokeColor3 = strokeColor
	top.Font = font
	top.FontSize = "Size18"

	local function smallButton(text, x)
		local button = Instance.new("TextButton", drag)
		button.Style = "Custom"
		button.Size = UDim2.new(0, 20, 0, 20)
		button.Position = UDim2.new(1, x, 0, 5)
		button.ZIndex = 10
		button.Font = font
		button.FontSize = "Size18"
		button.BackgroundColor3 = primary
		button.BorderSizePixel = 0
		button.TextColor3 = textColor
		button.BackgroundTransparency = transparency
		button.Text = text
		return button
	end

	local dragging = false
	local dragStart = nil
	local startPosition = nil
	local connections = {}

	local function close()
		for _, connection in pairs(connections) do pcall(function() connection:disconnect() end) end
		scr:Destroy()
	end

	smallButton("X", -25).MouseButton1Down:connect(close)
	local hide = smallButton("-", -50)
	hide.MouseButton1Down:connect(function()
		main.Visible = not main.Visible
		hide.Text = main.Visible and "-" or "+"
	end)
	local stop = smallButton("S", -75)
	stop.MouseButton1Down:connect(function() remote:FireServer("stop") end)

	top.MouseButton1Down:connect(function(x, y)
		dragging = true
		dragStart = Vector2.new(x, y)
		startPosition = drag.Position
	end)
	local input = game:GetService("UserInputService")
	table.insert(connections, input.InputChanged:connect(function(event)
		if dragging and event.UserInputType == Enum.UserInputType.MouseMovement then
			local delta = Vector2.new(event.Position.X, event.Position.Y) - dragStart
			drag.Position = UDim2.new(startPosition.X.Scale, startPosition.X.Offset + delta.X, startPosition.Y.Scale, startPosition.Y.Offset + delta.Y)
		end
	end))
	table.insert(connections, input.InputEnded:connect(function(event)
		if event.UserInputType == Enum.UserInputType.MouseButton1 then dragging = false end
	end))

	local search = Instance.new("TextBox", drag)
	search.Size = UDim2.new(0, 130, 0, 20)
	search.Position = UDim2.new(0, 5, 0, 5)
	search.BackgroundColor3 = secondary
	search.BackgroundTransparency = transparency
	search.TextColor3 = textColor
	search.BorderSizePixel = 0
	search.TextStrokeTransparency = strokeTransparency
	search.TextStrokeColor3 = strokeColor
	search.Text = ""
	search.ZIndex = 9
	search.Font = font
	search.TextScaled = true
	search.TextWrapped = true

	local function hover(entry, text)
		entry.MouseLeave:connect(function() info.Visible = false end)
		entry.MouseMoved:connect(function(x, y)
			info.Visible = true
			info.Text = text
			info.Size = UDim2.new(0, info.TextBounds.X + 5, 0, 20)
			info.Position = UDim2.new(0, x, 0, y - 20)
		end)
	end

	local function entry(text, y)
		local button = Instance.new("TextButton")
		button.Style = "Custom"
		button.AutoButtonColor = false
		button.BackgroundTransparency = 1
		button.BorderSizePixel = 0
		button.Font = font
		button.FontSize = "Size24"
		button.TextScaled = true
		button.ZIndex = 8
		button.Text = text
		button.Size = UDim2.new(1, -2, 0, 20)
		button.Position = UDim2.new(0, 0, 0, y)
		button.TextColor3 = textColor
		button.TextStrokeColor3 = strokeColor
		button.TextStrokeTransparency = strokeTransparency
		button.TextXAlignment = "Left"
		button.TextYAlignment = "Top"
		button.Parent = cmf
		return button
	end

	local function show(filter)
		cmf:ClearAllChildren()
		filter = string.lower(filter or "")
		local num = 0
		for _, dance in pairs(dances()) do
			if filter == "" or filter == "all" or string.find(string.lower(dance.title), filter, 1, true) or string.find(dance.slug, filter, 1, true) then
				local button = entry(dance.title, num * 20)
				hover(button, "/e " .. dance.slug)
				local slug = dance.slug
				button.MouseButton1Click:connect(function() choose(slug) end)
				num = num + 1
			end
		end
		local counter = entry(num .. " Dance(s)", num * 20)
		counter.Name = "Counter"
		counter.AutoButtonColor = false
		hover(counter, "Click a dance to do it, S stops dancing.")
		cmf.CanvasSize = UDim2.new(0, 0, 0, (num + 1) * 20)
	end

	search.FocusLost:connect(function(enterPressed)
		if enterPressed then show(search.Text) end
	end)

	show("")
	scr.Parent = playerGui
	drag:TweenPosition(UDim2.new(0, 35, 0.5, -160), "Out", "Elastic", 1, true)
end

remote.OnClientEvent:connect(function(action)
	if action == "open" then open() end
end)

local function watch(character)
	local humanoid = character:WaitForChild("Humanoid")
	local moving = false
	humanoid.Running:connect(function(speed)
		local now = speed > 1
		if now and not moving then remote:FireServer("stop") end
		moving = now
	end)
	humanoid.Jumping:connect(function(active)
		if active then remote:FireServer("stop") end
	end)
end

player.CharacterAdded:connect(watch)
if player.Character ~= nil then watch(player.Character) end

local wheelName = "FinobeEmoteWheel"
local wheelImage = "rbxassetid://2140200001"
local highlightImage = "rbxassetid://2140200002"
local wheel = nil

local function buildWheel()
	local playerGui = player:FindFirstChild("PlayerGui")
	if playerGui == nil then return nil end

	local state = { page = 1, visible = {}, slots = {} }

	local scr = Instance.new("ScreenGui")
	scr.Name = wheelName

	local holder = Instance.new("Frame", scr)
	holder.BackgroundTransparency = 1
	holder.BorderSizePixel = 0
	holder.Size = UDim2.new(0, 360, 0, 400)
	holder.Position = UDim2.new(0.5, -180, 0.5, -200)
	holder.Visible = false

	local modal = Instance.new("TextButton", holder)
	modal.BackgroundTransparency = 1
	modal.Text = ""
	modal.Size = UDim2.new(0, 0, 0, 0)
	modal.Modal = true

	local ring = Instance.new("ImageLabel", holder)
	ring.BackgroundTransparency = 1
	ring.Image = wheelImage
	ring.Size = UDim2.new(0, 360, 0, 360)
	ring.Position = UDim2.new(0, 0, 0, 0)
	ring.ImageTransparency = 0.35
	ring.ZIndex = 1

	local highlight = Instance.new("ImageLabel", ring)
	highlight.BackgroundTransparency = 1
	highlight.Image = highlightImage
	highlight.Size = UDim2.new(1, 0, 1, 0)
	highlight.Visible = false
	highlight.ZIndex = 2

	local center = Instance.new("TextLabel", ring)
	center.BackgroundTransparency = 1
	center.Size = UDim2.new(0, 104, 0, 104)
	center.Position = UDim2.new(0.5, -52, 0.5, -52)
	center.Font = "SourceSans"
	center.FontSize = "Size18"
	center.TextWrapped = true
	center.TextColor3 = Color3.new(1, 1, 1)
	center.Text = "Select an Emote"
	center.ZIndex = 4

	for i = 1, 8 do
		local angle = math.rad((i - 1) * 45 - 90)
		local x = 180 + math.cos(angle) * 121
		local y = 180 + math.sin(angle) * 121
		local slot = Instance.new("TextButton", ring)
		slot.Style = "Custom"
		slot.AutoButtonColor = false
		slot.BackgroundTransparency = 1
		slot.BorderSizePixel = 0
		slot.Text = ""
		slot.Size = UDim2.new(0, 80, 0, 80)
		slot.Position = UDim2.new(0, x - 40, 0, y - 40)
		slot.ZIndex = 3

		local thumb = Instance.new("ImageLabel", slot)
		thumb.BackgroundTransparency = 1
		thumb.Size = UDim2.new(0, 64, 0, 64)
		thumb.Position = UDim2.new(0.5, -32, 0, 0)
		thumb.ZIndex = 3

		local number = Instance.new("TextLabel", slot)
		number.BackgroundTransparency = 1
		number.Size = UDim2.new(1, 0, 0, 16)
		number.Position = UDim2.new(0, 0, 1, -16)
		number.Font = "SourceSansBold"
		number.FontSize = "Size14"
		number.TextColor3 = Color3.new(1, 1, 1)
		number.TextStrokeTransparency = 0.6
		number.Text = tostring(i)
		number.ZIndex = 4

		state.slots[i] = { button = slot, thumb = thumb }

		slot.MouseEnter:connect(function()
			local dance = state.visible[i]
			if dance == nil then return end
			highlight.Rotation = (i - 1) * 45
			highlight.Visible = true
			center.Text = dance.title
		end)
		slot.MouseLeave:connect(function()
			highlight.Visible = false
			center.Text = "Select an Emote"
		end)
		slot.MouseButton1Down:connect(function()
			local dance = state.visible[i]
			if dance == nil then return end
			holder.Visible = false
			choose(dance.slug)
		end)
	end

	local function arrow(text, x)
		local button = Instance.new("TextButton", holder)
		button.Style = "Custom"
		button.AutoButtonColor = true
		button.BackgroundColor3 = Color3.new(0.08, 0.1, 0.16)
		button.BackgroundTransparency = 0.6
		button.BorderSizePixel = 0
		button.Size = UDim2.new(0, 30, 0, 26)
		button.Position = UDim2.new(0.5, x, 1, -30)
		button.Font = "SourceSansBold"
		button.FontSize = "Size18"
		button.TextColor3 = Color3.new(1, 1, 1)
		button.Text = text
		button.ZIndex = 3
		return button
	end

	local previous = arrow("<", -110)
	local nextPage = arrow(">", 80)
	local pageLabel = Instance.new("TextLabel", holder)
	pageLabel.BackgroundTransparency = 1
	pageLabel.Size = UDim2.new(0, 160, 0, 26)
	pageLabel.Position = UDim2.new(0.5, -80, 1, -30)
	pageLabel.Font = "SourceSansBold"
	pageLabel.FontSize = "Size18"
	pageLabel.TextColor3 = Color3.new(1, 1, 1)
	pageLabel.TextStrokeTransparency = 0.6
	pageLabel.ZIndex = 3

	local function refresh()
		local list = dances()
		local pages = math.max(1, math.ceil(#list / 8))
		if state.page > pages then state.page = pages end
		if state.page < 1 then state.page = 1 end
		pageLabel.Text = state.page .. " / " .. pages
		state.visible = {}
		for i = 1, 8 do
			local dance = list[(state.page - 1) * 8 + i]
			state.visible[i] = dance
			local slot = state.slots[i]
			slot.thumb.Image = dance and ("rbxassetid://" .. (dance.id + 100000)) or ""
			slot.button.Visible = dance ~= nil
		end
		highlight.Visible = false
		center.Text = "Select an Emote"
	end

	previous.MouseButton1Down:connect(function() state.page = state.page - 1 refresh() end)
	nextPage.MouseButton1Down:connect(function() state.page = state.page + 1 refresh() end)
	refresh()
	scr.Parent = playerGui

	return {
		gui = scr,
		holder = holder,
		state = state,
		refresh = refresh,
		pick = function(i)
			local dance = state.visible[i]
			if dance == nil then return end
			holder.Visible = false
			choose(dance.slug)
		end,
		page = function(step) state.page = state.page + step refresh() end,
	}
end

local function getWheel()
	if wheel == nil or wheel.gui.Parent == nil then
		wheel = buildWheel()
	end
	return wheel
end

local numberKeys = {
	[Enum.KeyCode.One] = 1, [Enum.KeyCode.Two] = 2, [Enum.KeyCode.Three] = 3, [Enum.KeyCode.Four] = 4,
	[Enum.KeyCode.Five] = 5, [Enum.KeyCode.Six] = 6, [Enum.KeyCode.Seven] = 7, [Enum.KeyCode.Eight] = 8,
}

local function typing()
	local focused = nil
	pcall(function() focused = game:GetService("UserInputService"):GetFocusedTextBox() end)
	return focused ~= nil
end

game:GetService("UserInputService").InputBegan:connect(function(input, processed)
	if processed or input.UserInputType ~= Enum.UserInputType.Keyboard or typing() then return end
	local key = input.KeyCode
	if key == Enum.KeyCode.Minus or key == Enum.KeyCode.KeypadMinus then
		local w = getWheel()
		if w == nil then return end
		w.holder.Visible = not w.holder.Visible
		if w.holder.Visible then w.refresh() end
		return
	end
	if wheel == nil or wheel.gui.Parent == nil or not wheel.holder.Visible then return end
	if numberKeys[key] then
		wheel.pick(numberKeys[key])
	elseif key == Enum.KeyCode.Left then
		wheel.page(-1)
	elseif key == Enum.KeyCode.Right then
		wheel.page(1)
	end
end)
