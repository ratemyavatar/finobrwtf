-----------------------------
--LOCAL FUNCTION DEFINITIONS-
-----------------------------
local c = game.Workspace.Terrain
local SetCell = c.SetCell
local SetWaterCell = c.SetWaterCell
local GetWaterCell = c.GetWaterCell
local GetCell = c.GetCell
local WorldToCellPreferSolid = c.WorldToCellPreferSolid
local CellCenterToWorld = c.CellCenterToWorld
local waterMaterial = 17

local brushTypes = {"Circular", "Square"}
local waterForceDirections = {"NegX","X","NegY","Y","NegZ","Z"}
local waterForces = {"None", "Small", "Medium", "Strong", "Max"}

local mediumWaterForce = Enum.WaterForce.Medium

-----------------
--DEFAULT VALUES-
-----------------
loaded = false
on = false

local terrainSelectorGui, terrainSelected, radiusLabel, dragBar, closeEvent, helpFrame, currSelectionUpdate, currSelectionDestroy, lastCell, lastLastCell, waterPanel = nil
local dragging = false
local painting = false

--- exposed values to user via gui
local currentMaterial = Enum.CellMaterial.Water
local radius = 2
local brushType = "Square"
local currWaterForceDirection = "NegX"
local currWaterForce = "None"

---------------
--PLUGIN SETUP-
---------------
self = PluginManager():CreatePlugin()
mouse = self:GetMouse()
mouse.Button1Down:connect(function() mouseDown(mouse) end)
mouse.Button1Up:connect(function() mouseUp(mouse) end)
mouse.Move:connect(function() mouseMove(mouse) end)

self.Deactivation:connect(function()
	Off()
end)
toolbar = self:CreateToolbar("Terrain")
toolbarbutton = toolbar:CreateButton("", "Material Brush", "materialBrush.png")
toolbarbutton.Click:connect(function()
	if on then
		Off()
	elseif loaded then
		On()
	end
end)

-- lua library load
local RbxGui = LoadLibrary("RbxGui")
local RbxUtil = LoadLibrary("RbxUtility")

-----------------------
--FUNCTION DEFINITIONS-
-----------------------
function paintWaterfall(setCells)
	if setCells then
		print("water fall paint")
		for i = 1, #setCells do
			SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce,  Enum.WaterDirection.NegY)
		end
	end
end

function setWaterDirection(mouseCellPos, setCells)
	if not setCells then return end
	if #setCells <= 0 then return end


	local initX = setCells[1].xPos
	local initZ = setCells[1].zPos

	local endX = setCells[#setCells].xPos
	local endZ = setCells[#setCells].zPos

	local zWidth = math.abs(endZ - initZ)
	local zMiddle = math.ceil(zWidth/2 + initZ)
	local xMiddle = math.ceil(zWidth/2 + initX)

	local down = endX - initX
	local up,left,right = nil
	if down < 0 then
		down = Enum.WaterDirection.NegX
		up = Enum.WaterDirection.X
		left = Enum.WaterDirection.Z
		right = Enum.WaterDirection.NegZ
	else
		down = Enum.WaterDirection.X
		up = Enum.WaterDirection.NegX
		left = Enum.WaterDirection.NegZ
		right = Enum.WaterDirection.Z
	end


	for i = 1, #setCells do
		if(setCells[i].xPos == initX) then
			SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, down)
		elseif(setCells[i].xPos == endX) then
			SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, up)
		else
			if setCells[i].zPos < zMiddle then
				SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, right)
			elseif setCells[i].zPos > zMiddle then
				SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, left)
			else
				if setCells[i].xPos < xMiddle then
					SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, down)
				elseif  setCells[i].xPos > xMiddle then
					SetWaterCell(c, setCells[i].xPos, setCells[i].yPos, setCells[i].zPos, mediumWaterForce, up)
				end
			end
		end
	end
end

