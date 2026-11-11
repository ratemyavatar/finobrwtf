#include "pool.hpp"

#include "log.hpp"
#include "net.hpp"
#include "procs.hpp"
#include "soap.hpp"

#include <cerrno>
#include <cstdlib>
#include <cstring>
#include <sstream>
#include <sys/stat.h>
#include <unistd.h>

namespace rd {

namespace {

using Clock = std::chrono::steady_clock;

}

Pool::Pool(const Config& config) : config_(config)
{
    workers_.resize(static_cast<size_t>(config_.workers));
    for (size_t i = 0; i < workers_.size(); i++) {
        workers_[i].port = config_.basePort + static_cast<int>(i);
    }
}

Pool::~Pool()
{
    stop();
}

const char* Pool::stateName(State state)
{
    switch (state) {
        case State::Down: return "down";
        case State::Starting: return "starting";
        case State::Idle: return "idle";
        case State::Busy: return "busy";
        case State::Checking: return "checking";
        case State::Restart: return "restarting";
    }
    return "unknown";
}

bool Pool::startDisplay(std::string& error)
{
    if (!config_.display.empty()) {
        display_ = config_.display;
        return true;
    }
    const char* current = std::getenv("DISPLAY");
    if (current && *current) {
        display_ = current;
        return true;
    }
    int number = 90;
    for (; number < 200; number++) {
        struct stat st{};
        if (::stat(("/tmp/.X" + std::to_string(number) + "-lock").c_str(), &st) != 0) {
            break;
        }
    }
    display_ = ":" + std::to_string(number);
    return launchXvfb(error);
}

bool Pool::launchXvfb(std::string& error)
{
    std::string number = display_.substr(1);
    ::unlink(("/tmp/.X" + number + "-lock").c_str());
    ::unlink(("/tmp/.X11-unix/X" + number).c_str());
    Launch spec;
    spec.args = {"Xvfb", display_, "-screen", "0", "1280x1024x24", "-nolisten", "tcp"};
    pid_t pid = launch(spec);
    if (pid < 0) {
        error = std::string("fork failed: ") + std::strerror(errno);
        return false;
    }
    xvfb_ = pid;
    std::string socketPath = "/tmp/.X11-unix/X" + number;
    for (int i = 0; i < 100; i++) {
        struct stat st{};
        if (::stat(socketPath.c_str(), &st) == 0) {
            log("xvfb is up on " + display_);
            return true;
        }
        if (!alive(pid)) {
            xvfb_ = -1;
            error = "Xvfb exited right away, is it installed?";
            return false;
        }
        ::usleep(100000);
    }
    error = "Xvfb did not start";
    return false;
}

bool Pool::start(std::string& error)
{
    std::string exe = config_.rccDir + "/RCCService.exe";
    if (::access(exe.c_str(), R_OK) != 0) {
        error = "can't read " + exe;
        return false;
    }
    if (!startDisplay(error)) {
        return false;
    }
    running_ = true;
    {
        std::lock_guard<std::mutex> guard(lock_);
        for (auto& worker : workers_) {
            spawn(worker);
        }
    }
    monitor_ = std::thread([this] { monitor(); });
    return true;
}

void Pool::stop()
{
    if (!running_.exchange(false)) {
        return;
    }
    changed_.notify_all();
    if (monitor_.joinable()) {
        monitor_.join();
    }
    std::vector<pid_t> pids;
    {
        std::lock_guard<std::mutex> guard(lock_);
        for (auto& worker : workers_) {
            if (worker.pid > 0) {
                pids.push_back(worker.pid);
                worker.pid = -1;
            }
            worker.state = State::Down;
        }
    }
    for (pid_t pid : pids) {
        terminate(pid);
    }
    if (xvfb_ > 0) {
        terminate(xvfb_);
        xvfb_ = -1;
    }
}

void Pool::spawn(Worker& worker)
{
    Launch spec;
    spec.dir = config_.rccDir;
    spec.display = display_;
    spec.winePrefix = config_.winePrefix;
    spec.args = {config_.wine, "RCCService.exe", "-Console", "-verbose", "-placeid:-1", "-port", std::to_string(worker.port)};
    pid_t pid = launch(spec);
    if (pid < 0) {
        log("fork failed for rcc on port " + std::to_string(worker.port) + ": " + std::strerror(errno));
        worker.state = State::Down;
        worker.retryAt = Clock::now() + std::chrono::seconds(5);
        return;
    }
    worker.pid = pid;
    worker.state = State::Starting;
    worker.jobs = 0;
    worker.failedChecks = 0;
    worker.since = Clock::now();
    worker.lastCheck = Clock::now();
    log("started rcc on port " + std::to_string(worker.port) + " (pid " + std::to_string(pid) + ")");
}

bool Pool::hello(int port, int timeoutSeconds)
{
    Response response;
    std::string error;
    if (!post(port, "\"http://finobe.wtf/HelloWorld\"", helloWorldRequest(), timeoutSeconds, response, error)) {
        return false;
    }
    return response.status == 200 && response.body.find("HelloWorldResult") != std::string::npos;
}

void Pool::reap()
{
    reapChildren();
    int status = 0;
    if (xvfb_ > 0 && takeExit(xvfb_, status)) {
        log("xvfb exited, starting it again");
        xvfb_ = -1;
        std::string error;
        if (!launchXvfb(error)) {
            log("xvfb restart failed: " + error);
        }
    }
    std::lock_guard<std::mutex> guard(lock_);
    for (auto& worker : workers_) {
        if (worker.pid > 0 && takeExit(worker.pid, status)) {
            log("rcc on port " + std::to_string(worker.port) + " exited (" + describeExit(status) + ")");
            worker.pid = -1;
            if (worker.state != State::Busy) {
                worker.state = State::Down;
                worker.retryAt = Clock::now() + std::chrono::seconds(2);
            } else {
                worker.state = State::Restart;
            }
        }
    }
}

void Pool::monitor()
{
    while (running_) {
        reap();
        std::vector<size_t> toCheck;
        std::vector<size_t> toHealth;
        std::vector<std::pair<size_t, pid_t>> toKill;
        auto now = Clock::now();
        {
            std::lock_guard<std::mutex> guard(lock_);
            for (size_t i = 0; i < workers_.size(); i++) {
                auto& worker = workers_[i];
                if (worker.state == State::Restart && worker.pid > 0) {
                    toKill.push_back({i, worker.pid});
                    worker.pid = -1;
                } else if (worker.state == State::Restart || (worker.state == State::Down && now >= worker.retryAt)) {
                    worker.restarts++;
                    spawn(worker);
                } else if (worker.state == State::Starting) {
                    if (now - worker.since > std::chrono::seconds(config_.startTimeout)) {
                        log("rcc on port " + std::to_string(worker.port) + " took too long to start");
                        toKill.push_back({i, worker.pid});
                        worker.pid = -1;
                        worker.state = State::Restart;
                    } else if (now - worker.lastCheck >= std::chrono::seconds(2)) {
                        worker.lastCheck = now;
                        toCheck.push_back(i);
                    }
                } else if (worker.state == State::Idle && now - worker.lastCheck >= std::chrono::seconds(config_.healthEvery)) {
                    worker.state = State::Checking;
                    worker.lastCheck = now;
                    toHealth.push_back(i);
                }
            }
        }
        for (auto& item : toKill) {
            terminate(item.second);
            std::lock_guard<std::mutex> guard(lock_);
            workers_[item.first].state = State::Restart;
        }
        for (size_t i : toCheck) {
            int workerPort = workers_[i].port;
            bool up = hello(workerPort, 3);
            if (up) {
                std::string ver;
                if (version_.empty()) {
                    Response response;
                    std::string error;
                    if (post(workerPort, "\"http://finobe.wtf/GetVersion\"", getVersionRequest(), 10, response, error)) {
                        ver = firstElement(response.body, "GetVersionResult");
                    }
                }
                std::lock_guard<std::mutex> guard(lock_);
                if (workers_[i].state == State::Starting) {
                    workers_[i].state = State::Idle;
                    workers_[i].lastUsed = Clock::now();
                    log("rcc on port " + std::to_string(workerPort) + " is ready");
                    if (!ver.empty() && version_.empty()) {
                        version_ = ver;
                    }
                }
                changed_.notify_all();
            }
        }
        for (size_t i : toHealth) {
            bool up = hello(workers_[i].port, 10);
            std::lock_guard<std::mutex> guard(lock_);
            auto& worker = workers_[i];
            if (worker.state != State::Checking) {
                continue;
            }
            if (up) {
                worker.failedChecks = 0;
                worker.state = State::Idle;
            } else if (++worker.failedChecks >= 2) {
                log("rcc on port " + std::to_string(worker.port) + " failed health checks, restarting it");
                worker.state = State::Restart;
            } else {
                worker.state = State::Idle;
            }
            changed_.notify_all();
        }
        std::unique_lock<std::mutex> guard(lock_);
        changed_.wait_for(guard, std::chrono::milliseconds(500));
    }
}

int Pool::acquire(int timeoutSeconds)
{
    waiting_++;
    std::unique_lock<std::mutex> guard(lock_);
    auto deadline = Clock::now() + std::chrono::seconds(timeoutSeconds);
    while (running_) {
        int best = -1;
        for (size_t i = 0; i < workers_.size(); i++) {
            if (workers_[i].state == State::Idle && (best < 0 || workers_[i].lastUsed < workers_[static_cast<size_t>(best)].lastUsed)) {
                best = static_cast<int>(i);
            }
        }
        if (best >= 0) {
            workers_[static_cast<size_t>(best)].state = State::Busy;
            workers_[static_cast<size_t>(best)].lastUsed = Clock::now();
            waiting_--;
            return best;
        }
        if (changed_.wait_until(guard, deadline) == std::cv_status::timeout) {
            break;
        }
    }
    waiting_--;
    return -1;
}

void Pool::release(int index, bool healthy)
{
    std::lock_guard<std::mutex> guard(lock_);
    auto& worker = workers_[static_cast<size_t>(index)];
    if (healthy) {
        jobsDone_++;
    } else {
        jobsFailed_++;
    }
    if (worker.state != State::Busy) {
        changed_.notify_all();
        return;
    }
    worker.jobs++;
    if (!healthy) {
        log("rcc on port " + std::to_string(worker.port) + " failed a job, restarting it");
        worker.state = State::Restart;
    } else if (worker.jobs >= config_.recycleAfter) {
        log("rcc on port " + std::to_string(worker.port) + " did " + std::to_string(worker.jobs) + " jobs, recycling it");
        worker.state = State::Restart;
    } else {
        worker.state = State::Idle;
        worker.lastCheck = Clock::now();
    }
    changed_.notify_all();
}

int Pool::port(int index) const
{
    return workers_[static_cast<size_t>(index)].port;
}

std::string Pool::version()
{
    std::lock_guard<std::mutex> guard(lock_);
    return version_;
}

int Pool::readyCount()
{
    std::lock_guard<std::mutex> guard(lock_);
    int count = 0;
    for (auto& worker : workers_) {
        if (worker.state == State::Idle || worker.state == State::Busy || worker.state == State::Checking) {
            count++;
        }
    }
    return count;
}

std::string Pool::statusJson()
{
    std::lock_guard<std::mutex> guard(lock_);
    std::ostringstream out;
    auto now = Clock::now();
    out << "{\"version\":\"" << xmlEscape(version_) << "\",\"waiting\":" << waiting_.load()
        << ",\"jobsDone\":" << jobsDone_.load() << ",\"jobsFailed\":" << jobsFailed_.load() << ",\"workers\":[";
    for (size_t i = 0; i < workers_.size(); i++) {
        auto& worker = workers_[i];
        if (i) {
            out << ",";
        }
        out << "{\"port\":" << worker.port << ",\"state\":\"" << stateName(worker.state) << "\",\"pid\":" << worker.pid
            << ",\"jobs\":" << worker.jobs << ",\"restarts\":" << worker.restarts
            << ",\"upSeconds\":" << (worker.pid > 0 ? std::chrono::duration_cast<std::chrono::seconds>(now - worker.since).count() : 0) << "}";
    }
    out << "]}";
    return out.str();
}

}
