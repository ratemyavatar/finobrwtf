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


#endif
