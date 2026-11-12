--@ ModuleScript ReplicatedStorage RmaClient
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Client = {}
Client.Player = Players.LocalPlayer
Client.Config = require(ReplicatedStorage:WaitForChild("RmaConfig"))
Client.Remotes = ReplicatedStorage:WaitForChild("RmaRemotes")
Client.Event = Client.Remotes:WaitForChild("Event")
Client.Request = Client.Remotes:WaitForChild("Request")
Client.State = nil
Client.StateChanged = Instance.new("BindableEvent")
Client.PanelOpened = Instance.new("BindableEvent")
Client.Booths = {}

local PlayerGui = Client.Player:WaitForChild("PlayerGui")
Client.PlayerGui = PlayerGui

Client.Colors = {
	Panel = Color3.fromRGB(20, 20, 20),
	Panel2 = Color3.fromRGB(34, 34, 34),
	Accent = Color3.fromRGB(255, 255, 255),
	Good = Color3.fromRGB(70, 200, 110),
	Bad = Color3.fromRGB(230, 70, 70),
	Info = Color3.fromRGB(90, 160, 255),
	Gold = Color3.fromRGB(255, 205, 70),
	Text = Color3.fromRGB(255, 255, 255),
	Muted = Color3.fromRGB(170, 170, 170),
}

function Client.Set(o, k, v)
	if k == "Position" then
		o.Position = v
	elseif k == "Size" then
		o.Size = v
	elseif k == "AnchorPoint" then
		o.AnchorPoint = v
	elseif k == "TextSize" then
		o.TextSize = v
	elseif k == "Text" then
		o.Text = v
	elseif k == "PlaceholderText" then
		o.PlaceholderText = v
	elseif k == "TextEditable" then
		o.TextEditable = v
	elseif k == "Parent" then
		o.Parent = v
	else
		pcall(function()
			o[k] = v
		end)
	end
end

function Client.Make(class, props, parent)
	local o = Instance.new(class)
	if o:IsA("GuiObject") then
		o.BorderSizePixel = 0
	end
	if o:IsA("TextLabel") or o:IsA("TextButton") or o:IsA("TextBox") then
		o.Font = Enum.Font.SourceSansBold
		o.TextColor3 = Client.Colors.Text
		o.TextScaled = true
		o.BackgroundTransparency = 1
	end
	for k, v in pairs(props or {}) do
		Client.Set(o, k, v)
	end
	if parent then
		o.Parent = parent
	end
	return o
end

function Client.Round(o, radius)
	local c = Instance.new("UICorner")
	c.CornerRadius = UDim.new(0, radius or 8)
	c.Parent = o
	return c
end

function Client.Stroke(o, color, thickness)
	local s = Instance.new("UIStroke")
	s.Color = color or Color3.new(1, 1, 1)
	s.Thickness = thickness or 1.5
	s.Transparency = 0.6
	s.Parent = o
	return s
end

local screen = PlayerGui:FindFirstChild("RmaPanels")
if not screen then
	screen = Instance.new("ScreenGui")
	screen.Name = "RmaPanels"
	screen.ResetOnSpawn = false
	screen.DisplayOrder = 150
	screen.Parent = PlayerGui
end
Client.Screen = screen

Client.Panels = {}
Client.Current = nil

function Client.Panel(name, title, width, height)
	local frame = Client.Make("Frame", {
		Name = name,
		Size = UDim2.new(0, width or 520, 0, height or 400),
		Position = UDim2.new(0.5, -(width or 520) / 2, 0.5, -(height or 400) / 2),
		BackgroundColor3 = Client.Colors.Panel,
		BackgroundTransparency = 0.15,
		Visible = false,
		ZIndex = 2,
	}, screen)
	Client.Round(frame, 12)
	Client.Stroke(frame, Color3.new(1, 1, 1), 2)
	local header = Client.Make("Frame", {
		Name = "Header",
		Size = UDim2.new(1, 0, 0, 44),
		BackgroundColor3 = Color3.new(0, 0, 0),
		ZIndex = 2,
	}, frame)
	Client.Round(header, 12)
	Client.Make("TextLabel", {
		Name = "Title",
		Text = title,
		Size = UDim2.new(1, -70, 0, 30),
		Position = UDim2.new(0, 16, 0, 7),
		TextXAlignment = Enum.TextXAlignment.Left,
		ZIndex = 3,
	}, header)
	local close = Client.Make("TextButton", {
		Name = "Close",
		Text = "X",
		Size = UDim2.new(0, 32, 0, 32),
		Position = UDim2.new(1, -40, 0, 6),
		BackgroundTransparency = 0.2,
		BackgroundColor3 = Client.Colors.Bad,
		ZIndex = 3,
	}, header)
	Client.Round(close, 8)
	local body = Client.Make("Frame", {
		Name = "Body",
		Size = UDim2.new(1, -24, 1, -60),
		Position = UDim2.new(0, 12, 0, 52),
		BackgroundTransparency = 1,
		ZIndex = 2,
	}, frame)
	close.MouseButton1Click:Connect(function()
		Client.Close(name)
	end)
	Client.Panels[name] = { Frame = frame, Body = body, Title = header.Title }
	return Client.Panels[name]
