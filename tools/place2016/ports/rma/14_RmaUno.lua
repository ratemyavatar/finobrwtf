--@ Script ServerScriptService RmaUno
local Players = game:GetService("Players")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local Data = require(script.Parent:WaitForChild("RmaData"))
local Event = ReplicatedStorage:WaitForChild("RmaRemotes"):WaitForChild("Event")

local COLORS = { "Red", "Yellow", "Green", "Blue" }
local COLOR3 = {
	Red = Color3.fromRGB(220, 50, 50),
	Yellow = Color3.fromRGB(240, 200, 30),
	Green = Color3.fromRGB(60, 170, 70),
	Blue = Color3.fromRGB(40, 110, 220),
	Wild = Color3.fromRGB(30, 30, 30),
}
local BACK = "rbxassetid://10068670575"
local WIN_CREDITS = 200
local TURN_SECONDS = 30

local function label(card)
	if card.v == "Skip" then
		return "Skip"
	elseif card.v == "Reverse" then
		return "Rev"
	elseif card.v == "Draw2" then
		return "+2"
	elseif card.v == "Wild" then
		return "Wild"
	elseif card.v == "Draw4" then
		return "+4"
	end
	return tostring(card.v)
end

local function newDeck()
	local deck = {}
	for _, c in ipairs(COLORS) do
		table.insert(deck, { c = c, v = 0 })
		for n = 1, 9 do
			table.insert(deck, { c = c, v = n })
			table.insert(deck, { c = c, v = n })
		end
		for _, v in ipairs({ "Skip", "Reverse", "Draw2" }) do
			table.insert(deck, { c = c, v = v })
			table.insert(deck, { c = c, v = v })
		end
	end
	for _ = 1, 4 do
		table.insert(deck, { c = "Wild", v = "Wild" })
		table.insert(deck, { c = "Wild", v = "Draw4" })
	end
	for i = #deck, 2, -1 do
		local j = math.random(1, i)
		deck[i], deck[j] = deck[j], deck[i]
	end
	return deck
end

local function cardFace(part, card, faceUp)
	for _, c in ipairs(part:GetChildren()) do
		if c:IsA("SurfaceGui") or c:IsA("Decal") then
			c:Destroy()
		end
	end
	if not faceUp then
		local d = Instance.new("Decal")
		d.Texture = BACK
		d.Face = Enum.NormalId.Top
		d.Parent = part
		return
	end
	local g = Instance.new("SurfaceGui")
	g.Face = Enum.NormalId.Top
	g.CanvasSize = Vector2.new(120, 180)
	local bg = Instance.new("Frame")
	bg.Size = UDim2.new(1, 0, 1, 0)
	bg.BackgroundColor3 = Color3.new(1, 1, 1)
	bg.BorderSizePixel = 0
	bg.Parent = g
	local inner = Instance.new("Frame")
	inner.Size = UDim2.new(1, -16, 1, -16)
	inner.Position = UDim2.new(0, 8, 0, 8)
	inner.BackgroundColor3 = COLOR3[card.c] or COLOR3.Wild
	inner.BorderSizePixel = 0
	inner.Parent = bg
	local t = Instance.new("TextLabel")
	t.BackgroundTransparency = 1
	t.Size = UDim2.new(1, 0, 1, 0)
	t.Font = Enum.Font.SourceSansBold
	t.TextScaled = true
	t.TextColor3 = Color3.new(1, 1, 1)
	t.TextStrokeTransparency = 0
	t.Text = label(card)
	t.Parent = inner
	g.Parent = part
end

local function makeCard(parent, cf)
	local p = Instance.new("Part")
	p.Name = "RmaCard"
	p.Anchored = true
	p.CanCollide = false
	p.FormFactor = Enum.FormFactor.Custom
	p.Size = Vector3.new(1.2, 0.05, 1.8)
	p.Color = Color3.new(1, 1, 1)
	p.TopSurface = Enum.SurfaceType.Smooth
	p.BottomSurface = Enum.SurfaceType.Smooth
	p.CFrame = cf
	p.Parent = parent
	return p
end

local Tables = {}

