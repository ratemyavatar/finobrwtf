#pragma once

#include "config.hpp"

#include <atomic>
#include <chrono>
#include <condition_variable>
#include <mutex>
#include <string>
#include <sys/types.h>
#include <thread>
#include <vector>

namespace rd {

class Pool {
public:
    explicit Pool(const Config& config);
    ~Pool();

    bool start(std::string& error);
    void stop();

    int acquire(int timeoutSeconds);
    void release(int index, bool healthy);
    int port(int index) const;

    std::string version();
    std::string display() const { return display_; }
    int readyCount();
    std::string statusJson();

private:
    enum class State { Down, Starting, Idle, Busy, Checking, Restart };

    struct Worker {
        int port = 0;
        pid_t pid = -1;
        State state = State::Down;
        int jobs = 0;
        int failedChecks = 0;
        int restarts = 0;
        std::chrono::steady_clock::time_point since;
        std::chrono::steady_clock::time_point lastUsed;
        std::chrono::steady_clock::time_point lastCheck;
        std::chrono::steady_clock::time_point retryAt;
    };

    void monitor();
    void spawn(Worker& worker);
    bool hello(int port, int timeoutSeconds);
    bool startDisplay(std::string& error);
    bool launchXvfb(std::string& error);
    void reap();
    static const char* stateName(State state);

    Config config_;
    std::string display_;
    pid_t xvfb_ = -1;
    std::vector<Worker> workers_;
    std::mutex lock_;
    std::condition_variable changed_;
    std::thread monitor_;
    std::atomic<bool> running_{false};
    std::string version_;
    std::atomic<long> waiting_{0};
    std::atomic<long> jobsDone_{0};
    std::atomic<long> jobsFailed_{0};
};

}
