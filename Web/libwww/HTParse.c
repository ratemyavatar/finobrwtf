#include <stdlib.h>
#include <string.h>
#include "HTParse.h"

typedef struct { const char *p; size_t n; } span;

static void split(const char *u, span *access, span *host, span *path, span *anchor)
{
    const char *s = u, *c;
    access->p = host->p = path->p = anchor->p = 0;
    access->n = host->n = path->n = anchor->n = 0;
    while (*s == ' ') s++;
    for (c = s; *c && *c != ':' && *c != '/' && *c != '?' && *c != '#'; c++) {}
    if (*c == ':' && c > s) {
        access->p = s; access->n = (size_t)(c - s);
        s = c + 1;
    }
    if (s[0] == '/' && s[1] == '/') {
        s += 2;
        for (c = s; *c && *c != '/' && *c != '?' && *c != '#'; c++) {}
        host->p = s; host->n = (size_t)(c - s);
        s = c;
    }
    for (c = s; *c && *c != '#'; c++) {}
    path->p = s; path->n = (size_t)(c - s);
    if (*c == '#') { anchor->p = c + 1; anchor->n = strlen(c + 1); }
}

char *HTParse(const char *aName, const char *relatedName, int wanted)
{
    span a, h, p, n, ra, rh, rp, rn;
    size_t len;
    char *out, *o;
    if (!aName) aName = "";
    split(aName, &a, &h, &p, &n);
    if (relatedName) {
        split(relatedName, &ra, &rh, &rp, &rn);
        if (!a.p) a = ra;
        if (!h.p && (!a.p || (ra.p && a.n == ra.n && !strncmp(a.p, ra.p, a.n)))) {
            h = rh;
            if (p.n == 0) p = rp;
        }
    }
    len = a.n + h.n + p.n + n.n + 8;
    out = (char *)malloc(len);
    if (!out) return 0;
    o = out;
    if ((wanted & PARSE_ACCESS) && a.p) {
        memcpy(o, a.p, a.n); o += a.n;
        if (wanted & PARSE_PUNCTUATION) *o++ = ':';
    }
    if ((wanted & PARSE_HOST) && h.p) {
        if (wanted & PARSE_PUNCTUATION) { *o++ = '/'; *o++ = '/'; }
        memcpy(o, h.p, h.n); o += h.n;
    }
    if ((wanted & PARSE_PATH) && p.p) {
        if (h.p && (wanted & PARSE_PUNCTUATION) == 0 && p.n && p.p[0] != '/') *o++ = '/';
        memcpy(o, p.p, p.n); o += p.n;
    }
    if ((wanted & PARSE_ANCHOR) && n.p) {
        if (wanted & PARSE_PUNCTUATION) *o++ = '#';
        memcpy(o, n.p, n.n); o += n.n;
    }
    *o = 0;
    return out;
}

char *HTSimplify(char **filename)
{
    char *s, *path, *q, *end;
    if (!filename || !*filename) return 0;
    s = *filename;
    path = strstr(s, "://");
    path = path ? strchr(path + 3, '/') : s;
    if (!path) return s;
    end = path + strcspn(path, "?#");
    for (;;) {
        q = strstr(path, "/./");
        if (!q || q >= end) break;
        memmove(q, q + 2, strlen(q + 2) + 1);
        end -= 2;
    }
    for (;;) {
        char *prev;
        q = strstr(path, "/../");
        if (!q || q >= end) break;
        if (q == path) { memmove(q, q + 3, strlen(q + 3) + 1); end -= 3; continue; }
        prev = q - 1;
        while (prev > path && *prev != '/') prev--;
        memmove(prev, q + 3, strlen(q + 3) + 1);
        end -= (q + 3) - prev;
    }
    return s;
}
