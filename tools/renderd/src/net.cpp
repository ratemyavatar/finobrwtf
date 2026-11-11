#include "net.hpp"

#include <arpa/inet.h>
#include <cerrno>
#include <chrono>
#include <cstring>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <poll.h>
#include <sys/socket.h>
#include <unistd.h>

namespace rd {

namespace {

const size_t kMaxHeader = 64 * 1024;

using Clock = std::chrono::steady_clock;

int remainingMs(Clock::time_point deadline)
{
    auto left = std::chrono::duration_cast<std::chrono::milliseconds>(deadline - Clock::now()).count();
    return left > 0 ? static_cast<int>(left) : 0;
}

bool waitFor(int fd, short events, Clock::time_point deadline)
{
    while (true) {
        int ms = remainingMs(deadline);
        if (ms <= 0) {
            return false;
        }
        pollfd p{fd, events, 0};
        int r = ::poll(&p, 1, ms);
        if (r > 0) {
            return true;
        }
        if (r == 0) {
            return false;
        }
        if (errno != EINTR) {
            return false;
        }
    }
}

bool parseHeaders(const std::string& block, std::string& firstLine, std::map<std::string, std::string>& headers)
{
    size_t start = 0;
    bool first = true;
    while (start < block.size()) {
        size_t end = block.find("\r\n", start);
        if (end == std::string::npos) {
            end = block.size();
        }
        std::string line = block.substr(start, end - start);
        start = end + 2;
        if (first) {
            firstLine = line;
            first = false;
            continue;
        }
        if (line.empty()) {
            continue;
        }
        size_t colon = line.find(':');
        if (colon == std::string::npos || colon == 0) {
            return false;
        }
        std::string name = lower(line.substr(0, colon));
        std::string value = line.substr(colon + 1);
        size_t a = value.find_first_not_of(" \t");
        size_t b = value.find_last_not_of(" \t");
        value = a == std::string::npos ? "" : value.substr(a, b - a + 1);
        headers[name] = value;
    }
    return !first;
}

bool parseLength(const std::string& text, size_t& out)
{
    if (text.empty() || text.size() > 12) {
        return false;
    }
    size_t value = 0;
    for (char c : text) {
        if (c < '0' || c > '9') {
            return false;
        }
        value = value * 10 + static_cast<size_t>(c - '0');
    }
    out = value;
    return true;
}

bool dechunk(const std::string& raw, std::string& out)
{
    size_t pos = 0;
    out.clear();
    while (true) {
        size_t end = raw.find("\r\n", pos);
        if (end == std::string::npos) {
            return false;
        }
        std::string sizeText = raw.substr(pos, end - pos);
        size_t semi = sizeText.find(';');
        if (semi != std::string::npos) {
            sizeText = sizeText.substr(0, semi);
        }
        char* stop = nullptr;
        unsigned long size = std::strtoul(sizeText.c_str(), &stop, 16);
        if (sizeText.empty() || *stop != '\0') {
            return false;
        }
        pos = end + 2;
        if (size == 0) {
            return true;
        }
        if (pos + size > raw.size()) {
            return false;
        }
        out.append(raw, pos, size);
        pos += size + 2;
    }
}

}

std::string lower(std::string text)
{
    for (char& c : text) {
        if (c >= 'A' && c <= 'Z') {
            c = static_cast<char>(c - 'A' + 'a');
        }
    }
    return text;
}

int listenTcp(const std::string& host, int port, std::string& error)
{
    int fd = ::socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd < 0) {
        error = std::strerror(errno);
        return -1;
    }
    int one = 1;
    ::setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(static_cast<uint16_t>(port));
    if (::inet_pton(AF_INET, host.c_str(), &addr.sin_addr) != 1) {
        error = "bad listen address " + host;
        ::close(fd);
        return -1;
    }
    if (::bind(fd, reinterpret_cast<sockaddr*>(&addr), sizeof(addr)) != 0 || ::listen(fd, 256) != 0) {
        error = std::strerror(errno);
        ::close(fd);
        return -1;
    }
    return fd;
}

int acceptClient(int listener, std::string& peer)
{
    sockaddr_in addr{};
    socklen_t len = sizeof(addr);
    int fd = ::accept4(listener, reinterpret_cast<sockaddr*>(&addr), &len, SOCK_CLOEXEC);
    if (fd < 0) {
        return -1;
    }
    char text[INET_ADDRSTRLEN] = {0};
    ::inet_ntop(AF_INET, &addr.sin_addr, text, sizeof(text));
    peer = text;
    int one = 1;
    ::setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
    return fd;
}

