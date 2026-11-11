#pragma once

#include <map>
#include <string>

namespace rd {

struct Request {
    std::string method;
    std::string path;
    std::map<std::string, std::string> headers;
    std::string body;
};

struct Response {
    int status = 0;
    std::map<std::string, std::string> headers;
    std::string body;
};

enum class ReadResult { Ok, Closed, Timeout, TooLarge, Bad, LengthRequired };

int listenTcp(const std::string& host, int port, std::string& error);
int acceptClient(int listener, std::string& peer);
void setTimeouts(int fd, int seconds);
ReadResult readRequest(int fd, Request& request, size_t maxBody);
bool writeAll(int fd, const std::string& data);
std::string buildResponse(int status, const std::string& contentType, const std::string& body);
bool post(int port, const std::string& soapAction, const std::string& body, int timeoutSeconds, Response& response, std::string& error);
std::string lower(std::string text);

}
