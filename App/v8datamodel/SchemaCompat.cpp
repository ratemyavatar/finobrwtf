#include "stdafx.h"

#include "V8DataModel/SchemaCompat.h"
#include "V8DataModel/Camera.h"
#include "V8DataModel/DataModel.h"
#include "V8DataModel/Explosion.h"
#include "V8DataModel/GuiService.h"
#include "V8DataModel/PhysicsSettings.h"
#include "V8DataModel/TextBox.h"
#include "V8DataModel/TextButton.h"
#include "V8DataModel/TextLabel.h"
#include "V8DataModel/GameBasicSettings.h"
#include "V8DataModel/UserInputService.h"
#include "V8DataModel/Workspace.h"
#include "V8DataModel/ClickDetector.h"
#include "V8DataModel/Attachment.h"
#include "Script/LuaSourceContainer.h"
#include "Network/Player.h"
#include "Network/Players.h"
#include "Util/SoundChannel.h"

namespace RBX {

const char* const sConstraint = "Constraint";
const char* const sBallSocketConstraint = "BallSocketConstraint";
const char* const sHingeConstraint = "HingeConstraint";
const char* const sMoveToConstraint = "MoveToConstraint";
const char* const sRodConstraint = "RodConstraint";
const char* const sRopeConstraint = "RopeConstraint";
const char* const sSlidingBallConstraint = "SlidingBallConstraint";
const char* const sCylindricalConstraint = "CylindricalConstraint";
const char* const sPrismaticConstraint = "PrismaticConstraint";
const char* const sSpringConstraint = "SpringConstraint";
const char* const sPostEffect = "PostEffect";
const char* const sBloomEffect = "BloomEffect";
const char* const sBlurEffect = "BlurEffect";
const char* const sColorCorrectionEffect = "ColorCorrectionEffect";
const char* const sSunRaysEffect = "SunRaysEffect";
const char* const sThumbnailGenerator = "ThumbnailGenerator";
const char* const sMeshPart = "MeshPart";

namespace Reflection {
template<>
EnumDesc<ActuatorType>::EnumDesc()
	:EnumDescriptor("ActuatorType")
{
	addPair(ActuatorType_None, "None");
	addPair(ActuatorType_Motor, "Motor");
	addPair(ActuatorType_Servo, "Servo");
}

template<>
EnumDesc<GameAvatarType>::EnumDesc()
	:EnumDescriptor("GameAvatarType")
{
	addPair(GameAvatarType_R6, "R6");
	addPair(GameAvatarType_R15, "R15");
	addPair(GameAvatarType_PlayerChoice, "PlayerChoice");
}
}

namespace {

using Reflection::PropertyDescriptor;

const PropertyDescriptor::Attributes REP = PropertyDescriptor::STANDARD;
const PropertyDescriptor::Attributes NOREP = PropertyDescriptor::STANDARD_NO_REPLICATE;

template<class C, class T, int ID>
struct ExtraProp
{
	Reflection::PropDescriptor<C, T> desc;
	static const char* reg(const char* name, const T& def) { Instance::registerExtraProperty(ID, boost::any(def)); return name; }
	ExtraProp(const char* name, const T& def, PropertyDescriptor::Attributes flags)
		: desc(reg(name, def), "Data", &Instance::getExtra<T, ID>, &Instance::setExtra<T, ID>, flags)
	{
		Instance::bindExtraDescriptor(ID, &desc);
	}
};

template<class C, class T, int ID>
struct ExtraReadOnlyProp
{
	Reflection::PropDescriptor<C, T> desc;
	static const char* reg(const char* name, const T& def) { Instance::registerExtraProperty(ID, boost::any(def)); return name; }
	ExtraReadOnlyProp(const char* name, const T& def)
		: desc(reg(name, def), "Data", &Instance::getExtra<T, ID>, NULL, PropertyDescriptor::UI)
	{
	}
};

template<class C, class T, int ID>
struct ExtraEnumProp
{
	Reflection::EnumPropDescriptor<C, T> desc;
	static const char* reg(const char* name, const T& def) { Instance::registerExtraProperty(ID, boost::any(def)); return name; }
	ExtraEnumProp(const char* name, const T& def, PropertyDescriptor::Attributes flags)
		: desc(reg(name, def), "Data", &Instance::getExtra<T, ID>, &Instance::setExtra<T, ID>, flags)
	{
		Instance::bindExtraDescriptor(ID, &desc);
	}
};

template<class C, int ID>
struct ExtraRefProp
{
	Reflection::RefPropDescriptor<C, Instance> desc;
	ExtraRefProp(const char* name, PropertyDescriptor::Attributes flags)
		: desc(name, "Data", &Instance::getExtraRef<ID>, &Instance::setExtraRef<ID>, flags)
	{
		Instance::bindExtraDescriptor(ID, &desc);
	}
};

#define RBX_EXTRA_EVENT(C, Sig, ID) Reflection::RemoteEventDesc<C, Sig, rbx::remote_signal<Sig>, rbx::remote_signal<Sig>* (C::*)(bool)>
#define RBX_EXTRA_SIGNAL(Sig, ID) &Instance::getExtraSignal<rbx::remote_signal<Sig>, ID>

using namespace SchemaId;
using Reflection::RemoteEventCommon;

REFLECTION_BEGIN();

ExtraProp<Camera, float, CameraHeadScale> p_CameraHeadScale("HeadScale", 1.0f, REP);
ExtraEnumProp<DataModel, GameAvatarType, DataModelGameAvatarType> p_GameAvatarType("GameAvatarType", GameAvatarType_R6, REP);
ExtraReadOnlyProp<DataModel, bool, DataModelIsSFFlagsLoaded> p_IsSFFlagsLoaded("IsSFFlagsLoaded", true);
ExtraProp<Explosion, bool, ExplosionVisible> p_ExplosionVisible("Visible", true, REP);
ExtraProp<GuiService, bool, GuiServiceScreenGuiEnabled> p_ScreenGuiEnabled("ScreenGuiEnabled", true, PropertyDescriptor::UI);
ExtraProp<LuaSourceContainer, std::string, LuaSourceScriptGuid> p_ScriptGuid("ScriptGuid", std::string(), REP);
ExtraReadOnlyProp<PartOperation, int, PartOperationTriangleCount> p_TriangleCount("TriangleCount", 0);
ExtraProp<PhysicsSettings, bool, PhysicsAttachmentsShown> p_AreAttachmentsShown("AreAttachmentsShown", false, REP);
ExtraProp<PhysicsSettings, bool, PhysicsConstraintsShown> p_AreConstraintsShown("AreConstraintsShown", false, REP);
ExtraProp<Network::Player, int, PlayerCharacterAppearanceId> p_CharacterAppearanceId("CharacterAppearanceId", 0, REP);
ExtraRefProp<Network::Player, PlayerTeam> p_PlayerTeam("Team", PropertyDescriptor::UI);
ExtraProp<Soundscape::SoundChannel, float, SoundXmlMaxDistance> p_XmlMaxDistance("xmlRead_MaxDistance_3", 10000.0f, PropertyDescriptor::UI);
ExtraProp<Soundscape::SoundChannel, float, SoundXmlMinDistance> p_XmlMinDistance("xmlRead_MinDistance_3", 10.0f, PropertyDescriptor::UI);
ExtraProp<TextBox, bool, TextBoxConfidential> p_TextBoxConfidential("Confidential", false, PropertyDescriptor::UI);
ExtraProp<GuiTextButton, bool, TextButtonConfidential> p_TextButtonConfidential("Confidential", false, PropertyDescriptor::UI);
ExtraProp<TextLabel, bool, TextLabelConfidential> p_TextLabelConfidential("Confidential", false, PropertyDescriptor::UI);
ExtraProp<GameBasicSettings, bool, UserGameSettingsChatVisible> p_ChatVisible("ChatVisible", true, REP);
ExtraProp<UserInputService, float, UserInputMouseDeltaSensitivity> p_MouseDeltaSensitivity("MouseDeltaSensitivity", 1.0f, PropertyDescriptor::UI);
ExtraProp<Workspace, float, WorkspaceGravity> p_Gravity("Gravity", 196.2f, REP);

ExtraRefProp<ReplicatedConstraint, ConstraintAttachment0> p_Attachment0("Attachment0", REP);
ExtraRefProp<ReplicatedConstraint, ConstraintAttachment1> p_Attachment1("Attachment1", REP);
ExtraProp<ReplicatedConstraint, bool, ConstraintEnabled> p_ConstraintEnabled("Enabled", true, REP);

ExtraProp<BallSocketConstraint, bool, BallSocketLimitsEnabled> p_BallLimits("LimitsEnabled", false, REP);
ExtraProp<BallSocketConstraint, float, BallSocketRestitution> p_BallRestitution("Restitution", 0.0f, REP);
ExtraProp<BallSocketConstraint, float, BallSocketUpperAngle> p_BallUpperAngle("UpperAngle", 45.0f, REP);

ExtraEnumProp<HingeConstraint, ActuatorType, HingeActuatorType> p_HingeActuator("ActuatorType", ActuatorType_None, REP);
ExtraProp<HingeConstraint, float, HingeAngularSpeed> p_HingeAngularSpeed("AngularSpeed", 0.0f, REP);
ExtraProp<HingeConstraint, float, HingeAngularVelocity> p_HingeAngularVelocity("AngularVelocity", 0.0f, REP);
ExtraReadOnlyProp<HingeConstraint, float, HingeCurrentAngle> p_HingeCurrentAngle("CurrentAngle", 0.0f);
ExtraProp<HingeConstraint, bool, HingeLimitsEnabled> p_HingeLimits("LimitsEnabled", false, REP);
ExtraProp<HingeConstraint, float, HingeLowerAngle> p_HingeLowerAngle("LowerAngle", -45.0f, REP);
ExtraProp<HingeConstraint, float, HingeMotorMaxAcceleration> p_HingeMotorMaxAccel("MotorMaxAcceleration", 1e10f, REP);
ExtraProp<HingeConstraint, float, HingeMotorMaxTorque> p_HingeMotorMaxTorque("MotorMaxTorque", 0.0f, REP);
ExtraProp<HingeConstraint, float, HingeRestitution> p_HingeRestitution("Restitution", 0.0f, REP);
ExtraProp<HingeConstraint, float, HingeServoMaxTorque> p_HingeServoMaxTorque("ServoMaxTorque", 0.0f, REP);
ExtraProp<HingeConstraint, float, HingeTargetAngle> p_HingeTargetAngle("TargetAngle", 0.0f, REP);
ExtraProp<HingeConstraint, float, HingeUpperAngle> p_HingeUpperAngle("UpperAngle", 45.0f, REP);

ExtraProp<MoveToConstraint, float, MoveToMaxForce> p_MoveToMaxForce("MaxForce", 0.0f, REP);
ExtraProp<MoveToConstraint, float, MoveToStabilizingDistance> p_MoveToStabilizing("StabilizingDistance", 0.0f, REP);
ExtraProp<MoveToConstraint, float, MoveToVelocity> p_MoveToVelocity("Velocity", 0.0f, REP);

ExtraReadOnlyProp<RodConstraint, float, RodCurrentDistance> p_RodCurrent("CurrentDistance", 0.0f);
ExtraProp<RodConstraint, float, RodLength> p_RodLength("Length", 5.0f, REP);

ExtraReadOnlyProp<RopeConstraint, float, RopeCurrentDistance> p_RopeCurrent("CurrentDistance", 0.0f);
ExtraProp<RopeConstraint, float, RopeLength> p_RopeLength("Length", 5.0f, REP);
ExtraProp<RopeConstraint, float, RopeRestitution> p_RopeRestitution("Restitution", 0.0f, REP);

ExtraEnumProp<SlidingBallConstraint, ActuatorType, SlidingActuatorType> p_SlidingActuator("ActuatorType", ActuatorType_None, REP);
ExtraReadOnlyProp<SlidingBallConstraint, float, SlidingCurrentPosition> p_SlidingCurrent("CurrentPosition", 0.0f);
ExtraProp<SlidingBallConstraint, bool, SlidingLimitsEnabled> p_SlidingLimits("LimitsEnabled", false, REP);
ExtraProp<SlidingBallConstraint, float, SlidingLowerLimit> p_SlidingLower("LowerLimit", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingMotorMaxAcceleration> p_SlidingMotorAccel("MotorMaxAcceleration", 1e10f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingMotorMaxForce> p_SlidingMotorForce("MotorMaxForce", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingRestitution> p_SlidingRestitution("Restitution", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingServoMaxForce> p_SlidingServoForce("ServoMaxForce", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingSpeed> p_SlidingSpeed("Speed", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingTargetPosition> p_SlidingTarget("TargetPosition", 0.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingUpperLimit> p_SlidingUpper("UpperLimit", 5.0f, REP);
ExtraProp<SlidingBallConstraint, float, SlidingVelocity> p_SlidingVelocity("Velocity", 0.0f, REP);

ExtraProp<CylindricalConstraint, float, CylindricalAzimuthalAngle> p_CylAzimuthal("AzimuthalAngle", 0.0f, REP);
ExtraProp<CylindricalConstraint, float, CylindricalInclinationAngle> p_CylInclination("InclinationAngle", 0.0f, REP);

ExtraReadOnlyProp<SpringConstraint, float, SpringCurrentLength> p_SpringCurrent("CurrentLength", 0.0f);
ExtraProp<SpringConstraint, float, SpringDamping> p_SpringDamping("Damping", 0.0f, REP);
ExtraProp<SpringConstraint, float, SpringFreeLength> p_SpringFree("FreeLength", 1.0f, REP);
ExtraProp<SpringConstraint, bool, SpringLimitsEnabled> p_SpringLimits("LimitsEnabled", false, REP);
ExtraProp<SpringConstraint, float, SpringMaxForce> p_SpringMaxForce("MaxForce", 1e10f, REP);
ExtraProp<SpringConstraint, float, SpringMaxLength> p_SpringMaxLength("MaxLength", 5.0f, REP);
ExtraProp<SpringConstraint, float, SpringMinLength> p_SpringMinLength("MinLength", 0.0f, REP);
ExtraProp<SpringConstraint, float, SpringStiffness> p_SpringStiffness("Stiffness", 0.0f, REP);

ExtraProp<PostEffect, bool, PostEffectEnabled> p_PostEffectEnabled("Enabled", true, REP);
ExtraProp<BloomEffect, float, BloomIntensity> p_BloomIntensity("Intensity", 0.4f, REP);
ExtraProp<BloomEffect, float, BloomSize> p_BloomSize("Size", 24.0f, REP);
ExtraProp<BloomEffect, float, BloomThreshold> p_BloomThreshold("Threshold", 0.95f, REP);
ExtraProp<BlurEffect, float, BlurSize> p_BlurSize("Size", 24.0f, REP);
ExtraProp<ColorCorrectionEffect, float, ColorCorrectionBrightness> p_CCBrightness("Brightness", 0.0f, REP);
ExtraProp<ColorCorrectionEffect, float, ColorCorrectionContrast> p_CCContrast("Contrast", 0.0f, REP);
ExtraProp<ColorCorrectionEffect, float, ColorCorrectionSaturation> p_CCSaturation("Saturation", 0.0f, REP);
ExtraProp<ColorCorrectionEffect, G3D::Color3, ColorCorrectionTintColor> p_CCTint("TintColor", G3D::Color3(1, 1, 1), REP);
ExtraProp<SunRaysEffect, float, SunRaysIntensity> p_SunRaysIntensity("Intensity", 0.25f, REP);
ExtraProp<SunRaysEffect, float, SunRaysSpread> p_SunRaysSpread("Spread", 1.0f, REP);
ExtraProp<ThumbnailGenerator, int, ThumbnailGraphicsMode> p_GraphicsMode("GraphicsMode", 0, REP);

Reflection::PropDescriptor<MeshPart, ContentId> p_MeshId("MeshID", "Data", &MeshPart::getMeshId, &MeshPart::setMeshId, REP);
Reflection::PropDescriptor<MeshPart, ContentId> p_TextureId("TextureID", "Data", &MeshPart::getTextureId, &MeshPart::setTextureId, REP);
Reflection::PropDescriptor<MeshPart, Vector3> p_InitialSize("InitialSize", "Data", &MeshPart::getInitialSize, &MeshPart::setInitialSize, REP);
Reflection::PropDescriptor<MeshPart, BinaryString> p_MeshPhysicsData("PhysicsData", "Data", &MeshPart::getPhysicsData, &MeshPart::setPhysicsData, REP);
Reflection::EnumPropDescriptor<MeshPart, CollisionFidelity> p_MeshCollisionFidelity("CollisionFidelity", "Data", &MeshPart::getCollisionFidelity, &MeshPart::setCollisionFidelity, NOREP);

RBX_EXTRA_EVENT(ClickDetector, void(shared_ptr<Instance>, int), EventClickMouseActionReplicated) e_MouseActionReplicated(RBX_EXTRA_SIGNAL(void(shared_ptr<Instance>, int), EventClickMouseActionReplicated), "MouseActionReplicated", "player", "actionType", Security::Roblox, RemoteEventCommon::REPLICATE_ONLY, RemoteEventCommon::CLIENT_SERVER);
RBX_EXTRA_EVENT(LuaSourceContainer, void(), EventRequestScriptHash) e_RequestScriptHash(RBX_EXTRA_SIGNAL(void(), EventRequestScriptHash), "RequestScriptHash", Security::Roblox, RemoteEventCommon::REPLICATE_ONLY, RemoteEventCommon::CLIENT_SERVER);
RBX_EXTRA_EVENT(LuaSourceContainer, void(std::string), EventVerifyScriptHash) e_VerifyScriptHash(RBX_EXTRA_SIGNAL(void(std::string), EventVerifyScriptHash), "VerifyScriptHash", "hash", Security::Roblox, RemoteEventCommon::REPLICATE_ONLY, RemoteEventCommon::CLIENT_SERVER);
RBX_EXTRA_EVENT(Network::Players, void(), EventCloudEditPublishFinished) e_CloudEditPublishFinished(RBX_EXTRA_SIGNAL(void(), EventCloudEditPublishFinished), "CloudEditPublishFinished", Security::Roblox, RemoteEventCommon::REPLICATE_ONLY, RemoteEventCommon::BROADCAST);
RBX_EXTRA_EVENT(Network::Players, void(), EventRequestCloudEditImmediatePublish) e_RequestCloudEditImmediatePublish(RBX_EXTRA_SIGNAL(void(), EventRequestCloudEditImmediatePublish), "RequestCloudEditImmediatePublish", Security::Roblox, RemoteEventCommon::REPLICATE_ONLY, RemoteEventCommon::CLIENT_SERVER);

REFLECTION_END();

}


MeshPart::MeshPart()
	: Super()
	, initialSize(1, 1, 1)
	, collisionFidelity(CollisionFidelity_Default)
{
	setName("MeshPart");
}

void MeshPart::setMeshId(const ContentId& value)
{
	if (meshId == value)
		return;
	meshId = value;
	raisePropertyChanged(p_MeshId);
}

void MeshPart::setTextureId(const ContentId& value)
{
	if (textureId == value)
		return;
	textureId = value;
	raisePropertyChanged(p_TextureId);
}

void MeshPart::setInitialSize(const Vector3& value)
{
	if (initialSize == value)
		return;
	initialSize = value;
	raisePropertyChanged(p_InitialSize);
}

FileMesh* MeshPart::getRenderMesh()
{
	if (meshId.isNull())
		return NULL;
	if (!renderMesh)
		renderMesh = Creatable<Instance>::create<FileMesh>();
	if (renderMesh->getMeshId() != MeshId(meshId.toString()))
		renderMesh->setMeshId(MeshId(meshId.toString()));
	if (renderMesh->getTextureId() != TextureId(textureId.toString()))
		renderMesh->setTextureId(TextureId(textureId.toString()));
	Vector3 scale = getMeshScale();
	if (renderMesh->getScale() != scale)
		renderMesh->setScale(scale);
	return renderMesh.get();
}

bool MeshPart::isMeshPartProperty(const Reflection::PropertyDescriptor& descriptor)
{
	return descriptor == p_MeshId || descriptor == p_TextureId || descriptor == p_InitialSize;
}

DataModelMesh* getMeshPartShape(PartInstance* part)
{
	if (MeshPart* meshPart = Instance::fastDynamicCast<MeshPart>(part))
		return meshPart->getRenderMesh();
	return NULL;
}

Vector3 MeshPart::getMeshScale() const
{
	Vector3 size = getPartSizeXml();
	return Vector3(initialSize.x > 0 ? size.x / initialSize.x : 1.0f, initialSize.y > 0 ? size.y / initialSize.y : 1.0f, initialSize.z > 0 ? size.z / initialSize.z : 1.0f);
}

}
