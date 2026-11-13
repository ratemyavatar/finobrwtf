#include <string>
#include <boost/thread/mutex.hpp>
#include <boost/thread/condition_variable.hpp>
#include <boost/date_time/posix_time/posix_time_types.hpp>
#include <emscripten.h>

namespace
{
boost::mutex pasteMutex;
boost::condition_variable pasteReady;
std::string pasteText;
unsigned pasteSeq = 0;
unsigned pasteTaken = 0;
}

extern "C" EMSCRIPTEN_KEEPALIVE void rbx_web_set_paste(const char* text)
{
    boost::mutex::scoped_lock lock(pasteMutex);
    pasteText = text ? text : "";
    ++pasteSeq;
    pasteReady.notify_all();
}

namespace RBX
{
namespace Web
{
std::string takePasteText()
{
    boost::mutex::scoped_lock lock(pasteMutex);
    boost::system_time deadline = boost::get_system_time() + boost::posix_time::milliseconds(300);
    while (pasteSeq == pasteTaken)
    {
        if (!pasteReady.timed_wait(lock, deadline))
            return std::string();
    }
    pasteTaken = pasteSeq;
    std::string text;
    text.reserve(pasteText.size());
    for (size_t i = 0; i < pasteText.size(); ++i)
    {
        char c = pasteText[i];
        if (c == '\r')
            continue;
        text += (c == '\n' || c == '\t') ? ' ' : c;
    }
    return text;
}
}
}
