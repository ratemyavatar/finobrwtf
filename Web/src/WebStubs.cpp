#include "DeviceGL.h"

#include <netdb.h>
#include <arpa/inet.h>
#include <string.h>

namespace RBX
{
namespace Graphics
{
DeviceVRGL* DeviceVRGL::createCardboard()
{
    return NULL;
}
}
}

extern "C" struct hostent* gethostbyname(const char* name)
{
    static struct in_addr addr;
    static char* addrs[2];
    static char* aliases[1];
    static char namebuf[256];
    static struct hostent h;

    if (!name || inet_aton(name, &addr) == 0)
    {
        h_errno = HOST_NOT_FOUND;
        return NULL;
    }

    strncpy(namebuf, name, sizeof(namebuf) - 1);
    addrs[0] = (char*)&addr;
    addrs[1] = NULL;
    aliases[0] = NULL;
    h.h_name = namebuf;
    h.h_aliases = aliases;
    h.h_addrtype = AF_INET;
    h.h_length = sizeof(addr);
    h.h_addr_list = addrs;
    return &h;
}
