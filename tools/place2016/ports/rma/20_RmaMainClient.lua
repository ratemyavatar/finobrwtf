--@ LocalScript StarterPlayer/StarterPlayerScripts RmaMainClient
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local Lighting = game:GetService("Lighting")
local RunService = game:GetService("RunService")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local Config = Client.Config
local player = Client.Player
local PlayerGui = Client.PlayerGui

local Main = PlayerGui:WaitForChild("Main")
local Buttons = Main:WaitForChild("Buttons")
local CreditsFrame = Main:FindFirstChild("Credits")

local function hook(name, panel)
	local b = Buttons:FindFirstChild(name)
	if b then
		b.MouseButton1Click:Connect(function()
			Client.Toggle(panel)
		end)
	end
	return b
end

hook("Shop", "Store")
hook("Booth", "Booth")
hook("Rating", "Rating")
hook("Profile", "Profile")
hook("Titles", "Titles")

local function extraButton(name, text, panel, order)
	local template = Buttons:FindFirstChild("Titles") or Buttons:FindFirstChild("Profile")
	if not template or Buttons:FindFirstChild(name) then
		return
	end
	local b = template:Clone()
	b.Name = name
	b.LayoutOrder = order or 50
	local l = b:FindFirstChild("Label")
	if l then
		l.Text = text
	end
	local icon = b:FindFirstChild("Icon")
	if icon then
		icon.Visible = false
		local t = Client.Make("TextLabel", {
			Text = text == "Settings" and "S" or "!",
			Size = icon.Size,
			Position = icon.Position,
			AnchorPoint = icon.AnchorPoint,
			TextColor3 = Color3.new(0, 0, 0),
			ZIndex = icon.ZIndex,
		}, b)
		t.Name = "Glyph"
	end
	b.Parent = Buttons
	b.MouseButton1Click:Connect(function()
		Client.Toggle(panel)
	end)
end
extraButton("Settings", "Settings", "Settings", 60)
extraButton("ModCall", "Mod Call", "ModCall", 61)

local nextPayoutAt = tick() + Config.PayoutSeconds
local function refreshCredits(s)
	if not CreditsFrame then
		return
	end
	local label = CreditsFrame:FindFirstChild("Credits")
	if label then
		label.Text = "$" .. Config.Comma(s.credits)
	end
	nextPayoutAt = tick() + (s.nextPayout or Config.PayoutSeconds)
end
Client.StateChanged.Event:Connect(refreshCredits)
if Client.State then
	refreshCredits(Client.State)
end

spawn(function()
	while true do
		wait(1)
		local countdown = CreditsFrame and CreditsFrame:FindFirstChild("Countdown")
		if countdown then
			countdown.Text = "(" .. Config.Clock(nextPayoutAt - tick()) .. ")"
		end
	end
end)

Client.Event.OnClientEvent:Connect(function(action, a, b)
	if action == "Payout" then
		Client.Notify("+" .. Config.Comma(a) .. " credits", "good")
		Client.Sound("ShopChime")
	elseif action == "Rated" then
		Client.Notify(a .. " rated your avatar " .. b .. " star" .. (b == 1 and "" or "s") .. "!", "good")
	elseif action == "Announce" then
		Client.Notify(a, "good")
	elseif action == "ModCall" then
		Client.Notify("MOD CALL from " .. a .. ": " .. b, "bad")
	elseif action == "OpenBooth" then
		Client.Open("Booth")
	elseif action == "OpenProfile" then
		Client.Open("Profile", a)
	end
end)

local Rules = PlayerGui:WaitForChild("Rules", 10)
local rulesShown = false
local function showRules(s)
	if rulesShown or not Rules then
		return
	end
	rulesShown = true
	if s.rulesAgreed and s.settings and s.settings.AutoAgreeToRules then
		return
	end
	Rules.Enabled = true
end
if Rules then
	local agree = Rules:FindFirstChild("Agree")
	local disagree = Rules:FindFirstChild("Disagree")
	if agree then
		agree.MouseButton1Click:Connect(function()
			Rules.Enabled = false
			Client.Fire("AgreeRules")
		end)
	end
	if disagree then
		disagree.MouseButton1Click:Connect(function()
			pcall(function()
				player:Kick("You need to agree to the rules to play.")
			end)
		end)
	end
end

local Settings = PlayerGui:WaitForChild("Settings", 10)
local settingsHolder = Settings and Settings:FindFirstChild("Holder")
local scroll = settingsHolder and settingsHolder:FindFirstChild("SettingsScroll")
local toggles = {}

local rainModel
local dayLoop = false
local function applySetting(key, on)
	if key == "LowGraphics" then
		for _, d in ipairs(workspace:GetDescendants()) do
			if d:IsA("ParticleEmitter") or d:IsA("Fire") or d:IsA("Smoke") or d:IsA("Sparkles") then
				d.Enabled = not on
			end
		end
		pcall(function()
			Lighting.GlobalShadows = not on
		end)
	elseif key == "Day" then
		dayLoop = on
	elseif key == "Rain" then
		if on and not rainModel then
			local src = player:WaitForChild("PlayerScripts"):FindFirstChild("Rain")
			local m = src and src:FindFirstChild("Rain")
			if m then
				rainModel = m:Clone()
				rainModel.Parent = workspace.CurrentCamera
			end
		elseif not on and rainModel then
			rainModel:Destroy()
			rainModel = nil
		end
	elseif key == "RainSounds" then
		if rainModel then
			for _, d in ipairs(rainModel:GetDescendants()) do
				if d:IsA("Sound") then
					if on then
						d.Looped = true
						d:Play()
					else
						d:Stop()
					end
				end
			end
		end
	end
