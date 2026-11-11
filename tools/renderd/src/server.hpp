#pragma once

#include "config.hpp"
#include "pool.hpp"

#include <atomic>

namespace rd {

class Server {
public:
    Server(const Config& config, Pool& pool);

    bool open(std::string& error);
    void run();
    void shutdown();

private:
    void handle(int fd, const std::string& peer);
    std::string answer(const std::string& body, const std::string& soapActionHeader, int& status);
    std::string runJob(const std::string& action, const std::string& body, const std::string& soapActionHeader, int& status);
    bool allowed(const std::string& peer) const;

    Config config_;
    Pool& pool_;
    int listener_ = -1;
    std::atomic<bool> running_{false};
    std::atomic<int> active_{0};
};

}
