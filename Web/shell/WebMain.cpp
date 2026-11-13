#include "WebHost.h"
#include "FunctionMarshaller.h"
#include "PlaceLauncher.h"
#include "RobloxInfo.h"
#include "LogManager.h"
#include "rbx/CEvent.h"
#include "util/standardout.h"
#include "util/Http.h"
#include "rbx/SystemUtil.h"
#include "v8tree/Instance.h"

#include <emscripten.h>
#include <emscripten/threading.h>
#include <emscripten/websocket.h>
#include <unistd.h>
#include <deque>
#include <string>
#include <boost/thread/mutex.hpp>

extern void SetBaseURL(const std::string& baseUrl);

namespace RBX
{
namespace JNI
{
extern std::string fileSystemCacheDir;
extern std::string fileSystemFilesDir;
}

namespace Web
{
static boost::mutex messagesLock;
static std::deque<FunctionMarshaller::Closure*> messages;
static pthread_t uiThread;

static void pushMessage(FunctionMarshaller::Closure* closure)
{
    boost::mutex::scoped_lock lock(messagesLock);
    messages.push_back(closure);
}

void processAppEvents()
{
    for (;;)
    {
        FunctionMarshaller::Closure* closure = NULL;
        {
            boost::mutex::scoped_lock lock(messagesLock);
            if (messages.empty())
                return;
            closure = messages.front();
            messages.pop_front();
        }
        FunctionMarshaller::handleAppEvent(closure);
    }
}

void sendAppEvent(void* pClosure)
{
    FunctionMarshaller::Closure* closure = (FunctionMarshaller::Closure*)pClosure;
    CEvent* waitEvent = closure->waitEvent;
    if (pthread_equal(pthread_self(), uiThread))
    {
        FunctionMarshaller::handleAppEvent(closure);
        return;
    }
    pushMessage(closure);
    if (waitEvent)
        waitEvent->Wait();
}

void postAppEvent(void* pClosure)
{
    pushMessage((FunctionMarshaller::Closure*)pClosure);
}

void exitGame()
{
    MAIN_THREAD_ASYNC_EM_ASM({ if (Module.onExitGame) Module.onExitGame(); });
}

void exitGameWithError(std::string errId)
{
    MAIN_THREAD_ASYNC_EM_ASM({ if (Module.onExitGame) Module.onExitGame(UTF8ToString($0)); }, strdup(errId.c_str()));
}

void textBoxFocused(boost::shared_ptr<RBX::Instance>)
{
    MAIN_THREAD_ASYNC_EM_ASM({ if (Module.showKeyboard) Module.showKeyboard(); });
}

void textBoxFocusLost(boost::shared_ptr<RBX::Instance>)
{
    MAIN_THREAD_ASYNC_EM_ASM({ if (Module.hideKeyboard) Module.hideKeyboard(); });
}

void motionEventListening(std::string)
{
}

std::string getApiUrl()
{
    return "";
}
}
}

namespace RBX
{
namespace Web
{
extern std::string proxyOrigin;
extern std::string udpRelayUrl;
extern std::string proxyDomain;
}
}

static std::string readConfig(const char* key, const char* fallback)
{
    char* v = (char*)MAIN_THREAD_EM_ASM_INT({
        var c = Module.rbxConfig || {};
        var v = c[UTF8ToString($0)];
        if (v === undefined || v === null) return 0;
        return allocateUTF8(String(v));
    }, key);
    if (!v)
        return fallback;
    std::string s(v);
    free(v);
    return s;
}

static void tick()
{
    RBX::Web::processAppEvents();
}

int main()
{
    using namespace RBX;
    Web::uiThread = pthread_self();

    std::string baseUrl = readConfig("baseUrl", "https://www.finobe.wtf/");
    int placeId = atoi(readConfig("placeId", "0").c_str());
    int width = atoi(readConfig("width", "1280").c_str());
    int height = atoi(readConfig("height", "720").c_str());
    bool touch = readConfig("touch", "0") == "1";

    Web::proxyOrigin = readConfig("proxyOrigin", "");
    {
        size_t hs = baseUrl.find("://");
        hs = hs == std::string::npos ? 0 : hs + 3;
        std::string host = baseUrl.substr(hs, baseUrl.find_first_of("/:", hs) - hs);
        Web::proxyDomain = host.compare(0, 4, "www.") == 0 ? host.substr(4) : host;
    }

    std::string relayUrl = readConfig("relayUrl", "wss://www.finobe.wtf/webrelay");
    Web::udpRelayUrl = relayUrl + (relayUrl.find('?') == std::string::npos ? "?udp=1" : "&udp=1");

    JNI::fileSystemCacheDir = "/cache";
    JNI::fileSystemFilesDir = "/files";
    SetBaseURL(baseUrl);
    RobloxInfo::getRobloxInfo().setAssetFolderPath("/content/");
    JNI::LogManager::setupFastLog();

    StandardOut::singleton()->printf(MESSAGE_INFO, "WebPlayer starting place %d on %s", placeId, baseUrl.c_str());

    static StartGameParams sgp;
    sgp.viewWidth = width;
    sgp.viewHeight = height;
    sgp.view = (void*)"#canvas";
    sgp.placeId = placeId;
    sgp.userId = 0;
    sgp.assetFolderPath = "/content/";
    sgp.isTouchDevice = touch;
    sgp.joinRequestType = JOIN_GAME_REQUEST_PLACEID;

    PlaceLauncher::getPlaceLauncher().startGame(sgp);

    emscripten_set_main_loop(tick, 0, 1);
    return 0;
}
