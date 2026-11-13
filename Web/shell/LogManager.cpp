#include "LogManager.h"
#include "FastLog.h"
#include "util/standardout.h"

#include <stdio.h>

namespace RBX
{
namespace JNI
{
std::string platformUserAgent = "Roblox/WebPlayer";
std::string robloxVersion = "0.0.0.0";
int lastPlaceId = 0;

static void fastLogMessage(FLog::Channel channel, const char* message)
{
    if (channel == 1)
        fprintf(stderr, "%s\n", message);
}

static void standardOutMessage(const RBX::StandardOutMessage& m)
{
    fprintf(stderr, "[out %d] %s\n", (int)m.type, m.message.c_str());
}

namespace LogManager
{
void setupFastLog()
{
    FLog::SetExternalLogFunc(&fastLogMessage);
    static bool connected = false;
    if (!connected)
    {
        connected = true;
        RBX::StandardOut::singleton()->messageOut.connect(&standardOutMessage);
    }
}

void tearDownFastLog()
{
    FLog::SetExternalLogFunc(NULL);
}

void setupBreakpad(bool)
{
}
}
}
}
