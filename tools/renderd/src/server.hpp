#pragma once

#include "config.hpp"
#include "games.hpp"
#include "net.hpp"
#include "pool.hpp"

#include <atomic>

namespace rd {

class Server {
public:
    enum class Mode { Soap, Host2012 };

    Server(const Config& config, Pool& pool, Games& games, Mode mode);

    bool open(std::string& error);
    void run();
    void shutdown();

private:
    void handle(int fd, const std::string& peer);
    void handleSoap(int fd, const std::string& peer, const Request& request);
    void handle2012(int fd, const Request& request);
    std::string answer(const std::string& body, const std::string& soapActionHeader, int& status);
    std::string runJob(const std::string& action, const std::string& body, const std::string& soapActionHeader, int& status);
    bool allowed(const std::string& peer) const;

    Config config_;
    Pool& pool_;
    Games& games_;
    Mode mode_;
    int port_;
    int listener_ = -1;
    std::atomic<bool> running_{false};
    std::atomic<int> active_{0};
};

}
