#pragma once
#include <string>
#include <boost/shared_ptr.hpp>

namespace RBX
{
class Instance;
namespace Web
{
void sendAppEvent(void* pClosure);
void postAppEvent(void* pClosure);
void processAppEvents();
void exitGame();
void exitGameWithError(std::string errId);
void textBoxFocused(boost::shared_ptr<RBX::Instance> textBox);
void textBoxFocusLost(boost::shared_ptr<RBX::Instance> textBox);
void motionEventListening(std::string sensor);
std::string getApiUrl();
}
}
