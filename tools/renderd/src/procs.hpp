#pragma once

#include <string>
#include <sys/types.h>
#include <vector>

namespace rd {

struct Launch {
    std::string dir;
    std::vector<std::string> args;
    std::string display;
    std::string winePrefix;
};

pid_t launch(const Launch& spec);
void reapChildren();
bool takeExit(pid_t pid, int& status);
bool alive(pid_t pid);
void terminate(pid_t pid);
std::string describeExit(int status);

}