function paintCircular(cellPos, setCells)
	local radiusSquared = radius * radius
	
	local finalX = cellPos.x + radius
	local finalZ = cellPos.z + radius
	local finalY = cellPos.y + radius

	for x = cellPos.x - radius, finalX do
		for z = cellPos.z - radius, finalZ do
			for y = cellPos.y - radius, finalY do
				local tempCellPos = Vector3.new(x, y, z)
				local holdDist = tempCellPos - cellPos
				local distSq = (holdDist):Dot(holdDist)
				if (distSq < radiusSquared) then
					local oldMaterial, oldType, oldOrientation = GetCell(c, x, y, z)
					if oldMaterial.Value > 0 then 
						if currentMaterial ~= waterMaterial then
							SetCell(c, x, y, z, currentMaterial, oldType, oldOrientation)
						else
							table.insert(setCells,{xPos = x, yPos = y, zPos = z})
						end
					end
				end
			end
		end
	end
end

function paintSquare(cellPos, setCells)
	local finalX = cellPos.x + radius - 1 
	local finalZ = cellPos.z + radius - 1
	local finalY = cellPos.y + radius - 1
	
	for x = cellPos.x - radius + 1, finalX do
		for z = cellPos.z - radius + 1, finalZ do
			for y = cellPos.y - radius + 1, finalY do
				local tempCellPos = Vector3.new(x, y, z)
				local oldMaterial, oldType, oldOrientation = GetCell(c, x, y, z)
				if oldMaterial.Value > 0 then 
					if currentMaterial ~= waterMaterial then
						SetCell(c, x, y, z, currentMaterial, oldType, oldOrientation)
					else
						table.insert(setCells,{xPos = x, yPos = y, zPos = z})
					end
				end
			end
		end
	end
end

function paint(startPos)
	if startPos and c then
		local cellPos = startPos
		local setCells = {}

		if brushType == "Circular" then
			paintCircular(cellPos, setCells)
		elseif brushType == "Square" then
			paintSquare(cellPos, setCells)
		end

		if (currentMaterial == waterMaterial) then
			setWaterDirection(cellPos, setCells)
		end

		return setCells
	end
end

function calculateRegion( mouse )
	local cellPos = WorldToCellPreferSolid(c, mouse.Hit.p)

	local lowVec = Vector3.new(cellPos.x - radius,cellPos.y - radius,cellPos.z - radius)
	local highVec = Vector3.new(cellPos.x + radius,cellPos.y + radius,cellPos.z + radius)
	lowVec = CellCenterToWorld(c,lowVec.x,lowVec.y,lowVec.z)
	highVec = CellCenterToWorld(c,highVec.x,highVec.y,highVec.z)

	return Region3.new(lowVec + Vector3.new(2,2,2),highVec - Vector3.new(2,2,2))
end

function createSelection(mouse, massSelection)
	currSelectionUpdate, currSelectionDestroy = RbxUtil.SelectTerrainRegion(calculateRegion(mouse),BrickColor.new("Lime green"),massSelection,game.CoreGui)
end

function updateSelection(mouse)
	if not currSelectionUpdate then
		createSelection(mouse, radius > 4)
	else
		currSelectionUpdate(calculateRegion(mouse),BrickColor.new("Lime green"))
	end
end

