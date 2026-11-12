do
	local PREFIX = "__cv:"
	local function fmt(n) return string.format("%.9g", n) end

	local function encode(v)
		local t = typeof(v)
		if t == "string" then return "StringValue", v end
		if t == "number" then return "NumberValue", v end
		if t == "boolean" then return "BoolValue", v end
		if t == "Vector3" then return "Vector3Value", v end
		if t == "Color3" then return "Color3Value", v end
		if t == "CFrame" then return "CFrameValue", v end
		if t == "BrickColor" then return "BrickColorValue", v end
		if t == "Instance" then return "ObjectValue", v end
		local s
		if t == "UDim2" then
			s = "UDim2:" .. fmt(v.X.Scale) .. "," .. fmt(v.X.Offset) .. "," .. fmt(v.Y.Scale) .. "," .. fmt(v.Y.Offset)
		elseif t == "UDim" then
			s = "UDim:" .. fmt(v.Scale) .. "," .. fmt(v.Offset)
		elseif t == "Vector2" then
			s = "Vector2:" .. fmt(v.x) .. "," .. fmt(v.y)
		elseif t == "NumberRange" then
			s = "NumberRange:" .. fmt(v.Min) .. "," .. fmt(v.Max)
		elseif t == "EnumItem" then
			local name = C.enumName(v)
			local tn = type(v) == "table" and v.EnumTypeName or tostring(v):match("^Enum%.([^.]+)")
			s = "Enum:" .. tostring(tn) .. "." .. tostring(name)
		elseif t == "ColorSequence" then
			local parts = {}
			for _, k in ipairs(v.Keypoints) do
				parts[#parts + 1] = fmt(k.Time) .. "," .. fmt(k.Value.r) .. "," .. fmt(k.Value.g) .. "," .. fmt(k.Value.b)
			end
			s = "CS:" .. table.concat(parts, ";")
		elseif t == "NumberSequence" then
			local parts = {}
			for _, k in ipairs(v.Keypoints) do
				parts[#parts + 1] = fmt(k.Time) .. "," .. fmt(k.Value) .. "," .. fmt(k.Envelope)
			end
			s = "NS:" .. table.concat(parts, ";")
		elseif t == "Font" then
			s = "Font:" .. tostring(v.Family)
		elseif t == "Rect" then
			s = "Rect:" .. fmt(v.Min.x) .. "," .. fmt(v.Min.y) .. "," .. fmt(v.Max.x) .. "," .. fmt(v.Max.y)
		else
			return nil
		end
		return "StringValue", PREFIX .. s
	end
	C.encode = encode

	local function nums(s)
		local out = {}
		for n in s:gmatch("[^,;]+") do out[#out + 1] = tonumber(n) or 0 end
		return out
	end

	local function decodeString(s)
		if s:sub(1, #PREFIX) ~= PREFIX then return s end
		local kind, rest = s:sub(#PREFIX + 1):match("^([^:]+):(.*)$")
		if not kind then return s end
		local n = nums(rest)
		if kind == "UDim2" then return realUDim2.new(n[1], n[2], n[3], n[4]) end
		if kind == "UDim" then return realUDim.new(n[1], n[2]) end
		if kind == "Vector2" then return realVector2.new(n[1], n[2]) end
		if kind == "NumberRange" then return NumberRange.new(n[1], n[2]) end
		if kind == "Enum" then return C.enum(rest) end
		if kind == "EnumNum" then return tonumber(rest) end
		if kind == "Font" then return G.Font.new(rest) end
		if kind == "Rect" then return G.Rect.new(n[1], n[2], n[3], n[4]) end
		if kind == "CS" then
			local kps = {}
			for i = 1, #n, 4 do kps[#kps + 1] = ColorSequenceKeypoint.new(n[i], realColor3.new(n[i + 1], n[i + 2], n[i + 3])) end
			if #kps < 2 then kps[2] = ColorSequenceKeypoint.new(1, kps[1] and kps[1].Value or realColor3.new(1, 1, 1)) end
			kps[1] = ColorSequenceKeypoint.new(0, kps[1].Value)
			kps[#kps] = ColorSequenceKeypoint.new(1, kps[#kps].Value)
			local ok, cs = pcall(ColorSequence.new, kps)
			if ok then return cs end
			return ColorSequence.new(kps[1].Value)
		end
		if kind == "NS" then
			local kps = {}
			for i = 1, #n, 3 do kps[#kps + 1] = NumberSequenceKeypoint.new(n[i], n[i + 1], n[i + 2]) end
			local ok, ns = pcall(NumberSequence.new, kps)
			if ok then return ns end
			return NumberSequence.new(n[2] or 0)
		end
		return s
	end

	local function decodeValue(obj)
		local ok, v = pcall(idx, obj, "Value")
		if not ok then return nil end
		if type(v) == "string" then return decodeString(v) end
		return v
	end
	C.decodeValue = decodeValue

	local function writeValue(folder, name, v)
		local existing = folder:FindFirstChild(name)
		if v == nil then
			if existing then existing:Destroy() end
			return
		end
		local class, raw = encode(v)
		if not class then return end
		if existing and existing.ClassName ~= class then
			existing:Destroy()
			existing = nil
		end
		if not existing then
			existing = realInstance.new(class)
			existing.Name = name
			existing.Value = raw
			existing.Parent = folder
		elseif existing.Value ~= raw then
			existing.Value = raw
		end
	end

	local function folderOf(o, name, create)
		local f = o:FindFirstChild(name)
		if not f and create then
			f = realInstance.new("Configuration")
			f.Name = name
			f.Parent = o
		end
		return f
	end
	C.folderOf = folderOf

	local shadow = weak()
	local function props(o)
		local sh = shadow[o]
		if sh then return sh end
		sh = {}
		shadow[o] = sh
		local f = o:FindFirstChild("__compat_props")
		if f then
			for _, v in ipairs(f:GetChildren()) do sh[v.Name] = decodeValue(v) end
		end
		return sh
	end
	C.props = props

	local propSignals = weak()
	local function propSignal(o, k)
		local map = propSignals[o]
		if not map then
			map = {}
			propSignals[o] = map
		end
		local s = map[k]
		if not s then
			s = Signal.new()
			map[k] = s
		end
		return s
	end
	C.propSignal = propSignal
	local function fireProp(o, k)
		local map = propSignals[o]
		if map and map[k] then map[k]:Fire() end
		local cs = C.changedSignals and C.changedSignals[o]
		if cs then cs:Fire(k) end
	end
	C.fireProp = fireProp

	C.NO_WRITE_THROUGH = { AbsoluteContentSize = true, CurrentPage = true, AbsoluteCellSize = true, AbsoluteCellCount = true }
	local function setShadow(o, k, v)
		local sh = props(o)
		if sh[k] == v then return end
		sh[k] = v
		if not C.NO_WRITE_THROUGH[k] then
			pcall(function() writeValue(folderOf(o, "__compat_props", true), k, v) end)
		end
		fireProp(o, k)
		if C.onShadow then C.onShadow(o, k, v) end
	end
	C.setShadow = setShadow

	local classCache = weak()
	local function dummyClass(o)
		local c = classCache[o]
		if c ~= nil then return c or nil end
		c = false
		local ok, cn = pcall(idx, o, "ClassName")
		if ok and (cn == "Configuration" or cn == "Folder") then
			local marker = o:FindFirstChild("__compat_class")
			if marker then c = marker.Value end
		end
		classCache[o] = c
		return c or nil
	end
	C.dummyClass = dummyClass

	C.CLASSES = {}
	local CLASS_PARENTS = {
		UIListLayout = "UIGridStyleLayout", UIGridLayout = "UIGridStyleLayout", UIPageLayout = "UIGridStyleLayout", UITableLayout = "UIGridStyleLayout",
		UIGridStyleLayout = "UILayout", UILayout = "UIComponent",
		UIPadding = "UIComponent", UIScale = "UIComponent", UICorner = "UIComponent", UIStroke = "UIComponent", UIGradient = "UIComponent",
		UIAspectRatioConstraint = "UIConstraint", UISizeConstraint = "UIConstraint", UITextSizeConstraint = "UIConstraint", UIConstraint = "UIComponent",
		UIFlexItem = "UIComponent", UIDragDetector = "UIComponent", UIComponent = "UIBase", UIBase = "Instance",
		WeldConstraint = "Constraint", NoCollisionConstraint = "Constraint", RigidConstraint = "Constraint",
		AlignPosition = "Constraint", AlignOrientation = "Constraint", LinearVelocity = "Constraint", AngularVelocity = "Constraint",
		VectorForce = "Constraint", Torque = "Constraint", LineForce = "Constraint", PlaneConstraint = "Constraint", Constraint = "Instance",
		Beam = "Instance", Trail = "Instance", Highlight = "Instance", ProximityPrompt = "Instance",
		CanvasGroup = "GuiObject", GuiObject = "GuiBase2d", GuiBase2d = "GuiBase", GuiBase = "Instance",
		TextChatCommand = "Instance", TextChannel = "Instance", Bone = "Attachment", SurfaceAppearance = "Instance",
		Atmosphere = "Instance", Clouds = "Instance", Sky = "Instance", SunRaysEffect = "PostEffect", DepthOfFieldEffect = "PostEffect", PostEffect = "Instance",
		SoundGroup = "Instance", SoundEffect = "Instance", ReverbSoundEffect = "SoundEffect", EqualizerSoundEffect = "SoundEffect",
		ChorusSoundEffect = "SoundEffect", PitchShiftSoundEffect = "SoundEffect", DistortionSoundEffect = "SoundEffect",
		HumanoidDescription = "Instance", Actor = "Model", WorldModel = "Model", PathfindingModifier = "Instance",
		WrapLayer = "Instance", WrapTarget = "Instance", MaterialVariant = "Instance", Wedge = "Instance",
		AudioPlayer = "Instance", AudioEmitter = "Instance", AudioListener = "Instance", AudioDeviceOutput = "Instance",
		RemoteFunction = "Instance", UnreliableRemoteEvent = "BaseRemoteEvent", BaseRemoteEvent = "Instance",
		IntConstrainedValue = "ValueBase", DoubleConstrainedValue = "ValueBase", ValueBase = "Instance",
		Model = "PVInstance", PVInstance = "Instance",
	}
	C.CLASS_PARENTS = CLASS_PARENTS

	local function classIsA(cls, want)
		while cls do
			if cls == want then return true end
			cls = CLASS_PARENTS[cls]
		end
		return want == "Instance"
	end
	C.classIsA = classIsA

	local function makeDummy(class, parent)
		local container = "Configuration"
		if class == "Actor" or class == "WorldModel" then container = "Model" end
		local o = realInstance.new(container)
		o.Name = class
		local marker = realInstance.new("StringValue")
		marker.Name = "__compat_class"
		marker.Value = class
		marker.Parent = o
		classCache[o] = class
		local def = C.CLASSES[class]
		if def and def.defaults then
			local sh = props(o)
			for k, v in pairs(def.defaults) do
				if sh[k] == nil then sh[k] = v end
			end
		end
		if def and def.init then def.init(o) end
		if parent then o.Parent = parent end
		return o
	end
	C.makeDummy = makeDummy

	local CREATE_MAP = {
		UnreliableRemoteEvent = "RemoteEvent",
		WeldConstraint = "ManualWeld",
	}

	local newInstance = {}
	function newInstance.new(class, parent)
		local mapped = CREATE_MAP[class]
		if mapped then
			local o = realInstance.new(mapped)
			if class == "WeldConstraint" then
				classCache[o] = false
				C.weldConstraints[o] = true
			end
			if parent then o.Parent = parent end
			return o
		end
		local ok, o = pcall(realInstance.new, class)
		if ok and o then
			if parent then o.Parent = parent end
			return o
		end
		return makeDummy(class, parent)
	end
	newInstance.fromExisting = function(o) return o:Clone() end
	G.Instance = newInstance
	C.weldConstraints = weak()

	local function attrFolder(o, create)
		return folderOf(o, "__compat_attrs", create)
	end

	local attrSignals = weak()
	local function watchAttrs(o)
		local w = attrSignals[o]
		if w then return w end
		w = { byName = {}, any = Signal.new(), hooked = false }
		attrSignals[o] = w
		local function fire(name)
			local s = w.byName[name]
			if s then s:Fire() end
			w.any:Fire(name)
		end
		local valueConns = {}
		local function hookValue(v)
			if valueConns[v] then return end
			valueConns[v] = v.Changed:connect(function() fire(v.Name) end)
		end
		local function hookFolder(f)
			for _, v in ipairs(f:GetChildren()) do hookValue(v) end
			f.ChildAdded:connect(function(v)
				hookValue(v)
				fire(v.Name)
			end)
			f.ChildRemoved:connect(function(v)
				if valueConns[v] then valueConns[v]:disconnect() valueConns[v] = nil end
				fire(v.Name)
			end)
		end
		local f = attrFolder(o, false)
		if f then
			hookFolder(f)
		else
			local conn
			conn = o.ChildAdded:connect(function(child)
				if child.Name == "__compat_attrs" then
					conn:disconnect()
					hookFolder(child)
					for _, v in ipairs(child:GetChildren()) do fire(v.Name) end
				end
			end)
		end
		return w
	end

	C.watchAttrsAny = function(o) return watchAttrs(o).any end

	local function getAttribute(o, name)
		local f = attrFolder(o, false)
		if not f then return nil end
		local v = f:FindFirstChild(name)
		if not v then return nil end
		return decodeValue(v)
	end
	C.getAttribute = getAttribute
	local function setAttribute(o, name, value)
		local f = attrFolder(o, value ~= nil)
		if not f then return end
		writeValue(f, name, value)
	end
	C.setAttribute = setAttribute

	local function filtered(list)
		local out = {}
		for _, c in ipairs(list) do
			if not hidden(c) then out[#out + 1] = c end
		end
		return out
	end
	C.filtered = filtered

	local function descendants(o, out)
		out = out or {}
		for _, c in ipairs(o:GetChildren()) do
			if not hidden(c) then
				out[#out + 1] = c
				descendants(c, out)
			end
		end
		return out
	end
	C.descendants = descendants

	local aliasCache = weak()
	local function aliasClass(o)
		local c = aliasCache[o]
		if c ~= nil then return c or nil end
		c = false
		local ok, cn = pcall(idx, o, "ClassName")
		if ok and (cn == "Part" or cn == "Frame") then
			local marker = o:FindFirstChild("__compat_class")
			if marker then c = marker.Value end
		elseif ok and cn == "ManualWeld" then
			if o:FindFirstChild("__compat_wc") then c = "WeldConstraint" end
		end
		aliasCache[o] = c
		return c or nil
	end
	C.aliasClass = aliasClass
	C.isWeldConstraint = function(o)
		return C.weldConstraints[o] == true or aliasClass(o) == "WeldConstraint"
	end

	local function realIsA(o, class)
		local dc = dummyClass(o)
		if dc then return classIsA(dc, class) end
		local ac = aliasClass(o)
		if ac then
			if ac == class then return true end
			if ac == "UnionOperation" and (class == "PartOperation" or class == "TriangleMeshPart") then return true end
			if ac == "MeshPart" and class == "TriangleMeshPart" then return true end
			if class == "Part" then return false end
		end
		if C.weldConstraints[o] or ac == "WeldConstraint" then return class == "WeldConstraint" or class == "Constraint" or class == "Instance" end
		local ok, r = pcall(o.IsA, o, class)
		if ok and r then return true end
		if class == "Constraint" or class == "PVInstance" then
			local okc, cn = pcall(idx, o, "ClassName")
			if okc and class == "PVInstance" then return o:IsA("BasePart") or o:IsA("Model") end
		end
		return false
	end
	C.isA = realIsA

	local function className(o)
		local dc = dummyClass(o)
		if dc then return dc end
		local ac = aliasClass(o)
		if ac then return ac end
		if C.weldConstraints[o] then return "WeldConstraint" end
		return o.ClassName
	end
	C.className = className

	local tags = {}
	tags.inited = false
	tags.sets = {}
	tags.added = {}
	tags.removed = {}
	tags.byInst = weak()

	local function tagSignal(map, tag)
		local s = map[tag]
		if not s then
			s = Signal.new()
			map[tag] = s
		end
		return s
	end

	local function registerTag(inst, tag)
		local set = tags.sets[tag]
		if not set then
			set = {}
			tags.sets[tag] = set
		end
		if set[inst] then return end
		set[inst] = true
		local mine = tags.byInst[inst]
		if not mine then
			mine = {}
			tags.byInst[inst] = mine
		end
		mine[tag] = true
		if tags.added[tag] then tags.added[tag]:Fire(inst) end
	end
	local function unregisterTag(inst, tag)
		local set = tags.sets[tag]
		if not set or not set[inst] then return end
		set[inst] = nil
		local mine = tags.byInst[inst]
		if mine then mine[tag] = nil end
		if tags.removed[tag] then tags.removed[tag]:Fire(inst) end
	end

	local function hookTagFolder(folder)
		local inst = folder.Parent
		if not inst then return end
		for _, v in ipairs(folder:GetChildren()) do registerTag(inst, v.Name) end
	end

	local function scan(o)
		local ok, children = pcall(o.GetChildren, o)
		if not ok then return end
		for _, c in ipairs(children) do
			local okn, n = pcall(idx, c, "Name")
			if okn and n == "__compat_tags" then
				hookTagFolder(c)
			elseif okn then
				scan(c)
			end
		end
	end

	local function initTags()
		if tags.inited then return end
		tags.inited = true
		for _, s in ipairs({ "Workspace", "ReplicatedStorage", "ReplicatedFirst", "Lighting", "StarterGui", "StarterPack", "StarterPlayer", "Players", "SoundService", "Teams" }) do
			local ok, svc = pcall(game.GetService, game, s)
			if ok and svc then scan(svc) end
		end
		if IS_SERVER then
			for _, s in ipairs({ "ServerStorage", "ServerScriptService" }) do
				local ok, svc = pcall(game.GetService, game, s)
				if ok and svc then scan(svc) end
			end
		end
		game.DescendantAdded:connect(function(d)
			local okn, n = pcall(idx, d, "Name")
			if not okn then return end
			if n == "__compat_tags" then
				hookTagFolder(d)
				return
			end
			local p = d.Parent
			if p and p.Name == "__compat_tags" and p.Parent then
				registerTag(p.Parent, n)
				return
			end
			if n:find(HIDDEN) then return end
			local f = d:FindFirstChild("__compat_tags")
			if f then hookTagFolder(f) end
		end)
		game.DescendantRemoving:connect(function(d)
			local mine = tags.byInst[d]
			if mine then
				local list = {}
				for tag in pairs(mine) do list[#list + 1] = tag end
				for _, tag in ipairs(list) do
					local set = tags.sets[tag]
					if set then set[d] = nil end
					if tags.removed[tag] then tags.removed[tag]:Fire(d) end
				end
				tags.byInst[d] = nil
				return
			end
			local okn, n = pcall(idx, d, "Name")
			if okn then
				local p = d.Parent
				if p and p.Name == "__compat_tags" and p.Parent then
					delay(0, function()
						if d.Parent ~= p then unregisterTag(p.Parent, n) end
					end)
				end
			end
		end)
	end

	local CollectionService = {}
	C.CollectionService = CollectionService
	function CollectionService:AddTag(inst, tag)
		initTags()
		local f = folderOf(inst, "__compat_tags", true)
		if not f:FindFirstChild(tag) then
			local v = realInstance.new("BoolValue")
			v.Name = tag
			v.Value = true
			v.Parent = f
		end
		registerTag(inst, tag)
	end
	function CollectionService:RemoveTag(inst, tag)
		initTags()
		local f = folderOf(inst, "__compat_tags", false)
		if f then
			local v = f:FindFirstChild(tag)
			if v then v:Destroy() end
		end
		unregisterTag(inst, tag)
	end
	function CollectionService:HasTag(inst, tag)
		local f = folderOf(inst, "__compat_tags", false)
		return f ~= nil and f:FindFirstChild(tag) ~= nil
	end
	function CollectionService:GetTags(inst)
		local out = {}
		local f = folderOf(inst, "__compat_tags", false)
		if f then for _, v in ipairs(f:GetChildren()) do out[#out + 1] = v.Name end end
		return out
	end
	function CollectionService:GetTagged(tag)
		initTags()
		local out = {}
		local set = tags.sets[tag]
		if set then
			for inst in pairs(set) do
				if inst.Parent and inst:IsDescendantOf(game) then out[#out + 1] = inst end
			end
		end
		return out
	end
	function CollectionService:GetAllTags()
		initTags()
		local out = {}
		for tag in pairs(tags.sets) do out[#out + 1] = tag end
		return out
	end
	function CollectionService:GetInstanceAddedSignal(tag)
		initTags()
		return tagSignal(tags.added, tag)
	end
	function CollectionService:GetInstanceRemovedSignal(tag)
		initTags()
		return tagSignal(tags.removed, tag)
	end
	CollectionService.TagAdded = Signal.new()
	CollectionService.TagRemoved = Signal.new()
	CollectionService.ClassName = "CollectionService"
	CollectionService.Name = "CollectionService"

	local METHODS = {}
	C.METHODS = METHODS

	function METHODS.Connect(o, fn) return o:connect(fn) end
	function METHODS.ConnectParallel(o, fn) return o:connect(fn) end
	function METHODS.Once(o, fn)
		local conn
		local done = false
		conn = o:connect(function(...)
			if done then return end
			done = true
			if conn then conn:disconnect() end
			fn(...)
		end)
		return conn
	end
	function METHODS.Wait(o, ...)
		local ok, w = pcall(idx, o, "wait")
		if ok and w then return w(o, ...) end
		return o:Wait(...)
	end
	function METHODS.Disconnect(o) return o:disconnect() end

	function METHODS.GetChildren(o) return filtered(o:GetChildren()) end
	function METHODS.getChildren(o) return filtered(o:GetChildren()) end
	function METHODS.children(o) return filtered(o:GetChildren()) end
	function METHODS.GetDescendants(o) return descendants(o) end
	function METHODS.ClearAllChildren(o)
		for _, c in ipairs(o:GetChildren()) do
			if not hidden(c) then pcall(c.Destroy, c) end
		end
	end

	local function animatorStandIn(o, name)
		if name ~= "Animator" then return nil end
		local ok, cn = pcall(idx, o, "ClassName")
		if ok and (cn == "Humanoid" or cn == "AnimationController") then
			local real = o:FindFirstChild("Animator")
			if real then return real end
			return o
		end
		return nil
	end

	function METHODS.WaitForChild(o, name, timeout)
		local found = o:FindFirstChild(name)
		if found then return found end
		local standIn = animatorStandIn(o, name)
		if standIn then return standIn end
		if o == game then
			local ok, svc = pcall(C.getService, name)
			if ok and svc then return svc end
		end
		if timeout then
			local start = tick()
			while tick() - start < timeout do
				found = o:FindFirstChild(name)
				if found then return found end
				wait(0.05)
			end
			return nil
		end
		return o:WaitForChild(name)
	end
	function METHODS.FindFirstChild(o, name, recursive)
		local found = o:FindFirstChild(name, recursive)
		if found then return found end
		return animatorStandIn(o, name)
	end
	function METHODS.FindFirstChildOfClass(o, class)
		for _, c in ipairs(o:GetChildren()) do
			if not hidden(c) and className(c) == class then return c end
		end
		return animatorStandIn(o, class)
	end
	function METHODS.FindFirstChildWhichIsA(o, class, recursive)
		for _, c in ipairs(o:GetChildren()) do
			if not hidden(c) and realIsA(c, class) then return c end
		end
		if recursive then
			for _, c in ipairs(o:GetChildren()) do
				if not hidden(c) then
					local r = METHODS.FindFirstChildWhichIsA(c, class, true)
					if r then return r end
				end
			end
		end
		return animatorStandIn(o, class)
	end
	function METHODS.FindFirstDescendant(o, name)
		return o:FindFirstChild(name, true)
	end
	function METHODS.FindFirstAncestor(o, name)
		local p = o.Parent
		while p do
			if p.Name == name then return p end
			p = p.Parent
		end
		return nil
	end
	function METHODS.FindFirstAncestorOfClass(o, class)
		local p = o.Parent
		while p do
			if className(p) == class then return p end
			p = p.Parent
		end
		return nil
	end
	function METHODS.FindFirstAncestorWhichIsA(o, class)
		local p = o.Parent
		while p do
			if realIsA(p, class) then return p end
			p = p.Parent
		end
		return nil
	end
	function METHODS.IsA(o, class)
		if not isInstance(o) then
			local ok, f = pcall(idx, o, "IsA")
			if ok and f then return f(o, class) end
			return typeof(o) == class
		end
		return realIsA(o, class)
	end
	function METHODS.IsPropertyModified() return false end
	function METHODS.ResetPropertyToDefault() end

	function METHODS.GetAttribute(o, name) return getAttribute(o, name) end
	function METHODS.SetAttribute(o, name, value) return setAttribute(o, name, value) end
	function METHODS.GetAttributes(o)
		local out = {}
		local f = attrFolder(o, false)
		if f then for _, v in ipairs(f:GetChildren()) do out[v.Name] = decodeValue(v) end end
		return out
	end
	function METHODS.GetAttributeChangedSignal(o, name)
		local w = watchAttrs(o)
		local s = w.byName[name]
		if not s then
			s = Signal.new()
			w.byName[name] = s
		end
		return s
	end

	function METHODS.AddTag(o, tag) return CollectionService:AddTag(o, tag) end
	function METHODS.RemoveTag(o, tag) return CollectionService:RemoveTag(o, tag) end
	function METHODS.HasTag(o, tag) return CollectionService:HasTag(o, tag) end
	function METHODS.GetTags(o) return CollectionService:GetTags(o) end

	C.changedSignals = weak()
	function METHODS.GetPropertyChangedSignal(o, prop)
		local dc = dummyClass(o)
		if dc then return propSignal(o, prop) end
		local isValue = false
		pcall(function() isValue = o:IsA("ValueBase") end)
		local emulated = C.EMULATED_PROPS[prop]
		return C.proxySignal(function(fn)
			local conns = {}
			if emulated then
				conns[#conns + 1] = propSignal(o, prop):Connect(fn)
			end
			local alias = C.PROP_ALIASES[prop]
			if isValue and prop == "Value" then
				conns[#conns + 1] = o.Changed:connect(function() fn() end)
			elseif not emulated or alias then
				conns[#conns + 1] = o.Changed:connect(function(p)
					if p == prop or (alias and p == alias) then fn() end
				end)
			end
			return { Disconnect = function()
				for _, c in ipairs(conns) do
					if type(c) == "table" then c:Disconnect() else c:disconnect() end
				end
			end }
		end)
	end

	function METHODS.GetPivot(o)
		if o:IsA("BasePart") then return o.CFrame end
		local primary = o.PrimaryPart
		if primary then return primary.CFrame end
		local ok, cf = pcall(o.GetModelCFrame, o)
		if ok then return cf end
		return realCFrame.new()
	end
	function METHODS.PivotTo(o, cf)
		if o:IsA("BasePart") then
			o.CFrame = cf
			return
		end
		if o.PrimaryPart then
			o:SetPrimaryPartCFrame(cf)
			return
		end
		local current = METHODS.GetPivot(o)
		local delta = cf * current:inverse()
		for _, d in ipairs(descendants(o)) do
			if d:IsA("BasePart") then d.CFrame = delta * d.CFrame end
		end
	end
	function METHODS.GetBoundingBox(o)
		local okc, cf = pcall(o.GetModelCFrame, o)
		local oks, size = pcall(o.GetExtentsSize, o)
		return okc and cf or realCFrame.new(), oks and size or realVector3.new()
	end
	function METHODS.ScaleTo() end
	function METHODS.GetScale() return 1 end
	function METHODS.GetNetworkOwner() return nil end
	function METHODS.SetNetworkOwner(o, p) pcall(o.SetNetworkOwner, o, p) end
	function METHODS.SetNetworkOwnershipAuto(o) pcall(o.SetNetworkOwnershipAuto, o) end
	function METHODS.CanSetNetworkOwnership(o)
		local ok, r = pcall(o.CanSetNetworkOwnership, o)
		if ok then return r end
		return o:IsDescendantOf(workspace) and not o.Anchored
	end
	function METHODS.GetMass(o)
		local ok, r = pcall(o.GetMass, o)
		if ok then return r end
		return o.Size.x * o.Size.y * o.Size.z * 0.7
	end
	function METHODS.ApplyImpulse(o, impulse)
		local mass = METHODS.GetMass(o)
		o.Velocity = o.Velocity + impulse / math.max(mass, 0.001)
	end
	function METHODS.ApplyAngularImpulse(o, impulse)
		local mass = METHODS.GetMass(o)
		o.RotVelocity = o.RotVelocity + impulse / math.max(mass, 0.001)
	end
	function METHODS.GetVelocityAtPosition(o) return o.Velocity end
	function METHODS.GetRootPart(o)
		local ok, r = pcall(o.GetRootPart, o)
		if ok then return r end
		return o
	end
	function METHODS.GetConnectedParts(o, recursive)
		local ok, r = pcall(o.GetConnectedParts, o, recursive)
		if ok then return r end
		return { o }
	end
	function METHODS.GetTouchingParts(o)
		local ok, r = pcall(o.GetTouchingParts, o)
		if ok then return r end
		return {}
	end
	function METHODS.GetFullName(o)
		local ok, r = pcall(o.GetFullName, o)
		if ok then return r end
		return o.Name
	end
	function METHODS.GetActor() return nil end
	function METHODS.GetStyled(o, k) return C.g(o, k) end
	function METHODS.SetStyled(o, k, v) return C.s(o, k, v) end

	function METHODS.Raycast(o, origin, dir, params)
		return C.raycast(origin, dir, params)
	end
	function METHODS.Blockcast(o, cf, size, dir, params)
		return C.raycast(cf.p, dir, params)
	end
	function METHODS.Spherecast(o, pos, radius, dir, params)
		return C.raycast(pos, dir, params)
	end
	function METHODS.Shapecast(o, part, dir, params)
		return C.raycast(part.Position, dir, params)
	end

	function METHODS.GetPartBoundsInBox(o, cf, size, params)
		return C.overlap(cf, size, params)
	end
	function METHODS.GetPartBoundsInRadius(o, pos, radius, params)
		return C.overlap(realCFrame.new(pos), realVector3.new(radius * 2, radius * 2, radius * 2), params)
	end
	function METHODS.GetPartsInPart(o, part, params)
		return C.overlap(part.CFrame, part.Size, params, part)
	end
	function METHODS.GetServerTimeNow() return tick() end
	function METHODS.ArePartsTouchingOthers() return false end
end
