#include <emscripten/websocket.h>
#include <arpa/inet.h>
#include <errno.h>
#include <netinet/in.h>
#include <stdarg.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/ioctl.h>
#include <unistd.h>
#include <chrono>
#include <condition_variable>
#include <deque>
#include <map>
#include <memory>
#include <mutex>
#include <string>
#include <vector>

namespace RBX { namespace Web { std::string udpRelayUrl; } }

namespace
{
    const int kFirstFd = 0x40000000;

    struct WebUdpSocket
    {
        std::mutex lock;
        std::condition_variable changed;
        std::deque<std::vector<unsigned char> > queue;
        EMSCRIPTEN_WEBSOCKET_T ws = 0;
        bool opening = false;
        bool open = false;
        bool closed = false;
        int fd = 0;
    };

    std::mutex registryLock;
    std::map<int, std::shared_ptr<WebUdpSocket> > registry;
    int nextFd = kFirstFd;

    std::shared_ptr<WebUdpSocket> find(int fd)
    {
        std::lock_guard<std::mutex> g(registryLock);
        std::map<int, std::shared_ptr<WebUdpSocket> >::iterator it = registry.find(fd);
        return it == registry.end() ? std::shared_ptr<WebUdpSocket>() : it->second;
    }

    std::shared_ptr<WebUdpSocket> fromUserData(void* userData)
    {
        return find((int)(intptr_t)userData);
    }

    EM_BOOL onOpen(int, const EmscriptenWebSocketOpenEvent*, void* userData)
    {
        if (std::shared_ptr<WebUdpSocket> s = fromUserData(userData))
        {
            std::lock_guard<std::mutex> g(s->lock);
            s->open = true;
            s->changed.notify_all();
        }
        return EM_TRUE;
    }

    EM_BOOL onClose(int, const EmscriptenWebSocketCloseEvent*, void* userData)
    {
        if (std::shared_ptr<WebUdpSocket> s = fromUserData(userData))
        {
            std::lock_guard<std::mutex> g(s->lock);
            s->closed = true;
            s->changed.notify_all();
        }
        return EM_TRUE;
    }

    EM_BOOL onError(int, const EmscriptenWebSocketErrorEvent*, void* userData)
    {
        if (std::shared_ptr<WebUdpSocket> s = fromUserData(userData))
        {
            std::lock_guard<std::mutex> g(s->lock);
            s->closed = true;
            s->changed.notify_all();
        }
        return EM_TRUE;
    }

    EM_BOOL onMessage(int, const EmscriptenWebSocketMessageEvent* e, void* userData)
    {
        if (e->isText || e->numBytes < 6)
            return EM_TRUE;
        if (std::shared_ptr<WebUdpSocket> s = fromUserData(userData))
        {
            std::lock_guard<std::mutex> g(s->lock);
            if (s->queue.size() < 4096)
                s->queue.push_back(std::vector<unsigned char>(e->data, e->data + e->numBytes));
            s->changed.notify_all();
        }
        return EM_TRUE;
    }

    bool ensureOpen(const std::shared_ptr<WebUdpSocket>& s)
    {
        std::unique_lock<std::mutex> l(s->lock);
        if (s->closed)
            return false;
        if (!s->opening)
        {
            s->opening = true;
            l.unlock();
            EmscriptenWebSocketCreateAttributes attrs;
            emscripten_websocket_init_create_attributes(&attrs);
            attrs.url = RBX::Web::udpRelayUrl.c_str();
            attrs.protocols = 0;
            EMSCRIPTEN_WEBSOCKET_T ws = emscripten_websocket_new(&attrs);
            l.lock();
            if (ws <= 0)
            {
                s->closed = true;
                return false;
            }
            s->ws = ws;
            l.unlock();
            void* ud = (void*)(intptr_t)s->fd;
            emscripten_websocket_set_onopen_callback(ws, ud, onOpen);
            emscripten_websocket_set_onclose_callback(ws, ud, onClose);
            emscripten_websocket_set_onerror_callback(ws, ud, onError);
            emscripten_websocket_set_onmessage_callback(ws, ud, onMessage);
            unsigned short state = 0;
            emscripten_websocket_get_ready_state(ws, &state);
            l.lock();
            if (state == 1)
                s->open = true;
        }
        s->changed.wait_for(l, std::chrono::seconds(15), [&] { return s->open || s->closed; });
        return s->open && !s->closed;
    }
}

