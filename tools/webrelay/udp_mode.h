#pragma once
#include <stdint.h>

struct UdpSession;
UdpSession *UdpOpen(int client_fd);
void UdpFrame(UdpSession *s, const uint8_t *payload, uint64_t len);
void UdpClose(UdpSession *s);
