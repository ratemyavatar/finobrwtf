#include "config.hpp"
#include "games.hpp"
#include "log.hpp"
#include "pool.hpp"
#include "server.hpp"

#include <csignal>
#include <cstdio>
#include <memory>
#include <pthread.h>
#include <thread>

int main(int argc, char** argv)
{
    rd::Config config;
    std::string error;
    if (!rd::parseArgs(argc, argv, config, error)) {
        if (!error.empty()) {
            std::fprintf(stderr, "%s\n\n", error.c_str());
        }
        std::fputs(rd::usage().c_str(), stderr);
        return error.empty() ? 0 : 2;
    }

    std::signal(SIGPIPE, SIG_IGN);
    sigset_t stopSignals;
    sigemptyset(&stopSignals);
    sigaddset(&stopSignals, SIGINT);
    sigaddset(&stopSignals, SIGTERM);
    pthread_sigmask(SIG_BLOCK, &stopSignals, nullptr);

    rd::Pool pool(config);
    rd::Games games(config);
    rd::Server soap(config, pool, games, rd::Server::Mode::Soap);
    std::unique_ptr<rd::Server> api2012;

    if (!soap.open(error)) {
        rd::log("can't listen: " + error);
        return 1;
    }
    if (games.enabled2012()) {
        api2012 = std::make_unique<rd::Server>(config, pool, games, rd::Server::Mode::Host2012);
        if (!api2012->open(error)) {
            rd::log("can't listen for the 2012 api: " + error);
            return 1;
        }
    }
    if (!pool.start(error)) {
        rd::log("can't start: " + error);
        return 1;
    }
    games.start(pool.display());

    std::thread waiter([&] {
        int signal = 0;
        sigwait(&stopSignals, &signal);
        rd::log("stopping");
        soap.shutdown();
        if (api2012) {
            api2012->shutdown();
        }
    });
    waiter.detach();

    std::thread apiThread;
    if (api2012) {
        apiThread = std::thread([&] { api2012->run(); });
    }
    soap.run();
    if (apiThread.joinable()) {
        apiThread.join();
    }
    games.stop();
    pool.stop();
    rd::log("stopped");
    return 0;
}
