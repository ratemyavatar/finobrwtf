#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include "posix_sockets.h"
#include "threads.h"
#include <string.h>
#include <errno.h>
#include <assert.h>

#include "websocket_to_posix_proxy.h"
#include "socket_registry.h"

#define MIN(a,b) (((a)<(b))?(a):(b))
#define MAX(a,b) (((a)>(b))?(a):(b))

uint64_t ntoh64(uint64_t x)
{
  return ntohl(x>>32) | ((uint64_t)ntohl(x&0xFFFFFFFFu) << 32);
}

#define POSIX_SOCKET_MSG_SOCKET 1
#define POSIX_SOCKET_MSG_SOCKETPAIR 2
#define POSIX_SOCKET_MSG_SHUTDOWN 3
#define POSIX_SOCKET_MSG_BIND 4
#define POSIX_SOCKET_MSG_CONNECT 5
#define POSIX_SOCKET_MSG_LISTEN 6
#define POSIX_SOCKET_MSG_ACCEPT 7
#define POSIX_SOCKET_MSG_GETSOCKNAME 8
#define POSIX_SOCKET_MSG_GETPEERNAME 9
#define POSIX_SOCKET_MSG_SEND 10
#define POSIX_SOCKET_MSG_RECV 11
#define POSIX_SOCKET_MSG_SENDTO 12
#define POSIX_SOCKET_MSG_RECVFROM 13
#define POSIX_SOCKET_MSG_SENDMSG 14
#define POSIX_SOCKET_MSG_RECVMSG 15
#define POSIX_SOCKET_MSG_GETSOCKOPT 16
#define POSIX_SOCKET_MSG_SETSOCKOPT 17
#define POSIX_SOCKET_MSG_GETADDRINFO 18
#define POSIX_SOCKET_MSG_GETNAMEINFO 19

#define MAX_SOCKADDR_SIZE 256
#define MAX_OPTIONVALUE_SIZE 16

struct SocketCallHeader
{
  int callId;
  int function;
};

static char buf_temp_str[2048] = {};

static char *BufferToString(const void *buf, size_t len)
{
  uint8_t *b = (uint8_t *)buf;
  if (!b)
  {
    sprintf(buf_temp_str, "(null ptr) (%d bytes)", (int)len);
    return buf_temp_str;
  }

  for(size_t i = 0; i < len && i*3 + 64 < sizeof(buf_temp_str); ++i)
  {
    sprintf(buf_temp_str + i*3, "%02X ", b[i]);
  }
  sprintf(buf_temp_str + len*3, " (%d bytes)", (int)len);
  return buf_temp_str;
}

void WebSocketMessageUnmaskPayload(uint8_t *payload, uint64_t payloadLength, uint32_t maskingKey)
{
  uint8_t maskingKey8[4];
  memcpy(maskingKey8, &maskingKey, 4);
  uint32_t *data_u32 = (uint32_t *)payload;
  uint32_t *end_u32 = (uint32_t *)((uintptr_t)(payload + (payloadLength & ~3u)));

  while(data_u32 < end_u32)
    *data_u32++ ^= maskingKey;

  uint8_t *end = payload + payloadLength;
  uint8_t *data = (uint8_t *)data_u32;
  while(data < end)
  {
    *data ^= maskingKey8[(data-payload) % 4];
    ++data;
  }
}

extern MUTEX_T webSocketSendLock;

void SendWebSocketMessage(int client_fd, void *buf, uint64_t numBytes)
{

  LOCK_MUTEX(&webSocketSendLock);
  uint8_t headerData[sizeof(WebSocketMessageHeader) + 8] = {};
  WebSocketMessageHeader *header = (WebSocketMessageHeader *)headerData;
  header->opcode = 0x02;
  header->fin = 1;
  int headerBytes = 2;

  if (numBytes < 126)
    header->payloadLength = numBytes;
  else if (numBytes <= 65535)
  {
    header->payloadLength = 126;
    *(uint16_t*)(headerData+headerBytes) = htons((unsigned short)numBytes);
    headerBytes += 2;
  }
  else
  {
    header->payloadLength = 127;
    *(uint64_t*)(headerData+headerBytes) = hton64(numBytes);
    headerBytes += 8;
  }

#ifdef POSIX_SOCKET_DEEP_DEBUG
  printf("Sending %llu bytes message (%llu bytes of payload) to WebSocket\n", headerBytes + numBytes, numBytes);

  printf("Header:");
  for(int i = 0; i < headerBytes; ++i)
    printf(" %02X", headerData[i]);

  printf("\nPayload:");
  for(int i = 0; i < numBytes; ++i)
    printf(" %02X", ((unsigned char*)buf)[i]);
  printf("\n");
#endif

  send(client_fd, (const char*)headerData, headerBytes, 0);
  send(client_fd, (const char*)buf, (int)numBytes, 0);
  UNLOCK_MUTEX(&webSocketSendLock);
}

#define MUSL_PF_UNSPEC       0
#define MUSL_PF_LOCAL        1
#define MUSL_PF_UNIX         PF_LOCAL
#define MUSL_PF_FILE         PF_LOCAL
#define MUSL_PF_INET         2
#define MUSL_PF_AX25         3
#define MUSL_PF_IPX          4
#define MUSL_PF_APPLETALK    5
#define MUSL_PF_NETROM       6
#define MUSL_PF_BRIDGE       7
#define MUSL_PF_ATMPVC       8
#define MUSL_PF_X25          9
#define MUSL_PF_INET6        10
#define MUSL_PF_ROSE         11
#define MUSL_PF_DECnet       12
#define MUSL_PF_NETBEUI      13
#define MUSL_PF_SECURITY     14
#define MUSL_PF_KEY          15
#define MUSL_PF_NETLINK      16
#define MUSL_PF_ROUTE        PF_NETLINK
#define MUSL_PF_PACKET       17
#define MUSL_PF_ASH          18
#define MUSL_PF_ECONET       19
#define MUSL_PF_ATMSVC       20
#define MUSL_PF_RDS          21
#define MUSL_PF_SNA          22
#define MUSL_PF_IRDA         23
#define MUSL_PF_PPPOX        24
#define MUSL_PF_WANPIPE      25
#define MUSL_PF_LLC          26
#define MUSL_PF_IB           27
#define MUSL_PF_MPLS         28
#define MUSL_PF_CAN          29
#define MUSL_PF_TIPC         30
#define MUSL_PF_BLUETOOTH    31
#define MUSL_PF_IUCV         32
#define MUSL_PF_RXRPC        33
#define MUSL_PF_ISDN         34
#define MUSL_PF_PHONET       35
#define MUSL_PF_IEEE802154   36
#define MUSL_PF_CAIF         37
#define MUSL_PF_ALG          38
#define MUSL_PF_NFC          39
#define MUSL_PF_VSOCK        40
#define MUSL_PF_KCM          41
#define MUSL_PF_MAX          42

