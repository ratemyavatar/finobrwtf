#pragma once

#include <string>
#include <vector>

namespace rd {

struct Config {
    std::string listen = "127.0.0.1";
    int port = 64989;
    int workers = 2;
    int basePort = 65100;
    std::string rccDir;
    std::string wine = "wine";
    std::string winePrefix;
    std::string display;
    std::vector<std::string> allow = {"127.0.0.1"};
    int queueTimeout = 120;
    int jobTimeout = 120;
    int startTimeout = 120;
    int recycleAfter = 250;
    int healthEvery = 15;
    size_t maxBody = 8 * 1024 * 1024;
    int maxConnections = 128;
    int gameMax = 8;
    int gameSpares = 1;
    int gameBasePort = 65200;
    int gameLifetime = 21600;
    std::string client2012;
    int port2012 = 64990;
    int max2012 = 8;
    std::string accessKey;
    std::string domain = "finobe.wtf";
};

bool parseArgs(int argc, char** argv, Config& config, std::string& error);
std::string usage();

}