function setPositionDirectionality()
	-- no dragging occured, lets set our water to be stagnant
	if lastCell and not lastLastCell then
		local cellsToSet = paint(lastCell)
		for i = 1, #cellsToSet do
			SetWaterCell(c, cellsToSet[i].xPos, cellsToSet[i].yPos, cellsToSet[i].zPos, Enum.WaterForce.None, Enum.WaterDirection.NegX)
		end
		return
	end

	local overallDirection = (lastCell - lastLastCell).unit
	local cellsToSet = paint(lastCell)

	local absX, absY, absZ = math.abs(overallDirection.X),math.abs(overallDirection.Y),math.abs(overallDirection.Z)
	local direction = nil

	if absX > absY and absX > absZ then
		if overallDirection.X > 0 then
			direction = Enum.WaterDirection.X
		else
			direction = Enum.WaterDirection.NegX
		end
	elseif absY > absX and absY > absZ then
		if overallDirection.Y > 0 then
			direction = Enum.WaterDirection.Y
		else
			direction = Enum.WaterDirection.NegY
		end
	elseif absZ > absX and absZ > absY then
		if overallDirection.Z > 0 then
			direction = Enum.WaterDirection.Z
		else
			direction = Enum.WaterDirection.NegZ
		end
	end

	if not direction then -- this should never be hit, but just in case
		direction = Enum.WaterDirection.NegX
	end

	for i = 1, #cellsToSet do
		SetWaterCell(c, cellsToSet[i].xPos, cellsToSet[i].yPos, cellsToSet[i].zPos, mediumWaterForce, direction)
	end
end

function mouseDown(mouse)
	if on and mouse.Target == game.Workspace.Terrain then
		dragging = true
		if mouse and mouse.Hit then
			if mouse.Target == game.Workspace.Terrain then
				local newCell = WorldToCellPreferSolid(c, mouse.Hit.p)
				if newCell then
					lastCell = newCell
					paint(newCell)
				end
			end
		end
	end
end

function mouseUp(mouse)
	dragging = false

	-- we need to fix directionality on last cell set (if water)
	if currentMaterial == waterMaterial then
		setPositionDirectionality()
	end

	lastLastCell = nil
	lastCell = nil
end

function directionIsDown(fromCell, toCell)
	if not fromCell and not toCell then
		return false
	end

	local direction = (toCell - fromCell).unit
	local absX,absY,absZ = math.abs(direction.X),math.abs(direction.Y),math.abs(direction.Z)
	if absY > absX and absY > absZ then
		return true
	end

	local viableCells = paint(toCell)
	if not viableCells then return false end
	if #viableCells < 2 then return false end

	local lowX, lowY,lowZ = viableCells[1].xPos,viableCells[1].yPos,viableCells[1].zPos
	local highX, highY, highZ = lowX, lowY,lowZ

	for i = 2, #viableCells do
		if viableCells[i].xPos < lowX then
			lowX = viableCells[i].xPos
		end
		if viableCells[i].xPos > highX then
			highX = viableCells[i].xPos
		end

		if viableCells[i].yPos < lowY then
			lowY = viableCells[i].yPos
		end
		if viableCells[i].yPos > highY then
			highY = viableCells[i].yPos
		end

		if viableCells[i].zPos < lowZ then
			lowZ = viableCells[i].zPos
		end
		if viableCells[i].zPos > highZ then
			highZ = viableCells[i].zPos
		end
	end

	local xRange, yRange, zRange = math.abs(highX - lowX), math.abs(highY - lowY), math.abs(highY - lowY)
	if yRange > xRange or yRange > zRange then
		return true
	end 


	return false
end

function mouseMove(mouse)
	if on then
		if mouse.Target == game.Workspace.Terrain then
			if lastCell ~= WorldToCellPreferSolid(c, mouse.Hit.p) then
				updateSelection(mouse)
				local newCell = WorldToCellPreferSolid(c, mouse.Hit.p)

				if dragging then
					while painting do
						print("WAITING")
						wait(1/30)
					end

					painting = true
						local setCells = paint(newCell)
						if lastCell and newCell then
							if (lastCell - newCell).magnitude > 1 then
								paintBetweenPoints(lastCell,newCell)
							elseif directionIsDown(lastCell, newCell) then
								paintWaterfall(setCells)
							end
						end
						lastLastCell = lastCell
						lastCell = newCell
					painting = false
				end

			end
		else
			destroySelection()
		end
	end
end

function destroySelection()
	if currSelectionUpdate then
		currSelectionUpdate = nil
	end
	if currSelectionDestroy then
		currSelectionDestroy()
		currSelectionDestroy = nil
	end
end

