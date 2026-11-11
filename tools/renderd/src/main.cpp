#include "config.hpp"
#include "log.hpp"
#include "pool.hpp"
#include "server.hpp"

#include <csignal>
#include <cstdio>
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
    rd::Server server(config, pool);
    if (!server.open(error)) {
        rd::log("can't listen: " + error);
        return 1;
    }
    if (!pool.start(error)) {
        rd::log("can't start: " + error);
        return 1;
    }

    std::thread waiter([&] {
        int signal = 0;
        sigwait(&stopSignals, &signal);
        rd::log("stopping");
        server.shutdown();
    });
    waiter.detach();

    server.run();
    pool.stop();
    rd::log("stopped");
    return 0;
}
