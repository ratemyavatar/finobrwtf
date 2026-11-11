#include "config.hpp"

#include <cstdlib>
#include <fstream>
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

bool readKey(const std::string& path, std::string& out)
{
    std::ifstream file(path);
    if (!file) {
        return false;
    }
    std::getline(file, out);
    while (!out.empty() && (out.back() == '\r' || out.back() == ' ' || out.back() == '\t')) {
        out.pop_back();
    }
    return out.size() >= 16;
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
        "  --health-every <s>     seconds between health checks (15)\n"
        "  --game-max <n>         most 2016 game servers at once, 0 turns them off (8)\n"
        "  --game-spares <n>      2016 rcc copies kept ready for new games (1)\n"
        "  --game-base-port <n>   first soap port for 2016 game rcc copies (65200)\n"
        "  --game-lifetime <s>    longest a 2012 game server may run (21600)\n"
        "  --client2012 <dir>     folder with the 2012 RobloxApp.exe, turns on 2012 game servers\n"
        "  --port2012 <n>         port for the 2012 start and stop api (64990)\n"
        "  --max2012 <n>          most 2012 game servers at once (8)\n"
        "  --access-key-file <f>  file with the site's FINOBE_CLOUD_AUTHORIZATION key\n"
        "  --domain <name>        site domain, used to check 2012 script urls (finobe.wtf)\n";
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
        else if (key == "--game-max") ok = toInt(value, 0, 256, config.gameMax);
        else if (key == "--game-spares") ok = toInt(value, 0, 16, config.gameSpares);
        else if (key == "--game-base-port") ok = toInt(value, 1, 65535, config.gameBasePort);
        else if (key == "--game-lifetime") ok = toInt(value, 60, 604800, config.gameLifetime);
        else if (key == "--client2012") config.client2012 = value;
        else if (key == "--port2012") ok = toInt(value, 1, 65535, config.port2012);
        else if (key == "--max2012") ok = toInt(value, 1, 256, config.max2012);
        else if (key == "--access-key-file") ok = readKey(value, config.accessKey);
        else if (key == "--domain") config.domain = value;
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
    if (config.accessKey.empty()) {
        if (const char* key = std::getenv("RENDERD_ACCESS_KEY")) {
            config.accessKey = key;
        }
    }
    if (!config.client2012.empty() && config.accessKey.size() < 16) {
        error = "2012 game servers need --access-key-file or RENDERD_ACCESS_KEY (16+ characters)";
        return false;
    }
    if (config.gameMax > 0 && config.gameBasePort + config.gameMax + config.gameSpares > 65535) {
        error = "--game-base-port is too high for that many games";
        return false;
    }
    auto overlap = [](int a, int an, int b, int bn) { return a < b + bn && b < a + an; };
    if (config.gameMax > 0 && overlap(config.basePort, config.workers, config.gameBasePort, config.gameMax + config.gameSpares)) {
        error = "render and game ports overlap";
        return false;
    }
    if (config.port >= config.basePort && config.port < config.basePort + config.workers) {
        error = "--port overlaps the worker ports";
        return false;
    }
    return true;
}

}
