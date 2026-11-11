#include "soap.hpp"

namespace rd {

namespace {

const char* kHead =
    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
    "<SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\" "
    "xmlns:ns1=\"http://finobe.wtf/\"><SOAP-ENV:Body>";
const char* kTail = "</SOAP-ENV:Body></SOAP-ENV:Envelope>";

bool nameChar(char c)
{
    return (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_' || c == '-' || c == '.' || c == ':';
}

std::string localPart(const std::string& name)
{
    size_t colon = name.rfind(':');
    return colon == std::string::npos ? name : name.substr(colon + 1);
}

std::string request(const std::string& inner)
{
    return std::string("<?xml version=\"1.0\" encoding=\"utf-8\"?>"
        "<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:ns1=\"http://finobe.wtf/\">"
        "<soap:Body>") + inner + "</soap:Body></soap:Envelope>";
}

}

std::string soapAction(const std::string& body)
{
    size_t pos = 0;
    bool inBody = false;
    while ((pos = body.find('<', pos)) != std::string::npos) {
        pos++;
        if (pos >= body.size()) {
            break;
        }
        char c = body[pos];
        if (c == '?' || c == '!' || c == '/') {
            continue;
        }
        size_t end = pos;
        while (end < body.size() && nameChar(body[end])) {
            end++;
        }
        std::string name = localPart(body.substr(pos, end - pos));
        if (inBody) {
            return name;
        }
        if (name == "Body") {
            inBody = true;
        }
    }
    return "";
}

std::string firstElement(const std::string& body, const std::string& localName)
{
    size_t pos = 0;
    while ((pos = body.find('<', pos)) != std::string::npos) {
        pos++;
        if (pos >= body.size() || body[pos] == '/' || body[pos] == '?' || body[pos] == '!') {
            continue;
        }
        size_t end = pos;
        while (end < body.size() && nameChar(body[end])) {
            end++;
        }
        if (localPart(body.substr(pos, end - pos)) != localName) {
            continue;
        }
        size_t close = body.find('>', end);
        if (close == std::string::npos || body[close - 1] == '/') {
            return "";
        }
        size_t stop = body.find('<', close + 1);
        if (stop == std::string::npos) {
            return "";
        }
        return body.substr(close + 1, stop - close - 1);
    }
    return "";
}

std::string xmlEscape(const std::string& text)
{
    std::string out;
    out.reserve(text.size());
    for (char c : text) {
        switch (c) {
            case '&': out += "&amp;"; break;
            case '<': out += "&lt;"; break;
            case '>': out += "&gt;"; break;
            case '"': out += "&quot;"; break;
            case '\'': out += "&apos;"; break;
            default: out += c; break;
        }
    }
    return out;
}

std::string envelope(const std::string& action, const std::string& inner)
{
    return std::string(kHead) + "<ns1:" + action + "Response>" + inner + "</ns1:" + action + "Response>" + kTail;
}

std::string fault(const std::string& message)
{
    return std::string(kHead) + "<SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode><faultstring>" + xmlEscape(message) + "</faultstring></SOAP-ENV:Fault>" + kTail;
}

std::string closeJobRequest(const std::string& jobId)
{
    return request("<ns1:CloseJob><ns1:jobID>" + xmlEscape(jobId) + "</ns1:jobID></ns1:CloseJob>");
}

std::string helloWorldRequest()
{
    return request("<ns1:HelloWorld/>");
}

std::string getVersionRequest()
{
    return request("<ns1:GetVersion/>");
}

}
