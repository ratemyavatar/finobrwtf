cmake_minimum_required(VERSION 3.16)
project(finobe_renderd CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
if(NOT CMAKE_BUILD_TYPE)
  set(CMAKE_BUILD_TYPE Release)
endif()

find_package(Threads REQUIRED)

add_executable(finobe-renderd
  src/main.cpp
  src/config.cpp
  src/log.cpp
  src/net.cpp
  src/soap.cpp
  src/pool.cpp
  src/server.cpp
  src/procs.cpp
  src/games.cpp
)
target_compile_options(finobe-renderd PRIVATE -Wall -Wextra -Wpedantic -O2 -D_FORTIFY_SOURCE=2 -fstack-protector-strong)
target_link_options(finobe-renderd PRIVATE -Wl,-z,relro,-z,now)
target_link_libraries(finobe-renderd PRIVATE Threads::Threads)
install(TARGETS finobe-renderd RUNTIME DESTINATION bin)
