do
	local props = C.props
	local setShadow = C.setShadow
	local paddingOf = C.paddingOf
	local layoutOf = C.layoutOf
	local transformed = C.transformed
	local constraintsOf = C.weak()
	C.constraintsOf = constraintsOf
	local registered = C.weak()
	local watchedContainers = C.weak()
	local dirty = {}
	local dirtyList = {}

	local function rawGet(o, k)
		local ok, v = pcall(idx, o, k)
		if ok then return v end
		return nil
	end
	local function isGui(o) return C.isGui(o) end
	local function name(e) return C.enumName(e) end

	local function markDirty(container)
		if not container or dirty[container] then return end
		dirty[container] = true
		dirtyList[#dirtyList + 1] = container
	end
	C.markDirty = markDirty

	local function padPixels(parent)
		local pad = paddingOf[parent]
		if not pad then return 0, 0, 0, 0 end
		local abs = rawGet(parent, "AbsoluteSize") or realVector2.new()
		local l, r, t, b
		if type(pad) == "table" then
			l, r, t, b = pad.L, pad.R, pad.T, pad.B
		else
			local p = props(pad)
			local z = realUDim.new(0, 0)
			l, r, t, b = p.PaddingLeft or z, p.PaddingRight or z, p.PaddingTop or z, p.PaddingBottom or z
		end
		return l.Scale * abs.x + l.Offset, r.Scale * abs.x + r.Offset, t.Scale * abs.y + t.Offset, b.Scale * abs.y + b.Offset
	end

	local function innerSize(parent)
		local abs = rawGet(parent, "AbsoluteSize") or realVector2.new()
		local ok, isScroll = pcall(parent.IsA, parent, "ScrollingFrame")
		if ok and isScroll then
			local cs = rawGet(parent, "CanvasSize")
			if cs then
				abs = realVector2.new(math.max(abs.x, cs.X.Scale * abs.x + cs.X.Offset), math.max(abs.y, cs.Y.Scale * abs.y + cs.Y.Offset))
			end
		end
		local l, r, t, b = padPixels(parent)
		return abs.x - l - r, abs.y - t - b, l, t
	end

	local function sizeOf(child, w, h)
		local sh = props(child)
		local s = sh.Size or rawGet(child, "Size")
		if not s then return 0, 0 end
		local x = s.X.Scale * w + s.X.Offset
		local y = s.Y.Scale * h + s.Y.Offset
		local cons = constraintsOf[child]
		if cons then x, y = C.applyConstraints(child, x, y) end
		return x, y
	end

	function C.applyConstraints(o, w, h)
		local cons = constraintsOf[o]
		if not cons then return w, h end
		for c in pairs(cons) do
			if c.Parent == o then
				local dc = C.dummyClass(c)
				local p = props(c)
				local d = C.CLASSES[dc] and C.CLASSES[dc].defaults or {}
				if dc == "UISizeConstraint" then
					local mn, mx = p.MinSize or d.MinSize, p.MaxSize or d.MaxSize
					w = math.max(mn.x, math.min(mx.x, w))
					h = math.max(mn.y, math.min(mx.y, h))
				elseif dc == "UIAspectRatioConstraint" then
					local ratio = p.AspectRatio or 1
					local atype = name(p.AspectType) or "FitWithinMaxSize"
					local axis = name(p.DominantAxis) or "Width"
					if ratio > 0 then
						if atype == "ScaleWithParentSize" then
							if axis == "Width" then h = w / ratio else w = h * ratio end
						else
							if w / math.max(h, 1e-6) > ratio then w = h * ratio else h = w / ratio end
						end
					end
				end
			end
		end
		return w, h
	end

	local function layoutChildren(parent, layout)
		local list = {}
		local index = 0
		for _, c in ipairs(parent:GetChildren()) do
			index = index + 1
			if not C.hidden(c) and isGui(c) then
				local okv, vis = pcall(idx, c, "Visible")
				if layout and C.dummyClass(layout) == "UIPageLayout" or (okv and vis) then
					list[#list + 1] = { c = c, i = index }
				end
			end
		end
		local sort = layout and name(props(layout).SortOrder) or "LayoutOrder"
		local custom = layout and C.customSort and C.customSort[layout]
		table.sort(list, function(a, b)
			if custom then return custom(a.c, b.c) end
			if sort == "Name" then
				if a.c.Name ~= b.c.Name then return a.c.Name < b.c.Name end
				return a.i < b.i
			end
			if sort == "Custom" then return a.i < b.i end
			local la, lb = props(a.c).LayoutOrder or 0, props(b.c).LayoutOrder or 0
			if la ~= lb then return la < lb end
			return a.i < b.i
		end)
		local out = {}
		for i, e in ipairs(list) do out[i] = e.c end
		return out
	end
	C.layoutChildren = layoutChildren

	local function setReal(o, k, v)
		local cur = rawGet(o, k)
		if cur ~= v then pcall(C.setidx, o, k, v) end
	end

	local function automatic(parent, contentW, contentH)
		local psh = props(parent)
		local l, r, t, b = padPixels(parent)
		local ok, isScroll = pcall(parent.IsA, parent, "ScrollingFrame")
		if ok and isScroll then
			local mode = name(psh.AutomaticCanvasSize)
			if mode and mode ~= "None" then
				local cs = rawGet(parent, "CanvasSize")
				local x, y = cs.X, cs.Y
				if mode == "X" or mode == "XY" then x = realUDim.new(0, contentW + l + r) end
				if mode == "Y" or mode == "XY" then y = realUDim.new(0, contentH + t + b) end
				setReal(parent, "CanvasSize", realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset))
			end
		end
		local mode = name(psh.AutomaticSize)
		if mode and mode ~= "None" and isGui(parent) then
			local logical = psh.Size or rawGet(parent, "Size")
			local abs = rawGet(parent, "AbsoluteSize") or realVector2.new()
			local x, y = logical.X, logical.Y
			if mode == "X" or mode == "XY" then
				local need = contentW + l + r
				if need > abs.x then x = realUDim.new(0, need) end
			end
			if mode == "Y" or mode == "XY" then
				local need = contentH + t + b
				if need > abs.y then y = realUDim.new(0, need) end
			end
			if psh.Size == nil then psh.Size = logical end
			transformed[parent] = true
			setReal(parent, "Size", realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset))
		end
	end

	local function doList(parent, layout)
		local sh = props(layout)
		local d = C.CLASSES.UIListLayout.defaults
		local w, h, ox, oy = innerSize(parent)
		local vertical = (name(sh.FillDirection) or "Vertical") == "Vertical"
		local pad = sh.Padding or d.Padding
		local gap = pad.Scale * (vertical and h or w) + pad.Offset
		local kids = layoutChildren(parent, layout)
		local sizes = {}
		local total, cross = 0, 0
		for i, c in ipairs(kids) do
			local cw, ch = sizeOf(c, w, h)
			sizes[i] = { cw, ch }
			total = total + (vertical and ch or cw)
			cross = math.max(cross, vertical and cw or ch)
		end
		if #kids > 1 then total = total + gap * (#kids - 1) end
		local halign = name(sh.HorizontalAlignment) or "Left"
		local valign = name(sh.VerticalAlignment) or "Top"
		local cursor
		if vertical then
			cursor = valign == "Center" and (h - total) / 2 or (valign == "Bottom" and h - total or 0)
		else
			cursor = halign == "Center" and (w - total) / 2 or (halign == "Right" and w - total or 0)
		end
		for i, c in ipairs(kids) do
			local cw, ch = sizes[i][1], sizes[i][2]
			local x, y
			if vertical then
				y = cursor
				x = halign == "Center" and (w - cw) / 2 or (halign == "Right" and w - cw or 0)
				cursor = cursor + ch + gap
			else
				x = cursor
				y = valign == "Center" and (h - ch) / 2 or (valign == "Bottom" and h - ch or 0)
				cursor = cursor + cw + gap
			end
			setReal(c, "Position", realUDim2.new(0, math.floor(ox + x + 0.5), 0, math.floor(oy + y + 0.5)))
		end
		local content = vertical and realVector2.new(cross, total) or realVector2.new(total, cross)
		if sh.AbsoluteContentSize ~= content then setShadow(layout, "AbsoluteContentSize", content) end
		automatic(parent, content.x, content.y)
	end

	local function doGrid(parent, layout)
		local sh = props(layout)
		local d = C.CLASSES.UIGridLayout.defaults
		local w, h, ox, oy = innerSize(parent)
		local cell = sh.CellSize or d.CellSize
		local cpad = sh.CellPadding or d.CellPadding
		local cw = cell.X.Scale * w + cell.X.Offset
		local ch = cell.Y.Scale * h + cell.Y.Offset
		local px = cpad.X.Scale * w + cpad.X.Offset
		local py = cpad.Y.Scale * h + cpad.Y.Offset
		local kids = layoutChildren(parent, layout)
		local horizontal = (name(sh.FillDirection) or "Horizontal") == "Horizontal"
		local maxCells = sh.FillDirectionMaxCells or 0
		local per
		if horizontal then
			per = math.max(1, math.floor((w + px) / math.max(cw + px, 1)))
		else
			per = math.max(1, math.floor((h + py) / math.max(ch + py, 1)))
		end
		if maxCells > 0 then per = math.min(per, maxCells) end
		local n = #kids
		local lines = math.max(1, math.ceil(n / per))
		local cols = horizontal and math.min(per, n) or lines
		local rows = horizontal and lines or math.min(per, n)
		local contentW = cols * cw + math.max(cols - 1, 0) * px
		local contentH = rows * ch + math.max(rows - 1, 0) * py
		local halign = name(sh.HorizontalAlignment) or "Left"
		local valign = name(sh.VerticalAlignment) or "Top"
		local startX = halign == "Center" and (w - contentW) / 2 or (halign == "Right" and w - contentW or 0)
		local startY = valign == "Center" and (h - contentH) / 2 or (valign == "Bottom" and h - contentH or 0)
		local corner = name(sh.StartCorner) or "TopLeft"
		for i, c in ipairs(kids) do
			local k = i - 1
			local col, row
			if horizontal then
				col, row = k % per, math.floor(k / per)
			else
				row, col = k % per, math.floor(k / per)
			end
			if corner == "TopRight" or corner == "BottomRight" then col = cols - 1 - col end
			if corner == "BottomLeft" or corner == "BottomRight" then row = rows - 1 - row end
			local x = startX + col * (cw + px)
			local y = startY + row * (ch + py)
			setReal(c, "Size", realUDim2.new(0, math.floor(cw + 0.5), 0, math.floor(ch + 0.5)))
			setReal(c, "Position", realUDim2.new(0, math.floor(ox + x + 0.5), 0, math.floor(oy + y + 0.5)))
		end
		local content = realVector2.new(contentW, contentH)
		if sh.AbsoluteContentSize ~= content then setShadow(layout, "AbsoluteContentSize", content) end
		sh.AbsoluteCellSize = realVector2.new(cw, ch)
		sh.AbsoluteCellCount = realVector2.new(cols, rows)
		automatic(parent, contentW, contentH)
	end

	local pageTweens = C.weak()
	local function doPage(parent, layout)
		local sh = props(layout)
		local w, h, ox, oy = innerSize(parent)
		local kids = layoutChildren(parent, layout)
		local current = sh.CurrentPage
		local ci = 1
		for i, c in ipairs(kids) do if c == current then ci = i end end
		if not current and kids[1] then sh.CurrentPage = kids[1] end
		local horizontal = (name(sh.FillDirection) or "Horizontal") == "Horizontal"
		local pad = sh.Padding or realUDim.new(0, 0)
		local gap = pad.Scale * (horizontal and w or h) + pad.Offset
		pcall(C.setidx, parent, "ClipsDescendants", true)
		local targets = {}
		for i, c in ipairs(kids) do
			local cw, ch = sizeOf(c, w, h)
			local x, y
			if horizontal then
				x = (i - ci) * (w + gap) + (w - cw) / 2
				y = (h - ch) / 2
			else
				y = (i - ci) * (h + gap) + (h - ch) / 2
				x = (w - cw) / 2
			end
			targets[c] = realUDim2.new(0, math.floor(ox + x + 0.5), 0, math.floor(oy + y + 0.5))
		end
		local animated = sh.Animated
		if animated == nil then animated = true end
		local tweenTime = sh.TweenTime or 1
		if animated and tweenTime > 0 and pageTweens[layout] ~= nil then
			local info = G.TweenInfo.new(tweenTime, sh.EasingStyle or G.Enum.EasingStyle.Back, sh.EasingDirection or G.Enum.EasingDirection.Out)
			for c, target in pairs(targets) do
				local from = rawGet(c, "Position")
				if from ~= target then
					local start = tick()
					local token = {}
					pageTweens[c] = token
					C.spawn(function()
						while pageTweens[c] == token do
							local a = math.min((tick() - start) / tweenTime, 1)
							local e = C.ease(info.EasingStyle, info.EasingDirection, a)
							pcall(C.setidx, c, "Position", C.TYPE_METHODS.UDim2.Lerp(from, target, e))
							if a >= 1 then break end
							RunService.RenderStepped:wait()
						end
					end)
				end
			end
		else
			for c, target in pairs(targets) do setReal(c, "Position", target) end
		end
		pageTweens[layout] = true
	end

	local function doLayout(parent)
		local layout = layoutOf[parent]
		if not layout or layout.Parent ~= parent then
			layoutOf[parent] = nil
			for _, c in ipairs(parent:GetChildren()) do
				if transformed[c] then C.applyGui(c) end
			end
			return
		end
		local dc = C.dummyClass(layout)
		if dc == "UIListLayout" then doList(parent, layout)
		elseif dc == "UIGridLayout" then doGrid(parent, layout)
		elseif dc == "UIPageLayout" then doPage(parent, layout)
		end
	end
	function C.layoutNow(parent)
		if layoutOf[parent] then pcall(doLayout, parent) end
	end
	C.layoutSetsSize = function(layout) return C.dummyClass(layout) == "UIGridLayout" end

	local function guiApply(o)
		local sh = props(o)
		local parent = o.Parent
		if not parent then return end
		local layout = layoutOf[parent]
		local size = sh.Size or rawGet(o, "Size")
		local pos = sh.Position or rawGet(o, "Position")
		if not size or not pos then return end
		local pad = paddingOf[parent]
		local pl, pr, pt, pb
		if pad then
			if type(pad) == "table" then
				pl, pr, pt, pb = pad.L, pad.R, pad.T, pad.B
			else
				local p = props(pad)
				local z = realUDim.new(0, 0)
				pl, pr, pt, pb = p.PaddingLeft or z, p.PaddingRight or z, p.PaddingTop or z, p.PaddingBottom or z
			end
		end
		local sx, sy = size.X, size.Y
		if pl then
			local w = realUDim.new(1 - pl.Scale - pr.Scale, -pl.Offset - pr.Offset)
			local h = realUDim.new(1 - pt.Scale - pb.Scale, -pt.Offset - pb.Offset)
			sx = realUDim.new(size.X.Scale * w.Scale, size.X.Scale * w.Offset + size.X.Offset)
			sy = realUDim.new(size.Y.Scale * h.Scale, size.Y.Scale * h.Offset + size.Y.Offset)
		end
		if constraintsOf[o] then
			local abs = rawGet(parent, "AbsoluteSize") or realVector2.new()
			local w = sx.Scale * abs.x + sx.Offset
			local h = sy.Scale * abs.y + sy.Offset
			w, h = C.applyConstraints(o, w, h)
			sx, sy = realUDim.new(0, math.floor(w + 0.5)), realUDim.new(0, math.floor(h + 0.5))
		end
		local mode = name(sh.AutomaticSize)
		local growing = mode and mode ~= "None"
		if not (layout and C.dummyClass(layout) == "UIGridLayout") and not growing then
			setReal(o, "Size", realUDim2.new(sx.Scale, sx.Offset, sy.Scale, sy.Offset))
		end
		if layout then return end
		local a = sh.AnchorPoint or realVector2.new(0, 0)
		local x, y = pos.X, pos.Y
		if pl then
			local w = realUDim.new(1 - pl.Scale - pr.Scale, -pl.Offset - pr.Offset)
			local h = realUDim.new(1 - pt.Scale - pb.Scale, -pt.Offset - pb.Offset)
			x = realUDim.new(pl.Scale + pos.X.Scale * w.Scale, pl.Offset + pos.X.Scale * w.Offset + pos.X.Offset)
			y = realUDim.new(pt.Scale + pos.Y.Scale * h.Scale, pt.Offset + pos.Y.Scale * h.Offset + pos.Y.Offset)
		end
		if a.x ~= 0 or a.y ~= 0 then
			local rs = growing and rawGet(o, "Size") or realUDim2.new(sx.Scale, sx.Offset, sy.Scale, sy.Offset)
			x = realUDim.new(x.Scale - a.x * rs.X.Scale, x.Offset - a.x * rs.X.Offset)
			y = realUDim.new(y.Scale - a.y * rs.Y.Scale, y.Offset - a.y * rs.Y.Offset)
		end
		setReal(o, "Position", realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset))
	end
	C.applyGui = function(o) pcall(guiApply, o) end

	local function watchChild(parent, c)
		if registered[c] then return end
		registered[c] = true
		c.Changed:connect(function(prop)
			if prop == "AbsoluteSize" or prop == "Visible" or prop == "Name" or prop == "Size" then
				if layoutOf[c.Parent or false] then markDirty(c.Parent) end
				if prop == "AbsoluteSize" then
					for _, k in ipairs(c:GetChildren()) do
						if constraintsOf[k] then C.applyGui(k) end
					end
					if layoutOf[c] then markDirty(c) end
				end
			elseif prop == "Text" or prop == "TextBounds" then
				if C.textAutoSize then C.textAutoSize(c) end
			end
		end)
	end

	local function watchContainer(parent)
		if watchedContainers[parent] then return end
		watchedContainers[parent] = true
		for _, c in ipairs(parent:GetChildren()) do
			if isGui(c) then watchChild(parent, c) end
		end
		parent.ChildAdded:connect(function(c)
			if isGui(c) then
				watchChild(parent, c)
				if paddingOf[parent] or transformed[c] then
					C.becomeTransformed(c)
					C.applyGui(c)
				end
			end
			if layoutOf[parent] then markDirty(parent) end
		end)
		parent.ChildRemoved:connect(function()
			if layoutOf[parent] then markDirty(parent) end
		end)
		parent.Changed:connect(function(prop)
			if prop == "AbsoluteSize" or prop == "CanvasSize" then
				if layoutOf[parent] then markDirty(parent) end
				for _, c in ipairs(parent:GetChildren()) do
					if constraintsOf[c] then C.applyGui(c) end
				end
			end
		end)
	end

	local function strokeApply(stroke)
		local parent = stroke.Parent
		if not parent or not isGui(parent) then return end
		local p = props(stroke)
		local d = C.CLASSES.UIStroke.defaults
		local enabled = p.Enabled
		if enabled == nil then enabled = true end
		local mode = name(p.ApplyStrokeMode) or "Contextual"
		local color = p.Color or d.Color
		local transparency = p.Transparency or 0
		local thickness = p.Thickness or 1
		local isText = rawGet(parent, "TextStrokeColor3") ~= nil
		if isText and mode == "Contextual" then
			pcall(C.setidx, parent, "TextStrokeColor3", color)
			pcall(C.setidx, parent, "TextStrokeTransparency", enabled and math.max(transparency, 0) or 1)
		else
			pcall(C.setidx, parent, "BorderColor3", color)
			pcall(C.setidx, parent, "BorderSizePixel", enabled and transparency < 1 and math.floor(thickness + 0.5) or 0)
		end
	end

	local function gradientApply(gr)
		local parent = gr.Parent
		if not parent or not isGui(parent) then return end
		local p = props(gr)
		if p.Enabled == false then return end
		local seq = p.Color
		if not seq then return end
		local ok, kps = pcall(idx, seq, "Keypoints")
		if not ok or not kps or #kps == 0 then return end
		local r, g, b = 0, 0, 0
		for _, k in ipairs(kps) do r, g, b = r + k.Value.r, g + k.Value.g, b + k.Value.b end
		local n = #kps
		local tint = realColor3.new(r / n, g / n, b / n)
		local psh = props(parent)
		local base = psh.__baseColor
		if not base then
			base = rawGet(parent, "BackgroundColor3")
			psh.__baseColor = base
		end
		if base then
			pcall(C.setidx, parent, "BackgroundColor3", realColor3.new(base.r * tint.r, base.g * tint.g, base.b * tint.b))
		end
		local okImg = rawGet(parent, "ImageColor3")
		if okImg then
			local ib = psh.__baseImage or okImg
			psh.__baseImage = ib
			pcall(C.setidx, parent, "ImageColor3", realColor3.new(ib.r * tint.r, ib.g * tint.g, ib.b * tint.b))
		end
	end

	local function registerDummy(o, dc)
		local parent = o.Parent
		if not parent then return end
		dc = dc or C.dummyClass(o)
		if not dc then return end
		if dc == "UIListLayout" or dc == "UIGridLayout" or dc == "UIPageLayout" then
			layoutOf[parent] = o
			watchContainer(parent)
			markDirty(parent)
		elseif dc == "UIPadding" then
			paddingOf[parent] = o
			watchContainer(parent)
			for _, c in ipairs(parent:GetChildren()) do
				if isGui(c) then
					C.becomeTransformed(c)
					C.applyGui(c)
				end
			end
			if layoutOf[parent] then markDirty(parent) end
		elseif dc == "UIAspectRatioConstraint" or dc == "UISizeConstraint" then
			local set = constraintsOf[parent]
			if not set then
				set = {}
				constraintsOf[parent] = set
			end
			set[o] = true
			if parent.Parent then watchContainer(parent.Parent) end
			C.becomeTransformed(parent)
			C.applyGui(parent)
			if parent.Parent and layoutOf[parent.Parent] then markDirty(parent.Parent) end
		elseif dc == "UIStroke" then
			strokeApply(o)
		elseif dc == "UIGradient" then
			gradientApply(o)
		end
	end
	C.registerDummy = registerDummy

	C.onDummySet = function(o, dc, k)
		local parent = o.Parent
		if not parent then return end
		if dc == "UIListLayout" or dc == "UIGridLayout" or dc == "UIPageLayout" then
			markDirty(parent)
		elseif dc == "UIPadding" then
			for _, c in ipairs(parent:GetChildren()) do
				if transformed[c] then C.applyGui(c) end
			end
			if layoutOf[parent] then markDirty(parent) end
		elseif dc == "UIAspectRatioConstraint" or dc == "UISizeConstraint" then
			C.applyGui(parent)
			if parent.Parent and layoutOf[parent.Parent] then markDirty(parent.Parent) end
		elseif dc == "UIStroke" then
			strokeApply(o)
		elseif dc == "UIGradient" then
			gradientApply(o)
		end
	end

	C.onShadow = function(o, k)
		if k == "LayoutOrder" or k == "AutomaticSize" then
			local p = o.Parent
			if p and layoutOf[p] then markDirty(p) end
			if k == "AutomaticSize" and layoutOf[o] then markDirty(o) end
		end
	end
	C.onGuiSize = function(o)
		local p = o.Parent
		if p and layoutOf[p] then markDirty(p) end
	end

	local function textAutoSize(o)
		local sh = props(o)
		local mode = name(sh.AutomaticSize)
		if not mode or mode == "None" or layoutOf[o] then return end
		local bounds = rawGet(o, "TextBounds")
		if not bounds then return end
		local logical = sh.Size or rawGet(o, "Size")
		if sh.Size == nil then sh.Size = logical end
		transformed[o] = true
		local l, r, t, b = padPixels(o)
		local abs = rawGet(o, "AbsoluteSize") or realVector2.new()
		local x, y = logical.X, logical.Y
		if mode == "X" or mode == "XY" then x = realUDim.new(0, math.max(bounds.x + l + r, logical.X.Offset)) end
		if mode == "Y" or mode == "XY" then y = realUDim.new(0, math.max(bounds.y + t + b, logical.Y.Offset)) end
		setReal(o, "Size", realUDim2.new(x.Scale, x.Offset, y.Scale, y.Offset))
		if o.Parent and layoutOf[o.Parent] then markDirty(o.Parent) end
	end
	C.textAutoSize = textAutoSize

	local guiHidden = C.weak()
	function C.setGuiEnabled(gui, enabled)
		local store = guiHidden[gui]
		if not enabled then
			if store then return end
			store = {}
			guiHidden[gui] = store
			for _, c in ipairs(gui:GetChildren()) do
				if isGui(c) then
					store[c] = rawGet(c, "Visible")
					pcall(C.setidx, c, "Visible", false)
				end
			end
			store.__conn = gui.ChildAdded:connect(function(c)
				if isGui(c) and guiHidden[gui] then
					store[c] = rawGet(c, "Visible")
					pcall(C.setidx, c, "Visible", false)
				end
			end)
		else
			if not store then return end
			guiHidden[gui] = nil
			if store.__conn then store.__conn:disconnect() end
			for c, vis in pairs(store) do
				if c ~= "__conn" and c.Parent == gui then pcall(C.setidx, c, "Visible", vis) end
			end
		end
	end
	local oldVisSet = C.SET.Visible
	C.SET.Visible = function(o, v)
		local p = o.Parent
		local store = p and guiHidden[p]
		if store and store[o] ~= nil then
			store[o] = v
			return true
		end
		if p and layoutOf[p] then markDirty(p) end
		return false
	end
	C.GET.Visible = function(o)
		if not isInstance(o) then return false end
		local p = o.Parent
		local store = p and guiHidden[p]
		if store and store[o] ~= nil then return true, store[o] end
		return false
	end

	local INSET = 36
	local function setupScreenGui(gui)
		local sh = props(gui)
		if sh.IgnoreGuiInset then
			paddingOf[gui] = { L = realUDim.new(0, 0), R = realUDim.new(0, 0), T = realUDim.new(0, -INSET), B = realUDim.new(0, 0) }
			watchContainer(gui)
			for _, c in ipairs(gui:GetChildren()) do
				if isGui(c) then
					C.becomeTransformed(c)
					C.applyGui(c)
				end
			end
		end
		if sh.Enabled == false then C.setGuiEnabled(gui, false) end
	end
	C.setupScreenGui = setupScreenGui

	local function scanTree(root)
		local stack = { root }
		while #stack > 0 do
			local o = table.remove(stack)
			local okc, children = pcall(o.GetChildren, o)
			if okc then
				for _, c in ipairs(children) do
					local n = c.Name
					if n == "__compat_class" then
						registerDummy(o)
					elseif not n:find(C.HIDDEN) then
						stack[#stack + 1] = c
						local okt, sg = pcall(c.IsA, c, "ScreenGui")
						if okt and sg then setupScreenGui(c) end
						if isGui(c) then
							local sh = props(c)
							local a = sh.AnchorPoint
							if a and (a.x ~= 0 or a.y ~= 0) then transformed[c] = true end
							if sh.AutomaticSize and name(sh.AutomaticSize) ~= "None" then
								watchContainer(c)
								textAutoSize(c)
								if layoutOf[c] then markDirty(c) end
							end
						end
					end
				end
			end
		end
	end
	C.scanTree = scanTree

	local function hookRoot(root)
		scanTree(root)
		root.DescendantAdded:connect(function(d)
			local n = d.Name
			if n == "__compat_class" then
				if d.Parent then registerDummy(d.Parent) end
				return
			end
			if n:find(C.HIDDEN) then return end
			local p = d.Parent
			if p and p.Name:find(C.HIDDEN) then return end
			local okt, sg = pcall(d.IsA, d, "ScreenGui")
			if okt and sg then
				delay(0, function() setupScreenGui(d) end)
			end
			if isGui(d) then
				local sh = props(d)
				local a = sh.AnchorPoint
				if a and (a.x ~= 0 or a.y ~= 0) then transformed[d] = true end
				if sh.AutomaticSize and name(sh.AutomaticSize) ~= "None" then
					watchContainer(d)
					delay(0, function() textAutoSize(d) end)
				end
			end
		end)
	end

	if IS_SERVER then
		local sg = game:GetService("StarterGui")
		local store = Instance.new("Folder")
		store.Name = "__compat_startergui"
		store.Parent = game:GetService("ServerStorage")
		local function stash(c)
			if c:IsA("LocalScript") or c:IsA("Script") then return end
			c.Parent = store
		end
		for _, c in ipairs(sg:GetChildren()) do stash(c) end
		sg.ChildAdded:connect(function(c)
			wait()
			if c.Parent == sg then stash(c) end
		end)
		pcall(setidx, sg, "ResetPlayerGuiOnSpawn", false)
		local given = C.weak()
		local function scriptsLast(o)
			local scripts = {}
			for _, c in ipairs(o:GetChildren()) do
				if c:IsA("LocalScript") then
					scripts[#scripts + 1] = c
				else
					scriptsLast(c)
				end
			end
			for _, c in ipairs(scripts) do
				c.Parent = nil
				c.Parent = o
			end
		end
		local function giveGui(player)
			local pg = player:FindFirstChild("PlayerGui") or player:WaitForChild("PlayerGui", 10)
			if not pg then return end
			local first = not given[player]
			given[player] = true
			for _, template in ipairs(store:GetChildren()) do
				local okt, isSg = pcall(template.IsA, template, "ScreenGui")
				local resets = not (okt and isSg) or props(template).ResetOnSpawn ~= false
				if first or resets then
					if not first then
						local old = pg:FindFirstChild(template.Name)
						if old then old:Destroy() end
					end
					local copy = template:Clone()
					scriptsLast(copy)
					copy.Parent = pg
				end
			end
		end
		local function watch(player)
			player.CharacterAdded:connect(function(character)
				wait(0.25)
				if player.Character == character then giveGui(player) end
			end)
			if player.Character then giveGui(player) end
		end
		Players.PlayerAdded:connect(watch)
		for _, player in ipairs(Players:GetPlayers()) do watch(player) end
	end

	if IS_CLIENT then
		local function start()
			local player = Players.LocalPlayer
			while not player do
				wait()
				player = Players.LocalPlayer
			end
			local pg = player:WaitForChild("PlayerGui")
			hookRoot(pg)
			hookRoot(workspace)
			local step = RunService.RenderStepped
			step:connect(function()
				if #dirtyList == 0 then return end
				local list = dirtyList
				dirtyList = {}
				for _, container in ipairs(list) do
					dirty[container] = nil
					if container.Parent then pcall(doLayout, container) end
				end
			end)
			local sg = game:GetService("StarterGui")
			local first = true
			local function onCharacter()
				if first then
					first = false
					return
				end
				local ok, reset = pcall(idx, sg, "ResetPlayerGuiOnSpawn")
				if ok and reset then return end
				for _, template in ipairs(sg:GetChildren()) do
					local okt, isSg = pcall(template.IsA, template, "ScreenGui")
					if okt and isSg and props(template).ResetOnSpawn ~= false then
						local old = pg:FindFirstChild(template.Name)
						if old then old:Destroy() end
						local copy = template:Clone()
						copy.Parent = pg
					end
				end
			end
			player.CharacterAdded:connect(onCharacter)
			if player.Character then first = false end
		end

		local promptGui
		local promptsList = {}
		local promptsSet = C.weak()
		local function addPrompt(p)
			if promptsSet[p] then return end
			promptsSet[p] = true
			promptsList[#promptsList + 1] = p
		end
		C.addPrompt = addPrompt
		local oldRegister = C.registerDummy
		C.registerDummy = function(o, dc)
			dc = dc or C.dummyClass(o)
			if dc == "ProximityPrompt" then
				addPrompt(o)
				return
			end
			return oldRegister(o, dc)
		end
		registerDummy = C.registerDummy

		local holding
		local function fire(kind, prompt)
			local r = C.getRemote()
			r:FireServer(kind, prompt)
		end
		local function triggerLocal(prompt)
			local player = Players.LocalPlayer
			C.dummySignal(prompt, "Triggered"):Fire(player)
			C.ProximityPromptService.PromptTriggered:Fire(prompt, player)
		end
		function C.promptHoldBegin(prompt)
			local sh = props(prompt)
			local dur = sh.HoldDuration or 0
			fire("holdbegin", prompt)
			C.dummySignal(prompt, "PromptButtonHoldBegan"):Fire(Players.LocalPlayer)
			if dur <= 0 then
				fire("trigger", prompt)
				triggerLocal(prompt)
				return
			end
			local token = {}
			holding = { prompt = prompt, token = token, start = tick(), dur = dur }
		end
		function C.promptHoldEnd(prompt)
			if holding and holding.prompt == prompt then
				holding = nil
				fire("holdend", prompt)
				C.dummySignal(prompt, "PromptButtonHoldEnded"):Fire(Players.LocalPlayer)
			end
			fire("ended", prompt)
			C.dummySignal(prompt, "TriggerEnded"):Fire(Players.LocalPlayer)
		end

		local current
		local function ensureGui()
			if promptGui and promptGui.Parent then return promptGui end
			local player = Players.LocalPlayer
			local pg = player and player:FindFirstChild("PlayerGui")
			if not pg then return nil end
			local bb = realInstance.new("BillboardGui")
			bb.Name = "__compat_prompt"
			bb.Size = realUDim2.new(0, 160, 0, 52)
			bb.AlwaysOnTop = true
			pcall(function() bb.StudsOffset = realVector3.new(0, 1.5, 0) end)
			local frame = realInstance.new("Frame")
			frame.Name = "Box"
			frame.Size = realUDim2.new(1, 0, 1, 0)
			frame.BackgroundColor3 = realColor3.new(0.1, 0.1, 0.1)
			frame.BackgroundTransparency = 0.35
			frame.BorderSizePixel = 0
			frame.Parent = bb
			local key = realInstance.new("TextLabel")
			key.Name = "Key"
			key.Size = realUDim2.new(0, 40, 0, 40)
			key.Position = realUDim2.new(0, 6, 0, 6)
			key.BackgroundColor3 = realColor3.new(1, 1, 1)
			key.BackgroundTransparency = 0.1
			key.BorderSizePixel = 0
			key.Font = realEnum.Font.SourceSansBold
			key.FontSize = realEnum.FontSize.Size24
			key.TextColor3 = realColor3.new(0, 0, 0)
			key.Text = "E"
			key.Parent = frame
			local bar = realInstance.new("Frame")
			bar.Name = "Bar"
			bar.Size = realUDim2.new(0, 0, 0, 4)
			bar.Position = realUDim2.new(0, 6, 1, -6)
			bar.BackgroundColor3 = realColor3.new(1, 1, 1)
			bar.BorderSizePixel = 0
			bar.Parent = frame
			local action = realInstance.new("TextLabel")
			action.Name = "Action"
			action.BackgroundTransparency = 1
			action.Position = realUDim2.new(0, 52, 0, 22)
			action.Size = realUDim2.new(1, -56, 0, 24)
			action.Font = realEnum.Font.SourceSansBold
			action.FontSize = realEnum.FontSize.Size18
			action.TextColor3 = realColor3.new(1, 1, 1)
			action.TextXAlignment = realEnum.TextXAlignment.Left
			action.Parent = frame
			local object = realInstance.new("TextLabel")
			object.Name = "Object"
			object.BackgroundTransparency = 1
			object.Position = realUDim2.new(0, 52, 0, 4)
			object.Size = realUDim2.new(1, -56, 0, 18)
			object.Font = realEnum.Font.SourceSans
			object.FontSize = realEnum.FontSize.Size14
			object.TextColor3 = realColor3.new(0.85, 0.85, 0.85)
			object.TextXAlignment = realEnum.TextXAlignment.Left
			object.Parent = frame
			bb.Enabled = false
			bb.Parent = pg
			promptGui = bb
			return bb
		end

		local function keyName(sh)
			local k = C.enumName(sh.KeyboardKeyCode) or "E"
			return k
		end

		C.spawn(function()
			local UIS = game:GetService("UserInputService")
			UIS.InputBegan:connect(function(input, processed)
				if processed or not current then return end
				local sh = props(current)
				local want = sh.KeyboardKeyCode or realEnum.KeyCode.E
				if input.KeyCode == want or (input.KeyCode == realEnum.KeyCode.ButtonX and sh.GamepadKeyCode == nil) or input.KeyCode == sh.GamepadKeyCode then
					C.promptHoldBegin(current)
				end
			end)
			UIS.InputEnded:connect(function(input)
				if not current then return end
				local sh = props(current)
				local want = sh.KeyboardKeyCode or realEnum.KeyCode.E
				if input.KeyCode == want or input.KeyCode == sh.GamepadKeyCode then
					C.promptHoldEnd(current)
				end
			end)
			while true do
				wait(0.1)
				local player = Players.LocalPlayer
				local char = player and player.Character
				local root = char and (char:FindFirstChild("HumanoidRootPart") or char:FindFirstChild("Torso"))
				local best, bestDist
				if root then
					for i = #promptsList, 1, -1 do
						local p = promptsList[i]
						if not p.Parent or not p:IsDescendantOf(game) then
							table.remove(promptsList, i)
							promptsSet[p] = nil
						else
							local sh = props(p)
							if sh.Enabled ~= false then
								local pos = C.promptPosition(p)
								if pos then
									local d = (root.Position - pos).magnitude
									if d <= (sh.MaxActivationDistance or 10) and (not bestDist or d < bestDist) then
										best, bestDist = p, d
									end
								end
							end
						end
					end
				end
				if best ~= current then
					if current then
						if holding and holding.prompt == current then C.promptHoldEnd(current) end
						C.dummySignal(current, "PromptHidden"):Fire()
						C.ProximityPromptService.PromptHidden:Fire(current)
					end
					current = best
					if current then
						C.dummySignal(current, "PromptShown"):Fire(realEnum.UserInputType.Keyboard)
						C.ProximityPromptService.PromptShown:Fire(current, realEnum.UserInputType.Keyboard)
					end
				end
				local gui = ensureGui()
				if gui then
					local style = current and C.enumName(props(current).Style)
					if current and style ~= "Custom" then
						local sh = props(current)
						local part = current.Parent
						local okA, isAtt = pcall(part.IsA, part, "Attachment")
						if okA and isAtt then part = part.Parent end
						local okP, isPart = pcall(part.IsA, part, "BasePart")
						if okP and isPart then gui.Adornee = part end
						gui.Box.Action.Text = tostring(sh.ActionText or "Interact")
						gui.Box.Object.Text = tostring(sh.ObjectText or "")
						gui.Box.Key.Text = keyName(sh)
						gui.Enabled = true
					else
						gui.Enabled = false
					end
					local bar = gui.Box.Bar
					if holding and holding.prompt == current then
						local a = math.min((tick() - holding.start) / holding.dur, 1)
						bar.Size = realUDim2.new(a, -12, 0, 4)
						if a >= 1 then
							local p = holding.prompt
							holding = nil
							fire("trigger", p)
							triggerLocal(p)
						end
					else
						bar.Size = realUDim2.new(0, 0, 0, 4)
					end
				end
			end
		end)
		C.spawn(function()
			while true do
				if holding then
					local gui = promptGui
					if gui and gui.Parent then
						local a = math.min((tick() - holding.start) / holding.dur, 1)
						gui.Box.Bar.Size = realUDim2.new(a, -12 * a, 0, 4)
						if a >= 1 then
							local p = holding.prompt
							holding = nil
							fire("trigger", p)
							triggerLocal(p)
						end
					end
				end
				RunService.RenderStepped:wait()
			end
		end)
		C.spawn(function()
			local ok, err = pcall(start)
			if not ok then warn("compat gui: " .. tostring(err)) end
		end)
	end
end
