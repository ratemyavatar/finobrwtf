do
	local function extend(base, extra)
		return setmetatable(extra, { __index = function(_, k)
			local ok, v = pcall(idx, base, k)
			if ok then return v end
			return nil
		end })
	end
	C.extend = extend

	local m = extend(math, {})
	function m.clamp(x, lo, hi)
		if x < lo then return lo end
		if x > hi then return hi end
		return x
	end
	function m.sign(x)
		if x > 0 then return 1 elseif x < 0 then return -1 end
		return 0
	end
	function m.round(x)
		if x >= 0 then return math.floor(x + 0.5) end
		return math.ceil(x - 0.5)
	end
	function m.log(x, base)
		if base then return math.log(x) / math.log(base) end
		return math.log(x)
	end
	if not math.noise then
		function m.noise(x, y, z)
			local n = math.sin((x or 0) * 12.9898 + (y or 0) * 78.233 + (z or 0) * 37.719) * 43758.5453
			return (n - math.floor(n)) * 2 - 1
		end
	end
	function m.lerp(a, b, t) return a + (b - a) * t end
	function m.map(x, a, b, c, d) return c + (x - a) * (d - c) / (b - a) end
	G.math = m

	local t = extend(table, {})
	function t.find(list, value, init)
		for i = init or 1, #list do
			if list[i] == value then return i end
		end
		return nil
	end
	function t.clear(list)
		for k in pairs(list) do list[k] = nil end
	end
	function t.create(n, v)
		local out = {}
		if v ~= nil then for i = 1, n do out[i] = v end end
		return out
	end
	function t.pack(...)
		return { n = select("#", ...), ... }
	end
	t.unpack = unpack
	function t.clone(list)
		local out = {}
		for k, v in pairs(list) do out[k] = v end
		return setmetatable(out, getmetatable(list))
	end
	function t.freeze(list) return list end
	function t.isfrozen() return false end
	function t.move(a1, f, e, tpos, a2)
		a2 = a2 or a1
		if e >= f then
			if tpos > f and tpos <= e and a1 == a2 then
				for i = e - f, 0, -1 do a2[tpos + i] = a1[f + i] end
			else
				for i = 0, e - f do a2[tpos + i] = a1[f + i] end
			end
		end
		return a2
	end
	G.table = t

	local s = extend(string, {})
	function s.split(str, sep)
		sep = sep or ","
		local out = {}
		if sep == "" then
			for i = 1, #str do out[i] = str:sub(i, i) end
			return out
		end
		local start = 1
		while true do
			local a, b = string.find(str, sep, start, true)
			if not a then
				out[#out + 1] = str:sub(start)
				break
			end
			out[#out + 1] = str:sub(start, a - 1)
			start = b + 1
		end
		return out
	end
	G.string = s
	C.stringMethods = s

	local o = extend(os, {})
	function o.clock() return tick() end
	if not pcall(function() return os.date("*t") end) then
		function o.date(fmt, time)
			time = time or os.time()
			local days = math.floor(time / 86400)
			local secs = time - days * 86400
			local z = days + 719468
			local era = math.floor(z / 146097)
			local doe = z - era * 146097
			local yoe = math.floor((doe - math.floor(doe / 1460) + math.floor(doe / 36524) - math.floor(doe / 146096)) / 365)
			local y = yoe + era * 400
			local doy = doe - (365 * yoe + math.floor(yoe / 4) - math.floor(yoe / 100))
			local mp = math.floor((5 * doy + 2) / 153)
			local d = doy - math.floor((153 * mp + 2) / 5) + 1
			local mo = mp < 10 and mp + 3 or mp - 9
			if mo <= 2 then y = y + 1 end
			local tt = { year = y, month = mo, day = d, hour = math.floor(secs / 3600), min = math.floor(secs % 3600 / 60), sec = secs % 60, wday = (days + 4) % 7 + 1, yday = 1, isdst = false }
			if fmt and fmt:find("%*t") then return tt end
			return string.format("%04d-%02d-%02d %02d:%02d:%02d", tt.year, tt.month, tt.day, tt.hour, tt.min, tt.sec)
		end
	end
	G.os = o

	local utf8 = {}
	utf8.charpattern = "[%z\1-\127\194-\244][\128-\191]*"
	function utf8.char(...)
		local out = {}
		for i = 1, select("#", ...) do
			local cp = select(i, ...)
			if cp < 0x80 then
				out[#out + 1] = string.char(cp)
			elseif cp < 0x800 then
				out[#out + 1] = string.char(0xC0 + math.floor(cp / 0x40), 0x80 + cp % 0x40)
			elseif cp < 0x10000 then
				out[#out + 1] = string.char(0xE0 + math.floor(cp / 0x1000), 0x80 + math.floor(cp / 0x40) % 0x40, 0x80 + cp % 0x40)
			else
				out[#out + 1] = string.char(0xF0 + math.floor(cp / 0x40000), 0x80 + math.floor(cp / 0x1000) % 0x40, 0x80 + math.floor(cp / 0x40) % 0x40, 0x80 + cp % 0x40)
			end
		end
		return table.concat(out)
	end
	local function decode(str, i)
		local c = str:byte(i)
		if not c then return nil end
		if c < 0x80 then return c, 1 end
		if c < 0xE0 then return (c - 0xC0) * 0x40 + (str:byte(i + 1) or 0x80) - 0x80, 2 end
		if c < 0xF0 then return (c - 0xE0) * 0x1000 + ((str:byte(i + 1) or 0x80) - 0x80) * 0x40 + (str:byte(i + 2) or 0x80) - 0x80, 3 end
		return (c - 0xF0) * 0x40000 + ((str:byte(i + 1) or 0x80) - 0x80) * 0x1000 + ((str:byte(i + 2) or 0x80) - 0x80) * 0x40 + (str:byte(i + 3) or 0x80) - 0x80, 4
	end
	function utf8.codes(str)
		local i = 1
		return function()
			if i > #str then return nil end
			local cp, n = decode(str, i)
			local at = i
			i = i + n
			return at, cp
		end
	end
	function utf8.graphemes(str)
		local i = 1
		return function()
			if i > #str then return nil end
			local _, n = decode(str, i)
			local at = i
			i = i + n
			return at, i - 1
		end
	end
	function utf8.len(str, i, j)
		local n = 0
		for _ in string.gmatch(str:sub(i or 1, j or -1), utf8.charpattern) do n = n + 1 end
		return n
	end
	function utf8.codepoint(str, i, j)
		i = i or 1
		j = j or i
		local out = {}
		local p = i
		while p <= j do
			local cp, n = decode(str, p)
			if not cp then break end
			out[#out + 1] = cp
			p = p + n
		end
		return unpack(out)
	end
	function utf8.offset(str, n, i)
		i = i or 1
		local count = 0
		local p = i
		while p <= #str + 1 do
			count = count + 1
			if count == n then return p end
			local _, len = decode(str, p)
			p = p + (len or 1)
		end
		return nil
	end
	G.utf8 = utf8

	local bit = {}
	local function tobits(x) return math.floor(x) % 4294967296 end
	local function op(a, b, f)
		a, b = tobits(a), tobits(b)
		local r, p = 0, 1
		for _ = 1, 32 do
			local x, y = a % 2, b % 2
			if f(x, y) then r = r + p end
			a, b, p = math.floor(a / 2), math.floor(b / 2), p * 2
		end
		return r
	end
	function bit.band(a, b, ...)
		local r = op(a, b or 4294967295, function(x, y) return x == 1 and y == 1 end)
		if ... then return bit.band(r, ...) end
		return r
	end
	function bit.bor(a, b, ...)
		local r = op(a, b or 0, function(x, y) return x == 1 or y == 1 end)
		if ... then return bit.bor(r, ...) end
		return r
	end
	function bit.bxor(a, b, ...)
		local r = op(a, b or 0, function(x, y) return x ~= y end)
		if ... then return bit.bxor(r, ...) end
		return r
	end
	function bit.bnot(a) return 4294967295 - tobits(a) end
	function bit.btest(...) return bit.band(...) ~= 0 end
	function bit.lshift(a, n) return tobits(tobits(a) * 2 ^ n) end
	function bit.rshift(a, n) return math.floor(tobits(a) / 2 ^ n) end
	function bit.arshift(a, n)
		a = tobits(a)
		local r = math.floor(a / 2 ^ n)
		if a >= 2147483648 then r = r + (4294967296 - 2 ^ (32 - n)) end
		return tobits(r)
	end
	function bit.lrotate(a, n)
		n = n % 32
		a = tobits(a)
		return tobits(a * 2 ^ n) + math.floor(a / 2 ^ (32 - n))
	end
	function bit.rrotate(a, n) return bit.lrotate(a, 32 - n % 32) end
	function bit.extract(a, f, w)
		w = w or 1
		return math.floor(tobits(a) / 2 ^ f) % 2 ^ w
	end
	function bit.replace(a, v, f, w)
		w = w or 1
		local mask = 2 ^ w - 1
		local cleared = tobits(a) - bit.extract(a, f, w) * 2 ^ f
		return tobits(cleared + (tobits(v) % (mask + 1)) * 2 ^ f)
	end
	G.bit32 = bit

	local color = extend(realColor3, {})
	function color.fromRGB(r, g, b) return realColor3.new((r or 0) / 255, (g or 0) / 255, (b or 0) / 255) end
	function color.fromHex(hex)
		hex = hex:gsub("#", "")
		if #hex == 3 then hex = hex:sub(1, 1):rep(2) .. hex:sub(2, 2):rep(2) .. hex:sub(3, 3):rep(2) end
		return color.fromRGB(tonumber(hex:sub(1, 2), 16) or 0, tonumber(hex:sub(3, 4), 16) or 0, tonumber(hex:sub(5, 6), 16) or 0)
	end
	function color.fromHSV(h, s, v)
		local i = math.floor(h * 6)
		local f = h * 6 - i
		local p, q, t2 = v * (1 - s), v * (1 - f * s), v * (1 - (1 - f) * s)
		i = i % 6
		if i == 0 then return realColor3.new(v, t2, p) end
		if i == 1 then return realColor3.new(q, v, p) end
		if i == 2 then return realColor3.new(p, v, t2) end
		if i == 3 then return realColor3.new(p, q, v) end
		if i == 4 then return realColor3.new(t2, p, v) end
		return realColor3.new(v, p, q)
	end
	function color.toHSV(c)
		local r, g, b = c.r, c.g, c.b
		local mx, mn = math.max(r, g, b), math.min(r, g, b)
		local h, s, v = 0, 0, mx
		local d = mx - mn
		if mx ~= 0 then s = d / mx end
		if d ~= 0 then
			if mx == r then h = (g - b) / d % 6 elseif mx == g then h = (b - r) / d + 2 else h = (r - g) / d + 4 end
			h = h / 6
		end
		return h, s, v
	end
	G.Color3 = color

	local function angles(x, y, z) return realCFrame.Angles(x, y, z) end
	local cf = extend(realCFrame, {})
	cf.identity = realCFrame.new()
	function cf.fromEulerAnglesYXZ(x, y, z)
		return angles(0, y, 0) * angles(x, 0, 0) * angles(0, 0, z)
	end
	cf.fromOrientation = cf.fromEulerAnglesYXZ
	cf.fromEulerAnglesXYZ = function(x, y, z) return angles(x, y, z) end
	cf.fromEulerAngles = cf.fromEulerAnglesXYZ
	function cf.lookAt(at, target, up)
		up = up or realVector3.new(0, 1, 0)
		local look = (target - at)
		if look.magnitude < 1e-6 then return realCFrame.new(at) end
		look = look.unit
		local right = look:Cross(up)
		if right.magnitude < 1e-6 then
			right = look:Cross(realVector3.new(0, 0, 1))
		end
		right = right.unit
		local upv = right:Cross(look).unit
		return realCFrame.new(at.x, at.y, at.z, right.x, upv.x, -look.x, right.y, upv.y, -look.y, right.z, upv.z, -look.z)
	end
	function cf.lookAlong(at, dir, up) return cf.lookAt(at, at + dir, up) end
	function cf.fromMatrix(pos, vx, vy, vz)
		vz = vz or vx:Cross(vy).unit
		return realCFrame.new(pos.x, pos.y, pos.z, vx.x, vy.x, vz.x, vx.y, vy.y, vz.y, vx.z, vy.z, vz.z)
	end
	if not pcall(function() return realCFrame.fromAxisAngle end) or not realCFrame.fromAxisAngle then
		function cf.fromAxisAngle(axis, angle)
			axis = axis.unit
			local half = angle / 2
			local s2 = math.sin(half)
			return realCFrame.new(0, 0, 0, axis.x * s2, axis.y * s2, axis.z * s2, math.cos(half))
		end
	end
	G.CFrame = cf

	local v3 = extend(realVector3, {})
	v3.zero = realVector3.new(0, 0, 0)
	v3.one = realVector3.new(1, 1, 1)
	v3.xAxis = realVector3.new(1, 0, 0)
	v3.yAxis = realVector3.new(0, 1, 0)
	v3.zAxis = realVector3.new(0, 0, 1)
	G.Vector3 = v3

	local v2 = extend(realVector2, {})
	v2.zero = realVector2.new(0, 0)
	v2.one = realVector2.new(1, 1)
	v2.xAxis = realVector2.new(1, 0)
	v2.yAxis = realVector2.new(0, 1)
	G.Vector2 = v2

	local u2 = extend(realUDim2, {})
	function u2.fromScale(x, y) return realUDim2.new(x or 0, 0, y or 0, 0) end
	function u2.fromOffset(x, y) return realUDim2.new(0, x or 0, 0, y or 0) end
	G.UDim2 = u2
	G.UDim = extend(realUDim, {})

	local Random = {}
	Random.__index = Random
	Random.__type = "Random"
	local R = {}
	function R.new(seed)
		seed = math.floor(tonumber(seed) or (tick() * 1000 % 2147483647))
		local self = setmetatable({ state = seed % 2147483646 + 1 }, Random)
		return self
	end
	function Random:_next()
		self.state = (self.state * 48271) % 2147483647
		return self.state / 2147483647
	end
	function Random:NextNumber(a, b)
		a, b = a or 0, b or 1
		return a + (b - a) * self:_next()
	end
	function Random:NextInteger(a, b)
		return math.floor(a + (b - a + 1) * self:_next())
	end
	function Random:NextUnitVector()
		local z = self:NextNumber(-1, 1)
		local th = self:NextNumber(0, math.pi * 2)
		local r = math.sqrt(1 - z * z)
		return realVector3.new(r * math.cos(th), r * math.sin(th), z)
	end
	function Random:Shuffle(list)
		for i = #list, 2, -1 do
			local j = self:NextInteger(1, i)
			list[i], list[j] = list[j], list[i]
		end
	end
	function Random:Clone()
		return setmetatable({ state = self.state }, Random)
	end
	G.Random = R

	local DT = {}
	DT.__index = DT
	DT.__type = "DateTime"
	local function makeDT(ms)
		return setmetatable({ UnixTimestampMillis = math.floor(ms), UnixTimestamp = math.floor(ms / 1000) }, DT)
	end
	local DateTime = {}
	function DateTime.now() return makeDT(tick() * 1000) end
	function DateTime.fromUnixTimestamp(s2) return makeDT(s2 * 1000) end
	function DateTime.fromUnixTimestampMillis(ms) return makeDT(ms) end
	local function daysFrom(y, mo, d)
		y = mo <= 2 and y - 1 or y
		local era = math.floor(y / 400)
		local yoe = y - era * 400
		local mp = (mo + 9) % 12
		local doy = math.floor((153 * mp + 2) / 5) + d - 1
		local doe = yoe * 365 + math.floor(yoe / 4) - math.floor(yoe / 100) + doy
		return era * 146097 + doe - 719468
	end
	function DateTime.fromUniversalTime(y, mo, d, h, mi, sec, ms)
		local days = daysFrom(y or 1970, mo or 1, d or 1)
		return makeDT(((days * 86400) + (h or 0) * 3600 + (mi or 0) * 60 + (sec or 0)) * 1000 + (ms or 0))
	end
	DateTime.fromLocalTime = DateTime.fromUniversalTime
	function DT:ToUniversalTime()
		local tt = G.os.date("!*t", self.UnixTimestamp)
		if type(tt) ~= "table" then tt = G.os.date("*t", self.UnixTimestamp) end
		return { Year = tt.year, Month = tt.month, Day = tt.day, Hour = tt.hour, Minute = tt.min, Second = tt.sec, Millisecond = self.UnixTimestampMillis % 1000 }
	end
	DT.ToLocalTime = DT.ToUniversalTime
	function DT:FormatUniversalTime(fmt)
		local u = self:ToUniversalTime()
		return string.format("%04d-%02d-%02d %02d:%02d:%02d", u.Year, u.Month, u.Day, u.Hour, u.Minute, u.Second)
	end
	DT.FormatLocalTime = DT.FormatUniversalTime
	function DT:ToIsoDate()
		local u = self:ToUniversalTime()
		return string.format("%04d-%02d-%02dT%02d:%02d:%02dZ", u.Year, u.Month, u.Day, u.Hour, u.Minute, u.Second)
	end
	G.DateTime = DateTime

	local fontMeta = { __type = "Font" }
	fontMeta.__index = fontMeta
	local Font = {}
	function Font.new(family, weight, style)
		return setmetatable({ Family = family, Weight = weight, Style = style }, fontMeta)
	end
	function Font.fromEnum(e) return setmetatable({ Family = tostring(e), EnumFont = e }, fontMeta) end
	function Font.fromName(n, weight, style) return Font.new(n, weight, style) end
	function Font.fromId(id, weight, style) return Font.new(tostring(id), weight, style) end
	G.Font = Font

	local dbg = extend(debug, {})
	if not debug.profilebegin then function dbg.profilebegin() end end
	if not debug.profileend then function dbg.profileend() end end
	function dbg.setmemorycategory() end
	function dbg.resetmemorycategory() end
	if not debug.info then
		function dbg.info(level, what)
			if type(what) == "string" and what:find("s") then return "?" end
			return nil
		end
	end
	G.debug = dbg

	local cs = extend(ColorSequence, {})
	function cs.new(a, b)
		local ok, r = pcall(ColorSequence.new, a, b)
		if ok then return r end
		if type(a) == "table" and #a > 0 then
			local first, last = a[1], a[#a]
			return ColorSequence.new(first.Value, last.Value)
		end
		error(r, 2)
	end
	G.ColorSequence = cs
	local ns = extend(NumberSequence, {})
	function ns.new(a, b)
		local ok, r = pcall(NumberSequence.new, a, b)
		if ok then return r end
		if type(a) == "table" and #a > 0 then
			local first, last = a[1], a[#a]
			local ok2, r2 = pcall(NumberSequence.new, first.Value, last.Value)
			if ok2 then return r2 end
			return NumberSequence.new(first.Value)
		end
		error(r, 2)
	end
	G.NumberSequence = ns

	G.NumberRange = NumberRange
	G.PhysicalProperties = PhysicalProperties
	G.Rect = Rect or {
		new = function(a, b, c2, d)
			if type(a) == "number" then
				return { __type = "Rect", Min = realVector2.new(a, b), Max = realVector2.new(c2, d), Width = c2 - a, Height = d - b }
			end
			return { __type = "Rect", Min = a, Max = b, Width = b.x - a.x, Height = b.y - a.y }
		end,
	}
	G.warn = warn or print

	local TweenInfo = {}
	function TweenInfo.new(time, style, direction, repeatCount, reverses, delayTime)
		return {
			__type = "TweenInfo",
			Time = time or 1,
			EasingStyle = style,
			EasingDirection = direction,
			RepeatCount = repeatCount or 0,
			Reverses = reverses or false,
			DelayTime = delayTime or 0,
		}
	end
	G.TweenInfo = TweenInfo

	local RP = {}
	function RP.new()
		local p = {
			__type = "RaycastParams",
			FilterDescendantsInstances = {},
			FilterType = nil,
			IgnoreWater = false,
			CollisionGroup = "Default",
			RespectCanCollide = false,
		}
		function p.AddToFilter(self, x)
			if type(x) == "table" then
				for _, v in ipairs(x) do table.insert(self.FilterDescendantsInstances, v) end
			else
				table.insert(self.FilterDescendantsInstances, x)
			end
		end
		return p
	end
	G.RaycastParams = RP
	local OP = {}
	function OP.new()
		local p = RP.new()
		p.__type = "OverlapParams"
		p.MaxParts = 0
		return p
	end
	G.OverlapParams = OP
end
