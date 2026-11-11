#include "games.hpp"

#include "log.hpp"
#include "net.hpp"
#include "procs.hpp"
#include "soap.hpp"

#include <cstdlib>
#include <sstream>
#include <vector>

namespace rd {

namespace {

bool helloWorld(int port, int timeoutSeconds)
{
    Response response;
    std::string error;
    if (!post(port, "\"http://finobe.wtf/HelloWorld\"", helloWorldRequest(), timeoutSeconds, response, error)) {
        return false;
    }
    return response.status == 200 && response.body.find("HelloWorldResult") != std::string::npos;
}

double toSeconds(const std::string& text, double fallback)
{
    char* end = nullptr;
    double value = std::strtod(text.c_str(), &end);
    if (text.empty() || *end != '\0' || value < 0 || value > 31536000) {
        return fallback;
    }
    return value;
}

bool safeUrl(const std::string& url)
{
    for (char c : url) {
        if (c < 0x21 || c > 0x7e || c == '\'' || c == '"' || c == '\\' || c == '`') {
            return false;
        }
    }
    return true;
}

bool validJobId(const std::string& id)
{
    if (id.size() != 36) {
        return false;
    }
    for (char c : id) {
        bool hex = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
        if (!hex && c != '-') {
            return false;
        }
    }
    return true;
}

}

Games::Games(const Config& config) : config_(config) {}

Games::~Games()
{
    stop();
}

const char* Games::stateName(State state)
{
    switch (state) {
        case State::Starting: return "starting";
        case State::Spare: return "spare";
        case State::Opening: return "opening";
        case State::Running: return "running";
    }
    return "unknown";
}

void Games::start(const std::string& display)
{
    display_ = display;
    running_ = true;
    if (enabled()) {
        std::lock_guard<std::mutex> guard(lock_);
        for (int i = 0; i < config_.gameSpares; i++) {
            spawn("");
        }
    }
    monitor_ = std::thread([this] { monitor(); });
}

void Games::stop()
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
        for (auto& item : games_) {
            pids.push_back(item.second.pid);
        }
        for (auto& item : servers2012_) {
            pids.push_back(item.second.pid);
        }
        games_.clear();
        servers2012_.clear();
    }
    for (pid_t pid : pids) {
        terminate(pid);
    }
}

int Games::freePort()
{
    int total = config_.gameMax + config_.gameSpares;
    for (int port = config_.gameBasePort; port < config_.gameBasePort + total; port++) {
        bool used = false;
        for (auto& item : games_) {
            if (item.second.port == port) {
                used = true;
                break;
            }
        }
        if (!used) {
            return port;
        }
    }
    return -1;
}

int Games::spawn(const std::string& reservedFor)
{
    int port = freePort();
    if (port < 0) {
        return -1;
    }
    Launch spec;
    spec.dir = config_.rccDir;
    spec.display = display_;
    spec.winePrefix = config_.winePrefix;
    spec.args = {config_.wine, "RCCService.exe", "-Console", "-verbose", "-placeid:-1", "-port", std::to_string(port)};
    pid_t pid = launch(spec);
    if (pid < 0) {
        log("could not start a game rcc on port " + std::to_string(port));
        return -1;
    }
    int id = nextId_++;
    Game game;
    game.pid = pid;
    game.port = port;
    game.reservedFor = reservedFor;
    game.since = Clock::now();
    game.lastCheck = Clock::now();
    games_[id] = game;
    log("started game rcc on port " + std::to_string(port) + " (pid " + std::to_string(pid) + ")");
    return id;
}

int Games::findJob(const std::string& jobId)
{
    if (jobId.empty()) {
        return -1;
    }
    for (auto& item : games_) {
        if (item.second.jobId == jobId || item.second.reservedFor == jobId) {
            return item.first;
        }
    }
    return -1;
}

