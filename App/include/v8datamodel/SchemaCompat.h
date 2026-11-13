#pragma once

#include "V8Tree/Instance.h"
#include "V8DataModel/PartInstance.h"
#include "V8DataModel/PartOperation.h"
#include "V8DataModel/FileMesh.h"
#include "Util/ContentId.h"
#include "Util/BinaryString.h"

namespace RBX {

namespace SchemaId {
enum
{
	AttachmentAxis = 1,
	CameraHeadScale,
	DataModelGameAvatarType,
	DataModelIsSFFlagsLoaded,
	ExplosionVisible,
	GuiServiceScreenGuiEnabled,
	LuaSourceScriptGuid,
	PartOperationTriangleCount,
	PhysicsAttachmentsShown,
	PhysicsConstraintsShown,
	PlayerCharacterAppearanceId,
	PlayerTeam,
	SoundXmlMaxDistance,
	SoundXmlMinDistance,
	TextBoxConfidential,
	TextButtonConfidential,
	TextLabelConfidential,
	UserGameSettingsChatVisible,
	UserInputMouseDeltaSensitivity,
	WorkspaceGravity,

	ConstraintAttachment0 = 100,
	ConstraintAttachment1,
	ConstraintEnabled,
	BallSocketLimitsEnabled,
	BallSocketRestitution,
	BallSocketUpperAngle,
	HingeActuatorType,
	HingeAngularSpeed,
	HingeAngularVelocity,
	HingeCurrentAngle,
	HingeLimitsEnabled,
	HingeLowerAngle,
	HingeMotorMaxAcceleration,
	HingeMotorMaxTorque,
	HingeRestitution,
	HingeServoMaxTorque,
	HingeTargetAngle,
	HingeUpperAngle,
	MoveToMaxForce,
	MoveToStabilizingDistance,
	MoveToVelocity,
	RodCurrentDistance,
	RodLength,
	RopeCurrentDistance,
	RopeLength,
	RopeRestitution,
	SlidingActuatorType,
	SlidingCurrentPosition,
	SlidingLimitsEnabled,
	SlidingLowerLimit,
	SlidingMotorMaxAcceleration,
	SlidingMotorMaxForce,
	SlidingRestitution,
	SlidingServoMaxForce,
	SlidingSpeed,
	SlidingTargetPosition,
	SlidingUpperLimit,
	SlidingVelocity,
	CylindricalAzimuthalAngle,
	CylindricalInclinationAngle,
	SpringCurrentLength,
	SpringDamping,
	SpringFreeLength,
	SpringLimitsEnabled,
	SpringMaxForce,
	SpringMaxLength,
	SpringMinLength,
	SpringStiffness,

	PostEffectEnabled = 200,
	BloomIntensity,
	BloomSize,
	BloomThreshold,
	BlurSize,
	ColorCorrectionBrightness,
	ColorCorrectionContrast,
	ColorCorrectionSaturation,
	ColorCorrectionTintColor,
	SunRaysIntensity,
	SunRaysSpread,
	ThumbnailGraphicsMode,

	EventClickMouseActionReplicated = 300,
	EventRequestScriptHash,
	EventVerifyScriptHash,
	EventCloudEditPublishFinished,
	EventRequestCloudEditImmediatePublish,
	EventSoundLoaded,
	EventSoundResumed,
	EventSoundPlayingFromClient,
	EventSoundPlayingFromServer,
	EventSoundTimeFromClient,
	EventSoundTimeFromServer,
	EventSoundPlaybackActionSync,
};
}

enum ActuatorType
{
	ActuatorType_None = 0,
	ActuatorType_Motor = 1,
	ActuatorType_Servo = 2,
};

enum GameAvatarType
{
	GameAvatarType_R6 = 0,
	GameAvatarType_R15 = 1,
	GameAvatarType_PlayerChoice = 2,
};

extern const char* const sConstraint;
class ReplicatedConstraint : public DescribedNonCreatable<ReplicatedConstraint, Instance, sConstraint>
{
protected:
	ReplicatedConstraint(const char* name) : DescribedNonCreatable<ReplicatedConstraint, Instance, sConstraint>(name) {}
};

#define RBX_SCHEMA_CLASS(Name, Base) \
	extern const char* const s##Name; \
	class Name : public DescribedCreatable<Name, Base, s##Name> \
	{ \
	public: \
		Name() : DescribedCreatable<Name, Base, s##Name>(#Name) {} \
	protected: \
		Name(const char* name) : DescribedCreatable<Name, Base, s##Name>(name) {} \
	};

RBX_SCHEMA_CLASS(BallSocketConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(HingeConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(MoveToConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(RodConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(RopeConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(SlidingBallConstraint, ReplicatedConstraint)
RBX_SCHEMA_CLASS(CylindricalConstraint, SlidingBallConstraint)
RBX_SCHEMA_CLASS(PrismaticConstraint, SlidingBallConstraint)
RBX_SCHEMA_CLASS(SpringConstraint, ReplicatedConstraint)

extern const char* const sPostEffect;
class PostEffect : public DescribedNonCreatable<PostEffect, Instance, sPostEffect>
{
protected:
	PostEffect(const char* name) : DescribedNonCreatable<PostEffect, Instance, sPostEffect>(name) {}
};

RBX_SCHEMA_CLASS(BloomEffect, PostEffect)
RBX_SCHEMA_CLASS(BlurEffect, PostEffect)
RBX_SCHEMA_CLASS(ColorCorrectionEffect, PostEffect)
RBX_SCHEMA_CLASS(SunRaysEffect, PostEffect)
RBX_SCHEMA_CLASS(ThumbnailGenerator, Instance)

#undef RBX_SCHEMA_CLASS

extern const char* const sMeshPart;
class MeshPart : public DescribedCreatable<MeshPart, PartInstance, sMeshPart>
{
	typedef DescribedCreatable<MeshPart, PartInstance, sMeshPart> Super;
	ContentId meshId;
	ContentId textureId;
	Vector3 initialSize;
	BinaryString physicsData;
	CollisionFidelity collisionFidelity;
	shared_ptr<FileMesh> renderMesh;

public:
	MeshPart();

	const ContentId& getMeshId() const { return meshId; }
	void setMeshId(const ContentId& value);
	const ContentId& getTextureId() const { return textureId; }
	void setTextureId(const ContentId& value);
	const Vector3& getInitialSize() const { return initialSize; }
	void setInitialSize(const Vector3& value);
	const BinaryString& getPhysicsData() const { return physicsData; }
	void setPhysicsData(const BinaryString& value) { physicsData = value; }
	CollisionFidelity getCollisionFidelity() const { return collisionFidelity; }
	void setCollisionFidelity(CollisionFidelity value) { collisionFidelity = value; }

	Vector3 getMeshScale() const;
	FileMesh* getRenderMesh();

	static bool isMeshPartProperty(const Reflection::PropertyDescriptor& descriptor);
};

}
