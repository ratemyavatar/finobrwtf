#ifndef __SOCKET_DEFINES_H
#define __SOCKET_DEFINES_H

/// Internal







































	#if   defined(_WIN32)
		#define closesocket__ closesocket
		#define select__ select
	#else
		#define closesocket__ close
		#define select__ select
	#endif
#define accept__ accept
#define connect__ connect
#define socket__ socket
#define bind__ bind
#if defined(__EMSCRIPTEN__)
#include <string.h>
static inline int rbx_web_getsockname(int, struct sockaddr* address, socklen_t* address_len)
{
	if (address && address_len)
	{
		memset(address, 0, *address_len);
		address->sa_family = AF_INET;
	}
	return 0;
}
#define getsockname__ rbx_web_getsockname
#include <sys/types.h>
#include <sys/socket.h>
extern "C" {
int rbx_web_socket(int domain, int type, int protocol);
int rbx_web_bind(int fd, const struct sockaddr* addr, socklen_t len);
ssize_t rbx_web_sendto(int fd, const void* buf, size_t len, int flags, const struct sockaddr* to, socklen_t tolen);
ssize_t rbx_web_recvfrom(int fd, void* buf, size_t len, int flags, struct sockaddr* from, socklen_t* fromlen);
int rbx_web_close(int fd);
int rbx_web_setsockopt(int fd, int level, int name, const void* value, socklen_t len);
int rbx_web_getsockopt(int fd, int level, int name, void* value, socklen_t* len);
}
#else
#define getsockname__ getsockname
#endif
#define getsockopt__ getsockopt
#define inet_addr__ inet_addr
#define ioctlsocket__ ioctlsocket
#define listen__ listen
#define recv__ recv
#define recvfrom__ recvfrom
#define sendto__ sendto
#define send__ send
#define setsockopt__ setsockopt
#define shutdown__ shutdown
#define WSASendTo__ WSASendTo


#if defined(__EMSCRIPTEN__)
#undef closesocket__
#undef socket__
#undef bind__
#undef recvfrom__
#undef sendto__
#undef setsockopt__
#undef getsockopt__
#define closesocket__ rbx_web_close
#define socket__ rbx_web_socket
#define bind__ rbx_web_bind
#define recvfrom__ rbx_web_recvfrom
#define sendto__ rbx_web_sendto
#define setsockopt__ rbx_web_setsockopt
#define getsockopt__ rbx_web_getsockopt
#endif
#endif
