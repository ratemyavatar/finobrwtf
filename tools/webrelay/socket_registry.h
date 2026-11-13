#pragma once

#include "posix_sockets.h"

void TrackSocketUsedByConnection(int proxyConnection, SOCKET_T usedSocket);

void CloseSocketByConnection(int proxyConnection, SOCKET_T usedSocket);

void CloseAllSocketsByConnection(int proxyConnection);

bool IsSocketPartOfConnection(int proxyConnection, SOCKET_T usedSocket);
