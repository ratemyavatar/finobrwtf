#include "server.hpp"

#include "log.hpp"
#include "net.hpp"
#include "soap.hpp"

#include <algorithm>
#include <chrono>
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

}

Server::Server(const Config& config, Pool& pool) : config_(config), pool_(pool) {}

bool Server::open(std::string& error)
{
    listener_ = listenTcp(config_.listen, config_.port, error);
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
    log("listening on " + config_.listen + ":" + std::to_string(config_.port));
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
    if (request.method == "GET" && request.path == "/health") {
        int ready = pool_.readyCount();
        writeAll(fd, buildResponse(ready > 0 ? 200 : 503, "application/json", pool_.statusJson() + "\n"));
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
    if (isJob(action)) {
        return runJob(action, body, soapActionHeader, status);
    }
    if (action == "HelloWorld") {
        return envelope(action, "<ns1:HelloWorldResult>Hello World</ns1:HelloWorldResult>");
    }
    if (action == "GetVersion") {
        return envelope(action, "<ns1:GetVersionResult>" + xmlEscape(pool_.version()) + "</ns1:GetVersionResult>");
    }
    if (action == "GetStatus") {
        return envelope(action, "<ns1:GetStatusResult><ns1:version>" + xmlEscape(pool_.version()) + "</ns1:version><ns1:environmentCount>0</ns1:environmentCount></ns1:GetStatusResult>");
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
        return envelope(action, "<ns1:" + action + "Result></ns1:" + action + "Result>");
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
