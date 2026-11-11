#pragma once

#include "config.hpp"

#include <atomic>
#include <chrono>
#include <condition_variable>
#include <map>
#include <mutex>
#include <string>
#include <sys/types.h>
#include <thread>

namespace rd {

class Games {
public:
    explicit Games(const Config& config);
    ~Games();

    void start(const std::string& display);
    void stop();

    bool enabled() const { return config_.gameMax > 0; }
    bool enabled2012() const { return !config_.client2012.empty(); }

    std::string open(const std::string& body, const std::string& header, int& status);
    bool owns(const std::string& jobId);
    std::string forward(const std::string& jobId, const std::string& action, const std::string& body, const std::string& header, int& status);
    void close(const std::string& jobId);
    std::string jobsXml();
    int running();

    bool start2012(const std::string& jobId, const std::string& url, pid_t& pid, int& status, std::string& error);
    void stop2012(const std::string& jobId);
    int running2012();

    std::string statusJson();

private:
    using Clock = std::chrono::steady_clock;

    enum class State { Starting, Spare, Opening, Running };

    struct Game {
        pid_t pid = -1;
        int port = 0;
        State state = State::Starting;
        std::string jobId;
        std::string reservedFor;
        double expiration = 0;
        std::string category;
        std::string cores;
        Clock::time_point since;
        Clock::time_point lastCheck;
        Clock::time_point expiresAt;
        int failedChecks = 0;
    };

    struct Server2012 {
        pid_t pid = -1;
        Clock::time_point since;
    };

    void monitor();
    int freePort();
    int spawn(const std::string& reservedFor);
    int findJob(const std::string& jobId);
    void dropGame(int id, bool closeFirst);
    static const char* stateName(State state);

    Config config_;
    std::string display_;
    std::map<int, Game> games_;
    std::map<std::string, Server2012> servers2012_;
    int nextId_ = 1;
    std::mutex lock_;
    std::condition_variable changed_;
    std::thread monitor_;
    std::atomic<bool> running_{false};
};

}