void setTimeouts(int fd, int seconds)
{
    timeval tv{seconds, 0};
    ::setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    ::setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv));
}

ReadResult readRequest(int fd, Request& request, size_t maxBody)
{
    auto deadline = Clock::now() + std::chrono::seconds(30);
    std::string data;
    char buffer[16384];
    size_t headerEnd = std::string::npos;
    while (headerEnd == std::string::npos) {
        if (!waitFor(fd, POLLIN, deadline)) {
            return ReadResult::Timeout;
        }
        ssize_t n = ::recv(fd, buffer, sizeof(buffer), 0);
        if (n == 0) {
            return ReadResult::Closed;
        }
        if (n < 0) {
            if (errno == EINTR || errno == EAGAIN) {
                continue;
            }
            return ReadResult::Closed;
        }
        data.append(buffer, static_cast<size_t>(n));
        headerEnd = data.find("\r\n\r\n");
        if (headerEnd == std::string::npos && data.size() > kMaxHeader) {
            return ReadResult::TooLarge;
        }
    }
    if (headerEnd > kMaxHeader) {
        return ReadResult::TooLarge;
    }
    std::string firstLine;
    if (!parseHeaders(data.substr(0, headerEnd), firstLine, request.headers)) {
        return ReadResult::Bad;
    }
    size_t s1 = firstLine.find(' ');
    size_t s2 = firstLine.find(' ', s1 == std::string::npos ? 0 : s1 + 1);
    if (s1 == std::string::npos || s2 == std::string::npos) {
        return ReadResult::Bad;
    }
    request.method = firstLine.substr(0, s1);
    request.path = firstLine.substr(s1 + 1, s2 - s1 - 1);
    std::string rest = data.substr(headerEnd + 4);
    if (request.headers.count("transfer-encoding")) {
        return ReadResult::LengthRequired;
    }
    size_t length = 0;
    auto it = request.headers.find("content-length");
    if (it != request.headers.end()) {
        if (!parseLength(it->second, length)) {
            return ReadResult::Bad;
        }
    } else if (request.method == "POST") {
        return ReadResult::LengthRequired;
    }
    if (length > maxBody) {
        return ReadResult::TooLarge;
    }
    if (rest.size() > length) {
        rest.resize(length);
    }
    request.body = std::move(rest);
    request.body.reserve(length);
    while (request.body.size() < length) {
        if (!waitFor(fd, POLLIN, deadline)) {
            return ReadResult::Timeout;
        }
        size_t want = std::min(sizeof(buffer), length - request.body.size());
        ssize_t n = ::recv(fd, buffer, want, 0);
        if (n == 0) {
            return ReadResult::Closed;
        }
        if (n < 0) {
            if (errno == EINTR || errno == EAGAIN) {
                continue;
            }
            return ReadResult::Closed;
        }
        request.body.append(buffer, static_cast<size_t>(n));
    }
    return ReadResult::Ok;
}

bool writeAll(int fd, const std::string& data)
{
    size_t sent = 0;
    while (sent < data.size()) {
        ssize_t n = ::send(fd, data.data() + sent, data.size() - sent, MSG_NOSIGNAL);
        if (n < 0) {
            if (errno == EINTR) {
                continue;
            }
            return false;
        }
        sent += static_cast<size_t>(n);
    }
    return true;
}

std::string buildResponse(int status, const std::string& contentType, const std::string& body)
{
    const char* reason = "OK";
    switch (status) {
        case 400: reason = "Bad Request"; break;
        case 403: reason = "Forbidden"; break;
        case 404: reason = "Not Found"; break;
        case 405: reason = "Method Not Allowed"; break;
        case 408: reason = "Request Timeout"; break;
        case 411: reason = "Length Required"; break;
        case 413: reason = "Payload Too Large"; break;
        case 500: reason = "Internal Server Error"; break;
        case 503: reason = "Service Unavailable"; break;
        default: break;
    }
    std::string out = "HTTP/1.1 " + std::to_string(status) + " " + reason + "\r\n";
    out += "Content-Type: " + contentType + "\r\n";
    out += "Content-Length: " + std::to_string(body.size()) + "\r\n";
    out += "Connection: close\r\n\r\n";
    out += body;
    return out;
}

