#pragma once

#include <string>

namespace rd {

std::string soapAction(const std::string& body);
std::string firstElement(const std::string& body, const std::string& localName);
std::string xmlEscape(const std::string& text);
std::string envelope(const std::string& action, const std::string& inner);
std::string fault(const std::string& message);
std::string closeJobRequest(const std::string& jobId);
std::string helloWorldRequest();
std::string getVersionRequest();

}
