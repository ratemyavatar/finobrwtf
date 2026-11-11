#!/bin/sh
set -e
cd "$(dirname "$0")"
CC=i686-w64-mingw32-gcc
CXX=i686-w64-mingw32-g++
$CC -O2 -c miniz.c -o miniz.o
i686-w64-mingw32-windres resource.rc -O coff -o resource.o
$CXX -O2 -std=c++17 -municode -mwindows -static -s main.cpp miniz.o resource.o -o FinobeLauncher.exe \
    -lgdiplus -lwininet -lws2_32 -lshell32 -lole32 -luuid -lgdi32 -luser32 -lcomctl32
rm -f miniz.o resource.o
ls -la FinobeLauncher.exe
