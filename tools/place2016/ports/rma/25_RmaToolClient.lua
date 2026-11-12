--@ LocalScript StarterPlayer/StarterPlayerScripts RmaToolClient
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local player = Client.Player
local C = Client.Colors

local gui = Instance.new("ScreenGui")
gui.Name = "RmaToolInput"
gui.ResetOnSpawn = false
gui.DisplayOrder = 130
gui.Parent = Client.PlayerGui

local box = Client.Make("Frame", {
	Size = UDim2.new(0, 360, 0, 92),
	Position = UDim2.new(0.5, -180, 1, -310),
	BackgroundColor3 = C.Panel,
	BackgroundTransparency = 0.15,
	Visible = false,
}, gui)
Client.Round(box, 10)
local title = Client.Make("TextLabel", {
	Size = UDim2.new(1, -20, 0, 24),
	Position = UDim2.new(0, 10, 0, 6),
	TextXAlignment = Enum.TextXAlignment.Left,
}, box)
local input = Client.Make("TextBox", {
	Size = UDim2.new(1, -110, 0, 40),
	Position = UDim2.new(0, 10, 0, 40),
	BackgroundTransparency = 0.2,
	BackgroundColor3 = C.Panel2,
	ClearTextOnFocus = false,
	Text = "",
}, box)
Client.Round(input, 8)
local go = Client.Make("TextButton", {
	Size = UDim2.new(0, 80, 0, 40),
	Position = UDim2.new(1, -90, 0, 40),
	BackgroundTransparency = 0,
	BackgroundColor3 = C.Good,
	Text = "OK",
}, box)
Client.Round(go, 8)

local mode
go.MouseButton1Click:Connect(function()
	if mode == "sign" then
		Client.Fire("SignText", input.Text)
	elseif mode == "boombox" then
		Client.Fire("BoomBox", input.Text:match("%d+"))
	elseif mode == "petition" then
		petitionInfo = input.Text
		Client.Notify("Petition text saved. Click a player to ask them.", "info")
	end
end)

local petitionInfo = "Sign my petition!"

local function onEquip(tool)
	if tool.Name == "TextSign" then
		mode = "sign"
		title.Text = "Sign text"
		input.PlaceholderText = "What should your sign say?"
		box.Visible = true
	elseif tool.Name == "BoomBox" then
		mode = "boombox"
		title.Text = "BoomBox (audio id, empty to stop)"
		input.PlaceholderText = "Audio ID"
		box.Visible = true
	elseif tool.Name == "Petition" then
		mode = "petition"
		title.Text = "Petition: click a player to ask them"
		input.PlaceholderText = "What is your petition about?"
		input.Text = petitionInfo
		box.Visible = true
	end
end

local function onUnequip(tool)
	if tool.Name == "Petition" then
		petitionInfo = input.Text
	end
	mode = nil
	box.Visible = false
	input.Text = ""
end

local mouse = player:GetMouse()
local function hookTool(tool)
	tool.Equipped:Connect(function()
		onEquip(tool)
	end)
	tool.Unequipped:Connect(function()
		onUnequip(tool)
	end)
	if tool.Name == "Petition" then
		tool.Activated:Connect(function()
			local t = mouse.Target
			local model = t and t.Parent
			if model and model:IsA("Accessory") or model and model:IsA("Hat") then
				model = model.Parent
			end
			local target = model and Players:GetPlayerFromCharacter(model)
			if target then
				Client.Fire("PetitionAsk", target, input.Text ~= "" and input.Text or petitionInfo)
			else
				Client.Notify("Click on a player to ask them.", "info")
			end
		end)
	end
end

local function watch(container)
	for _, c in ipairs(container:GetChildren()) do
		if c:IsA("Tool") then
			hookTool(c)
		end
	end
	container.ChildAdded:Connect(function(c)
		if c:IsA("Tool") then
			hookTool(c)
		end
	end)
end
watch(player:WaitForChild("Backpack"))
player.CharacterAdded:Connect(function()
	box.Visible = false
	watch(player:WaitForChild("Backpack"))
end)

local ask = Client.Make("Frame", {
	Size = UDim2.new(0, 380, 0, 150),
	Position = UDim2.new(0.5, -190, 0.5, -75),
	BackgroundColor3 = C.Panel,
	BackgroundTransparency = 0.1,
	Visible = false,
	ZIndex = 5,
}, gui)
Client.Round(ask, 12)
local askText = Client.Make("TextLabel", {
	Size = UDim2.new(1, -20, 0, 80),
	Position = UDim2.new(0, 10, 0, 8),
	TextWrapped = true,
	ZIndex = 6,
}, ask)
local yes = Client.Make("TextButton", {
	Text = "Sign",
	Size = UDim2.new(0.5, -15, 0, 44),
	Position = UDim2.new(0, 10, 1, -54),
	BackgroundTransparency = 0,
	BackgroundColor3 = C.Good,
	ZIndex = 6,
}, ask)
local no = Client.Make("TextButton", {
	Text = "No",
	Size = UDim2.new(0.5, -15, 0, 44),
	Position = UDim2.new(0.5, 5, 1, -54),
	BackgroundTransparency = 0,
	BackgroundColor3 = C.Bad,
	ZIndex = 6,
}, ask)
Client.Round(yes, 8)
Client.Round(no, 8)

local asker
local function answer(v)
	ask.Visible = false
	if asker then
		Client.Fire("PetitionAnswer", asker, v)
	end
	asker = nil
end
yes.MouseButton1Click:Connect(function()
	answer(true)
end)
no.MouseButton1Click:Connect(function()
	answer(false)
end)

Client.Event.OnClientEvent:Connect(function(action, a, b)
	if action == "PetitionAsk" and typeof(a) == "Instance" then
		asker = a
		askText.Text = a.Name .. " wants you to sign their petition:\n\"" .. tostring(b) .. "\""
		ask.Visible = true
		delay(15, function()
			if asker == a then
				answer(false)
			end
		end)
	end
end)
