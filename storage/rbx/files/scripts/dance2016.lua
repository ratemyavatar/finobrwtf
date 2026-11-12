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
		local slug, title = string.match(line, "^%d+\t([^\t]+)\t(.+)$")
		if slug ~= nil then table.insert(list, { slug = slug, title = title }) end
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

	local drag = Instance.new("TextButton", scr)
	drag.Draggable = true
	drag.BackgroundTransparency = 1
	drag.Size = UDim2.new(0, 255, 0, 30)
	drag.Name = "Drag"
	drag.Position = UDim2.new(0, -400, 0.5, -160)
	drag.AutoButtonColor = false
	drag.Text = ""
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

	local top = Instance.new("TextLabel", drag)
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

	smallButton("X", -25).MouseButton1Click:connect(function() scr:Destroy() end)
	local hide = smallButton("-", -50)
	hide.MouseButton1Click:connect(function()
		main.Visible = not main.Visible
		hide.Text = main.Visible and "-" or "+"
	end)
	local stop = smallButton("S", -75)
	stop.MouseButton1Click:connect(function() remote:FireServer("stop") end)

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
