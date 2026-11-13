#pragma once
#ifdef __EMSCRIPTEN__
#define _byteswap_ulong(x) __builtin_bswap32(x)
#define _byteswap_ushort(x) __builtin_bswap16(x)
#define _byteswap_uint64(x) __builtin_bswap64(x)
#endif
#ifdef __EMSCRIPTEN__
#ifndef __forceinline
#define __forceinline inline __attribute__((always_inline))
#endif
#ifdef __cplusplus
static inline unsigned int _rotl(unsigned int v, int s) { s &= 31; return (v << s) | (v >> ((32 - s) & 31)); }
static inline unsigned int _rotr(unsigned int v, int s) { s &= 31; return (v >> s) | (v << ((32 - s) & 31)); }
#endif
#endif