bool post(int port, const std::string& soapAction, const std::string& body, int timeoutSeconds, Response& response, std::string& error)
{
    auto deadline = Clock::now() + std::chrono::seconds(timeoutSeconds);
    int fd = ::socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC | SOCK_NONBLOCK, 0);
    if (fd < 0) {
        error = std::strerror(errno);
        return false;
    }
    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(static_cast<uint16_t>(port));
    addr.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    int r = ::connect(fd, reinterpret_cast<sockaddr*>(&addr), sizeof(addr));
    if (r != 0 && errno != EINPROGRESS) {
        error = std::strerror(errno);
        ::close(fd);
        return false;
    }
    if (r != 0) {
        if (!waitFor(fd, POLLOUT, deadline)) {
            error = "connect timed out";
            ::close(fd);
            return false;
        }
        int soError = 0;
        socklen_t len = sizeof(soError);
        ::getsockopt(fd, SOL_SOCKET, SO_ERROR, &soError, &len);
        if (soError != 0) {
            error = std::strerror(soError);
            ::close(fd);
            return false;
        }
    }
    std::string head = "POST / HTTP/1.1\r\nHost: 127.0.0.1:" + std::to_string(port) + "\r\n";
    head += "Content-Type: text/xml; charset=utf-8\r\n";
    if (!soapAction.empty()) {
        head += "SOAPAction: " + soapAction + "\r\n";
    }
    head += "Content-Length: " + std::to_string(body.size()) + "\r\nConnection: close\r\n\r\n";
    std::string out = head + body;
    size_t sent = 0;
    while (sent < out.size()) {
        if (!waitFor(fd, POLLOUT, deadline)) {
            error = "send timed out";
            ::close(fd);
            return false;
        }
        ssize_t n = ::send(fd, out.data() + sent, out.size() - sent, MSG_NOSIGNAL);
        if (n < 0) {
            if (errno == EINTR || errno == EAGAIN) {
                continue;
            }
            error = std::strerror(errno);
            ::close(fd);
            return false;
        }
        sent += static_cast<size_t>(n);
    }
    std::string data;
    char buffer[65536];
    size_t headerEnd = std::string::npos;
    size_t length = 0;
    bool haveLength = false;
    bool chunked = false;
    std::string firstLine;
    while (true) {
        if (headerEnd != std::string::npos && haveLength && data.size() >= headerEnd + 4 + length) {
            break;
        }
        if (headerEnd != std::string::npos && chunked && data.find("\r\n0\r\n", headerEnd) != std::string::npos && data.size() >= 5 && data.compare(data.size() - 4, 4, "\r\n\r\n") == 0) {
            break;
        }
        if (!waitFor(fd, POLLIN, deadline)) {
            error = "rcc did not answer in time";
            ::close(fd);
            return false;
        }
        ssize_t n = ::recv(fd, buffer, sizeof(buffer), 0);
        if (n == 0) {
            break;
        }
        if (n < 0) {
            if (errno == EINTR || errno == EAGAIN) {
                continue;
            }
            error = std::strerror(errno);
            ::close(fd);
            return false;
        }
        data.append(buffer, static_cast<size_t>(n));
        if (data.size() > 256u * 1024u * 1024u) {
            error = "rcc answer is too big";
            ::close(fd);
            return false;
        }
        if (headerEnd == std::string::npos) {
            headerEnd = data.find("\r\n\r\n");
            if (headerEnd != std::string::npos) {
                response.headers.clear();
                if (!parseHeaders(data.substr(0, headerEnd), firstLine, response.headers)) {
                    error = "rcc sent bad headers";
                    ::close(fd);
                    return false;
                }
                auto te = response.headers.find("transfer-encoding");
                chunked = te != response.headers.end() && lower(te->second).find("chunked") != std::string::npos;
                auto cl = response.headers.find("content-length");
                if (!chunked && cl != response.headers.end()) {
                    haveLength = parseLength(cl->second, length);
                }
            }
        }
    }
    ::close(fd);
    if (headerEnd == std::string::npos) {
        error = "rcc closed the connection";
        return false;
    }
    size_t space = firstLine.find(' ');
    response.status = space == std::string::npos ? 0 : std::atoi(firstLine.c_str() + space + 1);
    std::string raw = data.substr(headerEnd + 4);
    if (chunked) {
        if (!dechunk(raw, response.body)) {
            error = "rcc sent a bad chunked body";
            return false;
        }
    } else {
        if (haveLength && raw.size() > length) {
            raw.resize(length);
        }
        if (haveLength && raw.size() < length) {
            error = "rcc answer was cut off";
            return false;
        }
        response.body = std::move(raw);
    }
    return true;
}

}