#define MUSL_AF_UNSPEC       MUSL_PF_UNSPEC
#define MUSL_AF_LOCAL        MUSL_PF_LOCAL
#define MUSL_AF_UNIX         MUSL_AF_LOCAL
#define MUSL_AF_FILE         MUSL_AF_LOCAL
#define MUSL_AF_INET         MUSL_PF_INET
#define MUSL_AF_AX25         MUSL_PF_AX25
#define MUSL_AF_IPX          MUSL_PF_IPX
#define MUSL_AF_APPLETALK    MUSL_PF_APPLETALK
#define MUSL_AF_NETROM       MUSL_PF_NETROM
#define MUSL_AF_BRIDGE       MUSL_PF_BRIDGE
#define MUSL_AF_ATMPVC       MUSL_PF_ATMPVC
#define MUSL_AF_X25          MUSL_PF_X25
#define MUSL_AF_INET6        MUSL_PF_INET6
#define MUSL_AF_ROSE         MUSL_PF_ROSE
#define MUSL_AF_DECnet       MUSL_PF_DECnet
#define MUSL_AF_NETBEUI      MUSL_PF_NETBEUI
#define MUSL_AF_SECURITY     MUSL_PF_SECURITY
#define MUSL_AF_KEY          MUSL_PF_KEY
#define MUSL_AF_NETLINK      MUSL_PF_NETLINK
#define MUSL_AF_ROUTE        MUSL_PF_ROUTE
#define MUSL_AF_PACKET       MUSL_PF_PACKET
#define MUSL_AF_ASH          MUSL_PF_ASH
#define MUSL_AF_ECONET       MUSL_PF_ECONET
#define MUSL_AF_ATMSVC       MUSL_PF_ATMSVC
#define MUSL_AF_RDS          MUSL_PF_RDS
#define MUSL_AF_SNA          MUSL_PF_SNA
#define MUSL_AF_IRDA         MUSL_PF_IRDA
#define MUSL_AF_PPPOX        MUSL_PF_PPPOX
#define MUSL_AF_WANPIPE      MUSL_PF_WANPIPE
#define MUSL_AF_LLC          MUSL_PF_LLC
#define MUSL_AF_IB           MUSL_PF_IB
#define MUSL_AF_MPLS         MUSL_PF_MPLS
#define MUSL_AF_CAN          MUSL_PF_CAN
#define MUSL_AF_TIPC         MUSL_PF_TIPC
#define MUSL_AF_BLUETOOTH    MUSL_PF_BLUETOOTH
#define MUSL_AF_IUCV         MUSL_PF_IUCV
#define MUSL_AF_RXRPC        MUSL_PF_RXRPC
#define MUSL_AF_ISDN         MUSL_PF_ISDN
#define MUSL_AF_PHONET       MUSL_PF_PHONET
#define MUSL_AF_IEEE802154   MUSL_PF_IEEE802154
#define MUSL_AF_CAIF         MUSL_PF_CAIF
#define MUSL_AF_ALG          MUSL_PF_ALG
#define MUSL_AF_NFC          MUSL_PF_NFC
#define MUSL_AF_VSOCK        MUSL_PF_VSOCK
#define MUSL_AF_KCM          MUSL_PF_KCM
#define MUSL_AF_MAX          MUSL_PF_MAX

static int Translate_Socket_Domain(int domain)
{
  switch(domain)
  {

  case MUSL_AF_UNSPEC: return AF_UNSPEC;
#ifdef AF_LOCAL
  case MUSL_AF_LOCAL: return AF_LOCAL;
#endif

  case MUSL_AF_INET: return AF_INET;

  case MUSL_AF_IPX: return AF_IPX;
  case MUSL_AF_APPLETALK: return AF_APPLETALK;

  case MUSL_AF_INET6: return AF_INET6;

  case MUSL_AF_DECnet: return AF_DECnet;

  case MUSL_AF_SNA: return AF_SNA;

#ifdef AF_ISDN
  case MUSL_AF_ISDN: return AF_ISDN;
#endif

  case MUSL_AF_MAX: return AF_MAX;
  default:
    fprintf(stderr, "Uncrecognized Socket Domain %d!\n", domain);
    return domain;
  }
}

#define MUSL_SOCK_STREAM    1
#define MUSL_SOCK_DGRAM     2
#define MUSL_SOCK_RAW       3
#define MUSL_SOCK_RDM       4
#define MUSL_SOCK_SEQPACKET 5
#define MUSL_SOCK_DCCP      6
#define MUSL_SOCK_PACKET    10
#define MUSL_SOCK_CLOEXEC   02000000
#define MUSL_SOCK_NONBLOCK  04000

static int Translate_Socket_Type(int type)
{
  if ((type & MUSL_SOCK_CLOEXEC) != 0)
  {
    fprintf(stderr, "Unsupported MUSL SOCK_CLOEXEC passed!\n");
    type &= ~MUSL_SOCK_CLOEXEC;
  }
  if ((type & MUSL_SOCK_NONBLOCK) != 0)
  {
    fprintf(stderr, "Unsupported MUSL SOCK_NONBLOCK passed!\n");
    type &= ~MUSL_SOCK_NONBLOCK;
  }

  switch(type)
  {
  case MUSL_SOCK_STREAM: return SOCK_STREAM;
  case MUSL_SOCK_DGRAM: return SOCK_DGRAM;
  case MUSL_SOCK_RAW: return SOCK_RAW;
  case MUSL_SOCK_RDM: return SOCK_RDM;
  case MUSL_SOCK_SEQPACKET: return SOCK_SEQPACKET;

  default:
    fprintf(stderr, "Uncrecognized socket type %d!\n", type);
    return type;
  }
}

