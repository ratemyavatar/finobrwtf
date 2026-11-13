#include <arpa/inet.h>
#include <errno.h>
#include <netinet/in.h>
#include <pthread.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>
#include <vector>

#include "udp_mode.h"

bool RelayAddressAllowed(const uint8_t *addr, uint32_t len);

struct UdpSession
{
  int client_fd;
  int sock;
  pthread_t reader;
  bool readerStarted;
  pthread_mutex_t sendLock;
};

static bool AddressAllowed(const sockaddr_in &a)
{
  uint8_t addr[8];
  addr[0] = 2;
  addr[1] = 0;
  memcpy(addr + 2, &a.sin_port, 2);
  memcpy(addr + 4, &a.sin_addr, 4);
  return RelayAddressAllowed(addr, sizeof(addr));
}

static void SendFrame(UdpSession *s, const uint8_t *data, size_t len)
{
  std::vector<uint8_t> frame;
  frame.reserve(len + 10);
  frame.push_back(0x82);
  if (len < 126)
    frame.push_back((uint8_t)len);
  else if (len <= 65535)
  {
    frame.push_back(126);
    frame.push_back((uint8_t)(len >> 8));
    frame.push_back((uint8_t)len);
  }
  else
  {
    frame.push_back(127);
    for (int i = 7; i >= 0; --i)
      frame.push_back((uint8_t)((uint64_t)len >> (i * 8)));
  }
  frame.insert(frame.end(), data, data + len);
  pthread_mutex_lock(&s->sendLock);
  size_t off = 0;
  while (off < frame.size())
  {
    ssize_t n = send(s->client_fd, frame.data() + off, frame.size() - off, MSG_NOSIGNAL);
    if (n < 0 && errno == EINTR)
      continue;
    if (n <= 0)
      break;
    off += (size_t)n;
  }
  pthread_mutex_unlock(&s->sendLock);
}

static void *UdpReader(void *arg)
{
  UdpSession *s = (UdpSession *)arg;
  std::vector<uint8_t> buf(6 + 65536);
  for (;;)
  {
    sockaddr_in from;
    socklen_t fromLen = sizeof(from);
    ssize_t n = recvfrom(s->sock, buf.data() + 6, 65536, 0, (sockaddr *)&from, &fromLen);
    if (n < 0 && errno == EINTR)
      continue;
    if (n <= 0)
      break;
    if (from.sin_family != AF_INET || !AddressAllowed(from))
      continue;
    memcpy(buf.data(), &from.sin_addr, 4);
    memcpy(buf.data() + 4, &from.sin_port, 2);
    SendFrame(s, buf.data(), (size_t)n + 6);
  }
  return 0;
}

UdpSession *UdpOpen(int client_fd)
{
  int sock = socket(AF_INET, SOCK_DGRAM, 0);
  if (sock < 0)
    return 0;
  sockaddr_in any;
  memset(&any, 0, sizeof(any));
  any.sin_family = AF_INET;
  any.sin_addr.s_addr = htonl(INADDR_ANY);
  any.sin_port = 0;
  if (bind(sock, (sockaddr *)&any, sizeof(any)) < 0)
  {
    close(sock);
    return 0;
  }
  int size = 1 << 20;
  setsockopt(sock, SOL_SOCKET, SO_RCVBUF, &size, sizeof(size));
  setsockopt(sock, SOL_SOCKET, SO_SNDBUF, &size, sizeof(size));
  UdpSession *s = new UdpSession();
  s->client_fd = client_fd;
  s->sock = sock;
  pthread_mutex_init(&s->sendLock, 0);
  s->readerStarted = pthread_create(&s->reader, 0, UdpReader, s) == 0;
  if (!s->readerStarted)
  {
    UdpClose(s);
    return 0;
  }
  return s;
}

void UdpFrame(UdpSession *s, const uint8_t *payload, uint64_t len)
{
  if (len < 6 || len > 6 + 65507)
    return;
  sockaddr_in to;
  memset(&to, 0, sizeof(to));
  to.sin_family = AF_INET;
  memcpy(&to.sin_addr, payload, 4);
  memcpy(&to.sin_port, payload + 4, 2);
  if (!AddressAllowed(to))
    return;
  sendto(s->sock, payload + 6, (size_t)(len - 6), 0, (sockaddr *)&to, sizeof(to));
}

void UdpClose(UdpSession *s)
{
  if (!s)
    return;
  shutdown(s->sock, SHUT_RDWR);
  if (s->readerStarted)
    pthread_join(s->reader, 0);
  close(s->sock);
  pthread_mutex_destroy(&s->sendLock);
  delete s;
}
