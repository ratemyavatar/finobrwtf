#pragma once
#include <string>

namespace RBX
{
namespace JNI
{
extern std::string platformUserAgent;
extern std::string robloxVersion;
extern int lastPlaceId;
namespace LogManager
{
void setupFastLog();
void tearDownFastLog();
void setupBreakpad(bool cleanup);
}
}
}