#define MUSL_IPPROTO_IP       0
#define MUSL_IPPROTO_HOPOPTS  0
#define MUSL_IPPROTO_ICMP     1
#define MUSL_IPPROTO_IGMP     2
#define MUSL_IPPROTO_IPIP     4
#define MUSL_IPPROTO_TCP      6
#define MUSL_IPPROTO_EGP      8
#define MUSL_IPPROTO_PUP      12
#define MUSL_IPPROTO_UDP      17
#define MUSL_IPPROTO_IDP      22
#define MUSL_IPPROTO_TP       29
#define MUSL_IPPROTO_DCCP     33
#define MUSL_IPPROTO_IPV6     41
#define MUSL_IPPROTO_ROUTING  43
#define MUSL_IPPROTO_FRAGMENT 44
#define MUSL_IPPROTO_RSVP     46
#define MUSL_IPPROTO_GRE      47
#define MUSL_IPPROTO_ESP      50
#define MUSL_IPPROTO_AH       51
#define MUSL_IPPROTO_ICMPV6   58
#define MUSL_IPPROTO_NONE     59
#define MUSL_IPPROTO_DSTOPTS  60
#define MUSL_IPPROTO_MTP      92
#define MUSL_IPPROTO_BEETPH   94
#define MUSL_IPPROTO_ENCAP    98
#define MUSL_IPPROTO_PIM      103
#define MUSL_IPPROTO_COMP     108
#define MUSL_IPPROTO_SCTP     132
#define MUSL_IPPROTO_MH       135
#define MUSL_IPPROTO_UDPLITE  136
#define MUSL_IPPROTO_MPLS     137
#define MUSL_IPPROTO_RAW      255

static int Translate_Socket_Protocol(int protocol)
{
  switch(protocol)
  {
    case MUSL_IPPROTO_IP: return IPPROTO_IP;

    case MUSL_IPPROTO_ICMP: return IPPROTO_ICMP;
    case MUSL_IPPROTO_IGMP: return IPPROTO_IGMP;
#ifdef IPPROTO_IPIP
    case MUSL_IPPROTO_IPIP: return IPPROTO_IPIP;
#endif
    case MUSL_IPPROTO_TCP: return IPPROTO_TCP;
    case MUSL_IPPROTO_EGP: return IPPROTO_EGP;
    case MUSL_IPPROTO_PUP: return IPPROTO_PUP;
    case MUSL_IPPROTO_UDP: return IPPROTO_UDP;
    case MUSL_IPPROTO_IDP: return IPPROTO_IDP;
#ifdef IPPROTO_TP
    case MUSL_IPPROTO_TP: return IPPROTO_TP;
#endif

    case MUSL_IPPROTO_IPV6: return IPPROTO_IPV6;
    case MUSL_IPPROTO_ROUTING: return IPPROTO_ROUTING;
    case MUSL_IPPROTO_FRAGMENT: return IPPROTO_FRAGMENT;
#ifdef IPPROTO_RSVP
    case MUSL_IPPROTO_RSVP: return IPPROTO_RSVP;
#endif
#ifdef IPPROTO_GRE
    case MUSL_IPPROTO_GRE: return IPPROTO_GRE;
#endif
    case MUSL_IPPROTO_ESP: return IPPROTO_ESP;
    case MUSL_IPPROTO_AH: return IPPROTO_AH;
    case MUSL_IPPROTO_ICMPV6: return IPPROTO_ICMPV6;
    case MUSL_IPPROTO_NONE: return IPPROTO_NONE;
    case MUSL_IPPROTO_DSTOPTS: return IPPROTO_DSTOPTS;
#ifdef IPPROTO_MTP
    case MUSL_IPPROTO_MTP: return IPPROTO_MTP;
#endif

#ifdef IPPROTO_ENCAP
    case MUSL_IPPROTO_ENCAP: return IPPROTO_ENCAP;
#endif
    case MUSL_IPPROTO_PIM: return IPPROTO_PIM;

    case MUSL_IPPROTO_SCTP: return IPPROTO_SCTP;

    case MUSL_IPPROTO_RAW: return IPPROTO_RAW;
    default:
    fprintf(stderr, "Unrecognized socket protocol %d!\n", protocol);
    return protocol;
  }
}

#define MUSL_SOL_SOCKET      1
#define MUSL_SOL_IP          0
#define MUSL_SOL_IPV6        41
#define MUSL_SOL_ICMPV6      58
#define MUSL_SOL_RAW         255
#define MUSL_SOL_DECNET      261
#define MUSL_SOL_X25         262
#define MUSL_SOL_PACKET      263
#define MUSL_SOL_ATM         264
#define MUSL_SOL_AAL         265
#define MUSL_SOL_IRDA        266
#define MUSL_SOL_NETBEUI     267
#define MUSL_SOL_LLC         268
#define MUSL_SOL_DCCP        269
#define MUSL_SOL_NETLINK     270
#define MUSL_SOL_TIPC        271
#define MUSL_SOL_RXRPC       272
#define MUSL_SOL_PPPOL2TP    273
#define MUSL_SOL_BLUETOOTH   274
#define MUSL_SOL_PNPIPE      275
#define MUSL_SOL_RDS         276
#define MUSL_SOL_IUCV        277
#define MUSL_SOL_CAIF        278
#define MUSL_SOL_ALG         279
#define MUSL_SOL_NFC         280
#define MUSL_SOL_KCM         281

static int Translate_Socket_Level(int level)
{
  switch(level)
  {
  case MUSL_SOL_SOCKET: return SOL_SOCKET;
  case MUSL_IPPROTO_TCP: return IPPROTO_TCP;

  default:
    fprintf(stderr, "Uncrecognized socket level %d!\n", level);
    return level;
  }
}