std::string Games::open(const std::string& body, const std::string& header, int& status)
{
    std::string jobId = firstElement(body, "id");
    if (jobId.empty() || jobId.size() > 128) {
        status = 500;
        return fault("a game server job needs an id");
    }
    int id = -1;
    int port = 0;
    {
        std::unique_lock<std::mutex> guard(lock_);
        if (findJob(jobId) >= 0) {
            status = 500;
            return fault("job " + jobId + " is already running");
        }
        int busy = 0;
        for (auto& item : games_) {
            if (item.second.state == State::Opening || item.second.state == State::Running || !item.second.reservedFor.empty()) {
                busy++;
            }
        }
        if (busy >= config_.gameMax) {
            status = 500;
            return fault("this machine is full, no room for another game server");
        }
        for (auto& item : games_) {
            if (item.second.state == State::Spare) {
                id = item.first;
                break;
            }
        }
        if (id < 0) {
            for (auto& item : games_) {
                if (item.second.state == State::Starting && item.second.reservedFor.empty()) {
                    id = item.first;
                    break;
                }
            }
            if (id < 0) {
                id = spawn(jobId);
            }
            if (id < 0) {
                status = 500;
                return fault("could not start an rcc for the game server");
            }
            games_[id].reservedFor = jobId;
            auto deadline = Clock::now() + std::chrono::seconds(config_.startTimeout);
            while (true) {
                auto it = games_.find(id);
                if (it == games_.end()) {
                    status = 500;
                    return fault("the rcc for the game server died while starting");
                }
                if (it->second.state == State::Spare) {
                    break;
                }
                if (changed_.wait_until(guard, deadline) == std::cv_status::timeout) {
                    status = 500;
                    return fault("the rcc for the game server took too long to start");
                }
            }
        }
        Game& game = games_[id];
        game.state = State::Opening;
        game.jobId = jobId;
        game.reservedFor.clear();
        game.expiration = toSeconds(firstElement(body, "expirationInSeconds"), 21600);
        game.category = firstElement(body, "category");
        game.cores = firstElement(body, "cores");
        port = game.port;
    }
    changed_.notify_all();
    Response response;
    std::string error;
    bool sent = post(port, header.empty() ? "\"http://finobe.wtf/OpenJobEx\"" : header, body, 170, response, error);
    if (!sent || response.status != 200) {
        log("game server " + jobId + " failed to open: " + (sent ? "rcc answered " + std::to_string(response.status) : error));
        {
            std::lock_guard<std::mutex> guard(lock_);
            dropGame(id, false);
        }
        changed_.notify_all();
        status = 500;
        return sent && !response.body.empty() ? response.body : fault("game server failed to start: " + error);
    }
    {
        std::lock_guard<std::mutex> guard(lock_);
        auto it = games_.find(id);
        if (it != games_.end()) {
            it->second.state = State::Running;
            it->second.lastCheck = Clock::now();
            it->second.expiresAt = Clock::now() + std::chrono::seconds(static_cast<long>(it->second.expiration) + 60);
        }
    }
    changed_.notify_all();
    log("game server " + jobId + " is running on rcc port " + std::to_string(port));
    status = 200;
    return response.body;
}

bool Games::owns(const std::string& jobId)
{
    if (jobId.empty()) {
        return false;
    }
    std::lock_guard<std::mutex> guard(lock_);
    return findJob(jobId) >= 0;
}

std::string Games::forward(const std::string& jobId, const std::string& action, const std::string& body, const std::string& header, int& status)
{
    int port = 0;
    {
        std::lock_guard<std::mutex> guard(lock_);
        int id = findJob(jobId);
        if (id < 0 || games_[id].state != State::Running) {
            status = 500;
            return fault("job " + jobId + " is not running");
        }
        port = games_[id].port;
    }
    Response response;
    std::string error;
    if (!post(port, header.empty() ? "\"http://finobe.wtf/" + action + "\"" : header, body, 60, response, error)) {
        status = 500;
        return fault(action + " failed: " + error);
    }
    if (action == "RenewLease" && response.status == 200) {
        double seconds = toSeconds(firstElement(body, "expirationInSeconds"), -1);
        if (seconds >= 0) {
            std::lock_guard<std::mutex> guard(lock_);
            int id = findJob(jobId);
            if (id >= 0) {
                games_[id].expiration = seconds;
                games_[id].expiresAt = Clock::now() + std::chrono::seconds(static_cast<long>(seconds) + 60);
            }
        }
    }
    status = response.status == 0 ? 500 : response.status;
    return response.body;
}

