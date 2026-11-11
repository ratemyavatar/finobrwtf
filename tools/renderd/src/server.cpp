#include "server.hpp"

#include "log.hpp"
#include "net.hpp"
#include "soap.hpp"

#include <algorithm>
#include <chrono>
#include <cstdlib>
#include <sys/socket.h>
#include <thread>
#include <unistd.h>

namespace rd {

namespace {

const char* kXml = "text/xml; charset=utf-8";

bool isJob(const std::string& action)
{
    return action == "OpenJob" || action == "OpenJobEx" || action == "BatchJob" || action == "BatchJobEx";
}

bool needsClose(const std::string& action)
{
    return action == "OpenJob" || action == "OpenJobEx";
}

bool isGameJob(const std::string& action, const std::string& body, int jobTimeout)
{
    if (action != "OpenJob" && action != "OpenJobEx") {
        return false;
    }
    if (firstElement(body, "name") == "GameServer") {
        return true;
    }
    char* end = nullptr;
    std::string text = firstElement(body, "expirationInSeconds");
    double seconds = std::strtod(text.c_str(), &end);
    return !text.empty() && *end == '\0' && seconds > jobTimeout;
}

bool jsonString(const std::string& body, const std::string& key, std::string& out)
{
    std::string quoted = "\"" + key + "\"";
    size_t pos = body.find(quoted);
    if (pos == std::string::npos) {
        return false;
    }
    pos = body.find_first_not_of(" \t\r\n", pos + quoted.size());
    if (pos == std::string::npos || body[pos] != ':') {
        return false;
    }
    pos = body.find_first_not_of(" \t\r\n", pos + 1);
    if (pos == std::string::npos || body[pos] != '"') {
        return false;
    }
    out.clear();
    for (size_t i = pos + 1; i < body.size(); i++) {
        char c = body[i];
        if (c == '"') {
            return true;
        }
        if (static_cast<unsigned char>(c) < 0x20) {
            return false;
        }
        if (c != '\\') {
            out += c;
            continue;
        }
        if (++i >= body.size()) {
            return false;
        }
        switch (body[i]) {
            case '"': out += '"'; break;
            case '\\': out += '\\'; break;
            case '/': out += '/'; break;
            case 'u': {
                if (i + 4 >= body.size()) {
                    return false;
                }
                unsigned value = static_cast<unsigned>(std::strtoul(body.substr(i + 1, 4).c_str(), nullptr, 16));
                if (value == 0 || value > 0x7e) {
                    return false;
                }
                out += static_cast<char>(value);
                i += 4;
                break;
            }
            default: return false;
        }
    }
    return false;
}

bool sameKey(const std::string& a, const std::string& b)
{
    if (a.empty() || b.empty()) {
        return false;
    }
    unsigned char diff = static_cast<unsigned char>(a.size() != b.size());
    for (size_t i = 0; i < b.size(); i++) {
        diff |= static_cast<unsigned char>((i < a.size() ? a[i] : 0) ^ b[i]);
    }
    return diff == 0;
}

std::string json(int status, const std::string& body)
{
    return buildResponse(status, "application/json", body);
}

}

Server::Server(const Config& config, Pool& pool, Games& games, Mode mode)
    : config_(config), pool_(pool), games_(games), mode_(mode), port_(mode == Mode::Soap ? config.port : config.port2012) {}

bool Server::open(std::string& error)
{
    listener_ = listenTcp(config_.listen, port_, error);
    if (listener_ < 0) {
        return false;
    }
    running_ = true;
    return true;
}

void Server::shutdown()
{
    if (running_.exchange(false) && listener_ >= 0) {
        ::shutdown(listener_, SHUT_RDWR);
    }
}

bool Server::allowed(const std::string& peer) const
{
    return std::find(config_.allow.begin(), config_.allow.end(), peer) != config_.allow.end();
}

void Server::run()
{
    log(std::string(mode_ == Mode::Soap ? "soap" : "2012 api") + " listening on " + config_.listen + ":" + std::to_string(port_));
    while (running_) {
        std::string peer;
        int fd = acceptClient(listener_, peer);
        if (fd < 0) {
            if (!running_) {
                break;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(50));
            continue;
        }
        if (!allowed(peer)) {
            log("refused connection from " + peer);
            writeAll(fd, buildResponse(403, "text/plain", "forbidden\n"));
            ::close(fd);
            continue;
        }
        if (active_.load() >= config_.maxConnections) {
            writeAll(fd, buildResponse(503, kXml, fault("too many requests at once")));
            ::close(fd);
            continue;
        }
        active_++;
        std::thread([this, fd, peer] {
            handle(fd, peer);
            ::close(fd);
            active_--;
        }).detach();
    }
    ::close(listener_);
    listener_ = -1;
    for (int i = 0; i < 300 && active_.load() > 0; i++) {
        std::this_thread::sleep_for(std::chrono::milliseconds(100));
    }
}

void Server::handle(int fd, const std::string& peer)
{
    setTimeouts(fd, 30);
    Request request;
    ReadResult result = readRequest(fd, request, config_.maxBody);
    switch (result) {
        case ReadResult::Ok: break;
        case ReadResult::Closed: return;
        case ReadResult::Timeout: writeAll(fd, buildResponse(408, "text/plain", "timeout\n")); return;
        case ReadResult::TooLarge: writeAll(fd, buildResponse(413, "text/plain", "too large\n")); return;
        case ReadResult::LengthRequired: writeAll(fd, buildResponse(411, "text/plain", "content-length required\n")); return;
        case ReadResult::Bad: writeAll(fd, buildResponse(400, "text/plain", "bad request\n")); return;
    }
    if (mode_ == Mode::Host2012) {
        handle2012(fd, request);
        return;
    }
    handleSoap(fd, peer, request);
}

void Server::handle2012(int fd, const Request& request)
{
    auto key = request.headers.find("accesskey");
    if (key == request.headers.end() || !sameKey(key->second, config_.accessKey)) {
        writeAll(fd, json(403, "{\"error\":\"forbidden\"}"));
        return;
    }
    std::string path = request.path;
    while (path.size() > 1 && path.back() == '/') {
        path.pop_back();
    }
    if (path == "/health") {
        writeAll(fd, json(200, "{\"running\":" + std::to_string(games_.running2012()) + "}"));
        return;
    }
    if (request.method != "POST" || (path != "/start" && path != "/stop")) {
        writeAll(fd, json(404, "{\"error\":\"not found\"}"));
        return;
    }
    std::string jobId;
    if (!jsonString(request.body, "jobId", jobId)) {
        writeAll(fd, json(400, "{\"error\":\"bad request\"}"));
        return;
    }
    if (path == "/stop") {
        games_.stop2012(jobId);
        writeAll(fd, json(200, "{\"ok\":true}"));
        return;
    }
    std::string url;
    pid_t pid = -1;
    int status = 200;
    std::string error;
    if (!jsonString(request.body, "scriptUrl", url) || !games_.start2012(jobId, url, pid, status, error)) {
        writeAll(fd, json(error.empty() ? 400 : status, "{\"error\":\"" + (error.empty() ? std::string("bad request") : error) + "\"}"));
        return;
    }
    writeAll(fd, json(200, "{\"pid\":" + std::to_string(pid) + "}"));
}

void Server::handleSoap(int fd, const std::string& peer, const Request& request)
{
    if (request.method == "GET" && request.path == "/health") {
        int ready = pool_.readyCount();
        std::string body = "{\"renders\":" + pool_.statusJson() + ",\"games\":" + games_.statusJson() + "}\n";
        writeAll(fd, buildResponse(ready > 0 ? 200 : 503, "application/json", body));
        return;
    }
    if (request.method != "POST") {
        writeAll(fd, buildResponse(405, "text/plain", "method not allowed\n"));
        return;
    }
    auto it = request.headers.find("soapaction");
    std::string header = it == request.headers.end() ? "" : it->second;
    int status = 200;
    auto started = std::chrono::steady_clock::now();
    std::string body = answer(request.body, header, status);
    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - started).count();
    std::string action = soapAction(request.body);
    if (isJob(action) || status != 200) {
        log(peer + " " + (action.empty() ? "?" : action) + " " + std::to_string(status) + " " + std::to_string(ms) + "ms");
    }
    writeAll(fd, buildResponse(status, kXml, body));
}

