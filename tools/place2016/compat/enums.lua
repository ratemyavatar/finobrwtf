do
	local ENUM_MAP = {
		["Font.Gotham"] = "SourceSans",
		["Font.GothamMedium"] = "SourceSans",
		["Font.GothamBold"] = "SourceSansBold",
		["Font.GothamBlack"] = "SourceSansBold",
		["Font.GothamSemibold"] = "SourceSansBold",
		["Font.GothamSSm"] = "SourceSans",
		["Font.Montserrat"] = "SourceSans",
		["Font.FredokaOne"] = "SourceSansBold",
		["Font.LuckiestGuy"] = "SourceSansBold",
		["Font.Bangers"] = "SourceSansBold",
		["Font.Cartoon"] = "Cartoon",
		["Font.Code"] = "Code",
		["Font.Ubuntu"] = "SourceSans",
		["Font.Roboto"] = "SourceSans",
		["Font.RobotoMono"] = "Code",
		["Font.Oswald"] = "SourceSansBold",
		["Font.Nunito"] = "SourceSans",
		["Font.BuilderSans"] = "SourceSans",
		["Font.BuilderSansMedium"] = "SourceSans",
		["Font.BuilderSansBold"] = "SourceSansBold",
		["Font.BuilderSansExtraBold"] = "SourceSansBold",
		["Font.Arimo"] = "Arial",
		["Font.ArimoBold"] = "ArialBold",
		["Font.Highway"] = "Highway",
		["Font.SciFi"] = "SciFi",
		["Font.Fantasy"] = "Fantasy",
		["Font.Antique"] = "Antique",
		["Font.SourceSansSemibold"] = "SourceSansBold",
		["Font.SourceSansItalic"] = "SourceSans",
		["Font.Merriweather"] = "Garamond",
		["Font.Kalam"] = "SourceSans",
		["Font.Creepster"] = "Fantasy",
		["Font.DenkOne"] = "SourceSansBold",
		["Font.GrenzeGotisch"] = "Antique",
		["Font.Bodoni"] = "Garamond",
		["Font.Sarpanch"] = "SciFi",
		["Font.TitilliumWeb"] = "SourceSans",
		["Font.AmaticSC"] = "SourceSans",
		["Font.IndieFlower"] = "SourceSans",
		["Font.PermanentMarker"] = "SourceSansBold",
		["Font.SpecialElite"] = "Code",
		["Font.JosefinSans"] = "SourceSans",
		["Font.PatrickHand"] = "SourceSans",
		["Font.Jura"] = "SciFi",
		["Font.Michroma"] = "SciFi",
		["Font.Nunito"] = "SourceSans",
		["Font.Zekton"] = "SciFi",
		["Font.Bangers"] = "SourceSansBold",
		["Material.ForceField"] = "Neon",
		["Material.Glass"] = "SmoothPlastic",
		["Material.CrackedLava"] = "Slate",
		["Material.Salt"] = "Sand",
		["Material.Asphalt"] = "Concrete",
		["Material.Limestone"] = "Slate",
		["Material.Pavement"] = "Concrete",
		["Material.Rock"] = "Slate",
		["Material.Mud"] = "Sand",
		["Material.LeafyGrass"] = "Grass",
		["Material.Cardboard"] = "Wood",
		["Material.Carpet"] = "Fabric",
		["Material.CeramicTiles"] = "Tile",
		["Material.ClayRoofTiles"] = "Brick",
		["Material.RoofShingles"] = "WoodPlanks",
		["Material.Leather"] = "Fabric",
		["Material.Plaster"] = "SmoothPlastic",
		["Material.Rubber"] = "Plastic",
		["Material.Snow"] = "Sand",
		["Material.Ground"] = "Grass",
		["Material.Glacier"] = "Ice",
		["Material.Basalt"] = "Slate",
		["Material.Air"] = "Plastic",
		["Material.Water"] = "Plastic",
		["EasingStyle.Cubic"] = "Quad",
		["EasingStyle.Exponential"] = "Quint",
		["EasingStyle.Circular"] = "Quart",
		["AnimationPriority.Action2"] = "Action",
		["AnimationPriority.Action3"] = "Action",
		["AnimationPriority.Action4"] = "Action",
	}
	C.ENUM_MAP = ENUM_MAP

	local fakeEnumMeta = { __type = "Enum" }
	local fakeItemMeta = { __type = "EnumItem" }
	fakeItemMeta.__index = fakeItemMeta
	fakeItemMeta.__tostring = function(self) return "Enum." .. self.EnumTypeName .. "." .. self.Name end
	function fakeItemMeta:IsA(n) return n == self.EnumTypeName end
	C.isFakeEnum = function(v)
		return type(v) == "table" and getmetatable(v) == fakeItemMeta
	end

	local proxies = {}
	local function enumProxy(name)
		local p = proxies[name]
		if p then return p end
		local okReal, real = pcall(idx, realEnum, name)
		if not okReal then real = nil end
		local items = {}
		local count = 0
		p = setmetatable({}, {
			__type = "Enum",
			__tostring = function() return name end,
			__index = function(self, item)
				if item == "GetEnumItems" then
					return function()
						local out = {}
						if real then
							for _, v in ipairs(real:GetEnumItems()) do out[#out + 1] = v end
						end
						for _, v in pairs(items) do
							if type(v) == "table" then out[#out + 1] = v end
						end
						return out
					end
				end
				if item == "FromName" then
					return function(_, n) return self[n] end
				end
				if item == "FromValue" then
					return function(_, v)
						if real then
							for _, e in ipairs(real:GetEnumItems()) do if e.Value == v then return e end end
						end
						return nil
					end
				end
				local v
				if real then
					local ok, r = pcall(idx, real, item)
					if ok and r ~= nil then v = r end
				end
				if v == nil then
					local mapped = ENUM_MAP[name .. "." .. item]
					if mapped and real then
						local ok, r = pcall(idx, real, mapped)
						if ok and r ~= nil then v = r end
					end
				end
				if v == nil and real and (name == "Font" or name == "Material") then
					local guess
					if name == "Font" then
						local lower = item:lower()
						guess = (lower:find("bold") or lower:find("black") or lower:find("heavy") or lower:find("semibold") or lower:find("bangers") or lower:find("luckiest") or lower:find("fredoka") or lower:find("oswald")) and "SourceSansBold" or (lower:find("light") and "SourceSansLight" or "SourceSans")
					else
						guess = "SmoothPlastic"
					end
					local ok, r = pcall(idx, real, guess)
					if ok and r ~= nil then v = r end
				end
				if v == nil then
					count = count + 1
					v = setmetatable({ Name = item, Value = 1000 + count, EnumTypeName = name, EnumType = self }, fakeItemMeta)
					items[item] = v
				end
				rawset(self, item, v)
				return v
			end,
		})
		proxies[name] = p
		return p
	end

	G.Enum = setmetatable({}, {
		__index = function(_, name) return enumProxy(name) end,
		__type = "Enums",
	})
	C.enum = function(path)
		local a, b = path:match("^([^.]+)%.(.+)$")
		return G.Enum[a][b]
	end

	function C.enumName(v)
		if v == nil then return nil end
		if type(v) == "table" then return rawget(v, "Name") end
		if type(v) == "string" then return v end
		local ok, n = pcall(idx, v, "Name")
		if ok then return n end
		return tostring(v):match("[^.]+$")
	end

	function C.coerceEnum(enumName, v)
		if type(v) == "string" then
			return G.Enum[enumName][v]
		end
		if type(v) == "number" then
			local okReal, real = pcall(idx, realEnum, enumName)
			if okReal and real then
				for _, e in ipairs(real:GetEnumItems()) do if e.Value == v then return e end end
			end
		end
		return v
	end
end