void Games::dropGame(int id, bool closeFirst)
{
    auto it = games_.find(id);
    if (it == games_.end()) {
        return;
    }
    Game game = it->second;
    games_.erase(it);
    std::thread([game, closeFirst] {
        if (closeFirst && !game.jobId.empty()) {
            Response response;
            std::string error;
            post(game.port, "\"http://finobe.wtf/CloseJob\"", closeJobRequest(game.jobId), 10, response, error);
        }
        terminate(game.pid);
    }).detach();
}

void Games::close(const std::string& jobId)
{
    {
        std::lock_guard<std::mutex> guard(lock_);
        int id = findJob(jobId);
        if (id < 0) {
            return;
        }
        log("closing game server " + jobId);
        dropGame(id, true);
    }
    changed_.notify_all();
}

std::string Games::jobsXml()
{
    std::lock_guard<std::mutex> guard(lock_);
    std::string out;
    for (auto& item : games_) {
        const Game& game = item.second;
        if (game.state != State::Running) {
            continue;
        }
        out += "<ns1:Job><ns1:id>" + xmlEscape(game.jobId) + "</ns1:id><ns1:expirationInSeconds>" + std::to_string(static_cast<long>(game.expiration))
            + "</ns1:expirationInSeconds><ns1:category>" + xmlEscape(game.category.empty() ? "0" : game.category)
            + "</ns1:category><ns1:cores>" + xmlEscape(game.cores.empty() ? "1" : game.cores) + "</ns1:cores></ns1:Job>";
    }
    return out;
}

int Games::running()
{
    std::lock_guard<std::mutex> guard(lock_);
    int count = 0;
    for (auto& item : games_) {
        if (item.second.state == State::Running) {
            count++;
        }
    }
    return count;
}

bool Games::start2012(const std::string& jobId, const std::string& url, pid_t& pid, int& status, std::string& error)
{
    std::string prefix = "http://assetgame." + config_.domain + "/game/gameserver2012.ashx?token=";
    if (!validJobId(jobId) || url.compare(0, prefix.size(), prefix) != 0 || url.size() > 512 || !safeUrl(url)) {
        status = 400;
        error = "bad request";
        return false;
    }
    std::lock_guard<std::mutex> guard(lock_);
    auto existing = servers2012_.find(jobId);
    if (existing != servers2012_.end()) {
        pid = existing->second.pid;
        return true;
    }
    if (static_cast<int>(servers2012_.size()) >= config_.max2012) {
        status = 503;
        error = "full";
        return false;
    }
    Launch spec;
    spec.dir = config_.client2012;
    spec.display = display_;
    spec.winePrefix = config_.winePrefix;
    spec.args = {config_.wine, "RobloxApp.exe", "-Script", "loadfile('" + url + "')()"};
    pid = launch(spec);
    if (pid < 0) {
        status = 500;
        error = "internal";
        return false;
    }
    servers2012_[jobId] = Server2012{pid, Clock::now()};
    log("started 2012 game server " + jobId + " (pid " + std::to_string(pid) + ")");
    return true;
}

void Games::stop2012(const std::string& jobId)
{
    pid_t pid = -1;
    {
        std::lock_guard<std::mutex> guard(lock_);
        auto it = servers2012_.find(jobId);
        if (it == servers2012_.end()) {
            return;
        }
        pid = it->second.pid;
        servers2012_.erase(it);
    }
    log("stopping 2012 game server " + jobId);
    std::thread([pid] { terminate(pid); }).detach();
}

int Games::running2012()
{
    std::lock_guard<std::mutex> guard(lock_);
    return static_cast<int>(servers2012_.size());
}