end

local function paint(button, on)
	local toggle = button:FindFirstChild("Toggle")
	local slider = toggle and toggle:FindFirstChild("Slider")
	if toggle then
		toggle.BackgroundColor3 = on and Client.Colors.Good or Color3.fromRGB(80, 80, 80)
	end
	if slider then
		slider.Position = on and UDim2.new(1, -23, 0.5, -10) or UDim2.new(0, 3, 0.5, -10)
		slider.AnchorPoint = Vector2.new(0, 0)
	end
end

if scroll then
	for _, b in ipairs(scroll:GetChildren()) do
		if b:IsA("TextButton") then
			toggles[b.Name] = b
			b.MouseButton1Click:Connect(function()
				local s = Client.State
				if not s then
					return
				end
				s.settings = s.settings or {}
				local on = not s.settings[b.Name]
				s.settings[b.Name] = on
				paint(b, on)
				applySetting(b.Name, on)
				Client.Fire("Setting", b.Name, on)
			end)
		end
	end
end

if Settings then
	Client.RegisterExternal("Settings", function()
		Settings.Enabled = true
	end, function()
		Settings.Enabled = false
	end)
	local close = settingsHolder and settingsHolder:FindFirstChild("CloseButton", true)
	if close then
		close.MouseButton1Click:Connect(function()
			Client.Close("Settings")
		end)
	end
end

local firstState = true
Client.StateChanged.Event:Connect(function(s)
	for name, b in pairs(toggles) do
		paint(b, s.settings and s.settings[name])
	end
	if firstState then
		firstState = false
		showRules(s)
		for name, on in pairs(s.settings or {}) do
			if on then
				applySetting(name, true)
			end
		end
	end
end)

spawn(function()
	while true do
		wait(0.25)
		if dayLoop then
			pcall(function()
				Lighting:SetMinutesAfterMidnight((Lighting:GetMinutesAfterMidnight() + 1) % 1440)
			end)
		end
	end
end)

local ModCallUI = PlayerGui:WaitForChild("ModCallUI", 10)
if ModCallUI then
	local frame = ModCallUI:FindFirstChild("MainFrame")
	local box = frame and frame:FindFirstChild("reportBox")
	local send = frame and frame:FindFirstChild("reportButton")
	local close = frame and frame:FindFirstChild("CloseButton")
	Client.RegisterExternal("ModCall", function()
		ModCallUI.Enabled = true
	end, function()
		ModCallUI.Enabled = false
	end)
	if close then
		close.MouseButton1Click:Connect(function()
			Client.Close("ModCall")
		end)
	end
	if send and box then
		send.MouseButton1Click:Connect(function()
			if #box.Text < 10 then
				Client.Notify("Please give a reason of at least 10 characters.", "bad")
				return
			end
			Client.Fire("ModCall", box.Text)
			box.Text = ""
			Client.Close("ModCall")
		end)
	end
end

local Region = PlayerGui:WaitForChild("ServerRegion", 10)
local regionLabel = Region and Region:FindFirstChild("Region")
local started = tick()
pcall(function()
	started = tick() - workspace.DistributedGameTime
end)
spawn(function()
	while regionLabel do
		local up = tick() - started
		local h = math.floor(up / 3600)
		local m = math.floor(up / 60) % 60
		regionLabel.Text = "Server: " .. string.sub(tostring(game.JobId), 1, 8) .. " | Players: " .. #Players:GetPlayers() .. " | Up " .. h .. "h " .. m .. "m"
		wait(15)
	end
end)

local Donations = PlayerGui:WaitForChild("Donations", 10)
if Donations then
	local board = workspace:FindFirstChild("Boards")
	local part = board and (board:FindFirstChild("Buttons") or board:FindFirstChild("Screen"))
	if part and part:IsA("BasePart") then
		Donations.Adornee = part
		Donations.Enabled = true
	end
	for _, b in ipairs(Donations:GetChildren()) do
		if b:IsA("TextButton") then
			b.MouseButton1Click:Connect(function()
				Client.Fire("Donate", b.Name)
			end)
		end
	end
end

local Tip = PlayerGui:FindFirstChild("ToolTip")
local tipLabel = Tip and Tip:FindFirstChild("Label")
if tipLabel then
	local mouse = player:GetMouse()
	for _, b in ipairs(Buttons:GetChildren()) do
		if b:IsA("GuiButton") then
			b.MouseEnter:Connect(function()
				local l = b:FindFirstChild("Label")
				tipLabel.Text = l and l.Text or b.Name
				tipLabel.Visible = true
			end)
			b.MouseLeave:Connect(function()
				tipLabel.Visible = false
			end)
		end
	end
	RunService.RenderStepped:Connect(function()
		if tipLabel.Visible then
			tipLabel.Position = UDim2.new(0, mouse.X + 16, 0, mouse.Y + 8)
		end
	end)
end

local mouse = player:GetMouse()
mouse.Button2Down:Connect(function()
	local t = mouse.Target
	if t and t.Name == "Tile" and t:FindFirstChild("MinesweeperClickDetector") then
		Client.Fire("MineFlag", t)
	end
end)
