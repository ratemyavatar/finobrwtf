do
	local SERVICE_METHODS = setmetatable({}, { __mode = "k" })
	C.SERVICE_METHODS = SERVICE_METHODS
	local services = {}

	local EASE = {}
	local function outBounce(t)
		if t < 1 / 2.75 then return 7.5625 * t * t end
		if t < 2 / 2.75 then t = t - 1.5 / 2.75 return 7.5625 * t * t + 0.75 end
		if t < 2.5 / 2.75 then t = t - 2.25 / 2.75 return 7.5625 * t * t + 0.9375 end
		t = t - 2.625 / 2.75
		return 7.5625 * t * t + 0.984375
	end
	EASE.Linear = function(t) return t end
	EASE.Sine = function(t) return 1 - math.cos(t * math.pi / 2) end
	EASE.Quad = function(t) return t * t end
	EASE.Cubic = function(t) return t * t * t end
	EASE.Quart = function(t) return t * t * t * t end
	EASE.Quint = function(t) return t * t * t * t * t end
	EASE.Exponential = function(t) if t == 0 then return 0 end return 2 ^ (10 * t - 10) end
	EASE.Circular = function(t) return 1 - math.sqrt(math.max(0, 1 - t * t)) end
	EASE.Back = function(t) local s = 1.70158 return t * t * ((s + 1) * t - s) end
	EASE.Bounce = function(t) return 1 - outBounce(1 - t) end
	EASE.Elastic = function(t)
		if t == 0 or t == 1 then return t end
		return -(2 ^ (10 * t - 10)) * math.sin((t * 10 - 10.75) * (2 * math.pi) / 3)
	end
	local function ease(style, direction, t)
		local f = EASE[C.enumName(style) or "Quad"] or EASE.Quad
		local dir = C.enumName(direction) or "Out"
		if dir == "In" then return f(t) end
		if dir == "InOut" then
			if t < 0.5 then return f(t * 2) / 2 end
			return 1 - f((1 - t) * 2) / 2
		end
		return 1 - f(1 - t)
	end
	C.ease = ease

	local function lerpValue(a, b, t)
		local ta = typeof(a)
		if ta == "number" then return a + (b - a) * t end
		if ta == "Vector3" or ta == "Vector2" then return a + (b - a) * t end
		if ta == "CFrame" then return a:lerp(b, t) end
		if ta == "Color3" then return realColor3.new(a.r + (b.r - a.r) * t, a.g + (b.g - a.g) * t, a.b + (b.b - a.b) * t) end
		if ta == "UDim2" then return C.TYPE_METHODS.UDim2.Lerp(a, b, t) end
		if ta == "UDim" then return C.lerpUDim(a, b, t) end
		if ta == "NumberRange" then return NumberRange.new(a.Min + (b.Min - a.Min) * t, a.Max + (b.Max - a.Max) * t) end
		if t >= 1 then return b end
		return a
	end
	C.lerpValue = lerpValue

	local active = {}
	local driver
	local function step()
		local now = tick()
		local i = 1
		while i <= #active do
			local tw = active[i]
			if rawget(tw, "_st") ~= "Playing" then
				table.remove(active, i)
			else
				local info = tw.TweenInfo
				local elapsed = now - tw.started - (info.DelayTime or 0)
				if elapsed >= 0 then
					local dur = math.max(info.Time, 0)
					local alpha = dur > 0 and math.min(elapsed / dur, 1) or 1
					local forward = not tw.reversing
					local a = forward and alpha or 1 - alpha
					local e = ease(info.EasingStyle, info.EasingDirection, a)
					for k, target in pairs(tw.goals) do
						local from = tw.from[k]
						if from ~= nil then
							local ok = pcall(C.s, tw.Instance, k, lerpValue(from, target, e))
							if not ok then tw.goals[k] = nil end
						end
					end
					if alpha >= 1 then
						if info.Reverses and forward then
							tw.reversing = true
							tw.started = now - (info.DelayTime or 0)
						elseif info.RepeatCount ~= 0 and (info.RepeatCount < 0 or tw.repeats < info.RepeatCount) then
							tw.repeats = tw.repeats + 1
							tw.reversing = false
							tw.started = now
						else
							rawset(tw, "_st", "Completed")
							table.remove(active, i)
							tw.Completed:Fire(G.Enum.PlaybackState.Completed)
							i = i - 1
						end
					end
				end
				i = i + 1
			end
		end
		if #active == 0 and driver then
			driver:disconnect()
			driver = nil
		end
	end
	local function ensureDriver()
		if driver then return end
		local sig = RunService.Heartbeat
		if IS_CLIENT then
			local ok, rs = pcall(idx, RunService, "RenderStepped")
			if ok and rs then sig = rs end
		end
		driver = sig:connect(step)
	end

	local Tween = {}
	Tween.__index = function(self, k)
		local v = rawget(Tween, k)
		if v ~= nil then return v end
		if k == "PlaybackState" then return G.Enum.PlaybackState[rawget(self, "_st") or "Begin"] end
		return nil
	end
	Tween.__type = "Instance"
	function Tween:Play()
		local playing = rawget(self, "_st") == "Playing"
		for i = #active, 1, -1 do
			local other = active[i]
			if other ~= self and other.Instance == self.Instance then
				for k in pairs(self.goals) do
					if other.goals[k] ~= nil then
						other.goals[k] = nil
					end
				end
				local any = next(other.goals)
				if not any then
					rawset(other, "_st", "Cancelled")
					table.remove(active, i)
				end
			end
		end
		if rawget(self, "_st") == "Paused" then
			self.started = tick() - (self.pausedAt - self.started)
		else
			self.from = {}
			for k in pairs(self.goals) do
				local ok, v = pcall(C.g, self.Instance, k)
				if ok then self.from[k] = v end
			end
			self.started = tick()
			self.repeats = 0
			self.reversing = false
		end
		rawset(self, "_st", "Playing")
		if not playing then active[#active + 1] = self end
		ensureDriver()
	end
	function Tween:Pause()
		if rawget(self, "_st") ~= "Playing" then return end
		rawset(self, "_st", "Paused")
		self.pausedAt = tick()
	end
	function Tween:Cancel()
		rawset(self, "_st", "Cancelled")
		self.Completed:Fire(G.Enum.PlaybackState.Cancelled)
	end
	function Tween:Destroy()
		rawset(self, "_st", "Cancelled")
	end
	function Tween:IsA(c) return c == "Tween" or c == "TweenBase" or c == "Instance" end
	function Tween:GetPropertyChangedSignal(k)
		local s = rawget(self, "_ps")
		if not s then s = Signal.new() rawset(self, "_ps", s) end
		return s
	end

	local TweenService = { Name = "TweenService", ClassName = "TweenService", __type = "Instance" }
	function TweenService:Create(inst, info, goals)
		if type(info) ~= "table" then info = G.TweenInfo.new() end
		local g = {}
		for k, v in pairs(goals) do g[k] = v end
		return setmetatable({
			Instance = inst,
			TweenInfo = info,
			goals = g,
			Completed = Signal.new(),
			ClassName = "Tween",
			Name = "Tween",
		}, Tween)
	end
	function TweenService:GetValue(alpha, style, direction)
		return ease(style, direction, alpha)
	end
	function TweenService:SmoothDamp(current, target, velocity, smoothTime, maxSpeed, dt)
		dt = dt or 1 / 60
		local omega = 2 / math.max(smoothTime, 0.0001)
		local x = omega * dt
		local exp = 1 / (1 + x + 0.48 * x * x + 0.235 * x * x * x)
		local change = current - target
		local temp = (velocity + change * omega) * dt
		velocity = (velocity - temp * omega) * exp
		return target + (change + temp) * exp, velocity
	end
	function TweenService:IsA(c) return c == "TweenService" or c == "Instance" end
	services.TweenService = TweenService
	C.TweenService = TweenService

	local function tweenGui(o, goals, direction, style, time, override, callback)
		local info = G.TweenInfo.new(time or 1, style or G.Enum.EasingStyle.Quad, direction or G.Enum.EasingDirection.Out)
		for _, tw in ipairs(active) do
			if tw.Instance == o and not override then
				for k in pairs(goals) do
					if tw.goals[k] ~= nil then return false end
				end
			end
		end
		local tw = TweenService:Create(o, info, goals)
		if callback then
			tw.Completed:Connect(function(state) callback(state == G.Enum.PlaybackState.Completed and realEnum.TweenStatus.Completed or realEnum.TweenStatus.Canceled) end)
		end
		tw:Play()
		return true
	end
	C.METHODS.TweenPosition = function(o, pos, direction, style, time, override, callback)
		return tweenGui(o, { Position = pos }, direction, style, time, override, callback)
	end
	C.METHODS.TweenSize = function(o, size, direction, style, time, override, callback)
		return tweenGui(o, { Size = size }, direction, style, time, override, callback)
	end
	C.METHODS.TweenSizeAndPosition = function(o, size, pos, direction, style, time, override, callback)
		return tweenGui(o, { Size = size, Position = pos }, direction, style, time, override, callback)
	end

	services.CollectionService = C.CollectionService

	local function stub(name, extra)
		local s = extra or {}
		s.Name = name
		s.ClassName = name
		s.__type = "Instance"
		s.IsA = s.IsA or function(_, c) return c == name or c == "Instance" end
		s.GetPropertyChangedSignal = s.GetPropertyChangedSignal or function() return Signal.new() end
		s.GetAttribute = s.GetAttribute or function() return nil end
		s.SetAttribute = s.SetAttribute or function() end
		s.Changed = s.Changed or Signal.new()
		s.GetFullName = function() return name end
		s.__instance = function(self)
			local inst = rawget(self, "__inst")
			if inst and inst.Parent then return inst end
			local holder = ReplicatedStorage:FindFirstChild("__compat_services")
			if not holder then
				holder = realInstance.new("Folder")
				holder.Name = "__compat_services"
				holder.Parent = ReplicatedStorage
			end
			inst = holder:FindFirstChild(name)
			if not inst then
				inst = realInstance.new("Folder")
				inst.Name = name
				inst.Parent = holder
			end
			rawset(self, "__inst", inst)
			return inst
		end
		s.FindFirstChild = function(self, n, r) return self:__instance():FindFirstChild(n, r) end
		s.WaitForChild = function(self, n, t)
			local f = self:__instance():FindFirstChild(n)
			if f or t then return f end
			return self:__instance():WaitForChild(n)
		end
		s.GetChildren = function(self) return C.filtered(self:__instance():GetChildren()) end
		s.GetDescendants = function(self) return C.descendants(self:__instance()) end
		return setmetatable(s, { __index = function(_, k)
			local ev = Signal.new()
			if type(k) == "string" and (k:match("Async$") or k:match("^Get") or k:match("^Set") or k:match("^Is") or k:match("^Prompt") or k:match("^Can")) then
				return function() return nil end
			end
			return ev
		end })
	end
	C.stub = stub

	local function folderService(name)
		local holder = ReplicatedStorage:FindFirstChild("__compat_services")
		local f = holder and holder:FindFirstChild(name)
		return f
	end

	services.PolicyService = stub("PolicyService", {
		GetPolicyInfoForPlayerAsync = function()
			return {
				ArePaidRandomItemsRestricted = false, AllowedExternalLinkReferences = { "Discord", "YouTube", "Twitch", "Facebook", "X" },
				IsPaidItemTradingAllowed = true, IsSubjectToChinaPolicies = false, IsEligibleToPurchaseSubscription = true,
				IsContentSharingAllowed = true, AreAdsAllowed = true,
			}
		end,
	})
	local function translator() return C.makeDummy("Translator") end
	services.LocalizationService = stub("LocalizationService", {
		GetTranslatorForPlayerAsync = translator,
		GetTranslatorForPlayer = translator,
		GetTranslatorForLocaleAsync = translator,
		GetCountryRegionForPlayerAsync = function() return "US" end,
		RobloxLocaleId = "en-us", SystemLocaleId = "en-us",
	})
	local subscribers = {}
	services.MessagingService = stub("MessagingService", {
		SubscribeAsync = function(_, topic, fn)
			subscribers[topic] = subscribers[topic] or {}
			local list = subscribers[topic]
			list[#list + 1] = fn
			return { Disconnect = function()
				for i, f in ipairs(list) do if f == fn then table.remove(list, i) break end end
			end }
		end,
		PublishAsync = function(_, topic, data)
			for _, fn in ipairs(subscribers[topic] or {}) do
				C.spawn(fn, { Data = data, Sent = tick() })
			end
		end,
	})
	services.SocialService = stub("SocialService", {
		CanSendGameInviteAsync = function() return false end,
		PromptGameInvite = function() end,
		CanSendCallInviteAsync = function() return false end,
		GameInvitePromptClosed = Signal.new(),
	})
	services.GroupService = stub("GroupService", {
		GetGroupsAsync = function() return {} end,
		GetGroupInfoAsync = function(_, id) return { Id = id, Name = "Group", Owner = { Id = 1, Name = "Owner" }, Roles = {} } end,
		PromptJoinAsync = function() end,
	})
	services.AvatarEditorService = stub("AvatarEditorService", {
		PromptSetFavorite = function() end,
		PromptAllowInventoryReadAccess = function() end,
		PromptSetFavoriteCompleted = Signal.new(),
	})
	services.GamepadService = stub("GamepadService", {
		EnableGamepadCursor = function() end,
		DisableGamepadCursor = function() end,
		GamepadCursorEnabled = false,
	})
	services.TextChatService = nil
	services.ProximityPromptService = stub("ProximityPromptService", {
		PromptTriggered = Signal.new(), PromptShown = Signal.new(), PromptHidden = Signal.new(),
		PromptButtonHoldBegan = Signal.new(), PromptButtonHoldEnded = Signal.new(), PromptTriggerEnded = Signal.new(),
		Enabled = true, MaxPromptsVisible = 16,
	})
	C.ProximityPromptService = services.ProximityPromptService
	services.VoiceChatService = stub("VoiceChatService", { IsVoiceEnabledForUserIdAsync = function() return false end })
	services.ExperienceNotificationService = stub("ExperienceNotificationService", { CanPromptOptInAsync = function() return false end })
	services.ExperienceService = stub("ExperienceService")
	services.AnalyticsService = stub("AnalyticsService", {
		LogCustomEvent = function() end, LogEconomyEvent = function() end, LogProgressionEvent = function() end,
		LogOnboardingFunnelStepEvent = function() end, LogFunnelStepEvent = function() end,
		FireCustomEvent = function() end, FireLogEvent = function() end, FireEvent = function() end, FirePlayerProgressionEvent = function() end,
	})
	services.MemoryStoreService = stub("MemoryStoreService")
	services.HapticService = stub("HapticService", { IsVibrationSupported = function() return false end, SetMotor = function() end })
	services.CaptureService = stub("CaptureService")
	services.CommerceService = stub("CommerceService")
	services.OpenCloudService = stub("OpenCloudService")
	services.UserService = stub("UserService", {
		GetUserInfosByUserIdsAsync = function(_, ids)
			local out = {}
			for _, id in ipairs(ids) do
				local ok, name = pcall(Players.GetNameFromUserIdAsync, Players, id)
				name = ok and name or ("Player" .. id)
				out[#out + 1] = { Id = id, Username = name, DisplayName = name, HasVerifiedBadge = false }
			end
			return out
		end,
	})
	services.AssetService = stub("AssetService", {
		CreateEditableImage = function() return nil end,
		GetBundleDetailsAsync = function() return { Items = {} } end,
	})
	services.VRService = nil
	services.CorePackages = stub("CorePackages")
	services.SafetyService = stub("SafetyService")

	local physicsGroups = { Default = 0 }
	local physicsCollide = {}
	services.PhysicsService = nil
	local physicsExtra = {
		RegisterCollisionGroup = function(_, name)
			if not physicsGroups[name] then
				local n = 0
				for _ in pairs(physicsGroups) do n = n + 1 end
				physicsGroups[name] = n
			end
		end,
		CreateCollisionGroup = function(self, name)
			self:RegisterCollisionGroup(name)
			return physicsGroups[name]
		end,
		CollisionGroupSetCollidable = function(_, a, b, v)
			physicsCollide[a .. "\0" .. b] = v
			physicsCollide[b .. "\0" .. a] = v
		end,
		CollisionGroupsAreCollidable = function(_, a, b)
			local v = physicsCollide[a .. "\0" .. b]
			if v == nil then return true end
			return v
		end,
		IsCollisionGroupRegistered = function(_, name) return physicsGroups[name] ~= nil end,
		GetRegisteredCollisionGroups = function()
			local out = {}
			for name, id in pairs(physicsGroups) do out[#out + 1] = { name = name, id = id, mask = -1 } end
			return out
		end,
		GetMaxCollisionGroups = function() return 32 end,
		SetPartCollisionGroup = function(_, part, name) C.setShadow(part, "CollisionGroup", name) end,
		GetCollisionGroupId = function(_, name) return physicsGroups[name] or 0 end,
		GetCollisionGroupName = function(_, id)
			for name, gid in pairs(physicsGroups) do if gid == id then return name end end
			return nil
		end,
		UnregisterCollisionGroup = function(_, name) physicsGroups[name] = nil end,
		RenameCollisionGroup = function(_, a, b) physicsGroups[b] = physicsGroups[a] physicsGroups[a] = nil end,
	}
	C.physicsCollide = function(a, b)
		local v = physicsCollide[(a or "Default") .. "\0" .. (b or "Default")]
		if v == nil then return true end
		return v
	end

	local textChatHolder
	local function textChatService()
		if textChatHolder then return textChatHolder end
		local f = folderService("TextChatService")
		if not f then
			f = C.makeDummy("TextChatService")
			if IS_SERVER then
				local holder = ReplicatedStorage:FindFirstChild("__compat_services")
				if not holder then
					holder = realInstance.new("Folder")
					holder.Name = "__compat_services"
					holder.Parent = ReplicatedStorage
				end
				f.Parent = holder
			end
		end
		textChatHolder = f
		return f
	end

	local hasServiceCache = {}
	function C.getService(name)
		local s = services[name]
		if s then return s end
		if name == "TextChatService" then return textChatService() end
		local cached = hasServiceCache[name]
		if cached then return cached end
		local ok, svc = pcall(game.GetService, game, name)
		if ok and svc then
			hasServiceCache[name] = svc
			if name == "PhysicsService" then
				SERVICE_METHODS[svc] = physicsExtra
			end
			return svc
		end
		if name == "PhysicsService" then
			local st = stub("PhysicsService", physicsExtra)
			services[name] = st
			return st
		end
		local f = folderService(name)
		if f then
			hasServiceCache[name] = f
			return f
		end
		local st = stub(name)
		services[name] = st
		return st
	end

	local Market = game:GetService("MarketplaceService")
	local GamePassService
	pcall(function() GamePassService = game:GetService("GamePassService") end)
	local passCache = {}
	local function findPlayer(userId)
		if typeof(userId) == "Instance" then return userId end
		for _, p in ipairs(Players:GetPlayers()) do
			if p.userId == userId then return p end
		end
		return nil
	end
	local passFinished = Signal.new()
	local productFinished = Signal.new()
	C.passFinished = passFinished
	local marketExtra = {
		UserOwnsGamePassAsync = function(_, userId, passId)
			local key = tostring(userId) .. ":" .. tostring(passId)
			if passCache[key] then return true end
			local player = findPlayer(userId)
			local owned = false
			if player and GamePassService then
				local ok, r = pcall(GamePassService.PlayerHasPass, GamePassService, player, passId)
				owned = ok and r or false
			end
			if not owned then
				local ok, r = pcall(Market.PlayerOwnsAsset, Market, player or userId, passId)
				owned = ok and r or false
			end
			if owned then passCache[key] = true end
			return owned
		end,
		PromptGamePassPurchase = function(_, player, passId)
			C.pendingPass = { player = player, id = passId }
			Market:PromptPurchase(player, passId)
		end,
		PromptProductPurchase = function(_, player, id, ...)
			return Market:PromptProductPurchase(player, id, ...)
		end,
		PromptPurchase = function(_, player, id, ...)
			return Market:PromptPurchase(player, id, ...)
		end,
		PromptPremiumPurchase = function() end,
		PromptBundlePurchase = function() end,
		PromptSubscriptionPurchase = function() end,
		GetProductInfo = function(_, id, infoType)
			local ok, r = pcall(Market.GetProductInfo, Market, id, infoType)
			if ok and r then return r end
			ok, r = pcall(Market.GetProductInfo, Market, id)
			if ok and r then return r end
			return { Name = "Item", Description = "", PriceInRobux = 0, IconImageAssetId = 0, AssetId = id, ProductId = id, IsForSale = true }
		end,
		GetDeveloperProductsAsync = function()
			return { GetCurrentPage = function() return {} end, IsFinished = true, AdvanceToNextPageAsync = function() end }
		end,
		PlayerOwnsAsset = function(_, player, id)
			local ok, r = pcall(Market.PlayerOwnsAsset, Market, player, id)
			return ok and r or false
		end,
		PlayerOwnsBundle = function() return false end,
		IsPlayerSubscribed = function() return false end,
		UserOwnsSubscriptionAsync = function() return false end,
	}
	SERVICE_METHODS[Market] = marketExtra
	pcall(function()
		Market.PromptPurchaseFinished:connect(function(player, id, purchased)
			if purchased then passCache[tostring(player.userId) .. ":" .. tostring(id)] = true end
			passFinished:Fire(player, id, purchased)
		end)
	end)
	C.marketGet = function(k)
		if k == "PromptGamePassPurchaseFinished" then return passFinished end
		if k == "PromptProductPurchaseFinished" then
			local ok, s = pcall(idx, Market, "PromptProductPurchaseFinished")
			if ok and s then return s end
			return productFinished
		end
		return nil
	end
	local oldMarketGet = C.GET.PromptGamePassPurchaseFinished
	C.GET.PromptGamePassPurchaseFinished = function(o)
		if o == Market then return true, passFinished end
		return false
	end
	C.GET.PromptProductPurchaseFinished = function(o)
		if o ~= Market then return false end
		return true, C.marketGet("PromptProductPurchaseFinished")
	end
	C.GET.PromptBundlePurchaseFinished = function(o)
		if o ~= Market then return false end
		return true, Signal.new()
	end
	C.GET.PromptPremiumPurchaseFinished = C.GET.PromptBundlePurchaseFinished
	C.GET.PromptSubscriptionPurchaseFinished = C.GET.PromptBundlePurchaseFinished

	local Http = game:GetService("HttpService")
	SERVICE_METHODS[Http] = {
		RequestAsync = function(_, req)
			local method = (req.Method or "GET"):upper()
			local ok, body
			if method == "GET" then
				ok, body = pcall(Http.GetAsync, Http, req.Url, true)
			else
				local ct = realEnum.HttpContentType.ApplicationJson
				ok, body = pcall(Http.PostAsync, Http, req.Url, req.Body or "", ct)
			end
			return { Success = ok, StatusCode = ok and 200 or 500, StatusMessage = ok and "OK" or tostring(body), Headers = {}, Body = ok and body or "" }
		end,
		GetSecret = function() return "" end,
		UrlEncode = function(_, s)
			local ok, r = pcall(Http.UrlEncode, Http, s)
			if ok then return r end
			return (tostring(s):gsub("[^%w%-_%.~]", function(c) return string.format("%%%02X", c:byte()) end))
		end,
	}

	local function thumbBase()
		local ok, base = pcall(function() return game:GetService("ContentProvider").BaseUrl end)
		base = ok and base or "http://www.roblox.com/"
		if base:sub(-1) ~= "/" then base = base .. "/" end
		return base
	end
	C.thumbBase = thumbBase
	local playersExtra = {
		GetUserThumbnailAsync = function(_, userId, thumbType, thumbSize)
			local size = tostring(C.enumName(thumbSize) or "Size420x420"):match("(%d+)x") or "420"
			local kind = C.enumName(thumbType) or "HeadShot"
			local url
			if kind == "HeadShot" then
				url = thumbBase() .. "Thumbs/Head.ashx?x=" .. size .. "&y=" .. size .. "&userId=" .. tostring(userId)
			else
				url = thumbBase() .. "Thumbs/Avatar.ashx?x=" .. size .. "&y=" .. size .. "&userId=" .. tostring(userId)
			end
			return url, true
		end,
		GetHumanoidDescriptionFromUserId = function(_, userId)
			local d = C.makeDummy("HumanoidDescription")
			C.setShadow(d, "UserId", userId)
			return d
		end,
		GetHumanoidDescriptionFromOutfitId = function()
			return C.makeDummy("HumanoidDescription")
		end,
		CreateHumanoidModelFromUserId = function(_, userId)
			local ok, model = pcall(Players.GetCharacterAppearanceAsync, Players, userId)
			return ok and model or realInstance.new("Model")
		end,
		CreateHumanoidModelFromDescription = function(_, desc)
			local m = realInstance.new("Model")
			local h = realInstance.new("Humanoid")
			h.Parent = m
			C.applyDescription(h, desc)
			return m
		end,
		GetFriendsAsync = function()
			return { GetCurrentPage = function() return {} end, IsFinished = true, AdvanceToNextPageAsync = function() end }
		end,
		GetNameFromUserIdAsync = function(_, id)
			local ok, r = pcall(Players.GetNameFromUserIdAsync, Players, id)
			if ok then return r end
			return "Player" .. tostring(id)
		end,
		GetUserIdFromNameAsync = function(_, name)
			local ok, r = pcall(Players.GetUserIdFromNameAsync, Players, name)
			if ok then return r end
			error("user not found", 2)
		end,
		GetPlayerByUserId = function(_, id)
			for _, p in ipairs(Players:GetPlayers()) do
				if p.userId == id then return p end
			end
			return nil
		end,
		BanAsync = function() end,
		UnbanAsync = function() end,
		GetBanHistoryAsync = function() return { GetCurrentPage = function() return {} end, IsFinished = true } end,
	}
	SERVICE_METHODS[Players] = playersExtra
	C.GET.PlayerCount = function(o)
		if o ~= Players then return false end
		return true, #Players:GetPlayers()
	end

	function C.applyDescription(humanoid, desc)
		local userId = C.props(desc).UserId
		local character = humanoid.Parent
		if not userId or not character then return end
		local ok, model = pcall(Players.GetCharacterAppearanceAsync, Players, userId)
		if not ok or not model then return end
		for _, c in ipairs(character:GetChildren()) do
			if c:IsA("Accoutrement") or c:IsA("Shirt") or c:IsA("Pants") or c:IsA("ShirtGraphic") or c:IsA("BodyColors") or c:IsA("CharacterMesh") then
				c:Destroy()
			end
		end
		for _, item in ipairs(model:GetChildren()) do
			if item:IsA("Accoutrement") then
				pcall(humanoid.AddAccessory, humanoid, item)
				if item.Parent ~= character then item.Parent = character end
			elseif item:IsA("Decal") and item.Name == "face" then
				local head = character:FindFirstChild("Head")
				if head then
					local old = head:FindFirstChild("face")
					if old then old:Destroy() end
					item.Parent = head
				end
			elseif item:IsA("ValueBase") then
			else
				item.Parent = character
			end
		end
	end
	C.ANY_METHODS.ApplyDescription = function(h, desc) C.applyDescription(h, desc) end
	C.ANY_METHODS.ApplyDescriptionReset = C.ANY_METHODS.ApplyDescription
	C.ANY_METHODS.GetAppliedDescription = function(h)
		local d = C.makeDummy("HumanoidDescription")
		local plr = Players:GetPlayerFromCharacter(h.Parent)
		if plr then C.setShadow(d, "UserId", plr.userId) end
		return d
	end
	C.ANY_METHODS.AddAccessory = function(h, acc)
		acc.Parent = h.Parent
	end
	C.ANY_METHODS.GetAccessories = function(h)
		local out = {}
		for _, c in ipairs(h.Parent:GetChildren()) do
			if c:IsA("Accoutrement") then out[#out + 1] = c end
		end
		return out
	end
	C.ANY_METHODS.RemoveAccessories = function(h)
		for _, c in ipairs(h.Parent:GetChildren()) do
			if c:IsA("Accoutrement") then c:Destroy() end
		end
	end
	C.ANY_METHODS.GetLimb = function() return realEnum.Limb.Unknown end
	C.ANY_METHODS.GetBodyPartR15 = function() return G.Enum.BodyPartR15.Unknown end
	C.ANY_METHODS.BuildRigFromAttachments = function() end
	C.ANY_METHODS.GetMoveVelocity = function(h)
		local root = h.Parent and h.Parent:FindFirstChild("HumanoidRootPart")
		return root and root.Velocity or realVector3.new()
	end

	local CAS = game:GetService("ContextActionService")
	SERVICE_METHODS[CAS] = {
		BindActionAtPriority = function(_, name, fn, touch, priority, ...)
			return CAS:BindAction(name, fn, touch, ...)
		end,
		BindAction = function(_, name, fn, touch, ...)
			local inputs = {}
			for i = 1, select("#", ...) do
				local v = select(i, ...)
				if not C.isFakeEnum(v) then inputs[#inputs + 1] = v end
			end
			return CAS:BindAction(name, fn, touch, unpack(inputs))
		end,
		SetTitle = function(_, name, title) pcall(CAS.SetTitle, CAS, name, title) end,
		SetImage = function(_, name, image) pcall(CAS.SetImage, CAS, name, image) end,
		SetPosition = function(_, name, pos) pcall(CAS.SetPosition, CAS, name, pos) end,
		SetDescription = function(_, name, d) pcall(CAS.SetDescription, CAS, name, d) end,
		GetButton = function(_, name)
			local ok, r = pcall(CAS.GetButton, CAS, name)
			return ok and r or nil
		end,
	}

	local SG = game:GetService("StarterGui")
	SERVICE_METHODS[SG] = {
		SetCore = function(_, name, value)
			local ok = pcall(SG.SetCore, SG, name, value)
			if not ok and name == "ResetButtonCallback" then return end
		end,
		GetCore = function(_, name)
			local ok, r = pcall(SG.GetCore, SG, name)
			if ok then return r end
			if name == "DevConsoleVisible" or name == "PointsNotificationsActive" then return false end
			if name == "BadgesNotificationsActive" then return true end
			return nil
		end,
		SetCoreGuiEnabled = function(_, t, enabled)
			if C.isFakeEnum(t) then return end
			pcall(SG.SetCoreGuiEnabled, SG, t, enabled)
		end,
		GetCoreGuiEnabled = function(_, t)
			if C.isFakeEnum(t) then return false end
			local ok, r = pcall(SG.GetCoreGuiEnabled, SG, t)
			return ok and r
		end,
	}

	local GS = game:GetService("GuiService")
	SERVICE_METHODS[GS] = {
		GetGuiInset = function()
			local ok, a, b = pcall(GS.GetGuiInset, GS)
			if ok then return a, b end
			return realVector2.new(0, 36), realVector2.new(0, 0)
		end,
		IsTenFootInterface = function()
			local ok, r = pcall(GS.IsTenFootInterface, GS)
			return ok and r
		end,
		AddSelectionParent = function(_, ...) pcall(GS.AddSelectionParent, GS, ...) end,
		RemoveSelectionGroup = function(_, ...) pcall(GS.RemoveSelectionGroup, GS, ...) end,
		Select = function(_, obj) pcall(function() GS.SelectedObject = obj end) end,
		OpenBrowserWindow = function() end,
		CloseInspectMenu = function() end,
		InspectPlayerFromUserId = function() end,
	}
	C.GET.MenuIsOpen = function(o)
		if o ~= GS then return false end
		local ok, v = pcall(idx, GS, "MenuIsOpen")
		if ok then return true, v end
		return true, false
	end
	C.GET.TopbarInset = function(o)
		if o ~= GS then return false end
		return true, G.Rect.new(0, 0, 800, 36)
	end

	local TS = game:GetService("TeleportService")
	SERVICE_METHODS[TS] = {
		TeleportAsync = function(_, placeId, players)
			for _, p in ipairs(players) do pcall(TS.Teleport, TS, placeId, p) end
			return { PrivateServerId = "", ReservedServerAccessCode = "" }
		end,
		ReserveServer = function() return "", "" end,
		GetPlayerPlaceInstanceAsync = function() return false, "", game.PlaceId, game.JobId end,
		GetLocalPlayerTeleportData = function()
			local ok, r = pcall(TS.GetLocalPlayerTeleportData, TS)
			return ok and r or nil
		end,
		SetTeleportGui = function(_, gui) pcall(TS.SetTeleportGui, TS, gui) end,
		SetTeleportSetting = function(_, k, v) pcall(TS.SetTeleportSetting, TS, k, v) end,
		GetTeleportSetting = function(_, k)
			local ok, r = pcall(TS.GetTeleportSetting, TS, k)
			return ok and r or nil
		end,
		TeleportToPlaceInstance = function(_, ...) pcall(TS.TeleportToPlaceInstance, TS, ...) end,
	}

	local Debris = game:GetService("Debris")
	SERVICE_METHODS[Debris] = {
		AddItem = function(_, item, t)
			if type(item) == "table" then
				delay(t or 10, function() if item.Destroy then item:Destroy() end end)
				return
			end
			Debris:AddItem(item, t or 10)
		end,
	}

	local CP = game:GetService("ContentProvider")
	SERVICE_METHODS[CP] = {
		PreloadAsync = function(_, list, callback)
			local ids = {}
			for _, v in ipairs(list or {}) do
				if type(v) == "string" then ids[#ids + 1] = v end
			end
			pcall(function()
				for _, id in ipairs(ids) do CP:Preload(id) end
			end)
			if callback then
				for _, v in ipairs(list or {}) do pcall(callback, v, G.Enum.AssetFetchStatus.Success) end
			end
		end,
		GetAssetFetchStatus = function() return G.Enum.AssetFetchStatus.Success end,
	}

	local UIS = game:GetService("UserInputService")
	SERVICE_METHODS[UIS] = {
		GetLastInputType = function()
			local ok, r = pcall(UIS.GetLastInputType, UIS)
			if ok then return r end
			return realEnum.UserInputType.MouseMovement
		end,
		GetStringForKeyCode = function(_, kc) return C.enumName(kc) or "" end,
		GetImageForKeyCode = function() return "" end,
		GetDeviceRotation = function() return realVector3.new(), realCFrame.new() end,
		GetConnectedGamepads = function()
			local ok, r = pcall(UIS.GetConnectedGamepads, UIS)
			return ok and r or {}
		end,
		GetNavigationGamepads = function()
			local ok, r = pcall(UIS.GetNavigationGamepads, UIS)
			return ok and r or {}
		end,
		GetGamepadConnected = function(_, g)
			local ok, r = pcall(UIS.GetGamepadConnected, UIS, g)
			return ok and r or false
		end,
		GetGamepadState = function(_, g)
			local ok, r = pcall(UIS.GetGamepadState, UIS, g)
			return ok and r or {}
		end,
		GetSupportedGamepadKeyCodes = function(_, g)
			local ok, r = pcall(UIS.GetSupportedGamepadKeyCodes, UIS, g)
			return ok and r or {}
		end,
		IsGamepadButtonDown = function(_, g, k)
			local ok, r = pcall(UIS.IsGamepadButtonDown, UIS, g, k)
			return ok and r or false
		end,
		GetMouseLocation = function()
			local ok, r = pcall(UIS.GetMouseLocation, UIS)
			if ok then return r end
			local m = Players.LocalPlayer and Players.LocalPlayer:GetMouse()
			return m and realVector2.new(m.X, m.Y) or realVector2.new()
		end,
		GetMouseDelta = function()
			local ok, r = pcall(UIS.GetMouseDelta, UIS)
			return ok and r or realVector2.new()
		end,
		GetMouseButtonsPressed = function()
			local ok, r = pcall(UIS.GetMouseButtonsPressed, UIS)
			return ok and r or {}
		end,
		GetKeysPressed = function()
			local ok, r = pcall(UIS.GetKeysPressed, UIS)
			return ok and r or {}
		end,
		GetFocusedTextBox = function()
			local ok, r = pcall(UIS.GetFocusedTextBox, UIS)
			return ok and r or nil
		end,
		IsKeyDown = function(_, k)
			if C.isFakeEnum(k) then return false end
			return UIS:IsKeyDown(k)
		end,
	}
	if IS_CLIENT then
		local function kindOf(t)
			local n = C.enumName(t) or ""
			if n == "Touch" then return "Touch" end
			if n:match("^Gamepad") then return "Gamepad" end
			if n == "Keyboard" or n:match("^Mouse") then return "KeyboardAndMouse" end
			return nil
		end
		pcall(function()
			UIS.LastInputTypeChanged:connect(function(t)
				local k = kindOf(t)
				if k and k ~= C.lastInputKind then
					C.lastInputKind = k
					C.fireProp(UIS, "PreferredInput")
				end
			end)
		end)
		local origGet = C.EMULATED_PROPS
		origGet.PreferredInput = true
	end

	local DSS = game:GetService("DataStoreService")
	local function wrapStore(store)
		if not store then return store end
		local w = {}
		local cache = {}
		setmetatable(w, { __index = function(_, k)
			local f = cache[k]
			if f then return f end
			if k == "UpdateAsync" then
				f = function(_, key, fn)
					return store:UpdateAsync(key, function(old)
						local r = fn(old, { Version = "1", CreatedTime = 0, UpdatedTime = 0, GetUserIds = function() return {} end, GetMetadata = function() return {} end })
						return r
					end)
				end
			elseif k == "GetAsync" then
				f = function(_, key) return store:GetAsync(key), { Version = "1", CreatedTime = 0, UpdatedTime = 0, GetUserIds = function() return {} end, GetMetadata = function() return {} end } end
			elseif k == "SetAsync" then
				f = function(_, key, value) return store:SetAsync(key, value) end
			elseif k == "RemoveAsync" then
				f = function(_, key)
					local ok, r = pcall(store.RemoveAsync, store, key)
					if ok then return r end
					local old = store:GetAsync(key)
					store:SetAsync(key, nil)
					return old
				end
			elseif k == "IncrementAsync" then
				f = function(_, key, delta) return store:IncrementAsync(key, delta) end
			elseif k == "GetSortedAsync" then
				f = function(_, ascending, size, min, max)
					local ok, r = pcall(store.GetSortedAsync, store, ascending, size, min, max)
					if ok then return r end
					return { GetCurrentPage = function() return {} end, IsFinished = true, AdvanceToNextPageAsync = function() end }
				end
			elseif k == "ListKeysAsync" or k == "ListVersionsAsync" then
				f = function() return { GetCurrentPage = function() return {} end, IsFinished = true, AdvanceToNextPageAsync = function() end } end
			elseif k == "GetVersionAsync" then
				f = function() return nil end
			else
				local okv, v = pcall(idx, store, k)
				if okv and type(v) == "function" then
					f = function(_, ...) return v(store, ...) end
				else
					return okv and v or nil
				end
			end
			cache[k] = f
			return f
		end })
		return w
	end
	SERVICE_METHODS[DSS] = {
		GetDataStore = function(_, name, scope, options)
			local ok, s = pcall(DSS.GetDataStore, DSS, name, scope)
			if not ok then
				warn("compat datastore: " .. tostring(s))
				return C.memoryStore(name)
			end
			return wrapStore(s)
		end,
		GetOrderedDataStore = function(_, name, scope)
			local ok, s = pcall(DSS.GetOrderedDataStore, DSS, name, scope)
			if not ok then return C.memoryStore(name) end
			return wrapStore(s)
		end,
		GetGlobalDataStore = function()
			local ok, s = pcall(DSS.GetGlobalDataStore, DSS)
			if not ok then return C.memoryStore("global") end
			return wrapStore(s)
		end,
		GetRequestBudgetForRequestType = function() return 100 end,
		ListDataStoresAsync = function() return { GetCurrentPage = function() return {} end, IsFinished = true } end,
	}
	local memoryStores = {}
	function C.memoryStore(name)
		if memoryStores[name] then return memoryStores[name] end
		local data = {}
		local s = {
			GetAsync = function(_, k) return data[k] end,
			SetAsync = function(_, k, v) data[k] = v end,
			UpdateAsync = function(_, k, fn)
				local r = fn(data[k])
				if r ~= nil then data[k] = r end
				return data[k]
			end,
			RemoveAsync = function(_, k) local old = data[k] data[k] = nil return old end,
			IncrementAsync = function(_, k, d) data[k] = (data[k] or 0) + (d or 1) return data[k] end,
			GetSortedAsync = function() return { GetCurrentPage = function() return {} end, IsFinished = true, AdvanceToNextPageAsync = function() end } end,
		}
		memoryStores[name] = s
		return s
	end

	local RunServiceExtra = {
		IsClient = function() return IS_CLIENT end,
		IsServer = function() return IS_SERVER end,
		IsRunning = function() return true end,
		IsEdit = function() return false end,
		IsRunMode = function() return false end,
		IsStudio = function()
			local ok, r = pcall(RunService.IsStudio, RunService)
			return ok and r or false
		end,
		BindToRenderStep = function(_, name, priority, fn)
			if C.isFakeEnum(priority) then priority = 200 end
			if type(priority) ~= "number" then priority = (priority and priority.Value) or 200 end
			local ok = pcall(RunService.BindToRenderStep, RunService, name, priority, fn)
			if not ok then
				C.renderBinds = C.renderBinds or {}
				C.renderBinds[name] = RunService.RenderStepped:connect(fn)
			end
		end,
		UnbindFromRenderStep = function(_, name)
			pcall(RunService.UnbindFromRenderStep, RunService, name)
			if C.renderBinds and C.renderBinds[name] then
				C.renderBinds[name]:disconnect()
				C.renderBinds[name] = nil
			end
		end,
	}
	SERVICE_METHODS[RunService] = RunServiceExtra

	local closeCallbacks = {}
	SERVICE_METHODS[game] = {
		BindToClose = function(_, fn)
			closeCallbacks[#closeCallbacks + 1] = fn
			if #closeCallbacks == 1 then
				pcall(function()
					game.OnClose = function()
						for _, f in ipairs(closeCallbacks) do pcall(f) end
					end
				end)
			end
		end,
		IsLoaded = function() return true end,
		GetJobsInfo = function() return {} end,
	}
	C.GET.Loaded = function(o)
		if o ~= game then return false end
		return true, C.proxySignal(function() return nil end)
	end
	C.GET.PrivateServerId = function(o)
		if o ~= game then return false end
		return true, ""
	end
	C.GET.PrivateServerOwnerId = function(o)
		if o ~= game then return false end
		return true, 0
	end
	C.GET.CreatorType = function(o)
		if o ~= game then return false end
		local ok, v = pcall(idx, game, "CreatorType")
		if ok then return true, v end
		return true, realEnum.CreatorType.User
	end

	local Lighting = game:GetService("Lighting")
	C.GET.GlobalShadows = function(o)
		if o ~= Lighting then return false end
		local ok, v = pcall(idx, Lighting, "GlobalShadows")
		if ok then return true, v end
		return true, true
	end

	SERVICE_METHODS[workspace] = {
		GetServerTimeNow = function() return tick() end,
		GetRealPhysicsFPS = function() return 60 end,
		GetNumAwakeParts = function() return 0 end,
		GetPhysicsThrottling = function() return 100 end,
		SetInsertPoint = function() end,
		IsRegion3Empty = function(_, r, ignore)
			local ok, v = pcall(workspace.IsRegion3Empty, workspace, r, ignore)
			if ok then return v end
			return #workspace:FindPartsInRegion3(r, ignore, 1) == 0
		end,
	}
	C.GET.StreamingEnabled = function(o)
		if o ~= workspace then return false end
		return true, false
	end
	C.GET.DistributedGameTime = function(o)
		if o ~= workspace then return false end
		local ok, v = pcall(idx, workspace, "DistributedGameTime")
		if ok then return true, v end
		return true, time()
	end

	local okUGS, UGS = pcall(function() return UserSettings():GetService("UserGameSettings") end)
	if okUGS and UGS then
		SERVICE_METHODS[UGS] = {
			GetCameraYInvertValue = function() return 1 end,
			GetOnboardingCompleted = function() return true end,
			SetOnboardingCompleted = function() end,
			InFullScreen = function()
				local ok, r = pcall(UGS.InFullScreen, UGS)
				return ok and r or false
			end,
			InStudioMode = function() return false end,
		}
	end
end