local function collectTables()
	local byCenter = {}
	for _, m in ipairs(workspace:GetChildren()) do
		if m.Name == "UNO_Content" then
			local t = m:FindFirstChild("Table")
			local center = t and t:FindFirstChild("Center")
			if center then
				local key = math.floor(center.Position.X + 0.5) .. "," .. math.floor(center.Position.Z + 0.5)
				byCenter[key] = byCenter[key] or {}
				table.insert(byCenter[key], t)
			end
		end
	end
	for _, list in pairs(byCenter) do
		local main, spotsByName = nil, {}
		for _, t in ipairs(list) do
			if t:FindFirstChild("PileCards") then
				main = main or t
			end
		end
		main = main or list[1]
		for _, t in ipairs(list) do
			local spots = t:FindFirstChild("Spots")
			if spots then
				for _, s in ipairs(spots:GetChildren()) do
					if not spotsByName[s.Name] or t ~= main then
						spotsByName[s.Name] = s
					end
				end
			end
		end
		local chosen = {}
		for _, s in pairs(spotsByName) do
			chosen[s] = true
		end
		for _, t in ipairs(list) do
			local spots = t:FindFirstChild("Spots")
			if spots then
				for _, s in ipairs(spots:GetChildren()) do
					if not chosen[s] then
						s:Destroy()
					end
				end
			end
		end
		for _, t in ipairs(list) do
			if t ~= main then
				for _, c in ipairs(t:GetChildren()) do
					if c.Name ~= "Spots" and c.Name ~= "Table" and c.Name ~= "Base" and c.Name ~= "standprevent" then
						c:Destroy()
					end
				end
			end
		end
		local seats = {}
		for name, s in pairs(spotsByName) do
			for _, d in ipairs(s:GetDescendants()) do
				if d.Name == "Card" and d:IsA("BasePart") then
					d:Destroy()
				end
			end
			local seat
			for _, d in ipairs(s:GetDescendants()) do
				if d:IsA("Seat") then
					seat = d
				end
			end
			local ref = s:FindFirstChild("Reference")
			local promptPart = s:FindFirstChild("PromptPart")
			local prompt = promptPart and promptPart:FindFirstChildOfClass("ProximityPrompt")
			table.insert(seats, { Name = name, Model = s, Seat = seat, Ref = ref, Prompt = prompt, Player = nil, Hand = {}, Cards = {} })
		end
		table.sort(seats, function(a, b)
			return a.Name < b.Name
		end)
		local thrown = main:FindFirstChild("ThrownCards")
		if thrown then
			thrown:ClearAllChildren()
		else
			thrown = Instance.new("Folder")
			thrown.Name = "ThrownCards"
			thrown.Parent = main
		end
		table.insert(Tables, {
			Id = #Tables + 1,
			Model = main,
			Center = main:FindFirstChild("Center"),
			Arrow = main:FindFirstChild("Arrow"),
			Pile = main:FindFirstChild("PileCards"),
			Thrown = thrown,
			Direction = main:FindFirstChild("Direction"),
			Seats = seats,
			Running = false,
		})
	end
end
collectTables()

local function seatOf(tbl, player)
	for _, s in ipairs(tbl.Seats) do
		if s.Player == player then
			return s
		end
	end
	return nil
end

local function tableOf(player)
	for _, tbl in ipairs(Tables) do
		if seatOf(tbl, player) then
			return tbl
		end
	end
	return nil
end

local function players(tbl)
	local out = {}
	for _, s in ipairs(tbl.Seats) do
		if s.Player then
			table.insert(out, s)
		end
	end
	return out
end

local function drawFrom(tbl)
	if #tbl.Deck == 0 then
		local top = table.remove(tbl.Discard)
		tbl.Deck = tbl.Discard
		for i = #tbl.Deck, 2, -1 do
			local j = math.random(1, i)
			tbl.Deck[i], tbl.Deck[j] = tbl.Deck[j], tbl.Deck[i]
		end
		tbl.Discard = { top }
		if #tbl.Deck == 0 then
			tbl.Deck = newDeck()
		end
	end
	return table.remove(tbl.Deck)
end

