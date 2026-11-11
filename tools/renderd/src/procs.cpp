#include "procs.hpp"

#include <cerrno>
#include <csignal>
#include <cstdlib>
#include <fcntl.h>
#include <map>
#include <mutex>
#include <sys/wait.h>
#include <unistd.h>

namespace rd {

namespace {

std::mutex exitLock;
std::map<pid_t, int> exits;

void redirectToNull()
{
    int null = ::open("/dev/null", O_RDWR);
    if (null >= 0) {
        ::dup2(null, 0);
        ::dup2(null, 1);
        ::dup2(null, 2);
        if (null > 2) {
            ::close(null);
        }
    }
}

}

pid_t launch(const Launch& spec)
{
    std::vector<char*> argv;
    for (const auto& arg : spec.args) {
        argv.push_back(const_cast<char*>(arg.c_str()));
    }
    argv.push_back(nullptr);
    pid_t pid = ::fork();
    if (pid != 0) {
        return pid;
    }
    sigset_t none;
    sigemptyset(&none);
    ::sigprocmask(SIG_SETMASK, &none, nullptr);
    std::signal(SIGPIPE, SIG_DFL);
    ::setsid();
    if (!spec.dir.empty() && ::chdir(spec.dir.c_str()) != 0) {
        ::_exit(126);
    }
    redirectToNull();
    for (const char* name : {"http_proxy", "https_proxy", "HTTP_PROXY", "HTTPS_PROXY", "no_proxy", "NO_PROXY", "all_proxy", "ALL_PROXY"}) {
        ::unsetenv(name);
    }
    if (!spec.display.empty()) {
        ::setenv("DISPLAY", spec.display.c_str(), 1);
    }
    ::setenv("WINEDEBUG", "-all", 1);
    ::setenv("WINEDLLOVERRIDES", "mscoree,mshtml=", 1);
    if (!spec.winePrefix.empty()) {
        ::setenv("WINEPREFIX", spec.winePrefix.c_str(), 1);
    }
    ::execvp(argv[0], argv.data());
    ::_exit(127);
}

void reapChildren()
{
    while (true) {
        int status = 0;
        pid_t pid = ::waitpid(-1, &status, WNOHANG);
        if (pid <= 0) {
            return;
        }
        std::lock_guard<std::mutex> guard(exitLock);
        exits[pid] = status;
    }
}

bool takeExit(pid_t pid, int& status)
{
    std::lock_guard<std::mutex> guard(exitLock);
    auto it = exits.find(pid);
    if (it == exits.end()) {
        return false;
    }
    status = it->second;
    exits.erase(it);
    return true;
}

bool alive(pid_t pid)
{
    reapChildren();
    std::lock_guard<std::mutex> guard(exitLock);
    return exits.find(pid) == exits.end();
}

void terminate(pid_t pid)
{
    if (pid <= 0) {
        return;
    }
    ::kill(-pid, SIGTERM);
    ::kill(pid, SIGTERM);
    int status = 0;
    for (int i = 0; i < 30; i++) {
        reapChildren();
        if (takeExit(pid, status)) {
            ::kill(-pid, SIGKILL);
            return;
        }
        ::usleep(100000);
    }
    ::kill(-pid, SIGKILL);
    ::kill(pid, SIGKILL);
    for (int i = 0; i < 50; i++) {
        reapChildren();
        if (takeExit(pid, status)) {
            return;
        }
        ::usleep(100000);
    }
}

std::string describeExit(int status)
{
    if (WIFSIGNALED(status)) {
        return "signal " + std::to_string(WTERMSIG(status));
    }
    return "code " + std::to_string(WEXITSTATUS(status));
}

}
