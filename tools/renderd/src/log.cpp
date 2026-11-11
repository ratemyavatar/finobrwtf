#include "log.hpp"

#include <chrono>
#include <cstdio>
#include <ctime>
#include <mutex>

namespace rd {

void log(const std::string& message)
{
    static std::mutex lock;
    auto now = std::chrono::system_clock::now();
    std::time_t t = std::chrono::system_clock::to_time_t(now);
    std::tm tm{};
    gmtime_r(&t, &tm);
    char stamp[32];
    std::strftime(stamp, sizeof(stamp), "%Y-%m-%d %H:%M:%S", &tm);
    std::lock_guard<std::mutex> guard(lock);
    std::fprintf(stderr, "%s %s\n", stamp, message.c_str());
    std::fflush(stderr);
}

}
