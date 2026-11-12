do
	local props = C.props
	local setShadow = C.setShadow
	local dummyClass = C.dummyClass
	local METHODS = C.METHODS
	local isFakeEnum = C.isFakeEnum

	local EMULATED = {
		AnchorPoint = true, TextSize = true, AutomaticSize = true, RichText = true, MaxVisibleGraphemes = true,
		LayoutOrder = true, Interactable = true, FontFace = true, LineHeight = true, Massless = true, CanTouch = true,
		CanQuery = true, CastShadow = true, CollisionGroup = true, CollisionGroupId = true, IgnoreGuiInset = true,
		ResetOnSpawn = true, ZIndexBehavior = true, DisplayOrder = true, UseJumpPower = true, JumpHeight = true,
		ScreenInsets = true, SafeAreaCompatibility = true, ClipToDeviceSafeArea = true, AutoLocalize = true,
		RootLocalizationTable = true, SelectionGroup = true, PlaybackRegionsEnabled = true, EvaluateStateMachine = true,
		RequiresNeck = true, BreakJointsOnDeath = true, AutomaticCanvasSize = true, ScrollingEnabled = true,
		VerticalScrollBarInset = true, HorizontalScrollBarInset = true, ElasticBehavior = true, ScrollBarImageColor3 = true,
		ScrollBarImageTransparency = true, TextTruncate = true, TextDirection = true, CursorPosition = true,
		ClearTextOnFocus = true, TextEditable = true, SoundGroup = true, RollOffMode = true, RollOffMinDistance = true,
		RollOffMaxDistance = true, EmitterSize = true, Attachment0 = true, Attachment1 = true, ZOffset = true,
		Shape = true, PivotOffset = true, WorldPivot = true, ModelStreamingMode = true, LevelOfDetail = true,
		Brightness = true, Shadows = true, Rotation = true, Enabled = true, Adornee = true, AlwaysOnTop = true,
		MaxDistance = true, LightInfluence = true, DistanceLowerLimit = true, DistanceUpperLimit = true,
		DistanceStep = true, Active = true, AutoButtonColor = true, Modal = true, Selectable = true, SelectionImageObject = true,
		NextSelectionDown = true, NextSelectionUp = true, NextSelectionLeft = true, NextSelectionRight = true,
		DisplayName = true, NameDisplayDistance = true, HealthDisplayDistance = true, DisplayDistanceType = true,
		HealthDisplayType = true, TextStrokeTransparency = true, Font = true, FontSize = true, PlaybackSpeed = true,
		Pitch = true, ImageRectOffset = true, ImageRectSize = true, ResampleMode = true, ScaleType = true,
		SliceCenter = true, SliceScale = true, TileSize = true, CanvasPosition = true, CanvasSize = true,
		LocalTransparencyModifier = true, MaxActivationDistance = true, CameraMaxZoomDistance = true, CameraMinZoomDistance = true,
		DevComputerMovementMode = true, DevTouchMovementMode = true, DevComputerCameraMode = true, DevTouchCameraMode = true,
		DevEnableMouseLock = true, CharacterWalkSpeed = true, CharacterJumpPower = true, CharacterJumpHeight = true,
		CharacterUseJumpPower = true, AutoJumpEnabled = true, CameraMode = true, Neutral = true, TeamColor = true,
		SizeConstraint = true, ClipsDescendants = true, TextWrapped = true, TextScaled = true,
	}
	C.EMULATED_PROPS = EMULATED
	C.PROP_ALIASES = {
		AssemblyLinearVelocity = "Velocity", AssemblyAngularVelocity = "RotVelocity", PlaybackSpeed = "Pitch",
		Orientation = "CFrame", TextSize = "FontSize", JumpHeight = "JumpPower",
	}

	local FONT_SIZES = { 8, 9, 10, 11, 12, 14, 18, 24, 28, 32, 36, 42, 48, 60, 96 }
	local function fontSizeFor(n)
		local best, bestDiff = 14, math.huge
		for _, s in ipairs(FONT_SIZES) do
			local d = math.abs(s - n)
			if d < bestDiff or (d == bestDiff and s < n) then best, bestDiff = s, d end
		end
		local ok, e = pcall(idx, realEnum.FontSize, "Size" .. best)
		if ok and e then return e end
		return realEnum.FontSize.Size14
	end
	local function sizeFromFont(e)
		local n = tostring(e):match("Size(%d+)")
		return tonumber(n) or 14
	end

	local function orientationOf(cf)
		local _, _, _, r00, r01, r02, r10, r11, r12, r20, r21, r22 = cf:components()
		local x = math.asin(math.max(-1, math.min(1, -r12)))
		local y, z
		if math.abs(r12) < 0.99999 then
			y = math.atan2(r02, r22)
			z = math.atan2(r10, r11)
		else
			y = math.atan2(-r20, r00)
			z = 0
		end
		return x, y, z
	end
	C.orientationOf = orientationOf

	local function udimMul(scale, u)
		return realUDim.new(u.Scale * scale, u.Offset * scale)
	end
	local function udimAdd(a, b) return realUDim.new(a.Scale + b.Scale, a.Offset + b.Offset) end

	local paddingOf = C.weak()
	C.paddingOf = paddingOf
	local transformed = C.weak()
	C.transformed = transformed
	local layoutOf = C.weak()
	C.layoutOf = layoutOf

	local function rawGet(o, k)
		local ok, v = pcall(idx, o, k)
		if ok then return v end
		return nil
	end

	local function padInfo(parent)
		local pad = parent and paddingOf[parent]
		if not pad then return nil end
		if type(pad) == "table" then return pad.L, pad.R, pad.T, pad.B end
		if not pad.Parent then return nil end
		local p = props(pad)
		local zero = realUDim.new(0, 0)
		return p.PaddingLeft or zero, p.PaddingRight or zero, p.PaddingTop or zero, p.PaddingBottom or zero
	end

	local function isTransformed(o)
		local sh = props(o)
		local a = sh.AnchorPoint
		if a and (a.x ~= 0 or a.y ~= 0) then return true end
		if paddingOf[o.Parent or false] then return true end
		return false
	end

	local function applyGui(o)
		local sh = props(o)
		local parent = o.Parent
		local layout = parent and layoutOf[parent]
		local pl, pr, pt, pb = padInfo(parent)
		local size = sh.Size or rawGet(o, "Size")
		local pos = sh.Position or rawGet(o, "Position")
		if not size or not pos then return end
		local realSizeX, realSizeY = size.X, size.Y
		if pl then
			local w = realUDim.new(1 - pl.Scale - pr.Scale, -pl.Offset - pr.Offset)
			local h = realUDim.new(1 - pt.Scale - pb.Scale, -pt.Offset - pb.Offset)
			realSizeX = udimAdd(udimMul(size.X.Scale, w), realUDim.new(0, size.X.Offset))
			realSizeY = udimAdd(udimMul(size.Y.Scale, h), realUDim.new(0, size.Y.Offset))
			if not (layout and C.layoutSetsSize and C.layoutSetsSize(layout)) then
				pcall(C.setidx, o, "Size", realUDim2.new(realSizeX.Scale, realSizeX.Offset, realSizeY.Scale, realSizeY.Offset))
			end
		end
		if layout then return end
		local a = sh.AnchorPoint or realVector2.new(0, 0)
		local x, y = pos.X, pos.Y
		if pl then
			local w = realUDim.new(1 - pl.Scale - pr.Scale, -pl.Offset - pr.Offset)
			local h = realUDim.new(1 - pt.Scale - pb.Scale, -pt.Offset - pb.Offset)
			x = udimAdd(udimAdd(pl, udimMul(pos.X.Scale, w)), realUDim.new(0, pos.X.Offset))
			y = udimAdd(udimAdd(pt, udimMul(pos.Y.Scale, h)), realUDim.new(0, pos.Y.Offset))
		end
		if a.x ~= 0 or a.y ~= 0 then
			x = udimAdd(x, udimMul(-a.x, realSizeX))
			y = udimAdd(y, udimMul(-a.y, realSizeY))
		end
		pcall(C.setidx, o, "Position", realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset))
	end
	C.applyGui = applyGui

	local function becomeTransformed(o)
		if transformed[o] then return end
		local sh = props(o)
		if sh.Position == nil then sh.Position = rawGet(o, "Position") end
		if sh.Size == nil then sh.Size = rawGet(o, "Size") end
		transformed[o] = true
	end
	C.becomeTransformed = becomeTransformed

	local function isGui(o)
		local ok, r = pcall(o.IsA, o, "GuiObject")
		return ok and r
	end
	C.isGui = isGui

	local GET = {}
	local SET = {}
	C.GET = GET
	C.SET = SET

	GET.Position = function(o)
		local t = typeof(o)
		if t == "CFrame" then return true, o.p end
		if t ~= "Instance" then return false end
		local sh = props(o)
		if sh.Position ~= nil and isGui(o) and (isTransformed(o) or transformed[o]) then return true, sh.Position end
		return false
	end
	SET.Position = function(o, v)
		if not isGui(o) then return false end
		if not isTransformed(o) then
			local sh = props(o)
			if sh.Position ~= nil then setShadow(o, "Position", nil) end
			return false
		end
		becomeTransformed(o)
		setShadow(o, "Position", v)
		C.applyGui(o)
		return true
	end
	GET.Size = function(o)
		if typeof(o) ~= "Instance" then return false end
		local sh = props(o)
		if sh.Size ~= nil and paddingOf[o.Parent or false] and isGui(o) then return true, sh.Size end
		return false
	end
	SET.Size = function(o, v)
		if not isGui(o) then return false end
		if not isTransformed(o) then
			local sh = props(o)
			if sh.Size ~= nil then setShadow(o, "Size", nil) end
			if C.onGuiSize then C.onGuiSize(o) end
			return false
		end
		becomeTransformed(o)
		setShadow(o, "Size", v)
		C.applyGui(o)
		if C.onGuiSize then C.onGuiSize(o) end
		return true
	end
	SET.AnchorPoint = function(o, v)
		if not isGui(o) then return false end
		becomeTransformed(o)
		setShadow(o, "AnchorPoint", v)
		C.applyGui(o)
		return true
	end
	GET.AnchorPoint = function(o)
		if typeof(o) ~= "Instance" then return false end
		return true, props(o).AnchorPoint or realVector2.new(0, 0)
	end
	SET.Parent = function(o, v)
		if not isInstance(o) then return false end
		if type(v) == "table" and rawget(v, "__instance") then v = v:__instance() end
		o.Parent = v
		if transformed[o] or (v and paddingOf[v]) then
			if isGui(o) then
				becomeTransformed(o)
				C.applyGui(o)
			end
		end
		return true
	end

	local function meshOf(o)
		if not isInstance(o) then return nil end
		local ok, m = pcall(o.FindFirstChild, o, "__compat_mesh")
		if ok then return m end
		return nil
	end
	C.meshOf = meshOf
	SET.TextureID = function(o, v)
		local m = meshOf(o)
		if not m then return false end
		m.TextureId = v
		setShadow(o, "TextureID", v)
		return true
	end
	GET.TextureID = function(o)
		local m = meshOf(o)
		if not m then return false end
		return true, m.TextureId
	end
	SET.TextureId = function(o, v)
		local m = meshOf(o)
		if not m then return false end
		local ok = pcall(C.setidx, o, "TextureId", v)
		if ok then return true end
		m.TextureId = v
		return true
	end
	GET.MeshId = function(o)
		local m = meshOf(o)
		if not m then return false end
		return true, m.MeshId
	end
	local oldSizeSet = SET.Size
	SET.Size = function(o, v, k)
		local m = meshOf(o)
		if m then
			local initial = props(o).InitialSize
			o.Size = v
			if initial then
				local real = o.Size
				m.Scale = realVector3.new(real.x / initial.x, real.y / initial.y, real.z / initial.z)
			end
			return true
		end
		return oldSizeSet(o, v, k)
	end

	SET.TextSize = function(o, v)
		local okf = pcall(C.setidx, o, "TextSize", v)
		if okf then return true end
		setShadow(o, "TextSize", v)
		pcall(C.setidx, o, "FontSize", fontSizeFor(v))
		return true
	end
	GET.TextSize = function(o)
		if typeof(o) ~= "Instance" then return false end
		local ok, v = pcall(idx, o, "TextSize")
		if ok then return true, v end
		local sh = props(o)
		if sh.TextSize then return true, sh.TextSize end
		local okf, fs = pcall(idx, o, "FontSize")
		if okf then return true, sizeFromFont(fs) end
		return false
	end

	local function stripRich(s)
		s = s:gsub("<br%s*/?>", "\n"):gsub("<[^<>]->", "")
		s = s:gsub("&lt;", "<"):gsub("&gt;", ">"):gsub("&quot;", "\""):gsub("&apos;", "'"):gsub("&amp;", "&")
		return s
	end
	local function applyText(o)
		local sh = props(o)
		local text = sh.Text
		if text == nil then return end
		local shown = text
		if sh.RichText then shown = stripRich(shown) end
		local maxg = sh.MaxVisibleGraphemes
		if maxg and maxg >= 0 then
			local out, n = {}, 0
			for ch in shown:gmatch("[%z\1-\127\194-\244][\128-\191]*") do
				n = n + 1
				if n > maxg then break end
				out[#out + 1] = ch
			end
			shown = table.concat(out)
		end
		pcall(C.setidx, o, "Text", shown)
	end
	SET.Text = function(o, v)
		if typeof(o) ~= "Instance" then return false end
		local sh = props(o)
		if sh.RichText or (sh.MaxVisibleGraphemes and sh.MaxVisibleGraphemes >= 0) then
			sh.Text = v
			applyText(o)
			if C.onGuiSize then C.onGuiSize(o) end
			return true
		end
		if sh.Text ~= nil then sh.Text = nil end
		if C.onTextChanged then C.onTextChanged(o) end
		return false
	end
	GET.Text = function(o)
		if typeof(o) ~= "Instance" then return false end
		local sh = props(o)
		if sh.Text ~= nil and (sh.RichText or (sh.MaxVisibleGraphemes and sh.MaxVisibleGraphemes >= 0)) then return true, sh.Text end
		return false
	end
	SET.RichText = function(o, v)
		local sh = props(o)
		if v and sh.Text == nil then sh.Text = rawGet(o, "Text") end
		setShadow(o, "RichText", v)
		applyText(o)
		return true
	end
	SET.MaxVisibleGraphemes = function(o, v)
		local sh = props(o)
		if sh.Text == nil then sh.Text = rawGet(o, "Text") end
		setShadow(o, "MaxVisibleGraphemes", v)
		applyText(o)
		return true
	end
	GET.ContentText = function(o)
		local sh = props(o)
		local t = sh.Text or rawGet(o, "Text") or ""
		return true, stripRich(t)
	end

	SET.Orientation = function(o, v)
		local ok = pcall(C.setidx, o, "Orientation", v)
		if ok then return true end
		local okp, p = pcall(idx, o, "Position")
		if not okp then return false end
		o.CFrame = realCFrame.new(p) * G.CFrame.fromEulerAnglesYXZ(math.rad(v.x), math.rad(v.y), math.rad(v.z))
		return true
	end
	GET.Orientation = function(o)
		local ok, v = pcall(idx, o, "Orientation")
		if ok then return true, v end
		local okc, cf = pcall(idx, o, "CFrame")
		if not okc then return false end
		local x, y, z = orientationOf(cf)
		return true, realVector3.new(math.deg(x), math.deg(y), math.deg(z))
	end
	local ALIAS = {
		AssemblyLinearVelocity = "Velocity", AssemblyAngularVelocity = "RotVelocity", PlaybackSpeed = "Pitch",
		LinearVelocity = "Velocity",
	}
	for new, old in pairs(ALIAS) do
		if new ~= "LinearVelocity" then
			SET[new] = function(o, v)
				if pcall(C.setidx, o, new, v) then return true end
				return pcall(C.setidx, o, old, v)
			end
			GET[new] = function(o)
				local ok, v = pcall(idx, o, new)
				if ok then return true, v end
				ok, v = pcall(idx, o, old)
				if ok then return true, v end
				return false
			end
		end
	end
	GET.AssemblyMass = function(o) return true, METHODS.GetMass(o) end
	GET.AssemblyRootPart = function(o) return true, o end
	GET.AssemblyCenterOfMass = function(o) return true, o.Position end
	GET.CurrentPhysicalProperties = function(o)
		local ok, v = pcall(idx, o, "CurrentPhysicalProperties")
		if ok then return true, v end
		return true, PhysicalProperties.new(0.7, 0.3, 0.5)
	end

	SET.JumpHeight = function(o, v)
		if pcall(C.setidx, o, "JumpHeight", v) then return true end
		setShadow(o, "JumpHeight", v)
		local okc = pcall(function() return o.ClassName end)
		if okc and o.ClassName == "StarterPlayer" then
			pcall(C.setidx, o, "CharacterJumpPower", math.sqrt(2 * 196.2 * v))
		else
			pcall(C.setidx, o, "JumpPower", math.sqrt(2 * 196.2 * v))
		end
		return true
	end
	GET.JumpHeight = function(o)
		local ok, v = pcall(idx, o, "JumpHeight")
		if ok then return true, v end
		local okp, jp = pcall(idx, o, "JumpPower")
		if okp then return true, jp * jp / (2 * 196.2) end
		return false
	end
	SET.CharacterJumpHeight = SET.JumpHeight

	GET.DisplayName = function(o)
		local ok, v = pcall(idx, o, "DisplayName")
		if ok then return true, v end
		local okn, n = pcall(idx, o, "Name")
		if okn then return true, n end
		return false
	end
	GET.RootPart = function(o)
		local ok, v = pcall(idx, o, "RootPart")
		if ok then return true, v end
		local okp, p = pcall(idx, o, "Parent")
		if okp and p then return true, p:FindFirstChild("HumanoidRootPart") or p:FindFirstChild("Torso") end
		return true, nil
	end
	GET.RigType = function(o)
		local ok, v = pcall(idx, o, "RigType")
		if ok then return true, v end
		local p = o.Parent
		if p and p:FindFirstChild("UpperTorso") then return true, G.Enum.HumanoidRigType.R15 end
		return true, G.Enum.HumanoidRigType.R6
	end
	GET.FloorMaterial = function(o)
		local ok, v = pcall(idx, o, "FloorMaterial")
		if ok then return true, v end
		return true, realEnum.Material.Plastic
	end
	GET.ClassName = function(o)
		if not isInstance(o) then return false end
		return true, C.className(o)
	end
	GET.Activated = function(o)
		if not isInstance(o) then return false end
		local ok, v = pcall(idx, o, "Activated")
		if ok then return true, v end
		local okb, click = pcall(idx, o, "MouseButton1Click")
		if not okb then return false end
		return true, C.proxySignal(function(fn)
			return click:connect(function() fn(nil, 1) end)
		end)
	end
	GET.Destroying = function(o)
		if not isInstance(o) then return false end
		return true, C.proxySignal(function(fn)
			local conn
			conn = o.AncestryChanged:connect(function(_, parent)
				if parent == nil then fn() end
			end)
			return conn
		end)
	end
	GET.ChildAdded = function(o)
		if not isInstance(o) then return false end
		local real = o.ChildAdded
		return true, C.proxySignal(function(fn)
			return real:connect(function(c) if not C.hidden(c) then fn(c) end end)
		end)
	end
	GET.ChildRemoved = function(o)
		if not isInstance(o) then return false end
		local real = o.ChildRemoved
		return true, C.proxySignal(function(fn)
			return real:connect(function(c) if not C.hidden(c) then fn(c) end end)
		end)
	end
	local function inHidden(d)
		local p = d
		while p do
			if C.hidden(p) then return true end
			p = p.Parent
		end
		return false
	end
	GET.DescendantAdded = function(o)
		if not isInstance(o) then return false end
		local real = o.DescendantAdded
		return true, C.proxySignal(function(fn)
			return real:connect(function(c) if not inHidden(c) then fn(c) end end)
		end)
	end
	GET.DescendantRemoving = function(o)
		if not isInstance(o) then return false end
		local real = o.DescendantRemoving
		return true, C.proxySignal(function(fn)
			return real:connect(function(c) if not C.hidden(c) then fn(c) end end)
		end)
	end
	GET.AttributeChanged = function(o)
		if not isInstance(o) then return false end
		return true, C.watchAttrsAny(o)
	end
	GET.Changed = function(o)
		if not isInstance(o) or dummyClass(o) then
			if dummyClass(o) then
				local s = C.changedSignals[o]
				if not s then
					s = Signal.new()
					C.changedSignals[o] = s
				end
				return true, s
			end
			return false
		end
		return false
	end
	GET.Parent = nil

	local RS = RunService
	GET.PreSimulation = function(o)
		if o ~= RS then return false end
		return true, C.proxySignal(function(fn) return RS.Stepped:connect(function(_, dt) fn(dt) end) end)
	end
	GET.PostSimulation = function(o)
		if o ~= RS then return false end
		return true, RS.Heartbeat
	end
	GET.PreRender = function(o)
		if o ~= RS then return false end
		local ok, sig = pcall(idx, RS, "RenderStepped")
		if ok and sig then return true, sig end
		return true, RS.Heartbeat
	end
	GET.PreAnimation = GET.PostSimulation

	GET.PreferredInput = function(o)
		local ok, v = pcall(idx, o, "PreferredInput")
		if ok then return true, v end
		local okt, touch = pcall(idx, o, "TouchEnabled")
		local okk, kb = pcall(idx, o, "KeyboardEnabled")
		local okg, gp = pcall(idx, o, "GamepadEnabled")
		if not (okt or okk or okg) then return false end
		local last = C.lastInputKind
		if last then return true, G.Enum.PreferredInput[last] end
		if okt and touch and not (okk and kb) then return true, G.Enum.PreferredInput.Touch end
		if okg and gp and not (okk and kb) then return true, G.Enum.PreferredInput.Gamepad end
		return true, G.Enum.PreferredInput.KeyboardAndMouse
	end

	GET.LookVector = function(o)
		if typeof(o) ~= "CFrame" then return false end
		return true, o.lookVector
	end
	GET.RightVector = function(o)
		if typeof(o) ~= "CFrame" then return false end
		return true, o:vectorToWorldSpace(realVector3.new(1, 0, 0))
	end
	GET.UpVector = function(o)
		if typeof(o) ~= "CFrame" then return false end
		return true, o:vectorToWorldSpace(realVector3.new(0, 1, 0))
	end
	GET.XVector = GET.RightVector
	GET.YVector = GET.UpVector
	GET.ZVector = function(o)
		if typeof(o) ~= "CFrame" then return false end
		return true, o:vectorToWorldSpace(realVector3.new(0, 0, 1))
	end
	GET.Rotation = function(o)
		local t = typeof(o)
		if t == "CFrame" then return true, o - o.p end
		return false
	end

	local DEFAULTS = {
		Massless = false, CanTouch = true, CanQuery = true, CastShadow = true, CollisionGroup = "Default", CollisionGroupId = 0,
		IgnoreGuiInset = false, ResetOnSpawn = true, DisplayOrder = 0, UseJumpPower = true, LayoutOrder = 0,
		AutomaticSize = function() return G.Enum.AutomaticSize.None end, RichText = false, MaxVisibleGraphemes = -1,
		Interactable = true, LineHeight = 1, AutoLocalize = true, EvaluateStateMachine = true, RequiresNeck = true,
		BreakJointsOnDeath = true, ScreenInsets = function() return G.Enum.ScreenInsets.CoreUISafeInsets end,
		AutomaticCanvasSize = function() return G.Enum.AutomaticSize.None end, Interpolation = 1,
		CollisionFidelity = function() return G.Enum.CollisionFidelity.Default end, ZOffset = 0,
		TextDirection = function() return G.Enum.TextDirection.Auto end,
		ZIndexBehavior = function() return G.Enum.ZIndexBehavior.Sibling end,
		SafeAreaCompatibility = function() return G.Enum.SafeAreaCompatibility.FullscreenExtension end,
		ClipToDeviceSafeArea = true, ElasticBehavior = function() return G.Enum.ElasticBehavior.WhenScrollable end,
		ScrollingEnabled = true, Enabled = true, Adornee = false, AlwaysOnTop = false, ResampleMode = function() return G.Enum.ResamplerMode.Default end,
		TextTruncate = function() return G.Enum.TextTruncate.None end, FontFace = function() return G.Font.new("SourceSans") end,
		DevCameraOcclusionMode = function() return G.Enum.DevCameraOcclusionMode.Zoom end,
		IsLoaded = true, StreamingEnabled = false, SignalBehavior = function() return G.Enum.SignalBehavior.Deferred end,
		GuiState = function() return G.Enum.GuiState.Idle end, Transparency = 0, PivotOffset = function() return realCFrame.new() end,
		CharacterUseJumpPower = true, MaxSlopeAngle = 89, WalkToPoint = function() return realVector3.new() end,
		RigType = false, SoundGroup = false, Adornee2 = false, LightInfluence = 0, MaxDistance = 0,
		AbsoluteRotation = 0, SelectionImageObject = false, NextSelectionDown = false, NextSelectionUp = false,
		NextSelectionLeft = false, NextSelectionRight = false, Selectable = true, SelectionOrder = 0,
		HoldDuration = 0, MaxActivationDistance = 10, Exclusivity = function() return G.Enum.ProximityPromptExclusivity.OnePerButton end,
		TextureSpeed = 1, TextureLength = 1, Width0 = 1, Width1 = 1, FaceCamera = false, Segments = 10,
		LocalTransparencyModifier = 0, RollOffMode = function() return G.Enum.RollOffMode.Inverse end,
		ContentText = "", TextFits = true, Style = false, PlaybackLoudness = 0, IsPlaying = false, IsPaused = false,
		AudioContent = "", HeadLocked = true, HeadScale = 1, VRDevice = "", DiagonalFieldOfView = 70,
		MaxAxisFieldOfView = 70, Focus = function() return realCFrame.new() end, ViewportSize = function() return realVector2.new(800, 600) end,
	}
	C.DEFAULTS = DEFAULTS

	local EVENT_WORDS = { "Changed", "Added", "Removed", "Finished", "Failed", "Began", "Ended", "Triggered", "Started",
		"Stopped", "Completed", "Activated", "Deactivated", "Clicked", "Opened", "Closed", "Received", "Fired", "Shown", "Hidden",
		"Entered", "Left", "Reached", "Loaded", "Updated", "Event", "Signal", "Requested", "Selected", "Pressed", "Released", "Action", "Moved", "Gesture", "Pinch", "Rotate", "Swipe", "Pan", "Tap", "Joined", "Died", "Touched" }
	function C.looksLikeEvent(k)
		for _, w in ipairs(EVENT_WORDS) do
			if k:sub(-#w) == w then return true end
		end
		return k:sub(1, 2) == "On"
	end
	local dead
	function C.deadSignal()
		dead = dead or C.proxySignal(function() return nil end)
		return dead
	end
	local warned = {}
	function C.warnOnce(k, o)
		local cn = "?"
		pcall(function() cn = o.ClassName end)
		local key = cn .. "." .. k
		if warned[key] then return end
		warned[key] = true
		warn("compat: " .. key .. " is not in this version, using nil")
	end

	local function lowerFirst(k) return k:sub(1, 1):lower() .. k:sub(2) end

	function C.g(o, k)
		local t = type(o)
		if t ~= "userdata" then
			return o[k]
		end
		local get = GET[k]
		if get then
			local handled, v = get(o)
			if handled then return v end
		end
		local ok, v = pcall(idx, o, k)
		if ok then return v end
		if isInstance(o) then
			local dc = dummyClass(o)
			if dc then return C.dummyGet(o, dc, k) end
			local sh = props(o)
			local sv = sh[k]
			if sv ~= nil then return sv end
			if o == game then
				local oks, svc = pcall(C.getService, k)
				if oks and svc then return svc end
			end
			local child = o:FindFirstChild(k)
			if child then return child end
			local d = DEFAULTS[k]
			if d ~= nil then
				if type(d) == "function" then return d(o) end
				if d == false and k ~= "Massless" and k ~= "IgnoreGuiInset" and k ~= "AlwaysOnTop" and k ~= "FaceCamera" then return nil end
				return d
			end
			if EMULATED[k] then return nil end
			if C.looksLikeEvent(k) then return C.deadSignal() end
			C.warnOnce(k, o)
			return nil
		end
		local lk = lowerFirst(k)
		ok, v = pcall(idx, o, lk)
		if ok then return v end
		local tm = C.TYPE_PROPS[typeof(o)]
		if tm and tm[k] then return tm[k](o) end
		error(tostring(k) .. " is not a valid member of " .. typeof(o), 2)
	end

	function C.s(o, k, v, ...)
		local t = type(o)
		if t ~= "userdata" then
			o[k] = v
			return
		end
		if k == "Parent" and type(v) == "table" and rawget(v, "__instance") then v = v:__instance() end
		if type(v) == "string" and C.CONTENT_PROPS[k] then v = C.normalizeContent(v) end
		if isFakeEnum(v) then
			setShadow(o, k, v)
			if C.onFakeEnum then C.onFakeEnum(o, k, v) end
			return
		end
		local dc = dummyClass(o)
		if dc then
			if k == "Parent" or k == "Name" or k == "Archivable" then
				o[k] = v
				if C.dummyParented and k == "Parent" then C.dummyParented(o, dc) end
				return
			end
			C.dummySet(o, dc, k, v)
			return
		end
		local set = SET[k]
		if set and set(o, v, k) then return end
		local ok, err = pcall(C.setidx, o, k, v)
		if ok then
			if C.afterSet then C.afterSet(o, k, v) end
			return
		end
		if not isInstance(o) then error(err, 2) end
		local okr, cur = pcall(idx, o, k)
		if okr then
			if typeof(cur) == "EnumItem" and type(v) == "number" then
				local e = C.coerceEnum(tostring(cur):match("^Enum%.([^.]+)") or "", v)
				if pcall(C.setidx, o, k, e) then return end
			end
			if type(cur) == "number" and type(v) == "boolean" then
				if pcall(C.setidx, o, k, v and 1 or 0) then return end
			end
			if typeof(cur) == "Instance" or cur == nil then
				setShadow(o, k, v)
				return
			end
			if type(v) == "string" and typeof(cur) == "EnumItem" then
				local e = C.coerceEnum(tostring(cur):match("^Enum%.([^.]+)") or "", v)
				if pcall(C.setidx, o, k, e) then return end
			end
			warn("compat: cannot set " .. tostring(k) .. ": " .. tostring(err))
			return
		end
		setShadow(o, k, v)
	end

	C.CONTENT_PROPS = { Image = true, HoverImage = true, PressedImage = true, SelectionImage = true, MeshId = true, TextureId = true,
		TextureID = true, SoundId = true, AnimationId = true, Texture = true, ShirtTemplate = true, PantsTemplate = true, Graphic = true,
		SkyboxBk = true, SkyboxDn = true, SkyboxFt = true, SkyboxLf = true, SkyboxRt = true, SkyboxUp = true, LinkedSource = true,
		ColorMap = true, NormalMap = true, Face = true, ImageContent = true }
	function C.normalizeContent(url)
		local lower = url:lower()
		if lower:find("roblox%.com/") then
			local id = lower:match("roblox%.com/v1/assetid/(%d+)") or (lower:find("roblox%.com/.-asset") and (lower:match("[?&]id=(%d+)")))
			if id then return "rbxassetid://" .. id end
		end
		local id = url:match("^%s*rbxassetid://%s*(%d+)")
		if id then return "rbxassetid://" .. id end
		return url
	end
	local DEAD_CONN = { Disconnect = function() end, disconnect = function() end, Connected = false }
	function C.m(o, name, ...)
		if o == nil and (name == "Connect" or name == "connect" or name == "Once" or name == "ConnectParallel") then
			C.warnOnce(name .. " on nil", game)
			return DEAD_CONN
		end
		local t = type(o)
		if t == "string" then
			local f = C.stringMethods[name]
			if not f then error("attempt to call missing method '" .. tostring(name) .. "' of string", 2) end
			return f(o, ...)
		end
		if t ~= "userdata" then
			local f = o[name]
			if f == nil then
				error("attempt to call missing method '" .. tostring(name) .. "' of " .. t, 2)
			end
			return f(o, ...)
		end
		local inst = isInstance(o)
		if inst then
			local dc = dummyClass(o)
			if dc then
				local def = C.CLASSES[dc]
				local f = def and def.methods and def.methods[name]
				if f then return f(o, ...) end
			end
			if o == game and (name == "GetService" or name == "FindService") then
				return C.getService(...)
			end
			local hook = C.SERVICE_METHODS[o]
			if hook and hook[name] then return hook[name](o, ...) end
		end
		local f = METHODS[name]
		if f then return f(o, ...) end
		local okf, fn = pcall(idx, o, name)
		if okf and fn then
			for i = 1, select("#", ...) do
				if isFakeEnum((select(i, ...))) then
					local res = { pcall(fn, o, ...) }
					if res[1] then return unpack(res, 2) end
					return nil
				end
			end
			return fn(o, ...)
		end
		local lk = lowerFirst(name)
		okf, fn = pcall(idx, o, lk)
		if okf and fn then return fn(o, ...) end
		local tm = C.TYPE_METHODS[typeof(o)]
		if tm and tm[name] then return tm[name](o, ...) end
		if inst then
			local any = C.ANY_METHODS[name]
			if any then return any(o, ...) end
			C.warnOnce(name .. "()", o)
			return nil
		end
		error(tostring(name) .. " is not a valid member of " .. (inst and o.ClassName or typeof(o)), 2)
	end

	function C.iter(x, ...)
		local t = type(x)
		if t == "function" then return x, ... end
		if t == "table" then
			local mt = getmetatable(x)
			if type(mt) == "table" and mt.__iter then return mt.__iter(x) end
			return next, x
		end
		if t == "userdata" then
			return pairs(x)
		end
		error("attempt to iterate over a " .. t .. " value", 2)
	end

	function C.tostr(v)
		if type(v) == "number" then
			if v == math.floor(v) and math.abs(v) < 1e15 then return string.format("%d", v) end
			return tostring(v)
		end
		return tostring(v)
	end

	local TP = {}
	C.TYPE_PROPS = TP
	TP.CFrame = {
		Position = function(o) return o.p end,
		LookVector = function(o) return o.lookVector end,
		RightVector = function(o) return o:vectorToWorldSpace(realVector3.new(1, 0, 0)) end,
		UpVector = function(o) return o:vectorToWorldSpace(realVector3.new(0, 1, 0)) end,
		Rotation = function(o) return o - o.p end,
	}
	TP.Vector3 = {
		Magnitude = function(o) return o.magnitude end,
		Unit = function(o) return o.unit end,
	}
	TP.Vector2 = TP.Vector3
	TP.Color3 = {
		R = function(o) return o.r end, G = function(o) return o.g end, B = function(o) return o.b end,
	}
	TP.EnumItem = {
		EnumType = function(o) return G.Enum[tostring(o):match("^Enum%.([^.]+)") or ""] end,
	}

	local TM = {}
	C.TYPE_METHODS = TM
	TM.CFrame = {
		GetComponents = function(o) return o:components() end,
		ToEulerAnglesYXZ = function(o) return orientationOf(o) end,
		ToOrientation = function(o) return orientationOf(o) end,
		ToEulerAnglesXYZ = function(o) return o:toEulerAnglesXYZ() end,
		ToEulerAngles = function(o) return o:toEulerAnglesXYZ() end,
		ToAxisAngle = function(o)
			local _, _, _, r00, r01, r02, r10, r11, r12, r20, r21, r22 = o:components()
			local angle = math.acos(math.max(-1, math.min(1, (r00 + r11 + r22 - 1) / 2)))
			local axis = realVector3.new(r21 - r12, r02 - r20, r10 - r01)
			if axis.magnitude < 1e-6 then return realVector3.new(1, 0, 0), angle end
			return axis.unit, angle
		end,
		Lerp = function(o, b, t2) return o:lerp(b, t2) end,
		FuzzyEq = function(o, b, eps)
			eps = eps or 1e-5
			local a1 = { o:components() }
			local b1 = { b:components() }
			for i = 1, 12 do if math.abs(a1[i] - b1[i]) > eps then return false end end
			return true
		end,
		Orthonormalize = function(o) return o end,
	}
	TM.Vector3 = {
		Max = function(a, b) return realVector3.new(math.max(a.x, b.x), math.max(a.y, b.y), math.max(a.z, b.z)) end,
		Min = function(a, b) return realVector3.new(math.min(a.x, b.x), math.min(a.y, b.y), math.min(a.z, b.z)) end,
		Abs = function(a) return realVector3.new(math.abs(a.x), math.abs(a.y), math.abs(a.z)) end,
		Floor = function(a) return realVector3.new(math.floor(a.x), math.floor(a.y), math.floor(a.z)) end,
		Ceil = function(a) return realVector3.new(math.ceil(a.x), math.ceil(a.y), math.ceil(a.z)) end,
		Sign = function(a) return realVector3.new(G.math.sign(a.x), G.math.sign(a.y), G.math.sign(a.z)) end,
		Lerp = function(a, b, t2) return a + (b - a) * t2 end,
		Dot = function(a, b) return a.x * b.x + a.y * b.y + a.z * b.z end,
		Cross = function(a, b) return realVector3.new(a.y * b.z - a.z * b.y, a.z * b.x - a.x * b.z, a.x * b.y - a.y * b.x) end,
		FuzzyEq = function(a, b, eps) return (a - b).magnitude <= (eps or 1e-5) end,
		Angle = function(a, b, axis)
			local d = (a.x * b.x + a.y * b.y + a.z * b.z) / math.max(a.magnitude * b.magnitude, 1e-9)
			local ang = math.acos(math.max(-1, math.min(1, d)))
			if axis then
				local c = realVector3.new(a.y * b.z - a.z * b.y, a.z * b.x - a.x * b.z, a.x * b.y - a.y * b.x)
				if c.x * axis.x + c.y * axis.y + c.z * axis.z < 0 then ang = -ang end
			end
			return ang
		end,
	}
	TM.Vector2 = {
		Max = function(a, b) return realVector2.new(math.max(a.x, b.x), math.max(a.y, b.y)) end,
		Min = function(a, b) return realVector2.new(math.min(a.x, b.x), math.min(a.y, b.y)) end,
		Abs = function(a) return realVector2.new(math.abs(a.x), math.abs(a.y)) end,
		Floor = function(a) return realVector2.new(math.floor(a.x), math.floor(a.y)) end,
		Lerp = function(a, b, t2) return a + (b - a) * t2 end,
		Dot = function(a, b) return a.x * b.x + a.y * b.y end,
		Cross = function(a, b) return a.x * b.y - a.y * b.x end,
		Angle = function(a, b) return math.atan2(a.x * b.y - a.y * b.x, a.x * b.x + a.y * b.y) end,
		FuzzyEq = function(a, b, eps) return (a - b).magnitude <= (eps or 1e-5) end,
	}
	local function lerpUDim(a, b, t2) return realUDim.new(a.Scale + (b.Scale - a.Scale) * t2, a.Offset + (b.Offset - a.Offset) * t2) end
	C.lerpUDim = lerpUDim
	TM.UDim2 = {
		Lerp = function(a, b, t2)
			local x, y = lerpUDim(a.X, b.X, t2), lerpUDim(a.Y, b.Y, t2)
			return realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset)
		end,
	}
	TM.Color3 = {
		Lerp = function(a, b, t2) return realColor3.new(a.r + (b.r - a.r) * t2, a.g + (b.g - a.g) * t2, a.b + (b.b - a.b) * t2) end,
		ToHSV = function(a) return G.Color3.toHSV(a) end,
		ToHex = function(a)
			return string.format("%02X%02X%02X", math.floor(a.r * 255 + 0.5), math.floor(a.g * 255 + 0.5), math.floor(a.b * 255 + 0.5))
		end,
	}
	TM.EnumItem = {
		IsA = function(o, n) return tostring(o):match("^Enum%.([^.]+)") == n end,
	}
	TM.Enum = {
		GetEnumItems = function(o) return o:GetEnumItems() end,
	}
	TM.RBXScriptSignal = {
		Connect = function(o, f) return o:connect(f) end,
		Wait = function(o) return o:wait() end,
	}
	TM.RBXScriptConnection = {
		Disconnect = function(o) return o:disconnect() end,
	}

	C.ANY_METHODS = {}
	C.ANY_METHODS.GetStyledPropertyChangedSignal = function(o, k) return METHODS.GetPropertyChangedSignal(o, k) end

	function C.raycast(origin, dir, params)
		local list = {}
		local mode = "exclude"
		if params then
			list = params.FilterDescendantsInstances or {}
			local ft = C.enumName(params.FilterType)
			if ft == "Include" or ft == "Whitelist" then mode = "include" end
		end
		local len = dir.magnitude
		if len > 5000 then dir = dir.unit * 5000 end
		local ray = Ray.new(origin, dir)
		local part, pos, normal, material
		local ignoreWater = params and params.IgnoreWater or false
		if mode == "include" then
			local ok, a, b, c2, d = pcall(workspace.FindPartOnRayWithWhitelist, workspace, ray, list, ignoreWater)
			if ok then
				part, pos, normal, material = a, b, c2, d
			else
				local all = {}
				for _, inst in ipairs(list) do all[#all + 1] = inst end
				part, pos, normal, material = workspace:FindPartOnRayWithIgnoreList(ray, {}, false, ignoreWater)
				if part then
					local keep = false
					for _, inst in ipairs(all) do
						if part == inst or part:IsDescendantOf(inst) then keep = true break end
					end
					if not keep then part = nil end
				end
			end
		else
			local ignore = {}
			for _, inst in ipairs(list) do ignore[#ignore + 1] = inst end
			for _ = 1, 8 do
				part, pos, normal, material = workspace:FindPartOnRayWithIgnoreList(ray, ignore, false, ignoreWater)
				if not part then break end
				local skip = false
				if params and params.RespectCanCollide and not part.CanCollide then skip = true end
				local psh = C.props(part)
				if psh.CanQuery == false then skip = true end
				if not skip then break end
				ignore[#ignore + 1] = part
			end
		end
		if not part then return nil end
		return {
			__type = "RaycastResult",
			Instance = part,
			Position = pos,
			Normal = normal or realVector3.new(0, 1, 0),
			Material = material or part.Material,
			Distance = (pos - origin).magnitude,
		}
	end

	function C.overlap(cf, size, params, shapePart)
		local corners = {}
		local half = size / 2
		for _, sx in ipairs({ -1, 1 }) do
			for _, sy in ipairs({ -1, 1 }) do
				for _, sz in ipairs({ -1, 1 }) do
					corners[#corners + 1] = cf * realVector3.new(half.x * sx, half.y * sy, half.z * sz)
				end
			end
		end
		local lo, hi = corners[1], corners[1]
		for _, p in ipairs(corners) do
			lo = realVector3.new(math.min(lo.x, p.x), math.min(lo.y, p.y), math.min(lo.z, p.z))
			hi = realVector3.new(math.max(hi.x, p.x), math.max(hi.y, p.y), math.max(hi.z, p.z))
		end
		local region = Region3.new(lo, hi)
		local list = params and params.FilterDescendantsInstances or {}
		local ft = params and C.enumName(params.FilterType)
		local found
		if ft == "Include" or ft == "Whitelist" then
			local ok, r = pcall(workspace.FindPartsInRegion3WithWhiteList, workspace, region, list, params.MaxParts and params.MaxParts > 0 and params.MaxParts or 1000)
			found = ok and r or {}
		else
			local ignore = {}
			for _, i in ipairs(list) do ignore[#ignore + 1] = i end
			if shapePart then ignore[#ignore + 1] = shapePart end
			found = workspace:FindPartsInRegion3WithIgnoreList(region, ignore, params and params.MaxParts and params.MaxParts > 0 and params.MaxParts or 1000)
		end
		local out = {}
		for _, p in ipairs(found) do
			local psh = C.props(p)
			if psh.CanQuery ~= false then out[#out + 1] = p end
		end
		return out
	end
end