function moveTowardsGoal(direction, currPos, goalPos, currCell)
	if currPos ~= goalPos then
		if currPos < goalPos then
			if direction == "X" then
				currCell = Vector3.new(currCell.X + 1, currCell.Y,currCell.Z)
			elseif direction == "Y" then
				currCell = Vector3.new(currCell.X, currCell.Y + 1,currCell.Z)
			elseif direction == "Z" then
				currCell = Vector3.new(currCell.X, currCell.Y,currCell.Z + 1)
			end
		elseif currPos > goalPos then
			if direction == "X" then
				currCell = Vector3.new(currCell.X - 1, currCell.Y,currCell.Z)
			elseif direction == "Y" then
				currCell = Vector3.new(currCell.X, currCell.Y - 1,currCell.Z)
			elseif direction == "Z" then
				currCell = Vector3.new(currCell.X, currCell.Y,currCell.Z - 1)
			end
		end
	end

	return currCell
end

function interpolateOneDim(direction, currPos, goalPos, currCell)
	if currPos ~= goalPos then

		currCell = moveTowardsGoal(direction,currPos,goalPos,currCell)

		--[[
		if currPos < goalPos then
			if direction == "X" then
				currCell = Vector3.new(currCell.X + 1, currCell.Y,currCell.Z)
			elseif direction == "Y" then
				currCell = Vector3.new(currCell.X, currCell.Y + 1,currCell.Z)
			elseif direction == "Z" then
				currCell = Vector3.new(currCell.X, currCell.Y,currCell.Z + 1)
			end
		elseif currPos > goalPos then
			if direction == "X" then
				currCell = Vector3.new(currCell.X - 1, currCell.Y,currCell.Z)
			elseif direction == "Y" then
				currCell = Vector3.new(currCell.X, currCell.Y - 1,currCell.Z)
			elseif direction == "Z" then
				currCell = Vector3.new(currCell.X, currCell.Y,currCell.Z - 1)
			end
		end]]

		local material = GetCell(c,currCell.X,currCell.Y,currCell.Z)

		--local drops = 0
		--while material == Enum.CellMaterial.Empty and currCell.Y > 0 do
		--	drops = drops + 1
		--	currCell = Vector3.new(currCell.X,currCell.Y - 1, currCell.Z)
		--	material = GetCell(c,currCell.X,currCell.Y,currCell.Z)
		--end
		--if drops <= (radius - 1) then
			paint(currCell)
		--end
	end

	return currCell
end

function paintBetweenPoints( lastCell,newCell)
	local currCell = lastCell

	if directionIsDown(lastCell, currCell) then
		while currCell.X ~= newCell.X or currCell.Z ~= newCell.Z or currCell.Y ~= newCell.Y do
			currCell = moveTowardsGoal("X",currCell.X,newCell.X,currCell)
			currCell = moveTowardsGoal("Z",currCell.Z,newCell.Z,currCell)
			currCell = moveTowardsGoal("Y",currCell.Y,newCell.Y,currCell)

			local setCells = paint(currCell)
			paintWaterfall(setCells)
		end
	else
		while currCell.X ~= newCell.X or currCell.Z ~= newCell.Z or currCell.Y ~= newCell.Y do
			currCell = interpolateOneDim("X",currCell.X,newCell.X,currCell)
			currCell = interpolateOneDim("Z",currCell.Z,newCell.Z,currCell)
			currCell = interpolateOneDim("Y",currCell.Y,newCell.Y,currCell)
		end
	end
end

function On()
	self:Activate(true)
	toolbarbutton:SetActive(true)

	dragBar.Visible = true
	on = true
end

function Off()
	toolbarbutton:SetActive(false)

	destroySelection()
	dragBar.Visible = false
	on = false
end

------
--GUI-
------

--screengui
local g = Instance.new("ScreenGui", game:GetService("CoreGui"))
g.Name = "MaterialPainterGui"

dragBar, containerFrame, helpFrame, closeEvent = RbxGui.CreatePluginFrame("Material Brush",UDim2.new(0,163,0,285),UDim2.new(0,0,0,0),false,g)
dragBar.Visible = false

