#include "fmod.hpp"
#include "fmod.h"

#define NO return FMOD_ERR_UNSUPPORTED;

extern "C" FMOD_RESULT F_API FMOD_System_Create(FMOD_SYSTEM** system) { if (system) *system = 0; NO }
extern "C" FMOD_RESULT F_API FMOD_Memory_GetStats(int* currentalloced, int* maxalloced, FMOD_BOOL blocking) { if (currentalloced) *currentalloced = 0; if (maxalloced) *maxalloced = 0; return FMOD_OK; }
extern "C" FMOD_RESULT F_API FMOD_Channel_GetUserData(FMOD_CHANNEL* channel, void** userdata) { if (userdata) *userdata = 0; NO }

namespace FMOD
{
FMOD_RESULT ChannelControl::setCallback(FMOD_CHANNELCONTROL_CALLBACK) { NO }
FMOD_RESULT ChannelControl::setUserData(void*) { NO }
FMOD_RESULT ChannelControl::getSystemObject(System** s) { if (s) *s = 0; NO }
FMOD_RESULT ChannelControl::set3DAttributes(const FMOD_VECTOR*, const FMOD_VECTOR*, const FMOD_VECTOR*) { NO }
FMOD_RESULT ChannelControl::set3DMinMaxDistance(float, float) { NO }
FMOD_RESULT ChannelControl::stop() { NO }
FMOD_RESULT ChannelControl::getMode(FMOD_MODE* m) { if (m) *m = 0; NO }
FMOD_RESULT ChannelControl::getMute(bool* m) { if (m) *m = false; NO }
FMOD_RESULT ChannelControl::setMode(FMOD_MODE) { NO }
FMOD_RESULT ChannelControl::setMute(bool) { NO }
FMOD_RESULT ChannelControl::getPaused(bool* p) { if (p) *p = false; NO }
FMOD_RESULT ChannelControl::getVolume(float* v) { if (v) *v = 0; NO }
FMOD_RESULT ChannelControl::isPlaying(bool* p) { if (p) *p = false; NO }
FMOD_RESULT ChannelControl::setPaused(bool) { NO }
FMOD_RESULT ChannelControl::setVolume(float) { NO }

FMOD_RESULT Sound::getDefaults(float* f, int* p) { if (f) *f = 0; if (p) *p = 0; NO }
FMOD_RESULT Sound::release() { NO }
FMOD_RESULT Sound::getLength(unsigned int* l, FMOD_TIMEUNIT) { if (l) *l = 0; NO }

FMOD_RESULT System::getVersion(unsigned int* v) { if (v) *v = 0; NO }
FMOD_RESULT System::createSound(const char*, FMOD_MODE, FMOD_CREATESOUNDEXINFO*, Sound** s) { if (s) *s = 0; NO }
FMOD_RESULT System::getCPUUsage(float* a, float* b, float* c, float* d, float* e) { float* p[] = {a, b, c, d, e}; for (float* x : p) if (x) *x = 0; NO }
FMOD_RESULT System::createStream(const char*, FMOD_MODE, FMOD_CREATESOUNDEXINFO*, Sound** s) { if (s) *s = 0; NO }
FMOD_RESULT System::getDriverInfo(int, char* n, int len, FMOD_GUID*, int*, FMOD_SPEAKERMODE*, int*) { if (n && len) *n = 0; NO }
FMOD_RESULT System::getNumDrivers(int* n) { if (n) *n = 0; NO }
FMOD_RESULT System::set3DSettings(float, float, float) { NO }
FMOD_RESULT System::setSoftwareFormat(int, FMOD_SPEAKERMODE, int) { NO }
FMOD_RESULT System::createChannelGroup(const char*, ChannelGroup** g) { if (g) *g = 0; NO }
FMOD_RESULT System::getChannelsPlaying(int* n) { if (n) *n = 0; NO }
FMOD_RESULT System::setReverbProperties(int, const FMOD_REVERB_PROPERTIES*) { NO }
FMOD_RESULT System::set3DListenerAttributes(int, const FMOD_VECTOR*, const FMOD_VECTOR*, const FMOD_VECTOR*, const FMOD_VECTOR*) { NO }
FMOD_RESULT System::init(int, FMOD_INITFLAGS, void*) { NO }
FMOD_RESULT System::update() { NO }
FMOD_RESULT System::release() { NO }
FMOD_RESULT System::getDriver(int* d) { if (d) *d = -1; NO }
FMOD_RESULT System::playSound(Sound*, ChannelGroup*, bool, Channel** c) { if (c) *c = 0; NO }
FMOD_RESULT System::setDriver(int) { NO }

FMOD_RESULT Channel::getPosition(unsigned int* p, FMOD_TIMEUNIT) { if (p) *p = 0; NO }
FMOD_RESULT Channel::setPosition(unsigned int, FMOD_TIMEUNIT) { NO }
FMOD_RESULT Channel::setPriority(int) { NO }
FMOD_RESULT Channel::setFrequency(float) { NO }
FMOD_RESULT Channel::setLoopCount(int) { NO }
FMOD_RESULT Channel::setChannelGroup(ChannelGroup*) { NO }
}