#define MUSL_SO_DEBUG        1
#define MUSL_SO_REUSEADDR    2
#define MUSL_SO_TYPE         3
#define MUSL_SO_ERROR        4
#define MUSL_SO_DONTROUTE    5
#define MUSL_SO_BROADCAST    6
#define MUSL_SO_SNDBUF       7
#define MUSL_SO_RCVBUF       8
#define MUSL_SO_KEEPALIVE    9
#define MUSL_SO_OOBINLINE    10
#define MUSL_SO_NO_CHECK     11
#define MUSL_SO_PRIORITY     12
#define MUSL_SO_LINGER       13
#define MUSL_SO_BSDCOMPAT    14
#define MUSL_SO_REUSEPORT    15
#define MUSL_SO_PASSCRED     16
#define MUSL_SO_PEERCRED     17
#define MUSL_SO_RCVLOWAT     18
#define MUSL_SO_SNDLOWAT     19
#define MUSL_SO_RCVTIMEO     20
#define MUSL_SO_SNDTIMEO     21
#define MUSL_SO_ACCEPTCONN   30
#define MUSL_SO_SNDBUFFORCE  32
#define MUSL_SO_RCVBUFFORCE  33
#define MUSL_SO_PROTOCOL     38
#define MUSL_SO_DOMAIN       39
#define MUSL_SO_SECURITY_AUTHENTICATION              22
#define MUSL_SO_SECURITY_ENCRYPTION_TRANSPORT        23
#define MUSL_SO_SECURITY_ENCRYPTION_NETWORK          24
#define MUSL_SO_BINDTODEVICE 25
#define MUSL_SO_ATTACH_FILTER        26
#define MUSL_SO_DETACH_FILTER        27
#define MUSL_SO_PEERNAME             28
#define MUSL_SO_TIMESTAMP            29
#define MUSL_SO_PEERSEC              31
#define MUSL_SO_PASSSEC              34
#define MUSL_SO_TIMESTAMPNS          35
#define MUSL_SO_MARK                 36
#define MUSL_SO_TIMESTAMPING         37
#define MUSL_SO_RXQ_OVFL             40
#define MUSL_SO_WIFI_STATUS          41
#define MUSL_SO_PEEK_OFF             42
#define MUSL_SO_NOFCS                43
#define MUSL_SO_LOCK_FILTER          44
#define MUSL_SO_SELECT_ERR_QUEUE     45
#define MUSL_SO_BUSY_POLL            46
#define MUSL_SO_MAX_PACING_RATE      47
#define MUSL_SO_BPF_EXTENSIONS       48
#define MUSL_SO_INCOMING_CPU         49
#define MUSL_SO_ATTACH_BPF           50
#define MUSL_SO_ATTACH_REUSEPORT_CBPF 51
#define MUSL_SO_ATTACH_REUSEPORT_EBPF 52
#define MUSL_SO_CNX_ADVICE           53

static int Translate_SOL_SOCKET_option(int sockopt)
{
  switch(sockopt)
  {
  case MUSL_SO_DEBUG: return SO_DEBUG;
  case MUSL_SO_REUSEADDR: return SO_REUSEADDR;
  case MUSL_SO_TYPE: return SO_TYPE;
  case MUSL_SO_ERROR: return SO_ERROR;
  case MUSL_SO_DONTROUTE: return SO_DONTROUTE;
  case MUSL_SO_BROADCAST: return SO_BROADCAST;
  case MUSL_SO_SNDBUF: return SO_SNDBUF;
  case MUSL_SO_RCVBUF: return SO_RCVBUF;
  case MUSL_SO_KEEPALIVE: return SO_KEEPALIVE;
  case MUSL_SO_OOBINLINE: return SO_OOBINLINE;

  case MUSL_SO_LINGER: return SO_LINGER;

#ifdef SO_REUSEPORT
  case MUSL_SO_REUSEPORT: return SO_REUSEPORT;
#endif

  case MUSL_SO_RCVLOWAT: return SO_RCVLOWAT;
  case MUSL_SO_SNDLOWAT: return SO_SNDLOWAT;
  case MUSL_SO_RCVTIMEO: return SO_RCVTIMEO;
  case MUSL_SO_SNDTIMEO: return SO_SNDTIMEO;
  case MUSL_SO_ACCEPTCONN: return SO_ACCEPTCONN;

#ifdef SO_TIMESTAMP
  case MUSL_SO_TIMESTAMP: return SO_TIMESTAMP;
#endif

  default:
    fprintf(stderr, "Unrecognized SOL_SOCKET option %d!\n", sockopt);
    return sockopt;
  }
}

#define MUSL_TCP_NODELAY 1
#define MUSL_TCP_MAXSEG   2
#define MUSL_TCP_CORK   3
#define MUSL_TCP_KEEPIDLE   4
#define MUSL_TCP_KEEPINTVL  5
#define MUSL_TCP_KEEPCNT  6
#define MUSL_TCP_SYNCNT   7
#define MUSL_TCP_LINGER2  8
#define MUSL_TCP_DEFER_ACCEPT 9
#define MUSL_TCP_WINDOW_CLAMP 10
#define MUSL_TCP_INFO   11
#define MUSL_TCP_QUICKACK   12
#define MUSL_TCP_CONGESTION   13
#define MUSL_TCP_MD5SIG   14
#define MUSL_TCP_THIN_LINEAR_TIMEOUTS 16
#define MUSL_TCP_THIN_DUPACK  17
#define MUSL_TCP_USER_TIMEOUT 18
#define MUSL_TCP_REPAIR       19
#define MUSL_TCP_REPAIR_QUEUE 20
#define MUSL_TCP_QUEUE_SEQ    21
#define MUSL_TCP_REPAIR_OPTIONS 22
#define MUSL_TCP_FASTOPEN     23
#define MUSL_TCP_TIMESTAMP    24
#define MUSL_TCP_NOTSENT_LOWAT 25
#define MUSL_TCP_CC_INFO      26
#define MUSL_TCP_SAVE_SYN     27
#define MUSL_TCP_SAVED_SYN    28

static int Translate_IPPROTO_TCP_option(int sockopt)
{
  switch(sockopt)
  {
  case MUSL_TCP_NODELAY: return TCP_NODELAY;
  case MUSL_TCP_MAXSEG: return TCP_MAXSEG;

  case MUSL_TCP_KEEPINTVL: return TCP_KEEPINTVL;
  case MUSL_TCP_KEEPCNT: return TCP_KEEPCNT;

  case MUSL_TCP_FASTOPEN: return TCP_FASTOPEN;

#ifdef TCP_NOTSENT_LOWAT
  case MUSL_TCP_NOTSENT_LOWAT: return TCP_NOTSENT_LOWAT;
#endif

  default:
    fprintf(stderr, "Unrecognized IPPROTO_TCP option %d!\n", sockopt);
    return sockopt;
  }
}