end

Client.Externals = {}

function Client.RegisterExternal(name, show, hide)
	Client.Externals[name] = { Show = show, Hide = hide }
end

function Client.Open(name, ...)
	for other, p in pairs(Client.Panels) do
		if other ~= name then
			p.Frame.Visible = false
		end
	end
	for other, e in pairs(Client.Externals) do
		if other ~= name then
			e.Hide()
		end
	end
	if Client.Panels[name] then
		Client.Panels[name].Frame.Visible = true
	elseif Client.Externals[name] then
		Client.Externals[name].Show()
	end
	Client.Current = name
	Client.PanelOpened:Fire(name, ...)
	Client.Sound("Click")
end

function Client.Close(name)
	if Client.Panels[name] then
		Client.Panels[name].Frame.Visible = false
	elseif Client.Externals[name] then
		Client.Externals[name].Hide()
	end
	if Client.Current == name then
		Client.Current = nil
	end
end

function Client.Toggle(name, ...)
	local open = false
	if Client.Panels[name] then
		open = Client.Panels[name].Frame.Visible
	elseif Client.Current == name then
		open = true
	end
	if open then
		Client.Close(name)
	else
		Client.Open(name, ...)
	end
end

function Client.Sound(name)
	local s = game:GetService("SoundService"):FindFirstChild(name .. "SFX") or game:GetService("SoundService"):FindFirstChild(name)
	if s and s:IsA("Sound") then
		pcall(function()
			s:Play()
		end)
	end
end

local toasts
local function toastHolder()
	if toasts and toasts.Parent then
		return toasts
	end
	local gui = PlayerGui:FindFirstChild("Notifications")
	if not gui then
		gui = Instance.new("ScreenGui")
		gui.Name = "Notifications"
		gui.ResetOnSpawn = false
		gui.Parent = PlayerGui
	end
	toasts = Client.Make("Frame", {
		Name = "RmaToasts",
		Size = UDim2.new(0, 360, 1, -120),
		Position = UDim2.new(1, -372, 0, 60),
		BackgroundTransparency = 1,
	}, gui)
	return toasts
end

local stack = {}
function Client.Notify(text, kind)
	if Client.State and Client.State.settings and Client.State.settings.DisableEmoteVFX and kind == "item" then
		return
	end
	local holder = toastHolder()
	local color = kind == "good" and Client.Colors.Good or kind == "bad" and Client.Colors.Bad or Client.Colors.Info
	local f = Client.Make("Frame", {
		Size = UDim2.new(1, 0, 0, 48),
		BackgroundColor3 = Color3.new(0, 0, 0),
		BackgroundTransparency = 0.25,
	}, holder)
	Client.Round(f, 10)
	Client.Make("Frame", { Size = UDim2.new(0, 6, 1, 0), BackgroundColor3 = color }, f)
	Client.Make("TextLabel", {
		Text = tostring(text),
		Size = UDim2.new(1, -24, 1, -10),
		Position = UDim2.new(0, 16, 0, 5),
		TextXAlignment = Enum.TextXAlignment.Left,
		TextWrapped = true,
	}, f)
	table.insert(stack, 1, f)
	for i, t in ipairs(stack) do
		t.Position = UDim2.new(0, 0, 0, (i - 1) * 54)
	end
	Client.Sound("Click")
	delay(4.5, function()
		for i, t in ipairs(stack) do
			if t == f then
				table.remove(stack, i)
				break
			end
		end
		f:Destroy()
		for i, t in ipairs(stack) do
			t.Position = UDim2.new(0, 0, 0, (i - 1) * 54)
		end
	end)
end

function Client.Headshot(userId)
	local ok, url = pcall(function()
		return Players:GetUserThumbnailAsync(userId, Enum.ThumbnailType.HeadShot, Enum.ThumbnailSize.Size150x150)
	end)
	return ok and url or ""
end

function Client.Ask(action, ...)
	local args = { ... }
	local ok, a, b = pcall(function()
		return Client.Request:InvokeServer(action, unpack(args))
	end)
	if not ok then
		return nil, "Something went wrong."
	end
	return a, b
end

function Client.Fire(action, ...)
	Client.Event:FireServer(action, ...)
end

Client.Event.OnClientEvent:Connect(function(action, a, b)
	if action == "State" then
		Client.State = a
		Client.StateChanged:Fire(a)
	elseif action == "Notify" then
		Client.Notify(a, b)
	elseif action == "Booth" and type(a) == "table" then
		Client.Booths[a.id] = a
	end
end)

spawn(function()
	local s = Client.Ask("State")
	if s and not Client.State then
		Client.State = s
		Client.StateChanged:Fire(s)
	end
end)

return Client