helpFrame.Size = UDim2.new(0,200,0,250)
helpFrame.ZIndex = 3
local textHelp = Instance.new("TextLabel",helpFrame)
textHelp.Name = "TextHelp"
textHelp.Font = Enum.Font.ArialBold
textHelp.FontSize = Enum.FontSize.Size14
textHelp.TextColor3 = Color3.new(1,1,1)
textHelp.Size = UDim2.new(1,-6,1,-6)
textHelp.Position = UDim2.new(0,3,0,3)
textHelp.TextXAlignment = Enum.TextXAlignment.Left
textHelp.BackgroundTransparency = 1
textHelp.TextWrap = true
textHelp.ZIndex = 4
textHelp.Text = "Changes existing terrain blocks to the specified material.  Simply hold the mouse down and drag to 'paint' the terrain (only cells inside the selection box will be affected).\n\nSize: The size of the brush we paint terrain with\n\nBrush Type: The shape we paint terrain with inside of our selection box.\n\nMaterial Selection: The terrain material we will paint."

closeEvent.Event:connect(function() Off() end)

terrainSelectorGui, terrainSelected, forceTerrainSelection = RbxGui.CreateTerrainMaterialSelector(UDim2.new(1, -10, 0, 184),UDim2.new(0, 5, 1, -190))
terrainSelectorGui.Parent = containerFrame
terrainSelectorGui.BackgroundTransparency = 1
terrainSelectorGui.BorderSizePixel = 0
terrainSelected.Event:connect(function ( newMaterial )
	currentMaterial = newMaterial
	--waterPanel.Visible = (newMaterial == waterMaterial)
end)
forceTerrainSelection(17)

--current radius display label
radiusLabel = Instance.new("TextLabel", containerFrame)
radiusLabel.Name = "RadiusLabel"
radiusLabel.Position = UDim2.new(0, 10, 0, 5)
radiusLabel.Size = UDim2.new(1, -3, 0, 14)
radiusLabel.Text = "Size: " .. radius
radiusLabel.TextColor3 = Color3.new(0.95, 0.95, 0.95)
radiusLabel.Font = Enum.Font.ArialBold
radiusLabel.FontSize = Enum.FontSize.Size14
radiusLabel.BorderColor3 = Color3.new(0, 0, 0)
radiusLabel.BackgroundTransparency = 1
radiusLabel.TextXAlignment = Enum.TextXAlignment.Left

--radius slider
radSliderGui, radSliderPosition = RbxGui.CreateSlider(10, 0, UDim2.new(0, 0, 0, 18))
radSliderGui.Size = UDim2.new(1,-2,0,20)
radSliderGui.Position = UDim2.new(0,0,0,24)
radSliderGui.Parent = containerFrame
radBar = radSliderGui:FindFirstChild("Bar")
radBar.Size = UDim2.new(1, -20, 0, 5)
radBar.Position = UDim2.new(0,10,0.5,-3)
radSliderPosition.Value = radius
radSliderPosition.Changed:connect(function()
	radius = radSliderPosition.Value
	radiusLabel.Text = "Size: " .. radius
	destroySelection()
end)

local brushTypeChanged = function(newBrush)
	brushType = newBrush
end
-- brush type drop-down
local brushDropDown, forceSelection = RbxGui.CreateDropDownMenu(brushTypes, brushTypeChanged)
forceSelection("Square")
brushDropDown.Size = UDim2.new(1,-10,0,25)
brushDropDown.Position = UDim2.new(0,5,0,65)
brushDropDown.Parent = containerFrame

local brushLabel = radiusLabel:Clone()
brushLabel.Name = "BrushLabel"
brushLabel.Text = "Brush Type"
brushLabel.Position = UDim2.new(0,10,0,50)
brushLabel.Parent = containerFrame

--------------------------
--SUCCESSFUL LOAD MESSAGE-
--------------------------
loaded = true