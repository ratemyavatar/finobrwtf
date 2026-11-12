do
	local CLASSES = C.CLASSES
	local props = C.props
	local setShadow = C.setShadow
	local E = G.Enum
	local U0 = realUDim.new(0, 0)

	local dummySignals = C.weak()
	local function dummySignal(o, name)
		local map = dummySignals[o]
		if not map then
			map = {}
			dummySignals[o] = map
		end
		local s = map[name]
		if not s then
			s = Signal.new()
			map[name] = s
		end
		return s
	end
	C.dummySignal = dummySignal

	function C.dummyGet(o, dc, k)
		local sh = props(o)
		local v = sh[k]
		if v ~= nil then return v end
		local def = CLASSES[dc]
		if def then
			if def.get and def.get[k] then return def.get[k](o) end
			if def.signals and def.signals[k] then return dummySignal(o, k) end
			if def.methods and def.methods[k] then return def.methods[k] end
			if def.defaults and def.defaults[k] ~= nil then
				local d = def.defaults[k]
				if type(d) == "function" then return d(o) end
				return d
			end
		end
		local child = o:FindFirstChild(k)
		if child then return child end
		local d = C.DEFAULTS[k]
		if d ~= nil then
			if type(d) == "function" then return d(o) end
			if d == false then return nil end
			return d
		end
		if k == "Enabled" or k == "Visible" then return true end
		if k:match("Changed$") or k:match("ed$") then return dummySignal(o, k) end
		return nil
	end

	function C.dummySet(o, dc, k, v)
		setShadow(o, k, v)
		local def = CLASSES[dc]
		if def and def.onSet then def.onSet(o, k, v) end
		if C.onDummySet then C.onDummySet(o, dc, k, v) end
	end

	local function reg(name, def)
		CLASSES[name] = def
		def.signals = def.signals or {}
		def.methods = def.methods or {}
		def.methods.IsA = function(o, c) return C.classIsA(name, c) end
		def.methods.GetPropertyChangedSignal = function(o, k) return C.propSignal(o, k) end
		def.methods.Destroy = function(o) o:Destroy() end
		def.methods.Clone = function(o) return o:Clone() end
		def.methods.GetAttribute = C.METHODS.GetAttribute
		def.methods.SetAttribute = C.METHODS.SetAttribute
		return def
	end

	local layoutApply = function(o)
		if C.layoutNow and o.Parent then C.layoutNow(o.Parent) end
	end
	reg("UIListLayout", {
		defaults = {
			Padding = U0, FillDirection = E.FillDirection.Vertical,
			SortOrder = E.SortOrder.LayoutOrder, HorizontalAlignment = E.HorizontalAlignment.Left,
			VerticalAlignment = E.VerticalAlignment.Top, Wraps = false, ItemLineAlignment = E.ItemLineAlignment.Automatic,
			HorizontalFlex = E.UIFlexAlignment.None, VerticalFlex = E.UIFlexAlignment.None,
			AbsoluteContentSize = realVector2.new(0, 0),
		},
		methods = { ApplyLayout = layoutApply, SetCustomSortFunction = function(o, f) C.customSort = C.customSort or C.weak() C.customSort[o] = f layoutApply(o) end },
	})
	reg("UIGridLayout", {
		defaults = {
			CellSize = realUDim2.new(0, 100, 0, 100), CellPadding = realUDim2.new(0, 5, 0, 5),
			FillDirection = E.FillDirection.Horizontal, FillDirectionMaxCells = 0, StartCorner = E.StartCorner.TopLeft,
			SortOrder = E.SortOrder.LayoutOrder, HorizontalAlignment = E.HorizontalAlignment.Left,
			VerticalAlignment = E.VerticalAlignment.Top, AbsoluteContentSize = realVector2.new(0, 0),
			AbsoluteCellSize = realVector2.new(100, 100), AbsoluteCellCount = realVector2.new(0, 0),
		},
		methods = { ApplyLayout = layoutApply },
	})
	local function pageIndex(o, page)
		local pages = C.layoutChildren and C.layoutChildren(o.Parent, o) or {}
		for i, p in ipairs(pages) do if p == page then return i, pages end end
		return nil, pages
	end
	local function jump(o, page)
		if not page then return end
		local old = props(o).CurrentPage
		if old == page then return end
		setShadow(o, "CurrentPage", page)
		if old then dummySignal(o, "PageLeave"):Fire(old) end
		dummySignal(o, "PageEnter"):Fire(page)
		layoutApply(o)
		delay(0, function() dummySignal(o, "Stopped"):Fire(page) end)
	end
	reg("UIPageLayout", {
		defaults = {
			Animated = true, Circular = false, EasingDirection = E.EasingDirection.Out, EasingStyle = E.EasingStyle.Back,
			Padding = U0, TweenTime = 1, FillDirection = E.FillDirection.Horizontal, SortOrder = E.SortOrder.LayoutOrder,
			HorizontalAlignment = E.HorizontalAlignment.Center, VerticalAlignment = E.VerticalAlignment.Center,
			GamepadInputEnabled = true, ScrollWheelInputEnabled = true, TouchInputEnabled = true,
			AbsoluteContentSize = realVector2.new(0, 0),
		},
		signals = { PageEnter = true, PageLeave = true, Stopped = true },
		get = {
			CurrentPage = function(o)
				local _, pages = pageIndex(o, nil)
				return pages[1]
			end,
		},
		methods = {
			ApplyLayout = layoutApply,
			JumpTo = function(o, page) jump(o, page) end,
			JumpToIndex = function(o, i)
				local _, pages = pageIndex(o, nil)
				jump(o, pages[i + 1])
			end,
			Next = function(o)
				local cur = C.g(o, "CurrentPage")
				local i, pages = pageIndex(o, cur)
				i = (i or 0) + 1
				if i > #pages then
					if not props(o).Circular then return end
					i = 1
				end
				jump(o, pages[i])
			end,
			Previous = function(o)
				local cur = C.g(o, "CurrentPage")
				local i, pages = pageIndex(o, cur)
				i = (i or 2) - 1
				if i < 1 then
					if not props(o).Circular then return end
					i = #pages
				end
				jump(o, pages[i])
			end,
		},
	})
	reg("UITableLayout", { defaults = { Padding = realUDim2.new(), FillEmptySpaceColumns = false, FillEmptySpaceRows = false, MajorAxis = E.TableMajorAxis.RowMajor, AbsoluteContentSize = realVector2.new() } })
	reg("UIPadding", { defaults = { PaddingTop = U0, PaddingBottom = U0, PaddingLeft = U0, PaddingRight = U0 } })
	reg("UIScale", { defaults = { Scale = 1 } })
	reg("UICorner", { defaults = { CornerRadius = realUDim.new(0, 8) } })
	reg("UIStroke", { defaults = { Color = realColor3.new(0, 0, 0), Thickness = 1, Transparency = 0, Enabled = true, ApplyStrokeMode = E.ApplyStrokeMode.Contextual, LineJoinMode = E.LineJoinMode.Round, BorderStrokePosition = E.BorderStrokePosition.Outer } })
	reg("UIGradient", { defaults = { Color = ColorSequence.new(realColor3.new(1, 1, 1)), Transparency = NumberSequence.new(0), Rotation = 0, Offset = realVector2.new(0, 0), Enabled = true } })
	reg("UIAspectRatioConstraint", { defaults = { AspectRatio = 1, AspectType = E.AspectType.FitWithinMaxSize, DominantAxis = E.DominantAxis.Width } })
	reg("UISizeConstraint", { defaults = { MinSize = realVector2.new(0, 0), MaxSize = realVector2.new(math.huge, math.huge) } })
	reg("UITextSizeConstraint", { defaults = { MinTextSize = 1, MaxTextSize = 100 } })
	reg("UIFlexItem", { defaults = { FlexMode = E.UIFlexMode.None, GrowRatio = 0, ShrinkRatio = 0 } })
	reg("UIDragDetector", { defaults = { Enabled = true }, signals = { DragStart = true, DragContinue = true, DragEnd = true } })
	reg("CanvasGroup", { defaults = { GroupTransparency = 0, GroupColor3 = realColor3.new(1, 1, 1) } })

	reg("Highlight", { defaults = { FillColor = realColor3.new(1, 0, 0), OutlineColor = realColor3.new(1, 1, 1), FillTransparency = 0.5, OutlineTransparency = 0, Enabled = true, DepthMode = E.HighlightDepthMode.AlwaysOnTop } })
	reg("Beam", { defaults = { Enabled = true, Width0 = 1, Width1 = 1, Segments = 10, FaceCamera = false, Texture = "", TextureSpeed = 1, LightEmission = 0, Transparency = NumberSequence.new(0.5), Color = ColorSequence.new(realColor3.new(1, 1, 1)) } })
	reg("Trail", { defaults = { Enabled = true, Lifetime = 2, MinLength = 0.1, MaxLength = 0, FaceCamera = false, Texture = "", LightEmission = 0, Transparency = NumberSequence.new(0.5), Color = ColorSequence.new(realColor3.new(1, 1, 1)), WidthScale = NumberSequence.new(1) }, methods = { Clear = function() end } })
	reg("Atmosphere", { defaults = { Density = 0.3, Offset = 0, Color = realColor3.new(0.78, 0.78, 0.78), Decay = realColor3.new(0.42, 0.44, 0.49), Glare = 0, Haze = 0 } })
	reg("Clouds", { defaults = { Cover = 0.5, Density = 0.7, Color = realColor3.new(1, 1, 1), Enabled = true } })
	reg("SunRaysEffect", { defaults = { Intensity = 0.25, Spread = 1, Enabled = true } })
	reg("DepthOfFieldEffect", { defaults = { FarIntensity = 0.75, FocusDistance = 0.05, InFocusRadius = 10, NearIntensity = 0.75, Enabled = true } })
	reg("SurfaceAppearance", { defaults = { ColorMap = "", MetalnessMap = "", NormalMap = "", RoughnessMap = "", AlphaMode = E.AlphaMode.Overlay } })
	reg("SoundGroup", { defaults = { Volume = 0.5 } })
	for _, n in ipairs({ "SoundEffect", "ReverbSoundEffect", "EqualizerSoundEffect", "ChorusSoundEffect", "PitchShiftSoundEffect", "DistortionSoundEffect", "EchoSoundEffect", "FlangeSoundEffect", "TremoloSoundEffect", "CompressorSoundEffect" }) do
		reg(n, { defaults = { Enabled = true, Priority = 0 } })
	end
	reg("HumanoidDescription", {
		defaults = { HeadColor = realColor3.new(1, 1, 1), BodyTypeScale = 0.3, DepthScale = 1, HeightScale = 1, WidthScale = 1, HeadScale = 1, ProportionScale = 1, Face = 0, Shirt = 0, Pants = 0, GraphicTShirt = 0, UserId = 0 },
		methods = {
			GetAccessories = function() return {} end,
			SetAccessories = function() end,
			GetEmotes = function() return {} end,
			SetEmotes = function() end,
			GetEquippedEmotes = function() return {} end,
			SetEquippedEmotes = function() end,
			AddEmote = function() end,
			RemoveEmote = function() end,
		},
	})
	reg("TextChannel", {
		methods = {
			SendAsync = function(o, text)
				if IS_CLIENT then
					local plr = Players.LocalPlayer
					pcall(function() Players:Chat(text) end)
				end
				return { Status = E.TextChatMessageStatus.Success, Text = text }
			end,
			DisplaySystemMessage = function(o, text)
				if IS_CLIENT then
					pcall(function()
						game:GetService("StarterGui"):SetCore("ChatMakeSystemMessage", { Text = (tostring(text):gsub("<[^<>]->", "")), Color = realColor3.new(1, 1, 0.6) })
					end)
				end
			end,
			AddUserAsync = function() return nil, true end,
			CanUserChatAsync = function() return true end,
		},
		signals = { MessageReceived = true, ShouldDeliverCallback = true },
	})
	reg("TextChatService", {
		defaults = { ChatVersion = E.ChatVersion.TextChatService, CreateDefaultCommands = true, CreateDefaultTextChannels = true },
		signals = { MessageReceived = true, SendingMessage = true },
		methods = { CanUserChatAsync = function() return true end, CanUsersChatAsync = function() return true end, DisplayBubble = function() end },
	})
	reg("ChatWindowConfiguration", { defaults = { Enabled = true } })
	reg("ChatInputBarConfiguration", { defaults = { Enabled = true } })
	reg("BubbleChatConfiguration", { defaults = { Enabled = true } })
	reg("ChannelTabsConfiguration", { defaults = { Enabled = true } })
	reg("TextChatCommand", { defaults = { PrimaryAlias = "", SecondaryAlias = "", Enabled = true, AutocompleteVisible = true }, signals = { Triggered = true } })

	reg("ProximityPrompt", {
		defaults = {
			ActionText = "Interact", ObjectText = "", HoldDuration = 0, MaxActivationDistance = 10, Enabled = true,
			RequiresLineOfSight = true, KeyboardKeyCode = realEnum.KeyCode.E, GamepadKeyCode = realEnum.KeyCode.ButtonX,
			ClickablePrompt = true, Exclusivity = E.ProximityPromptExclusivity.OnePerButton, Style = E.ProximityPromptStyle.Default,
			UIOffset = realVector2.new(0, 0), AutoLocalize = true,
		},
		signals = { Triggered = true, TriggerEnded = true, PromptButtonHoldBegan = true, PromptButtonHoldEnded = true, PromptShown = true, PromptHidden = true },
		methods = {
			InputHoldBegin = function(o) if C.promptHoldBegin then C.promptHoldBegin(o) end end,
			InputHoldEnd = function(o) if C.promptHoldEnd then C.promptHoldEnd(o) end end,
		},
	})

	reg("NoCollisionConstraint", { defaults = { Enabled = true } })
	reg("RigidConstraint", { defaults = { Enabled = true } })
	reg("AlignPosition", { defaults = { Enabled = true, MaxForce = 10000, MaxVelocity = math.huge, Responsiveness = 10, RigidityEnabled = false, Mode = E.PositionAlignmentMode.TwoAttachment, Position = realVector3.new() } })
	reg("AlignOrientation", { defaults = { Enabled = true, MaxTorque = 10000, MaxAngularVelocity = math.huge, Responsiveness = 10, RigidityEnabled = false, Mode = E.OrientationAlignmentMode.TwoAttachment, CFrame = realCFrame.new() } })
	reg("LinearVelocity", { defaults = { Enabled = true, MaxForce = 1000, VectorVelocity = realVector3.new(), VelocityConstraintMode = E.VelocityConstraintMode.Vector, RelativeTo = E.ActuatorRelativeTo.World } })
	reg("AngularVelocity", { defaults = { Enabled = true, MaxTorque = 1000, AngularVelocity = realVector3.new(), RelativeTo = E.ActuatorRelativeTo.World } })
	reg("VectorForce", { defaults = { Enabled = true, Force = realVector3.new(), ApplyAtCenterOfMass = false, RelativeTo = E.ActuatorRelativeTo.Attachment0 } })
	reg("Torque", { defaults = { Enabled = true, Torque = realVector3.new() } })
	reg("WorldModel", {})
	reg("Actor", {})
	reg("PathfindingModifier", { defaults = { Label = "", PassThrough = false } })
	reg("MaterialVariant", {})
	reg("WrapLayer", {})
	reg("WrapTarget", {})
	reg("Wedge", {})
	for _, n in ipairs({ "AudioPlayer", "AudioEmitter", "AudioListener", "AudioDeviceOutput", "AudioDeviceInput", "AudioFader", "AudioAnalyzer" }) do
		reg(n, { methods = { Play = function() end, Stop = function() end, GetConnectedWires = function() return {} end } })
	end
	reg("Wire", { defaults = { SourceName = "", TargetName = "" } })

	local function bodyFor(o, class)
		local sh = props(o)
		local body = sh.__body
		if body and body.Parent then return body end
		local a0 = sh.Attachment0
		local part = a0 and a0.Parent
		if not part then return nil end
		body = realInstance.new(class)
		body.Name = "__compat_body"
		body.Parent = part
		sh.__body = body
		return body
	end
	local function updateMover(o, dc)
		local sh = props(o)
		local enabled = sh.Enabled ~= false
		local d = CLASSES[dc].defaults
		local function val(k) local v = sh[k] if v == nil then v = d[k] end return v end
		if dc == "LinearVelocity" then
			local b = bodyFor(o, "BodyVelocity")
			if not b then return end
			b.Velocity = val("VectorVelocity")
			local f = enabled and math.min(val("MaxForce"), 1e9) or 0
			b.MaxForce = realVector3.new(f, f, f)
			b.P = 1e5
		elseif dc == "AngularVelocity" then
			local b = bodyFor(o, "BodyAngularVelocity")
			if not b then return end
			b.AngularVelocity = val("AngularVelocity")
			local f = enabled and math.min(val("MaxTorque"), 1e9) or 0
			b.MaxTorque = realVector3.new(f, f, f)
		elseif dc == "VectorForce" then
			local b = bodyFor(o, "BodyForce")
			if not b then return end
			b.Force = enabled and val("Force") or realVector3.new()
		elseif dc == "AlignPosition" then
			local b = bodyFor(o, "BodyPosition")
			if not b then return end
			local target = val("Position")
			local a1 = sh.Attachment1
			if a1 and a1.Parent then
				local ok, wp = pcall(function() return a1.WorldPosition end)
				if not ok then ok, wp = pcall(function() return a1.Parent.CFrame * a1.Position end) end
				if ok then target = wp end
			end
			b.Position = target
			local f = enabled and math.min(val("MaxForce"), 1e9) or 0
			if val("RigidityEnabled") then f = enabled and 1e9 or 0 end
			b.MaxForce = realVector3.new(f, f, f)
			b.P = 1e4 * (val("Responsiveness") / 10)
		elseif dc == "AlignOrientation" then
			local b = bodyFor(o, "BodyGyro")
			if not b then return end
			local target = val("CFrame")
			local a1 = sh.Attachment1
			if a1 and a1.Parent then
				local ok, cf = pcall(function() return a1.Parent.CFrame * a1.CFrame end)
				if ok then target = cf end
			end
			b.CFrame = target
			local f = enabled and math.min(val("MaxTorque"), 1e9) or 0
			if val("RigidityEnabled") then f = enabled and 1e9 or 0 end
			b.MaxTorque = realVector3.new(f, f, f)
			b.P = 3000 * (val("Responsiveness") / 10)
		end
	end
	C.updateMover = updateMover
	for _, n in ipairs({ "LinearVelocity", "AngularVelocity", "VectorForce", "AlignPosition", "AlignOrientation" }) do
		CLASSES[n].onSet = function(o) pcall(updateMover, o, n) end
		CLASSES[n].init = function(o) end
	end
	C.dummyParented = function(o, dc)
		if CLASSES[dc] and CLASSES[dc].onSet then pcall(CLASSES[dc].onSet, o, "Parent", o.Parent) end
		if C.registerDummy then C.registerDummy(o, dc) end
	end

	C.afterSet = function(o, k, v)
		if C.weldConstraints[o] and (k == "Part0" or k == "Part1") then
			local p0, p1 = o.Part0, o.Part1
			if p0 and p1 then
				o.C0 = p0.CFrame:inverse() * p1.CFrame
				o.C1 = realCFrame.new()
			end
		end
	end
	local oldEnabledSet = C.SET.Enabled
	C.SET.Enabled = function(o, v)
		if C.weldConstraints[o] then
			setShadow(o, "Enabled", v)
			local sh = props(o)
			if not v then
				sh.__part1 = o.Part1
				o.Part1 = nil
			elseif sh.__part1 then
				o.Part1 = sh.__part1
			end
			return true
		end
		local ok = pcall(C.setidx, o, "Enabled", v)
		if ok then return true end
		local okc, cn = pcall(idx, o, "ClassName")
		if okc and (cn == "ScreenGui" or cn == "BillboardGui" or cn == "SurfaceGui") then
			setShadow(o, "Enabled", v)
			if C.setGuiEnabled then C.setGuiEnabled(o, v) end
			return true
		end
		return false
	end
	C.GET.Enabled = function(o)
		if not isInstance(o) then return false end
		local ok, v = pcall(idx, o, "Enabled")
		if ok then return true, v end
		if C.dummyClass(o) then return false end
		local sh = props(o)
		if sh.Enabled ~= nil then return true, sh.Enabled end
		return true, true
	end

	local remote
	local function getRemote()
		if remote then return remote end
		remote = ReplicatedStorage:FindFirstChild("__compat_remote")
		if not remote and IS_SERVER then
			remote = realInstance.new("RemoteEvent")
			remote.Name = "__compat_remote"
			remote.Parent = ReplicatedStorage
		end
		if not remote then remote = ReplicatedStorage:WaitForChild("__compat_remote") end
		return remote
	end
	C.getRemote = getRemote

	local function firstPart(m)
		for _, d in ipairs(m:GetChildren()) do
			local ok, r = pcall(d.IsA, d, "BasePart")
			if ok and r then return d end
		end
		return nil
	end
	local function promptPart(o)
		local p = o.Parent
		while p do
			local ok, isPart = pcall(p.IsA, p, "BasePart")
			if ok and isPart then return p, nil end
			local okA, isAtt = pcall(p.IsA, p, "Attachment")
			if okA and isAtt then return p.Parent, p end
			local okM, isModel = pcall(p.IsA, p, "Model")
			if okM and isModel then
				return p.PrimaryPart or p:FindFirstChild("HumanoidRootPart") or firstPart(p), nil
			end
			p = p.Parent
		end
		return nil
	end
	local function promptPosition(o)
		local part, att = promptPart(o)
		if not part then return nil end
		if att then
			local ok, wp = pcall(function() return att.WorldPosition end)
			if ok then return wp end
			return part.CFrame * att.Position
		end
		return part.Position
	end
	C.promptPosition = promptPosition

	if IS_SERVER then
		local r = getRemote()
		r.OnServerEvent:connect(function(player, kind, prompt, extra)
			if typeof(prompt) ~= "Instance" or C.dummyClass(prompt) ~= "ProximityPrompt" then return end
			local sh = props(prompt)
			if sh.Enabled == false then return end
			local pos = promptPosition(prompt)
			local char = player.Character
			local root = char and (char:FindFirstChild("HumanoidRootPart") or char:FindFirstChild("Torso"))
			if not pos or not root then return end
			local maxd = sh.MaxActivationDistance or 10
			if (root.Position - pos).magnitude > maxd + 8 then return end
			local pps = C.ProximityPromptService
			if kind == "trigger" then
				dummySignal(prompt, "Triggered"):Fire(player)
				pps.PromptTriggered:Fire(prompt, player)
			elseif kind == "ended" then
				dummySignal(prompt, "TriggerEnded"):Fire(player)
				pps.PromptTriggerEnded:Fire(prompt, player)
			elseif kind == "holdbegin" then
				dummySignal(prompt, "PromptButtonHoldBegan"):Fire(player)
				pps.PromptButtonHoldBegan:Fire(prompt, player)
			elseif kind == "holdend" then
				dummySignal(prompt, "PromptButtonHoldEnded"):Fire(player)
				pps.PromptButtonHoldEnded:Fire(prompt, player)
			end
		end)

		local commands = {}
		local function scanCommands(root)
			for _, d in ipairs(C.descendants(root)) do
				if C.dummyClass(d) == "TextChatCommand" then commands[d] = true end
			end
		end
		local tcs = C.getService("TextChatService")
		scanCommands(tcs)
		tcs.DescendantAdded:connect(function(d)
			if d.Name == "__compat_class" and d.Value == "TextChatCommand" and d.Parent then commands[d.Parent] = true end
		end)
		local function onChat(player, message)
			local first = message:match("^(%S+)")
			if not first then return end
			first = first:lower()
			for cmd in pairs(commands) do
				if cmd.Parent then
					local sh = props(cmd)
					local enabled = sh.Enabled ~= false
					local a = tostring(sh.PrimaryAlias or ""):lower()
					local b = tostring(sh.SecondaryAlias or ""):lower()
					if enabled and ((a ~= "" and first == a) or (b ~= "" and first == b)) then
						local source = { __type = "Instance", ClassName = "TextSource", Name = player.Name, UserId = player.userId, CanSend = true }
						dummySignal(cmd, "Triggered"):Fire(source, message)
					end
				end
			end
		end
		local function hookPlayer(p) p.Chatted:connect(function(m) onChat(p, m) end) end
		for _, p in ipairs(Players:GetPlayers()) do hookPlayer(p) end
		Players.PlayerAdded:connect(hookPlayer)
	end
end