std::string Server::answer(const std::string& body, const std::string& soapActionHeader, int& status)
{
    std::string action = soapAction(body);
    if (action.empty()) {
        status = 400;
        return fault("no soap action in the request");
    }
    if (isGameJob(action, body, config_.jobTimeout)) {
        if (!games_.enabled()) {
            status = 500;
            return fault("game servers are turned off on this machine");
        }
        return games_.open(body, soapActionHeader, status);
    }
    if (isJob(action)) {
        return runJob(action, body, soapActionHeader, status);
    }
    std::string jobId = firstElement(body, "jobID");
    if (jobId.empty()) {
        jobId = firstElement(body, "jobId");
    }
    bool gameJob = games_.owns(jobId);
    if (action == "CloseJob") {
        if (gameJob) {
            games_.close(jobId);
        }
        return envelope(action, "");
    }
    if (gameJob && (action == "RenewLease" || action == "GetExpiration" || action == "Execute" || action == "ExecuteEx" || action == "Diag" || action == "DiagEx")) {
        return games_.forward(jobId, action, body, soapActionHeader, status);
    }
    if (action == "HelloWorld") {
        return envelope(action, "<ns1:HelloWorldResult>Hello World</ns1:HelloWorldResult>");
    }
    if (action == "GetVersion") {
        return envelope(action, "<ns1:GetVersionResult>" + xmlEscape(pool_.version()) + "</ns1:GetVersionResult>");
    }
    if (action == "GetStatus") {
        return envelope(action, "<ns1:GetStatusResult><ns1:version>" + xmlEscape(pool_.version()) + "</ns1:version><ns1:environmentCount>" + std::to_string(games_.running()) + "</ns1:environmentCount></ns1:GetStatusResult>");
    }
    if (action == "CloseJob") {
        return envelope(action, "");
    }
    if (action == "CloseAllJobs" || action == "CloseExpiredJobs") {
        return envelope(action, "<ns1:" + action + "Result>0</ns1:" + action + "Result>");
    }
    if (action == "RenewLease" || action == "GetExpiration") {
        return envelope(action, "<ns1:" + action + "Result>0</ns1:" + action + "Result>");
    }
    if (action == "GetAllJobs" || action == "GetAllJobsEx") {
        return envelope(action, "<ns1:" + action + "Result>" + games_.jobsXml() + "</ns1:" + action + "Result>");
    }
    status = 500;
    return fault(action + " is not supported by the render service");
}

std::string Server::runJob(const std::string& action, const std::string& body, const std::string& soapActionHeader, int& status)
{
    std::string jobId = firstElement(body, "id");
    int index = pool_.acquire(config_.queueTimeout);
    if (index < 0) {
        status = 500;
        return fault("no rcc was free in time, try again");
    }
    int port = pool_.port(index);
    std::string header = soapActionHeader.empty() ? "\"http://finobe.wtf/" + action + "\"" : soapActionHeader;
    Response response;
    std::string error;
    if (!post(port, header, body, config_.jobTimeout, response, error)) {
        log("job " + jobId + " on port " + std::to_string(port) + " failed: " + error);
        pool_.release(index, false);
        status = 500;
        return fault("render failed: " + error);
    }
    bool healthy = response.status == 200 || response.status == 500;
    if (needsClose(action) && !jobId.empty()) {
        Response closed;
        std::string closeError;
        if (!post(port, "\"http://finobe.wtf/CloseJob\"", closeJobRequest(jobId), 15, closed, closeError)) {
            log("closing job " + jobId + " on port " + std::to_string(port) + " failed: " + closeError);
            healthy = false;
        }
    }
    pool_.release(index, healthy);
    status = response.status == 0 ? 500 : response.status;
    return response.body;
}

}
