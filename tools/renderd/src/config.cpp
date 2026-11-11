#include "config.hpp"

#include <cstdlib>
#include <sstream>

namespace rd {

namespace {

bool toInt(const std::string& text, int low, int high, int& out)
{
    char* end = nullptr;
    long value = std::strtol(text.c_str(), &end, 10);
    if (text.empty() || *end != '\0' || value < low || value > high) {
        return false;
    }
    out = static_cast<int>(value);
    return true;
}

std::vector<std::string> split(const std::string& text)
{
    std::vector<std::string> parts;
    std::stringstream stream(text);
    std::string part;
    while (std::getline(stream, part, ',')) {
        if (!part.empty()) {
            parts.push_back(part);
        }
    }
    return parts;
}

}

std::string usage()
{
    return
        "usage: finobe-renderd --rcc <dir with RCCService.exe> [options]\n"
        "  --listen <ip>          address to listen on (127.0.0.1)\n"
        "  --port <n>             port the site talks to (64989)\n"
        "  --workers <n>          rcc processes to keep running (2)\n"
        "  --base-port <n>        first port for the rcc processes (65100)\n"
        "  --wine <path>          wine binary (wine)\n"
        "  --wine-prefix <dir>    WINEPREFIX for the rcc processes\n"
        "  --display <:n>         X display to use, starts Xvfb if not set and DISPLAY is empty\n"
        "  --allow <ips>          comma list of ips allowed to connect (127.0.0.1)\n"
        "  --queue-timeout <s>    how long a job waits for a free rcc (120)\n"
        "  --job-timeout <s>      how long one job may run (120)\n"
        "  --start-timeout <s>    how long an rcc may take to start (120)\n"
        "  --recycle-after <n>    restart an rcc after this many jobs (250)\n"
        "  --health-every <s>     seconds between health checks (15)\n";
}

bool parseArgs(int argc, char** argv, Config& config, std::string& error)
{
    for (int i = 1; i < argc; i++) {
        std::string key = argv[i];
        if (key == "--help" || key == "-h") {
            error = "";
            return false;
        }
        if (i + 1 >= argc) {
            error = "missing value for " + key;
            return false;
        }
        std::string value = argv[++i];
        bool ok = true;
        if (key == "--listen") config.listen = value;
        else if (key == "--port") ok = toInt(value, 1, 65535, config.port);
        else if (key == "--workers") ok = toInt(value, 1, 64, config.workers);
        else if (key == "--base-port") ok = toInt(value, 1, 65535, config.basePort);
        else if (key == "--rcc") config.rccDir = value;
        else if (key == "--wine") config.wine = value;
        else if (key == "--wine-prefix") config.winePrefix = value;
        else if (key == "--display") config.display = value;
        else if (key == "--allow") config.allow = split(value);
        else if (key == "--queue-timeout") ok = toInt(value, 1, 3600, config.queueTimeout);
        else if (key == "--job-timeout") ok = toInt(value, 1, 3600, config.jobTimeout);
        else if (key == "--start-timeout") ok = toInt(value, 5, 3600, config.startTimeout);
        else if (key == "--recycle-after") ok = toInt(value, 1, 1000000, config.recycleAfter);
        else if (key == "--health-every") ok = toInt(value, 1, 3600, config.healthEvery);
        else {
            error = "unknown option " + key;
            return false;
        }
        if (!ok) {
            error = "bad value for " + key;
            return false;
        }
    }
    if (config.rccDir.empty()) {
        error = "--rcc is required";
        return false;
    }
    if (config.basePort + config.workers - 1 > 65535) {
        error = "--base-port is too high for that many workers";
        return false;
    }
    if (config.port >= config.basePort && config.port < config.basePort + config.workers) {
        error = "--port overlaps the worker ports";
        return false;
    }
    return true;
}

}
