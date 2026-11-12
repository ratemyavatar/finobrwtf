--@ LocalScript StarterPlayer/StarterPlayerScripts RmaUnoClient
local ReplicatedStorage = game:GetService("ReplicatedStorage")

local Client = require(ReplicatedStorage:WaitForChild("RmaClient"))
local C = Client.Colors

local CARD_COLORS = {
	Red = Color3.fromRGB(220, 50, 50),
	Yellow = Color3.fromRGB(240, 200, 40),
	Green = Color3.fromRGB(60, 170, 70),
	Blue = Color3.fromRGB(40, 110, 220),
	Wild = Color3.fromRGB(30, 30, 30),
}

local function label(card)
	local v = card.v
	if v == "Draw2" then
		return "+2"
	elseif v == "Draw4" then
		return "+4"
	elseif v == "Skip" then
		return "SKIP"
	elseif v == "Reverse" then
		return "REV"
	elseif v == "Wild" then
		return "WILD"
	end
	return tostring(v)
end

local gui = Instance.new("ScreenGui")
gui.Name = "RmaUno"
gui.ResetOnSpawn = false
gui.DisplayOrder = 110
gui.Enabled = false
gui.Parent = Client.PlayerGui

local bar = Client.Make("Frame", {
	Size = UDim2.new(0, 760, 0, 190),
	Position = UDim2.new(0.5, -380, 1, -200),
	BackgroundColor3 = C.Panel,
	BackgroundTransparency = 0.2,
}, gui)
Client.Round(bar, 12)

local status = Client.Make("TextLabel", {
	Size = UDim2.new(1, -250, 0, 26),
	Position = UDim2.new(0, 12, 0, 6),
	TextXAlignment = Enum.TextXAlignment.Left,
}, bar)

local others = Client.Make("TextLabel", {
	Size = UDim2.new(1, -250, 0, 18),
	Position = UDim2.new(0, 12, 0, 32),
	TextXAlignment = Enum.TextXAlignment.Left,
	TextColor3 = C.Muted,
}, bar)

local top = Client.Make("Frame", {
	Size = UDim2.new(0, 46, 0, 66),
	Position = UDim2.new(1, -230, 0, 8),
	BackgroundColor3 = CARD_COLORS.Wild,
}, bar)
Client.Round(top, 6)
local topText = Client.Make("TextLabel", { Size = UDim2.new(1, -6, 1, -6), Position = UDim2.new(0, 3, 0, 3) }, top)

local function button(text, x, color)
	local b = Client.Make("TextButton", {
		Text = text,
		Size = UDim2.new(0, 80, 0, 30),
		Position = UDim2.new(1, x, 0, 8),
		BackgroundTransparency = 0,
		BackgroundColor3 = color,
	}, bar)
	Client.Round(b, 8)
	return b
end
local drawButton = button("Draw", -176, C.Info)
local leaveButton = button("Leave", -90, C.Bad)
local passButton = button("Pass", -176, C.Gold)
passButton.Position = UDim2.new(1, -176, 0, 44)
passButton.TextColor3 = Color3.new(0, 0, 0)

drawButton.MouseButton1Click:Connect(function()
	Client.Fire("UnoDraw")
end)
passButton.MouseButton1Click:Connect(function()
	Client.Fire("UnoDraw")
end)
leaveButton.MouseButton1Click:Connect(function()
	Client.Fire("UnoLeave")
end)

local hand = Client.Make("ScrollingFrame", {
	Size = UDim2.new(1, -24, 0, 100),
	Position = UDim2.new(0, 12, 0, 82),
	BackgroundTransparency = 1,
	ScrollBarThickness = 6,
	CanvasSize = UDim2.new(0, 0, 0, 0),
}, bar)

local picker = Client.Make("Frame", {
	Size = UDim2.new(0, 300, 0, 80),
	Position = UDim2.new(0.5, -150, 0, -90),
	BackgroundColor3 = C.Panel,
	BackgroundTransparency = 0.1,
	Visible = false,
}, bar)
Client.Round(picker, 10)
for i, name in ipairs({ "Red", "Yellow", "Green", "Blue" }) do
	local b = Client.Make("TextButton", {
		Text = "",
		Size = UDim2.new(0, 60, 0, 60),
		Position = UDim2.new(0, 10 + (i - 1) * 72, 0, 10),
		BackgroundTransparency = 0,
		BackgroundColor3 = CARD_COLORS[name],
	}, picker)
	Client.Round(b, 30)
	b.MouseButton1Click:Connect(function()
		picker.Visible = false
		Client.Fire("UnoColor", name)
	end)
end

local function draw(d)
	if d.left then
		gui.Enabled = false
		return
	end
	gui.Enabled = true
	for _, c in ipairs(hand:GetChildren()) do
		if c:IsA("GuiObject") then
			c:Destroy()
		end
	end
	if not d.running then
		status.Text = "Waiting for players... (" .. #(d.players or {}) .. " seated)"
	elseif d.choosing then
		status.Text = "Pick a color"
	elseif d.myTurn then
		status.Text = "Your turn! Current color: " .. tostring(d.color)
	else
		local who = "someone"
		for _, p in ipairs(d.players or {}) do
			if p.turn then
				who = p.name
			end
		end
		status.Text = who .. "'s turn. Color: " .. tostring(d.color)
	end
	local parts = {}
	for _, p in ipairs(d.players or {}) do
		table.insert(parts, p.name .. " (" .. p.cards .. ")")
	end
	others.Text = table.concat(parts, "   ") .. "   " .. (d.clockwise and "clockwise" or "counter-clockwise")
	if d.top then
		top.Visible = true
		top.BackgroundColor3 = CARD_COLORS[d.top.c == "Wild" and (d.color or "Wild") or d.top.c] or CARD_COLORS.Wild
		topText.Text = label(d.top)
	else
		top.Visible = false
	end
	picker.Visible = d.choosing and true or false
	drawButton.Visible = d.myTurn and not d.canPass and not d.choosing
	passButton.Visible = d.myTurn and d.canPass and not d.choosing
	for i, card in ipairs(d.hand or {}) do
		local b = Client.Make("TextButton", {
			Text = label(card),
			Size = UDim2.new(0, 62, 0, 88),
			Position = UDim2.new(0, (i - 1) * 68, 0, 0),
			BackgroundTransparency = 0,
			BackgroundColor3 = CARD_COLORS[card.c] or CARD_COLORS.Wild,
			TextStrokeTransparency = 0.3,
		}, hand)
		Client.Round(b, 6)
		b.MouseButton1Click:Connect(function()
			if d.myTurn then
				Client.Fire("UnoPlay", i)
			end
		end)
	end
	hand.CanvasSize = UDim2.new(0, #(d.hand or {}) * 68, 0, 0)
end

Client.Event.OnClientEvent:Connect(function(action, d)
	if action == "Uno" and type(d) == "table" then
		draw(d)
	end
end)