extern "C" {

int rbx_web_is_udp(int fd)
{
    return fd >= kFirstFd && find(fd) ? 1 : 0;
}

int rbx_web_socket(int domain, int type, int protocol)
{
    if (domain != AF_INET || (type & 0xf) != SOCK_DGRAM || RBX::Web::udpRelayUrl.empty())
        return socket(domain, type, protocol);
    std::shared_ptr<WebUdpSocket> s(new WebUdpSocket());
    std::lock_guard<std::mutex> g(registryLock);
    s->fd = nextFd++;
    registry[s->fd] = s;
    return s->fd;
}

int rbx_web_bind(int fd, const struct sockaddr* addr, socklen_t len)
{
    if (rbx_web_is_udp(fd))
        return 0;
    return bind(fd, addr, len);
}

ssize_t rbx_web_sendto(int fd, const void* buf, size_t len, int flags, const struct sockaddr* to, socklen_t tolen)
{
    std::shared_ptr<WebUdpSocket> s = fd >= kFirstFd ? find(fd) : std::shared_ptr<WebUdpSocket>();
    if (!s)
        return sendto(fd, buf, len, flags, to, tolen);
    if (!to || tolen < (socklen_t)sizeof(sockaddr_in) || to->sa_family != AF_INET)
    {
        errno = EAFNOSUPPORT;
        return -1;
    }
    if (!ensureOpen(s))
    {
        errno = ENETUNREACH;
        return -1;
    }
    const sockaddr_in* a = (const sockaddr_in*)to;
    std::vector<unsigned char> frame(6 + len);
    memcpy(&frame[0], &a->sin_addr, 4);
    memcpy(&frame[4], &a->sin_port, 2);
    if (len)
        memcpy(&frame[6], buf, len);
    if (emscripten_websocket_send_binary(s->ws, &frame[0], frame.size()) != EMSCRIPTEN_RESULT_SUCCESS)
    {
        errno = ENETUNREACH;
        return -1;
    }
    return (ssize_t)len;
}

ssize_t rbx_web_recvfrom(int fd, void* buf, size_t len, int flags, struct sockaddr* from, socklen_t* fromlen)
{
    std::shared_ptr<WebUdpSocket> s = fd >= kFirstFd ? find(fd) : std::shared_ptr<WebUdpSocket>();
    if (!s)
        return recvfrom(fd, buf, len, flags, from, fromlen);
    std::vector<unsigned char> packet;
    {
        std::unique_lock<std::mutex> l(s->lock);
        s->changed.wait_for(l, std::chrono::milliseconds(50), [&] { return !s->queue.empty() || s->closed; });
        if (s->queue.empty())
        {
            errno = EWOULDBLOCK;
            return -1;
        }
        packet.swap(s->queue.front());
        s->queue.pop_front();
    }
    if (from && fromlen && *fromlen >= (socklen_t)sizeof(sockaddr_in))
    {
        sockaddr_in a;
        memset(&a, 0, sizeof(a));
        a.sin_family = AF_INET;
        memcpy(&a.sin_addr, &packet[0], 4);
        memcpy(&a.sin_port, &packet[4], 2);
        memcpy(from, &a, sizeof(a));
        *fromlen = sizeof(a);
    }
    size_t n = packet.size() - 6;
    if (n > len)
        n = len;
    if (n)
        memcpy(buf, &packet[6], n);
    return (ssize_t)n;
}

int rbx_web_close(int fd)
{
    std::shared_ptr<WebUdpSocket> s;
    if (fd >= kFirstFd)
    {
        std::lock_guard<std::mutex> g(registryLock);
        std::map<int, std::shared_ptr<WebUdpSocket> >::iterator it = registry.find(fd);
        if (it != registry.end())
        {
            s = it->second;
            registry.erase(it);
        }
    }
    if (!s)
        return close(fd);
    EMSCRIPTEN_WEBSOCKET_T ws = 0;
    {
        std::lock_guard<std::mutex> g(s->lock);
        s->closed = true;
        ws = s->ws;
        s->changed.notify_all();
    }
    if (ws > 0)
    {
        emscripten_websocket_close(ws, 1000, "");
        emscripten_websocket_delete(ws);
    }
    return 0;
}

int rbx_web_setsockopt(int fd, int level, int name, const void* value, socklen_t len)
{
    if (rbx_web_is_udp(fd))
        return 0;
    return setsockopt(fd, level, name, value, len);
}

int rbx_web_getsockopt(int fd, int level, int name, void* value, socklen_t* len)
{
    if (rbx_web_is_udp(fd))
    {
        if (value && len && *len)
            memset(value, 0, *len);
        return 0;
    }
    return getsockopt(fd, level, name, value, len);
}

int rbx_web_ioctl(int fd, unsigned long request, void* arg)
{
    if (rbx_web_is_udp(fd))
        return 0;
    return ioctl(fd, request, arg);
}

}