void Socket(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int domain;
    int type;
    int protocol;
  };
  MSG *d = (MSG*)data;

  d->domain = Translate_Socket_Domain(d->domain);
  d->type = Translate_Socket_Type(d->type);
  d->protocol = Translate_Socket_Protocol(d->protocol);
  SOCKET_T ret = socket(d->domain, d->type, d->protocol);
  int errorCode = (ret < 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
  printf("socket(domain=%d,type=%d,protocol=%d)->%d\n", d->domain, d->type, d->protocol, ret);
  if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif

  if (errorCode == 0)
  {

    TrackSocketUsedByConnection(client_fd, ret);
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = CHECKED_TRUNCATE_TO_POSITIVE_INT32(ret);
  r.errno_ = errorCode;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Socketpair(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int domain;
    int type;
    int protocol;
  };
  MSG *d = (MSG*)data;

  SOCKET_T socket_vector[2];

#ifdef _MSC_VER
  printf("TODO implement socketpair() on Windows\n");
  int ret = -1;
  int errorCode = -1;
#else
  d->domain = Translate_Socket_Domain(d->domain);
  d->type = Translate_Socket_Type(d->type);
  d->protocol = Translate_Socket_Protocol(d->protocol);
  int ret = socketpair(d->domain, d->type, d->protocol, socket_vector);
  int errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#endif

#ifdef POSIX_SOCKET_DEBUG
  printf("socketpair(domain=%d,type=%d,protocol=%d, socket_vector=[%d,%d])->%d\n", d->domain, d->type, d->protocol, socket_vector[0], socket_vector[1], ret);
  if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif

  if (errorCode == 0)
  {

    TrackSocketUsedByConnection(client_fd, socket_vector[0]);
    TrackSocketUsedByConnection(client_fd, socket_vector[1]);
  }

  struct {
    int callId;
    int ret;
    int errno_;
    int sv[2];
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = errorCode;
  r.sv[0] = CHECKED_TRUNCATE_TO_POSITIVE_INT32(socket_vector[0]);
  r.sv[1] = CHECKED_TRUNCATE_TO_POSITIVE_INT32(socket_vector[1]);
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

#define MUSL_SHUT_RD 0
#define MUSL_SHUT_WR 1
#define MUSL_SHUT_RDWR 2

static int Translate_Shutdown_How(int how)
{
  switch(how)
  {
  case MUSL_SHUT_RD: return SHUTDOWN_READ;
  case MUSL_SHUT_WR: return SHUTDOWN_WRITE;
  case MUSL_SHUT_RDWR: return SHUTDOWN_BIDIRECTIONAL;
  default:
    fprintf(stderr, "Unrecognized shutdown() how option %d!\n", how);
    return how;
  }
}

void Shutdown(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    int how;
  };
  MSG *d = (MSG*)data;

  d->how = Translate_Shutdown_How(d->how);

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = shutdown(d->socket, d->how);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#ifdef POSIX_SOCKET_DEBUG
    printf("shutdown(socket=%d,how=%d)->%d\n", d->socket, d->how, ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
    if (errorCode == 0 && d->how == SHUTDOWN_BIDIRECTIONAL)
    {

      CloseSocketByConnection(client_fd, d->socket);
    }
  }
  else
  {
    fprintf(stderr, "shutdown(socket=%d,how=%d): Proxy client connection client_fd=%d attempted to call shutdown() on a socket fd=%d that it did not create (or has already shut down)\n", d->socket, d->how, client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Bind(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t address_len;
    uint8_t address[];
  };
  MSG *d = (MSG*)data;

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = bind(d->socket, (sockaddr*)d->address, d->address_len);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#ifdef POSIX_SOCKET_DEBUG
    printf("bind(socket=%d,address=%p,address_len=%d, address=\"%s\")->%d\n", d->socket, d->address, d->address_len, BufferToString(d->address, d->address_len), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "bind(): Proxy client connection client_fd=%d attempted to call bind() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Connect(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t address_len;
    uint8_t address[];
  };
  MSG *d = (MSG*)data;

  int actualAddressLen = MIN(d->address_len, (uint32_t)numBytes - sizeof(MSG));

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = connect(d->socket, (sockaddr*)d->address, actualAddressLen);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#ifdef POSIX_SOCKET_DEBUG
    printf("connect(socket=%d,address=%p,address_len=%d, address=\"%s\")->%d\n", d->socket, d->address, d->address_len, BufferToString(d->address, actualAddressLen), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "connect(): Proxy client connection client_fd=%d attempted to call connect() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Listen(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    int backlog;
  };
  MSG *d = (MSG*)data;

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = listen(d->socket, d->backlog);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#ifdef POSIX_SOCKET_DEBUG
    printf("listen(socket=%d,backlog=%d)->%d\n", d->socket, d->backlog, ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "bind(): Proxy client connection client_fd=%d attempted to call bind() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Accept(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t address_len;
  };
  MSG *d = (MSG*)data;

  uint8_t address[MAX_SOCKADDR_SIZE] = {};
  socklen_t addressLen = (socklen_t)MAX(0, MIN(d->address_len, MAX_SOCKADDR_SIZE));

  SOCKET_T ret;
  int errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
#ifdef POSIX_SOCKET_DEBUG
    printf("accept(socket=%d,address=%p,address_len=%u, address=\"%s\")\n", d->socket, address, d->address_len, BufferToString(address, addressLen));
#endif
    ret = accept(d->socket, d->address_len ? (sockaddr*)address : 0, d->address_len ? &addressLen : 0);
    errorCode = (ret < 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("accept returned %d (address=\"%s\")\n", ret, BufferToString(address, addressLen));
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif

		if (ret > 0)
		{

			TrackSocketUsedByConnection(client_fd, ret);
		}
  }
  else
  {
    fprintf(stderr, "accept(): Proxy client connection client_fd=%d attempted to call accept() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    addressLen = 0;
  }

  struct Result {
    int callId;
    int ret;
    int errno_;
    int address_len;
    uint8_t address[];
  };

  int actualAddressLen = MIN(addressLen, (socklen_t)d->address_len);
  int resultSize = sizeof(Result) + actualAddressLen;
  Result *r = (Result*)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = CHECKED_TRUNCATE_TO_POSITIVE_INT32(ret);
  r->errno_ = errorCode;
  r->address_len = addressLen;
  memcpy(r->address, address, actualAddressLen);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Getsockname(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t address_len;
  };
  MSG *d = (MSG*)data;

  uint8_t address[MAX_SOCKADDR_SIZE];

  socklen_t addressLen = (socklen_t)d->address_len;

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = getsockname(d->socket, (sockaddr*)address, &addressLen);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("getsockname(socket=%d,address=%p,address_len=%u)->%d (ret address: \"%s\")\n", d->socket, address, d->address_len, ret, BufferToString(address, addressLen));
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "getsockname(): Proxy client connection client_fd=%d attempted to call getsockname() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    addressLen = 0;
  }

  struct Result {
    int callId;
    int ret;
    int errno_;
    int address_len;
    uint8_t address[];
  };
  int actualAddressLen = MIN(addressLen, (socklen_t)d->address_len);
  int resultSize = sizeof(Result) + actualAddressLen;
  Result *r = (Result*)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = ret;
  r->errno_ = errorCode;
  r->address_len = addressLen;
  memcpy(r->address, address, actualAddressLen);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Getpeername(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t address_len;
  };
  MSG *d = (MSG*)data;

  uint8_t address[MAX_SOCKADDR_SIZE];
  socklen_t addressLen = (socklen_t)d->address_len;

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = getpeername(d->socket, (sockaddr*)address, &addressLen);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("getpeername(socket=%d,address=%p,address_len=%u, address=\"%s\")->%d\n", d->socket, address, d->address_len, BufferToString(address, addressLen), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "getpeername(): Proxy client connection client_fd=%d attempted to call getpeername() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    addressLen = 0;
  }

  struct Result {
    int callId;
    int ret;
    int errno_;
    int address_len;
    uint8_t address[];
  };
  int actualAddressLen = MIN(addressLen, (socklen_t)d->address_len);
  int resultSize = sizeof(Result) + actualAddressLen;
  Result *r = (Result*)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = ret;
  r->errno_ = errorCode;
  r->address_len = addressLen;
  memcpy(r->address, address, actualAddressLen);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Send(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t length;
    int flags;
    uint8_t message[];
  };
  MSG *d = (MSG*)data;

  int actualBytes = MIN((int)numBytes - sizeof(MSG), d->length);
  SEND_RET_TYPE ret;
  int errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = send(d->socket, (const char *)d->message, actualBytes, d->flags);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("send(socket=%d,message=%p,length=%zd,flags=%d, data=\"%s\")->" SEND_FORMATTING_SPECIFIER "\n", d->socket, d->message, d->length, d->flags, BufferToString(d->message, d->length), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "send(): Proxy client connection client_fd=%d attempted to call send() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = (int)ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Recv(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t length;
    int flags;
  };
  MSG *d = (MSG*)data;

  uint8_t *buffer = (uint8_t*)malloc(d->length);
  SEND_RET_TYPE ret;
  int errorCode;
  int receivedBytes;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = recv(d->socket, (char *)buffer, d->length, d->flags);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
    receivedBytes = MAX(ret, 0);

#ifdef POSIX_SOCKET_DEBUG
    printf("recv(socket=%d,buffer=%p,length=%zd,flags=%d)->" SEND_FORMATTING_SPECIFIER " received \"%s\"\n", d->socket, buffer, d->length, d->flags, ret, BufferToString(buffer, receivedBytes));
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "recv(): Proxy client connection client_fd=%d attempted to call recv() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    receivedBytes = 0;
  }

  struct Result {
    int callId;
    int ret;
    int errno_;
    uint8_t data[];
  };
  int resultSize = sizeof(Result) + receivedBytes;
  Result *r = (Result *)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = (int)ret;
  r->errno_ = errorCode;
  memcpy(r->data, buffer, receivedBytes);
  free(buffer);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Sendto(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t length;
    int flags;
    uint32_t dest_len;
    uint8_t dest_addr[MAX_SOCKADDR_SIZE];
    uint8_t message[];
  };
  MSG *d = (MSG*)data;

  SEND_RET_TYPE ret;
  int errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = sendto(d->socket, (const char *)d->message, d->length, d->flags, (sockaddr*)d->dest_addr, d->dest_len);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("sendto(socket=%d,message=%p,length=%zd,flags=%d,dest_addr=%p,dest_len=%d)->" SEND_FORMATTING_SPECIFIER "\n", d->socket, d->message, d->length, d->flags, d->dest_addr, d->dest_len, ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "sendto(): Proxy client connection client_fd=%d attempted to call sendto() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = (int)ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Recvfrom(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    uint32_t length;
    int flags;
    uint32_t address_len;
  };
  MSG *d = (MSG*)data;

  uint8_t address[MAX_SOCKADDR_SIZE];
  uint8_t *buffer = (uint8_t *)malloc(d->length);

  socklen_t address_len = (socklen_t)d->address_len;

  int ret, errorCode, receivedBytes;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = recvfrom(d->socket, (char *)buffer, d->length, d->flags, (sockaddr*)address, &address_len);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;
#ifdef POSIX_SOCKET_DEBUG
    printf("recvfrom(socket=%d,buffer=%p,length=%zd,flags=%d,address=%p,address_len=%u, address=\"%s\")->%d\n", d->socket, buffer, d->length, d->flags, address, d->address_len, BufferToString(address, address_len), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
    receivedBytes = MAX(ret, 0);
  }
  else
  {
    fprintf(stderr, "recvfrom(): Proxy client connection client_fd=%d attempted to call recvfrom() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    receivedBytes = 0;
    address_len = 0;
  }

  int actualAddressLen = MIN(address_len, (socklen_t)d->address_len);

  struct Result {
    int callId;
    int ret;
    int errno_;
    int data_len;
    int address_len;
    uint8_t data_and_address[];
  };
  int resultSize = sizeof(Result) + receivedBytes + actualAddressLen;
  Result *r = (Result *)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = (int)ret;
  r->errno_ = errorCode;
  r->data_len = receivedBytes;
  r->address_len = d->address_len;
  memcpy(r->data_and_address, buffer, receivedBytes);
  memcpy(r->data_and_address + receivedBytes, address, actualAddressLen);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Sendmsg(int client_fd, uint8_t *data, uint64_t numBytes)
{
	printf("TODO implement sendmsg()\n");
#ifdef POSIX_SOCKET_DEBUG

#endif

}

void Recvmsg(int client_fd, uint8_t *data, uint64_t numBytes)
{
	printf("TODO implement recvmsg()\n");
#ifdef POSIX_SOCKET_DEBUG

#endif
}

void Getsockopt(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    int level;
    int option_name;
    uint32_t option_len;
  };
  MSG *d = (MSG*)data;

  uint8_t option_value[MAX_OPTIONVALUE_SIZE];

  d->level = Translate_Socket_Level(d->level);
  d->option_name = Translate_SOL_SOCKET_option(d->option_name);

  socklen_t option_len = (socklen_t)d->option_len;

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = getsockopt(d->socket, d->level, d->option_name, (char*)option_value, &option_len);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("getsockopt(socket=%d,level=%d,option_name=%d,option_value=%p,option_len=%u, optionData=\"%s\")->%d\n", d->socket, d->level, d->option_name, option_value, d->option_len, BufferToString(option_value, option_len), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "getsockopt(): Proxy client connection client_fd=%d attempted to call getsockopt() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
    option_len = 0;
  }

  struct Result {
    int callId;
    int ret;
    int errno_;
    int option_len;
    uint8_t option_value[];
  };

  int actualOptionLen = MIN(option_len, (socklen_t)d->option_len);
  int resultSize = sizeof(Result) + actualOptionLen;
  Result *r = (Result*)malloc(resultSize);
  r->callId = d->header.callId;
  r->ret = ret;
  r->errno_ = errorCode;
  r->option_len = option_len;
  memcpy(r->option_value, option_value, actualOptionLen);
  SendWebSocketMessage(client_fd, r, resultSize);
  free(r);
}

void Setsockopt(int client_fd, uint8_t *data, uint64_t numBytes)
{
  struct MSG {
    SocketCallHeader header;
    int socket;
    int level;
    int option_name;
    int option_len;
    uint8_t option_value[];
  };
  MSG *d = (MSG*)data;
  int actualOptionLen = MIN(d->option_len, (int)(numBytes - sizeof(MSG)));

  d->level = Translate_Socket_Level(d->level);
  switch(d->level)
  {
    case SOL_SOCKET: d->option_name = Translate_SOL_SOCKET_option(d->option_name); break;
    case IPPROTO_TCP: d->option_name = Translate_IPPROTO_TCP_option(d->option_name); break;
    default:
      fprintf(stderr, "Unknown socket level %d, unable to translate socket option\n", d->level);
      break;
  }

  int ret, errorCode;

  if (IsSocketPartOfConnection(client_fd, d->socket))
  {
    ret = setsockopt(d->socket, d->level, d->option_name, (const char *)d->option_value, actualOptionLen);
    errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
    printf("setsockopt(socket=%d,level=%d,option_name=%d,option_value=%p,option_len=%d, optionData=\"%s\")->%d\n", d->socket, d->level, d->option_name, d->option_value, d->option_len, BufferToString(d->option_value, actualOptionLen), ret);
    if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif
  }
  else
  {
    fprintf(stderr, "setsockopt(): Proxy client connection client_fd=%d attempted to call setsockopt() on a socket fd=%d that it did not create (or has already shut down)\n", client_fd, d->socket);
    ret = errorCode = -1;
  }

  struct {
    int callId;
    int ret;
    int errno_;
  } r;
  r.callId = d->header.callId;
  r.ret = ret;
  r.errno_ = (ret != 0) ? errno : 0;
  SendWebSocketMessage(client_fd, &r, sizeof(r));
}

void Getaddrinfo(int client_fd, uint8_t *data, uint64_t numBytes)
{
#define MAX_NODE_LEN 2048
#define MAX_SERVICE_LEN 128

  struct MSG {
    SocketCallHeader header;
    char node[MAX_NODE_LEN];
    char service[MAX_SERVICE_LEN];
    int hasHints;
    int ai_flags;
    int ai_family;
    int ai_socktype;
    int ai_protocol;
  };
  MSG *d = (MSG*)data;

  addrinfo hints;
  memset(&hints, 0, sizeof(hints));
  hints.ai_flags = d->ai_flags;
  hints.ai_family = d->ai_family;
  hints.ai_socktype = d->ai_socktype;
  hints.ai_protocol = d->ai_protocol;

  addrinfo *res = 0;
  int ret = getaddrinfo(d->node, d->service, d->hasHints ? &hints : 0, &res);
  int errorCode = (ret != 0) ? GET_SOCKET_ERROR() : 0;

#ifdef POSIX_SOCKET_DEBUG
  printf("getaddrinfo(node=%s,service=%s,hasHints=%d,ai_flags=%d,ai_family=%d,ai_socktype=%d,ai_protocol=%d)->%d\n", d->node, d->service, d->hasHints, d->ai_flags, d->ai_family, d->ai_socktype, d->ai_protocol, ret);
  if (errorCode) PRINT_SOCKET_ERROR(errorCode);
#endif

  char ai_canonname[MAX_NODE_LEN] = {};
  int ai_addrTotalLen = 0;
  int addrCount = 0;

  if (ret == 0)
  {
    if (res && res->ai_canonname)
    {
      if (strlen(res->ai_canonname) >= MAX_NODE_LEN) printf("Warning: Truncated res->ai_canonname to %d bytes (was %s)\n", MAX_NODE_LEN, res->ai_canonname);
      strncpy(ai_canonname, res->ai_canonname, MAX_NODE_LEN-1);
    }

    addrinfo *ai = res;
    while(ai)
    {
      ai_addrTotalLen += CHECKED_TRUNCATE_TO_POSITIVE_INT32(ai->ai_addrlen);
      ++addrCount;
      ai = ai->ai_next;
    }
  }

  struct ResAddrinfo
  {
    int ai_flags;
    int ai_family;
    int ai_socktype;
    int ai_protocol;
    int ai_addrlen;
    uint8_t  ai_addr[];
  };

  struct Result {
    int callId;
    int ret;
    int errno_;
    char ai_canonname[MAX_NODE_LEN];
    int addrCount;
    uint8_t  addr[];
  };

  int resultSize = sizeof(Result) + sizeof(ResAddrinfo)*addrCount + ai_addrTotalLen;
  Result *r = (Result*)malloc(resultSize);

  memset(r, 0, resultSize);
  r->callId = d->header.callId;
  r->ret = ret;
  r->errno_ = errorCode;
  strncpy(r->ai_canonname, ai_canonname, MAX_NODE_LEN-1);
  r->addrCount = addrCount;

  addrinfo *ai = res;
  int offset = 0;
  while(ai)
  {
    ResAddrinfo *o = (ResAddrinfo*)(r->addr + offset);
    o->ai_flags = ai->ai_flags;
    o->ai_family = ai->ai_family;
    o->ai_socktype = ai->ai_socktype;
    o->ai_protocol = ai->ai_protocol;
    o->ai_addrlen = CHECKED_TRUNCATE_TO_POSITIVE_INT32(ai->ai_addrlen);
    memcpy(o->ai_addr, ai->ai_addr, ai->ai_addrlen);
    offset += sizeof(ResAddrinfo) + CHECKED_TRUNCATE_TO_POSITIVE_INT32(ai->ai_addrlen);
    ai = ai->ai_next;
  }
  if (res) freeaddrinfo(res);

  SendWebSocketMessage(client_fd, r, resultSize);

  free(r);
}

void Getnameinfo(int client_fd, uint8_t *data, uint64_t numBytes)
{
  fprintf(stderr, "TODO getnameinfo() unimplemented!\n");
}

static void *memdup(const void *ptr, size_t sz)
{
  if (!ptr) return 0;
  void *dup = malloc(sz);
  if (dup) memcpy(dup, ptr, sz);
  return dup;
}

struct MessageArg
{
  int client_fd;
  uint8_t *payload;
  uint64_t numBytes;
};

void ProcessWebSocketMessageSynchronouslyInCurrentThread(int client_fd, uint8_t *payload, uint64_t numBytes);

THREAD_RETURN_T message_processing_thread(void *arg)
{
  MessageArg *msg = (MessageArg*)arg;
  assert(msg);
  assert(msg->client_fd);
  ProcessWebSocketMessageSynchronouslyInCurrentThread(msg->client_fd, msg->payload, msg->numBytes);
  free(msg->payload);
  free(msg);
  EXIT_THREAD(0);
}

void ProcessWebSocketMessageAsynchronouslyInBackgroundThread(int client_fd, uint8_t *payload, uint64_t numBytes)
{
  MessageArg *arg = (MessageArg*)malloc(sizeof(MessageArg));
  arg->client_fd = client_fd;
  arg->payload = (uint8_t*)memdup(payload, (size_t)numBytes);
  arg->numBytes = numBytes;
  THREAD_T thread;

	CREATE_THREAD(thread, message_processing_thread, arg);
}

#include <stdlib.h>
#include <arpa/inet.h>

bool RelayAddressAllowed(const uint8_t *addr, uint32_t len)
{
  if (len < 8) return false;
  uint16_t family = addr[0] | (addr[1] << 8);
  if (family != 2) return false;
  uint16_t port = (uint16_t)((addr[2] << 8) | addr[3]);
  char ip[INET_ADDRSTRLEN];
  if (!inet_ntop(AF_INET, addr + 4, ip, sizeof(ip))) return false;
  const char *hosts = getenv("WEBRELAY_ALLOW");
  const char *ports = getenv("WEBRELAY_PORTS");
  int lo = 53000, hi = 65535;
  if (ports) sscanf(ports, "%d-%d", &lo, &hi);
  if (port < lo || port > hi) return false;
  if (!hosts || !*hosts) hosts = "127.0.0.1";
  const char *h = hosts;
  size_t n = strlen(ip);
  while (*h)
  {
    const char *e = strchr(h, ',');
    size_t l = e ? (size_t)(e - h) : strlen(h);
    if (l == n && !strncmp(h, ip, n)) return true;
    if (!e) break;
    h = e + 1;
  }
  return false;
}

static bool RelayAllowed(uint8_t *payload, uint64_t numBytes)
{
  if (numBytes < sizeof(SocketCallHeader)) return false;
  SocketCallHeader *header = (SocketCallHeader*)payload;
  int *w = (int*)(payload + sizeof(SocketCallHeader));
  uint64_t rest = numBytes - sizeof(SocketCallHeader);
  switch (header->function)
  {
  case POSIX_SOCKET_MSG_SOCKET:
    return rest >= 12 && w[0] == 2 && (w[1] & 0xf) == 2;
  case POSIX_SOCKET_MSG_BIND:
  {
    if (rest < 8) return false;
    uint32_t len = (uint32_t)w[1];
    const uint8_t *addr = (const uint8_t*)(w + 2);
    if (rest < 8 + 8 || len < 8) return false;
    uint16_t family = addr[0] | (addr[1] << 8);
    return family == 2 && addr[2] == 0 && addr[3] == 0;
  }
  case POSIX_SOCKET_MSG_CONNECT:
    if (rest < 8) return false;
    return RelayAddressAllowed((const uint8_t*)(w + 2), MIN((uint32_t)w[1], (uint32_t)(rest - 8)));
  case POSIX_SOCKET_MSG_SENDTO:
    if (rest < 16 + 8) return false;
    return RelayAddressAllowed((const uint8_t*)(w + 4), MIN((uint32_t)w[3], (uint32_t)(rest - 16)));
  case POSIX_SOCKET_MSG_SOCKETPAIR:
  case POSIX_SOCKET_MSG_LISTEN:
  case POSIX_SOCKET_MSG_ACCEPT:
  case POSIX_SOCKET_MSG_SENDMSG:
  case POSIX_SOCKET_MSG_RECVMSG:
    return false;
  default:
    return true;
  }
}

void ProcessWebSocketMessageSynchronouslyInCurrentThread(int client_fd, uint8_t *payload, uint64_t numBytes)
{
  if (!RelayAllowed(payload, numBytes))
  {
    struct { int callId; int ret; int errno_; } r;
    r.callId = numBytes >= sizeof(SocketCallHeader) ? ((SocketCallHeader*)payload)->callId : 0;
    r.ret = -1;
    r.errno_ = EACCES;
    SendWebSocketMessage(client_fd, &r, sizeof(r));
    return;
  }
  assert(numBytes >= sizeof(SocketCallHeader));
  SocketCallHeader *header = (SocketCallHeader*)payload;
  switch(header->function)
  {
    case POSIX_SOCKET_MSG_SOCKET: Socket(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SOCKETPAIR: Socketpair(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SHUTDOWN: Shutdown(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_BIND: Bind(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_CONNECT: Connect(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_LISTEN: Listen(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_ACCEPT: Accept(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_GETSOCKNAME: Getsockname(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_GETPEERNAME: Getpeername(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SEND: Send(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_RECV: Recv(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SENDTO: Sendto(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_RECVFROM: Recvfrom(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SENDMSG: Sendmsg(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_RECVMSG: Recvmsg(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_GETSOCKOPT: Getsockopt(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_SETSOCKOPT: Setsockopt(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_GETADDRINFO: Getaddrinfo(client_fd, payload, numBytes); break;
    case POSIX_SOCKET_MSG_GETNAMEINFO: Getnameinfo(client_fd, payload, numBytes); break;
    default:
      printf("Unknown POSIX_SOCKET_MSG %u received!\n", header->function);
      break;
	}
}

void ProcessWebSocketMessage(int client_fd, uint8_t *payload, uint64_t numBytes)
{
  if (numBytes < sizeof(SocketCallHeader))
  {
    printf("Received too small sockets call message! size: %d bytes, expected at least %d bytes\n", (int)numBytes, (int)sizeof(SocketCallHeader));
    return;
  }
  SocketCallHeader *header = (SocketCallHeader*)payload;
  if (header->function == POSIX_SOCKET_MSG_RECV || header->function == POSIX_SOCKET_MSG_RECVFROM || header->function == POSIX_SOCKET_MSG_RECVMSG || header->function == POSIX_SOCKET_MSG_CONNECT || header->function == POSIX_SOCKET_MSG_ACCEPT)
  {

    ProcessWebSocketMessageAsynchronouslyInBackgroundThread(client_fd, payload, numBytes);
  }
  else
  {
    ProcessWebSocketMessageSynchronouslyInCurrentThread(client_fd, payload, numBytes);
  }
}