local function renderHand(tbl, seat)
	for _, p in ipairs(seat.Cards) do
		p:Destroy()
	end
	seat.Cards = {}
	if not seat.Ref or not tbl.Center then
		return
	end
	local n = #seat.Hand
	local toCenter = (tbl.Center.Position - seat.Ref.Position) * Vector3.new(1, 0, 1)
	if toCenter.Magnitude < 0.01 then
		return
	end
	local forward = toCenter.Unit
	local right = forward:Cross(Vector3.new(0, 1, 0))
	local shown = math.min(n, 12)
	for i = 1, shown do
		local offset = (i - (shown + 1) / 2) * 0.55
		local pos = seat.Ref.Position + right * offset + Vector3.new(0, 1.6, 0) + forward * 0.6
		local cf = CFrame.new(pos, pos - forward) * CFrame.Angles(math.rad(-70), 0, 0)
		local part = makeCard(tbl.Model, cf)
		cardFace(part, nil, false)
		table.insert(seat.Cards, part)
	end
end

local function renderTop(tbl)
	tbl.Thrown:ClearAllChildren()
	local top = tbl.Discard and tbl.Discard[#tbl.Discard]
	if not top or not tbl.Center then
		return
	end
	local part = makeCard(tbl.Thrown, CFrame.new(tbl.Center.Position + Vector3.new(0, 0.6, 0)) * CFrame.Angles(0, math.rad(math.random(-20, 20)), 0))
	cardFace(part, { c = tbl.Color or top.c, v = top.v }, true)
end

local arrowParts = {}
local function pointArrow(tbl)
	local seat = tbl.Turn and players(tbl)[tbl.Turn]
	if not tbl.Arrow or not tbl.Center then
		return
	end
	if not arrowParts[tbl] then
		arrowParts[tbl] = {}
		for _, p in ipairs(tbl.Arrow:GetDescendants()) do
			if p:IsA("BasePart") then
				arrowParts[tbl][p] = CFrame.new(tbl.Center.Position):toObjectSpace(p.CFrame)
			end
		end
	end
	local angle = 0
	if seat and seat.Ref then
		local dir = (seat.Ref.Position - tbl.Center.Position) * Vector3.new(1, 0, 1)
		if dir.Magnitude > 0.01 then
			angle = math.atan2(-dir.X, -dir.Z)
		end
	end
	local base = CFrame.new(tbl.Center.Position) * CFrame.Angles(0, angle, 0)
	for p, rel in pairs(arrowParts[tbl]) do
		p.CFrame = base * rel
	end
end

local function sync(tbl)
	local list = players(tbl)
	local counts = {}
	for i, s in ipairs(list) do
		counts[i] = { name = s.Player.Name, cards = #s.Hand, turn = tbl.Turn == i }
	end
	local top = tbl.Discard and tbl.Discard[#tbl.Discard]
	for i, s in ipairs(list) do
		Event:FireClient(s.Player, "Uno", {
			table = tbl.Id,
			running = tbl.Running,
			hand = s.Hand,
			top = top,
			color = tbl.Color,
			myTurn = tbl.Running and tbl.Turn == i,
			choosing = tbl.Choosing == s.Player,
			players = counts,
			canPass = tbl.DrewThisTurn == s.Player,
			clockwise = tbl.Dir == 1,
		})
		renderHand(tbl, s)
	end
	renderTop(tbl)
	pointArrow(tbl)
	if tbl.Direction then
		local cw = tbl.Direction:FindFirstChild("Clockwise")
		if cw then
			cw.Value = tbl.Dir == 1
		end
	end
end

local function say(tbl, text, kind)
	for _, s in ipairs(players(tbl)) do
		Event:FireClient(s.Player, "Notify", text, kind or "info")
	end
end

local function advance(tbl, steps)
	local n = #players(tbl)
	if n == 0 then
		return
	end
	tbl.Turn = ((tbl.Turn - 1 + tbl.Dir * (steps or 1)) % n) + 1
	tbl.DrewThisTurn = nil
	tbl.TurnStarted = tick()
end

local function stop(tbl, winner)
	tbl.Running = false
	tbl.Choosing = nil
	if winner then
		local d = Data.Get(winner)
		if d then
			d.uno = d.uno + 1
			Data.AddCredits(winner, WIN_CREDITS)
			Event:FireClient(winner, "Payout", WIN_CREDITS)
		end
		say(tbl, winner.Name .. " won UNO!", "good")
		local emitter = tbl.Model:FindFirstChild("Emitter")
		local pe = emitter and emitter:FindFirstChildOfClass("ParticleEmitter")
		if pe then
			pe.Enabled = true
			delay(2, function()
				pe.Enabled = false
			end)
		end
	end
	for _, s in ipairs(tbl.Seats) do
		s.Hand = {}
	end
	tbl.Discard = {}
	sync(tbl)
end

local function start(tbl)
	local list = players(tbl)
	if tbl.Running or #list < 2 then
		return
	end
	tbl.Running = true
	tbl.Deck = newDeck()
	tbl.Discard = {}
	tbl.Dir = 1
	tbl.Turn = math.random(1, #list)
	for _, s in ipairs(list) do
		s.Hand = {}
		for _ = 1, 7 do
			table.insert(s.Hand, drawFrom(tbl))
		end
	end
	local first = drawFrom(tbl)
	while first.c == "Wild" do
		table.insert(tbl.Deck, 1, first)
		first = drawFrom(tbl)
	end
	table.insert(tbl.Discard, first)
	tbl.Color = first.c
	tbl.TurnStarted = tick()
	say(tbl, "UNO started with " .. #list .. " players!", "good")
	sync(tbl)
end

local function canPlay(tbl, card)
	local top = tbl.Discard[#tbl.Discard]
	if card.c == "Wild" then
		return true
	end
	return card.c == tbl.Color or card.v == top.v
end

local function play(player, index, color)
	local tbl = tableOf(player)
	if not tbl or not tbl.Running or tbl.Choosing then
		return
	end
	local list = players(tbl)
	local seat = list[tbl.Turn]
	if not seat or seat.Player ~= player then
		return
	end
	local card = seat.Hand[tonumber(index) or 0]
	if not card or not canPlay(tbl, card) then
		return
	end
	table.remove(seat.Hand, tonumber(index))
	table.insert(tbl.Discard, card)
	tbl.Color = card.c
	local skip = 1
	if card.v == "Skip" then
		skip = 2
	elseif card.v == "Reverse" then
		tbl.Dir = -tbl.Dir
		if #list == 2 then
			skip = 2
		end
	elseif card.v == "Draw2" then
		local nextIdx = ((tbl.Turn - 1 + tbl.Dir) % #list) + 1
		for _ = 1, 2 do
			table.insert(list[nextIdx].Hand, drawFrom(tbl))
		end
		skip = 2
	elseif card.v == "Draw4" then
		local nextIdx = ((tbl.Turn - 1 + tbl.Dir) % #list) + 1
		for _ = 1, 4 do
			table.insert(list[nextIdx].Hand, drawFrom(tbl))
		end
		skip = 2
	end
	if #seat.Hand == 0 then
		stop(tbl, player)
		return
	end
	if #seat.Hand == 1 then
		say(tbl, player.Name .. " has UNO!", "info")
	end
	if card.c == "Wild" then
		local chosen
		for _, c in ipairs(COLORS) do
			if c == color then
				chosen = c
			end
		end
		if chosen then
			tbl.Color = chosen
		else
			tbl.Choosing = player
			tbl.PendingSkip = skip
			sync(tbl)
			return
		end
	end
	advance(tbl, skip)
	sync(tbl)
end

local function chooseColor(player, color)
	local tbl = tableOf(player)
	if not tbl or tbl.Choosing ~= player then
		return
	end
	for _, c in ipairs(COLORS) do
		if c == color then
			tbl.Color = c
			tbl.Choosing = nil
			advance(tbl, tbl.PendingSkip or 1)
			sync(tbl)
			return
		end
	end
end

local function draw(player)
	local tbl = tableOf(player)
	if not tbl or not tbl.Running or tbl.Choosing then
		return
	end
	local list = players(tbl)
	local seat = list[tbl.Turn]
	if not seat or seat.Player ~= player then
		return
	end
	if tbl.DrewThisTurn == player then
		advance(tbl, 1)
	else
		table.insert(seat.Hand, drawFrom(tbl))
		tbl.DrewThisTurn = player
	end
	sync(tbl)
end

local function leave(player)
	for _, tbl in ipairs(Tables) do
		local seat = seatOf(tbl, player)
		if seat then
			local list = players(tbl)
			local wasTurn = tbl.Running and list[tbl.Turn] == seat
			for i, s in ipairs(list) do
				if s == seat and tbl.Running and i < tbl.Turn then
					tbl.Turn = tbl.Turn - 1
				end
			end
			for _, card in ipairs(seat.Hand) do
				if tbl.Deck then
					table.insert(tbl.Deck, 1, card)
				end
			end
			seat.Hand = {}
			seat.Player = nil
			renderHand(tbl, seat)
			if seat.Prompt then
				seat.Prompt.Enabled = true
			end
			if player.Parent then
				Event:FireClient(player, "Uno", { table = tbl.Id, left = true })
				local char = player.Character
				local h = char and char:FindFirstChild("Humanoid")
				if h then
					h.Sit = false
				end
			end
			if tbl.Running then
				local remaining = players(tbl)
				if #remaining < 2 then
					stop(tbl, remaining[1] and remaining[1].Player or nil)
				else
					if tbl.Turn > #remaining then
						tbl.Turn = 1
					end
					if wasTurn then
						tbl.DrewThisTurn = nil
						tbl.TurnStarted = tick()
					end
					sync(tbl)
				end
			else
				sync(tbl)
			end
		end
	end
end

local function join(player, tbl, seat)
	if seat.Player or tableOf(player) then
		return
	end
	if tbl.Running then
		Event:FireClient(player, "Notify", "A game is already running at this table.", "bad")
		return
	end
	seat.Player = player
	if seat.Prompt then
		seat.Prompt.Enabled = false
	end
	local char = player.Character
	local h = char and char:FindFirstChild("Humanoid")
	if seat.Seat and h then
		seat.Seat.Disabled = false
		pcall(function()
			seat.Seat:Sit(h)
		end)
	end
	Event:FireClient(player, "Notify", "You joined UNO. The game starts when 2 or more players are seated.", "info")
	sync(tbl)
	if #players(tbl) >= 2 and not tbl.Countdown then
		tbl.Countdown = true
		say(tbl, "UNO starts in 8 seconds!", "info")
		delay(8, function()
			tbl.Countdown = false
			start(tbl)
		end)
	end
end

for _, tbl in ipairs(Tables) do
	for _, seat in ipairs(tbl.Seats) do
		if not seat.Prompt and seat.Ref then
			seat.Prompt = Instance.new("ProximityPrompt")
			seat.Prompt.Parent = seat.Ref
		end
		if seat.Prompt then
			seat.Prompt.ActionText = "Join UNO"
			seat.Prompt.ObjectText = "Seat " .. seat.Name
			seat.Prompt.MaxActivationDistance = 8
			seat.Prompt.RequiresLineOfSight = false
			seat.Prompt.Enabled = true
			seat.Prompt.Triggered:Connect(function(player)
				join(player, tbl, seat)
			end)
		end
		if seat.Seat then
			seat.Seat.Disabled = false
			seat.Seat.Changed:Connect(function(prop)
				if prop == "Occupant" then
					local occ = seat.Seat.Occupant
					local who = occ and Players:GetPlayerFromCharacter(occ.Parent)
					if who and not seat.Player then
						join(who, tbl, seat)
					elseif seat.Player and (not who or who ~= seat.Player) then
						leave(seat.Player)
					end
				end
			end)
		end
	end
	if tbl.Pile then
		local cd = tbl.Pile:FindFirstChildOfClass("ClickDetector") or Instance.new("ClickDetector", tbl.Pile)
		cd.MaxActivationDistance = 20
		cd.MouseClick:Connect(function(player)
			draw(player)
		end)
	end
	sync(tbl)
end

Event.OnServerEvent:Connect(function(player, action, a, b)
	if action == "UnoPlay" then
		play(player, a, b)
	elseif action == "UnoDraw" then
		draw(player)
	elseif action == "UnoColor" then
		chooseColor(player, a)
	elseif action == "UnoLeave" then
		leave(player)
	end
end)

Players.PlayerRemoving:Connect(leave)

spawn(function()
	while true do
		wait(1)
		for _, tbl in ipairs(Tables) do
			if tbl.Running and tbl.TurnStarted and tick() - tbl.TurnStarted > TURN_SECONDS then
				local list = players(tbl)
				local seat = list[tbl.Turn]
				if seat then
					if tbl.Choosing == seat.Player then
						chooseColor(seat.Player, COLORS[math.random(1, 4)])
					else
						table.insert(seat.Hand, drawFrom(tbl))
						Event:FireClient(seat.Player, "Notify", "You ran out of time and drew a card.", "bad")
						advance(tbl, 1)
						sync(tbl)
					end
				end
			end
		end
	end
end)