void Games::monitor()
{
    while (running_) {
        reapChildren();
        std::vector<std::pair<int, int>> starting;
        std::vector<std::pair<int, int>> checks;
        auto now = Clock::now();
        {
            std::lock_guard<std::mutex> guard(lock_);
            int status = 0;
            for (auto it = servers2012_.begin(); it != servers2012_.end();) {
                if (takeExit(it->second.pid, status)) {
                    log("2012 game server " + it->first + " exited (" + describeExit(status) + ")");
                    it = servers2012_.erase(it);
                } else if (now - it->second.since > std::chrono::seconds(config_.gameLifetime)) {
                    log("2012 game server " + it->first + " ran too long, stopping it");
                    pid_t pid = it->second.pid;
                    std::thread([pid] { terminate(pid); }).detach();
                    it = servers2012_.erase(it);
                } else {
                    ++it;
                }
            }
            std::vector<int> gone;
            for (auto& item : games_) {
                Game& game = item.second;
                if (takeExit(game.pid, status)) {
                    log((game.jobId.empty() ? std::string("spare game rcc") : "game server " + game.jobId) + " on port " + std::to_string(game.port) + " exited (" + describeExit(status) + ")");
                    gone.push_back(item.first);
                    continue;
                }
                if (game.state == State::Starting) {
                    if (now - game.since > std::chrono::seconds(config_.startTimeout)) {
                        log("game rcc on port " + std::to_string(game.port) + " took too long to start");
                        dropGame(item.first, false);
                        break;
                    }
                    if (now - game.lastCheck >= std::chrono::seconds(2)) {
                        game.lastCheck = now;
                        starting.push_back({item.first, game.port});
                    }
                } else if (game.state == State::Running && now > game.expiresAt) {
                    log("game server " + game.jobId + " reached its expiration, closing it");
                    dropGame(item.first, true);
                    break;
                } else if ((game.state == State::Running || game.state == State::Spare) && now - game.lastCheck >= std::chrono::seconds(config_.healthEvery)) {
                    game.lastCheck = now;
                    checks.push_back({item.first, game.port});
                }
            }
            for (int id : gone) {
                games_.erase(id);
            }
            if (enabled()) {
                int spares = 0;
                int busy = 0;
                for (auto& item : games_) {
                    if (item.second.reservedFor.empty() && (item.second.state == State::Spare || item.second.state == State::Starting)) {
                        spares++;
                    } else {
                        busy++;
                    }
                }
                while (spares < config_.gameSpares && busy + spares < config_.gameMax + config_.gameSpares) {
                    if (spawn("") < 0) {
                        break;
                    }
                    spares++;
                }
            }
        }
        for (auto& item : starting) {
            if (helloWorld(item.second, 3)) {
                std::lock_guard<std::mutex> guard(lock_);
                auto it = games_.find(item.first);
                if (it != games_.end() && it->second.state == State::Starting) {
                    it->second.state = State::Spare;
                    it->second.lastCheck = Clock::now();
                    log("game rcc on port " + std::to_string(item.second) + " is ready");
                }
                changed_.notify_all();
            }
        }
        for (auto& item : checks) {
            bool up = helloWorld(item.second, 10);
            std::lock_guard<std::mutex> guard(lock_);
            auto it = games_.find(item.first);
            if (it == games_.end()) {
                continue;
            }
            if (up) {
                it->second.failedChecks = 0;
            } else if (++it->second.failedChecks >= 3) {
                log("game rcc on port " + std::to_string(item.second) + " stopped answering, killing it");
                dropGame(item.first, false);
            }
        }
        std::unique_lock<std::mutex> guard(lock_);
        changed_.wait_for(guard, std::chrono::milliseconds(500));
    }
}

std::string Games::statusJson()
{
    std::lock_guard<std::mutex> guard(lock_);
    std::ostringstream out;
    auto now = Clock::now();
    out << "{\"games\":[";
    bool first = true;
    for (auto& item : games_) {
        const Game& game = item.second;
        if (!first) {
            out << ",";
        }
        first = false;
        out << "{\"port\":" << game.port << ",\"state\":\"" << stateName(game.state) << "\",\"pid\":" << game.pid
            << ",\"jobId\":\"" << xmlEscape(game.jobId.empty() ? game.reservedFor : game.jobId) << "\",\"upSeconds\":"
            << std::chrono::duration_cast<std::chrono::seconds>(now - game.since).count() << "}";
    }
    out << "],\"games2012\":[";
    first = true;
    for (auto& item : servers2012_) {
        if (!first) {
            out << ",";
        }
        first = false;
        out << "{\"jobId\":\"" << xmlEscape(item.first) << "\",\"pid\":" << item.second.pid << ",\"upSeconds\":"
            << std::chrono::duration_cast<std::chrono::seconds>(now - item.second.since).count() << "}";
    }
    out << "]}";
    return out.str();
}

}
