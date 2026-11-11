// Finobe bootstrapper (FinobeLauncher.exe)
//
// - asks https://prod1-setup.<domain>/version which client build is current
// - downloads <version>-FinobePlayer.zip and unpacks it into %LOCALAPPDATA%\Finobe\Versions\<version>
//   (skipped when that build is already installed)
// - copies itself to %LOCALAPPDATA%\Finobe\FinobeLauncher.exe and registers finobe-launcher://
// - when started from a Play button (finobe-launcher:///1+launchmode:play+gameinfo:<token>+
//   placelauncherurl:<url>+clientversion:<year>), installs that year's client and starts it:
//     2016 and newer: RobloxPlayerBeta.exe --play -a <negotiate> -t <token> -j <url>
//     2012: waits for a server through PlaceLauncher, then RobloxApp.exe -Script "loadfile('<join>')()"
//   Each year is its own build: /version?year=<year> on the setup server, installed in
//   Versions\<year>\<version> (2016 stays in Versions\<version>, where older launchers put it).
//   Links without clientversion ask the site (Game/ClientVersion.ashx?placeId=) which year the place uses.
//
// Build: ./build.sh (MinGW-w64, i686). Plain Win32 + GDI+ + WinINet, no runtime DLLs.

#define WIN32_LEAN_AND_MEAN
#define NOMINMAX
#include <winsock2.h>
#include <ws2tcpip.h>
#include <windows.h>
#include <windowsx.h>
#include <wininet.h>
#include <shellapi.h>
#include <shlobj.h>
#include <objidl.h>
#include <gdiplus.h>
#include <string>
#include <vector>
#include <cmath>
#include <cstdio>
#include <cwchar>
#include "miniz.h"

#define IDR_LOGO 101

// ---------------------------------------------------------------- settings

static const wchar_t* kDomain = L"finobe.wtf";
static const wchar_t* kProtocol = L"finobe-launcher";
static const wchar_t* kTitle = L"Finobe";
// bump with every launcher release: a launcher older than the setup server's /launcher-build updates itself
static const int kLauncherBuild = 8;

// ---------------------------------------------------------------- UI state

enum { WM_APP_STATUS = WM_APP + 1, WM_APP_PROGRESS, WM_APP_DONE, WM_APP_FAIL };

static const int kWidth = 500, kHeight = 300;
static HWND gWnd;
static HINSTANCE gInst;
static Gdiplus::Image* gLogo;
static float gAngle;
static std::wstring gStatus = L"Connecting to Finobe...";
static std::wstring gSubStatus;
static int gProgress = -1;            // -1 = no bar, 0..1000
static bool gCancelHover, gCancelDown;
static volatile LONG gCancelled;
static DWORD gStepStarted;            // when the current slow step began
static RECT gCancelRect = { kWidth / 2 - 60, 245, kWidth / 2 + 60, 279 };

static void setStatus(const std::wstring& s) { PostMessageW(gWnd, WM_APP_STATUS, 0, (LPARAM) new std::wstring(s)); }
static void setProgress(int permille) { PostMessageW(gWnd, WM_APP_PROGRESS, (WPARAM) permille, 0); }
static bool cancelled() { return InterlockedCompareExchange(&gCancelled, 0, 0) != 0; }

// ---------------------------------------------------------------- helpers

static std::wstring widen(const std::string& s)
{
    if (s.empty()) return L"";
    int n = MultiByteToWideChar(CP_UTF8, 0, s.data(), (int) s.size(), nullptr, 0);
    std::wstring w(n, 0);
    MultiByteToWideChar(CP_UTF8, 0, s.data(), (int) s.size(), &w[0], n);
    return w;
}

static std::wstring trim(std::wstring s)
{
    while (!s.empty() && iswspace(s.back())) s.pop_back();
    size_t i = 0;
    while (i < s.size() && iswspace(s[i])) i++;
    return s.substr(i);
}

static std::wstring localAppData()
{
    wchar_t path[MAX_PATH];
    if (SHGetFolderPathW(nullptr, CSIDL_LOCAL_APPDATA, nullptr, 0, path) != S_OK) return L"C:\\Finobe";
    return path;
}

static bool fileExists(const std::wstring& p) { DWORD a = GetFileAttributesW(p.c_str()); return a != INVALID_FILE_ATTRIBUTES && !(a & FILE_ATTRIBUTE_DIRECTORY); }

static void makeDirs(const std::wstring& dir) { SHCreateDirectoryExW(nullptr, dir.c_str(), nullptr); }

static std::wstring urlDecode(const std::wstring& s)
{
    std::string bytes;
    for (size_t i = 0; i < s.size(); i++) {
        if (s[i] == L'%' && i + 2 < s.size() && iswxdigit(s[i + 1]) && iswxdigit(s[i + 2])) {
            bytes += (char) wcstol(s.substr(i + 1, 2).c_str(), nullptr, 16); i += 2;
        } else {
            char buf[8]; int n = WideCharToMultiByte(CP_UTF8, 0, &s[i], 1, buf, sizeof buf, nullptr, nullptr);
            bytes.append(buf, n);
        }
    }
    return widen(bytes);
}

// ---------------------------------------------------------------- HTTP

struct Inet {
    HINTERNET h = nullptr;
    Inet() { h = InternetOpenW(L"FinobeLauncher/2", INTERNET_OPEN_TYPE_PRECONFIG, nullptr, nullptr, 0); }
    ~Inet() { if (h) InternetCloseHandle(h); }
};

static DWORD urlFlags(const std::wstring& url)
{
    DWORD f = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE | INTERNET_FLAG_NO_COOKIES;
    return _wcsnicmp(url.c_str(), L"https:", 6) == 0 ? f | INTERNET_FLAG_SECURE : f;
}

static bool httpGetString(const std::wstring& url, std::string& out, std::wstring& err)
{
    Inet net;
    HINTERNET req = net.h ? InternetOpenUrlW(net.h, url.c_str(), nullptr, 0, urlFlags(url), 0) : nullptr;
    if (!req) { err = L"Couldn't reach " + url; return false; }

    DWORD status = 0, len = sizeof status;
    HttpQueryInfoW(req, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &status, &len, nullptr);
    char buf[4096]; DWORD got;
    while (InternetReadFile(req, buf, sizeof buf, &got) && got) out.append(buf, got);
    InternetCloseHandle(req);

    if (status != 200) { err = L"The server said " + std::to_wstring(status) + L" for " + url; return false; }
    return true;
}

// download to a file; reports progress 0..1000
static bool httpDownload(const std::wstring& url, const std::wstring& dest, std::wstring& err)
{
    Inet net;
    HINTERNET req = net.h ? InternetOpenUrlW(net.h, url.c_str(), nullptr, 0, urlFlags(url), 0) : nullptr;
    if (!req) { err = L"Couldn't reach " + url; return false; }

    DWORD status = 0, len = sizeof status;
    HttpQueryInfoW(req, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &status, &len, nullptr);
    if (status != 200) { InternetCloseHandle(req); err = L"The server said " + std::to_wstring(status) + L" for " + url; return false; }

    wchar_t clen[32] = L"0"; len = sizeof clen;
    HttpQueryInfoW(req, HTTP_QUERY_CONTENT_LENGTH, clen, &len, nullptr);
    unsigned long long total = wcstoull(clen, nullptr, 10), done = 0;

    FILE* f = _wfopen(dest.c_str(), L"wb");
    if (!f) { InternetCloseHandle(req); err = L"Couldn't write " + dest; return false; }

    std::vector<char> buf(1 << 16); DWORD got;
    bool ok = true;
    while (true) {
        if (cancelled()) { ok = false; break; }
        if (!InternetReadFile(req, buf.data(), (DWORD) buf.size(), &got)) { ok = false; err = L"The download stopped. Check your connection and try again."; break; }
        if (!got) break;
        fwrite(buf.data(), 1, got, f);
        done += got;
        if (total) setProgress((int) (done * 1000 / total));
    }
    fclose(f);
    InternetCloseHandle(req);
    if (ok && total && done != total) { ok = false; err = L"The download was incomplete. Please try again."; }
    return ok;
}

// ---------------------------------------------------------------- unzip

static size_t writeToFile(void* opaque, mz_uint64, const void* buf, size_t n) { return fwrite(buf, 1, n, (FILE*) opaque); }

static bool unzipTo(const std::wstring& zipPath, const std::wstring& dir, std::wstring& err)
{
    FILE* zf = _wfopen(zipPath.c_str(), L"rb");
    if (!zf) { err = L"Couldn't open the downloaded file."; return false; }

    mz_zip_archive zip; memset(&zip, 0, sizeof zip);
    if (!mz_zip_reader_init_cfile(&zip, zf, 0, 0)) { fclose(zf); err = L"The download is damaged. Please try again."; return false; }

    mz_uint count = mz_zip_reader_get_num_files(&zip);
    bool ok = true;
    for (mz_uint i = 0; i < count && ok; i++) {
        if (cancelled()) { ok = false; break; }
        mz_zip_archive_file_stat st;
        if (!mz_zip_reader_file_stat(&zip, i, &st)) { ok = false; break; }

        std::wstring name = widen(st.m_filename);
        for (auto& c : name) if (c == L'/') c = L'\\';
        if (name.find(L"..") != std::wstring::npos || (!name.empty() && (name[0] == L'\\' || name.find(L':') != std::wstring::npos))) continue;

        std::wstring out = dir + L"\\" + name;
        if (mz_zip_reader_is_file_a_directory(&zip, i)) { makeDirs(out); continue; }
        makeDirs(out.substr(0, out.find_last_of(L'\\')));

        FILE* f = _wfopen(out.c_str(), L"wb");
        if (!f) { ok = false; err = L"Couldn't write " + out; break; }
        ok = mz_zip_reader_extract_to_callback(&zip, i, writeToFile, f, 0);
        fclose(f);
        if (!ok) err = L"Couldn't unpack " + name;
        setProgress((int) ((i + 1) * 1000ULL / count));
    }
    mz_zip_reader_end(&zip);
    fclose(zf);
    return ok;
}

// ---------------------------------------------------------------- install / launch

static void registerProtocol(const std::wstring& launcherPath)
{
    std::wstring base = std::wstring(L"Software\\Classes\\") + kProtocol;
    HKEY k;
    if (RegCreateKeyExW(HKEY_CURRENT_USER, base.c_str(), 0, nullptr, 0, KEY_WRITE, nullptr, &k, nullptr) == ERROR_SUCCESS) {
        const wchar_t* desc = L"URL:Finobe Protocol";
        RegSetValueExW(k, nullptr, 0, REG_SZ, (const BYTE*) desc, (DWORD) ((wcslen(desc) + 1) * 2));
        RegSetValueExW(k, L"URL Protocol", 0, REG_SZ, (const BYTE*) L"", 2);
        RegCloseKey(k);
    }
    std::wstring icon = L"\"" + launcherPath + L"\",0";
    if (RegCreateKeyExW(HKEY_CURRENT_USER, (base + L"\\DefaultIcon").c_str(), 0, nullptr, 0, KEY_WRITE, nullptr, &k, nullptr) == ERROR_SUCCESS) {
        RegSetValueExW(k, nullptr, 0, REG_SZ, (const BYTE*) icon.c_str(), (DWORD) ((icon.size() + 1) * 2));
        RegCloseKey(k);
    }
    std::wstring cmd = L"\"" + launcherPath + L"\" \"%1\"";
    if (RegCreateKeyExW(HKEY_CURRENT_USER, (base + L"\\shell\\open\\command").c_str(), 0, nullptr, 0, KEY_WRITE, nullptr, &k, nullptr) == ERROR_SUCCESS) {
        RegSetValueExW(k, nullptr, 0, REG_SZ, (const BYTE*) cmd.c_str(), (DWORD) ((cmd.size() + 1) * 2));
        RegCloseKey(k);
    }
}

// finobe-launcher:///1+launchmode:play+gameinfo:<token>+placelauncherurl:<url>+k:l
struct LaunchArgs { bool present = false; std::wstring mode, token, placeLauncher, year; };

static LaunchArgs parseLaunchUri(const std::wstring& raw)
{
    LaunchArgs a;
    std::wstring s = raw;
    size_t p = s.find(L':');
    if (p == std::wstring::npos || _wcsnicmp(s.c_str(), kProtocol, wcslen(kProtocol)) != 0) return a;
    a.present = true;
    s = s.substr(p + 1);
    while (!s.empty() && s[0] == L'/') s.erase(0, 1);
    size_t start = 0;
    while (start <= s.size()) {
        size_t end = s.find(L'+', start);
        std::wstring part = s.substr(start, end == std::wstring::npos ? std::wstring::npos : end - start);
        size_t c = part.find(L':');
        if (c != std::wstring::npos) {
            std::wstring key = part.substr(0, c), val = urlDecode(part.substr(c + 1));
            if (key == L"launchmode") a.mode = val;
            else if (key == L"gameinfo") a.token = val;
            else if (key == L"placelauncherurl") a.placeLauncher = val;
            else if (key == L"clientversion") a.year = val;
        }
        if (end == std::wstring::npos) break;
        start = end + 1;
    }
    return a;
}

static bool isYear(const std::wstring& y) { return y.size() == 4 && y.find_first_not_of(L"0123456789") == std::wstring::npos; }

// the client exe each year's build starts
static std::wstring clientExe(const std::wstring& year) { return year == L"2012" ? L"RobloxApp.exe" : L"RobloxPlayerBeta.exe"; }

// value of "key": in a flat JSON object (enough for PlaceLauncher's reply); numbers come back as text
static std::string jsonValue(const std::string& json, const std::string& key)
{
    size_t p = json.find("\"" + key + "\"");
    if (p == std::string::npos) return "";
    p = json.find(':', p);
    if (p == std::string::npos) return "";
    p = json.find_first_not_of(" \t\r\n", p + 1);
    if (p == std::string::npos) return "";
    std::string out;
    if (json[p] == '"') {
        for (size_t i = p + 1; i < json.size() && json[i] != '"'; i++) {
            if (json[i] == '\\' && i + 1 < json.size()) { i++; out += json[i] == 'n' ? '\n' : json[i]; }
            else out += json[i];
        }
    } else {
        while (p < json.size() && json[p] != ',' && json[p] != '}' && json[p] != ' ') out += json[p++];
    }
    return out;
}

static std::wstring queryParam(const std::wstring& url, const std::wstring& name)
{
    std::wstring lower = url;
    for (auto& c : lower) c = towlower(c);
    std::wstring key = name;
    for (auto& c : key) c = towlower(c);
    for (const wchar_t* sep : { L"?", L"&" }) {
        size_t p = lower.find(sep + key + L"=");
        if (p != std::wstring::npos) {
            p += 1 + key.size() + 1;
            size_t e = url.find(L'&', p);
            return url.substr(p, e == std::wstring::npos ? std::wstring::npos : e - p);
        }
    }
    return L"";
}

// ---------------------------------------------------------------- udp relay

// The 2012 client only completes PlayerConnect against a local address: any remote
// IP or hostname makes it throw "Unknown exception" and it never opens the socket.
// So the join script hands it localhost, and this relays that UDP to the real game
// server (serverAddress/serverPort from PlaceLauncher).
static std::string gRelayAddr;
static int gRelayPort = 0;

static SOCKET gRelaySock = INVALID_SOCKET;

// bind synchronously: the 2012 client binds its local socket to the server port, so the
// relay has to own the port first, otherwise the client takes it and talks to itself
static bool bindRelay()
{
    char logp[MAX_PATH] = {0};
    GetTempPathA(MAX_PATH, logp);
    strcat(logp, "finobe_relay.log");
    FILE* lf = fopen(logp, "a");
    WSADATA wsa;
    if (WSAStartup(MAKEWORD(2, 2), &wsa) != 0) { if (lf) { fprintf(lf, "bind: WSAStartup failed\n"); fclose(lf); } return false; }
    SOCKET s = socket(AF_INET, SOCK_DGRAM, 0);
    if (s == INVALID_SOCKET) { if (lf) { fprintf(lf, "bind: socket failed %d\n", WSAGetLastError()); fclose(lf); } return false; }
    sockaddr_in local = {};
    local.sin_family = AF_INET;
    local.sin_addr.s_addr = INADDR_ANY;
    local.sin_port = htons((u_short) gRelayPort);
    if (bind(s, (sockaddr*) &local, sizeof local) != 0) {
        int e = WSAGetLastError();
        if (lf) { fprintf(lf, "bind: port %d failed err=%d\n", gRelayPort, e); fclose(lf); }
        closesocket(s); return false;
    }
    gRelaySock = s;
    if (lf) { fprintf(lf, "bind: ok port %d sock=%d\n", gRelayPort, (int) s); fflush(lf); fclose(lf); }
    return true;
}

static DWORD WINAPI relayThread(LPVOID)
{
    char logp[MAX_PATH] = {0};
    GetTempPathA(MAX_PATH, logp);
    strcat(logp, "finobe_relay.log");
    FILE* lf = fopen(logp, "a");
    if (lf) { fprintf(lf, "relay: start %s:%d (sock %d)\n", gRelayAddr.c_str(), gRelayPort, (int) gRelaySock); fflush(lf); }

    sockaddr_in target = {};
    target.sin_family = AF_INET;
    target.sin_port = htons((u_short) gRelayPort);
    addrinfo hints = {}; hints.ai_family = AF_INET; hints.ai_socktype = SOCK_DGRAM;
    addrinfo* res = nullptr;
    if (getaddrinfo(gRelayAddr.c_str(), nullptr, &hints, &res) != 0 || !res) { if (lf) { fprintf(lf, "relay: resolve failed\n"); fclose(lf); } return 1; }
    target.sin_addr = ((sockaddr_in*) res->ai_addr)->sin_addr;
    freeaddrinfo(res);

    SOCKET up = socket(AF_INET, SOCK_DGRAM, 0);
    if (up == INVALID_SOCKET) { if (lf) { fprintf(lf, "relay: upstream socket failed\n"); fclose(lf); } return 1; }
    if (lf) { fprintf(lf, "relay: forwarding to %s:%d\n", gRelayAddr.c_str(), gRelayPort); fflush(lf); }

    char buf[65535];
    sockaddr_storage client = {}; int clientLen = 0;
    for (;;) {
        fd_set r; FD_ZERO(&r); FD_SET(gRelaySock, &r); FD_SET(up, &r);
        if (select(0, &r, nullptr, nullptr, nullptr) <= 0) continue;
        if (FD_ISSET(gRelaySock, &r)) {
            int n = sizeof client;
            int got = recvfrom(gRelaySock, buf, sizeof buf, 0, (sockaddr*) &client, &n);
            if (got > 0) { clientLen = n; sendto(up, buf, got, 0, (sockaddr*) &target, sizeof target); }
        }
        if (FD_ISSET(up, &r)) {
            int got = recvfrom(up, buf, sizeof buf, 0, nullptr, nullptr);
            if (got > 0 && clientLen) sendto(gRelaySock, buf, got, 0, (sockaddr*) &client, clientLen);
        }
    }
}

// 2012: the 2012 client doesn't talk to PlaceLauncher itself, so wait for the server here
static bool launch2012(const std::wstring& dir, const LaunchArgs& a, std::wstring& err)
{
    std::wstring joinUrl;
    DWORD deadline = GetTickCount() + 150000;
    while (GetTickCount() < deadline && !cancelled()) {
        std::string reply; std::wstring e;
        if (!httpGetString(a.placeLauncher, reply, e)) { err = L"Couldn't reach Finobe. Check your internet connection and try again."; return false; }
        std::string status = jsonValue(reply, "status");
        if (status == "0") setStatus(L"Waiting for a server...");
        else if (status == "1") setStatus(L"The server is starting...");
        else if (status == "2") { joinUrl = widen(jsonValue(reply, "joinScriptUrl"));
            gRelayAddr = jsonValue(reply, "serverAddress");
            gRelayPort = atoi(jsonValue(reply, "serverPort").c_str()); break; }
        else {
            std::string msg = jsonValue(reply, "message");
            err = msg.empty() ? L"Couldn't join this game." : widen(msg);
            return false;
        }
        Sleep(2000);
    }
    if (cancelled()) return false;
    std::wstring prefix1 = std::wstring(L"http://assetgame.") + kDomain + L"/game/join.ashx?";
    std::wstring prefix2 = std::wstring(L"https://assetgame.") + kDomain + L"/game/join.ashx?";
    if (joinUrl.rfind(prefix1, 0) != 0 && joinUrl.rfind(prefix2, 0) != 0) { err = L"The server took too long to start. Try again in a bit."; return false; }
    if (joinUrl.find_first_of(L"'\\\" \t\r\n") != std::wstring::npos) { err = L"Bad join script URL."; return false; }

    // the join script connects to localhost; forward that UDP to the real server.
    // bind before starting the client so the client doesn't grab the port itself
    if (!gRelayAddr.empty() && gRelayPort > 0) {
        if (bindRelay()) CloseHandle(CreateThread(nullptr, 0, relayThread, nullptr, 0, nullptr));
    }

    setStatus(L"Starting Finobe...");
    // loadfile checks the site's signature on the join script before running it
    std::wstring exe = dir + L"\\RobloxApp.exe";
    std::wstring cmd = L"\"" + exe + L"\" -Script \"loadfile('" + joinUrl + L"')()\"";
    STARTUPINFOW si = { sizeof si }; PROCESS_INFORMATION pi;
    std::vector<wchar_t> buf(cmd.begin(), cmd.end()); buf.push_back(0);
    if (!CreateProcessW(exe.c_str(), buf.data(), nullptr, nullptr, FALSE, 0, nullptr, dir.c_str(), &si, &pi)) {
        err = L"Couldn't start Finobe (" + std::to_wstring(GetLastError()) + L").";
        return false;
    }
    CloseHandle(pi.hThread);
    // the relay thread lives and dies with this process, so stay alive while the client runs
    ShowWindow(gWnd, SW_HIDE);
    WaitForSingleObject(pi.hProcess, INFINITE);
    CloseHandle(pi.hProcess);
    return true;
}

static bool launchPlayer(const std::wstring& dir, const LaunchArgs& a, std::wstring& err)
{
    if (a.year == L"2012") return launch2012(dir, a, err);
    std::wstring exe = dir + L"\\RobloxPlayerBeta.exe";
    std::wstring negotiate = std::wstring(L"https://www.") + kDomain + L"/Login/Negotiate.ashx";
    std::wstring cmd = L"\"" + exe + L"\" --play -a \"" + negotiate + L"\" -t \"" + a.token + L"\" -j \"" + a.placeLauncher + L"\"";

    STARTUPINFOW si = { sizeof si }; PROCESS_INFORMATION pi;
    std::vector<wchar_t> buf(cmd.begin(), cmd.end()); buf.push_back(0);
    if (!CreateProcessW(exe.c_str(), buf.data(), nullptr, nullptr, FALSE, 0, nullptr, dir.c_str(), &si, &pi)) {
        err = L"Couldn't start Finobe (" + std::to_wstring(GetLastError()) + L").";
        return false;
    }
    CloseHandle(pi.hThread); CloseHandle(pi.hProcess);
    return true;
}

static void removeOtherVersions(const std::wstring& versionsDir, const std::wstring& keep)
{
    WIN32_FIND_DATAW fd;
    HANDLE h = FindFirstFileW((versionsDir + L"\\version-*").c_str(), &fd);
    if (h == INVALID_HANDLE_VALUE) return;
    do {
        if ((fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) && keep != fd.cFileName) {
            std::wstring path = versionsDir + L"\\" + fd.cFileName;
            path.push_back(0); path.push_back(0);   // SHFileOperation wants a double-null list
            SHFILEOPSTRUCTW op = {}; op.wFunc = FO_DELETE; op.pFrom = path.c_str();
            op.fFlags = FOF_NO_UI;
            SHFileOperationW(&op);   // best effort; a running old build stays until next time
        }
    } while (FindNextFileW(h, &fd));
    FindClose(h);
}

static std::wstring gLaunchUri;

// the 2012 client caches asset/script responses in WinINet; a stale entry (e.g. a 404
// from before a route existed) survives restarts and stops the core gui from loading.
// clear it once per launcher build, so a fix ships without asking anyone to clear anything.
static void clearHttpCacheIfUpdated()
{
    std::wstring root = localAppData() + L"\\Finobe";
    std::wstring stamp = root + L"\\.cachebuild";
    int stored = -1;
    if (FILE* f = _wfopen(stamp.c_str(), L"r")) { if (fscanf(f, "%d", &stored) != 1) stored = -1; fclose(f); }
    if (stored == kLauncherBuild) return;

    wchar_t local[32768];
    if (GetEnvironmentVariableW(L"LOCALAPPDATA", local, 32768) > 0) {
        std::wstring base = std::wstring(local) + L"\\Microsoft\\Windows\\INetCache";
        const wchar_t* subs[] = { L"Content.IE5", L"IE" };
        for (int i = 0; i < 2; i++) {
            std::wstring path = base + L"\\" + subs[i];
            path.push_back(0); path.push_back(0);   // SHFileOperation wants a double-null list
            SHFILEOPSTRUCTW op = {}; op.wFunc = FO_DELETE; op.pFrom = path.c_str();
            op.fFlags = FOF_NO_UI | FOF_NOCONFIRMATION;
            SHFileOperationW(&op);
        }
    }
    makeDirs(root);
    if (FILE* w = _wfopen(stamp.c_str(), L"w")) { fprintf(w, "%d\n", kLauncherBuild); fclose(w); }
}

static DWORD WINAPI worker(LPVOID)
{
    std::wstring err;
    LaunchArgs args = parseLaunchUri(gLaunchUri);
    std::wstring root = localAppData() + L"\\Finobe";
    std::wstring versionsDir = root + L"\\Versions";
    std::wstring setup = std::wstring(L"https://prod1-setup.") + kDomain;
    wchar_t over[512];   // FINOBE_SETUP_URL overrides the setup server (testing)
    if (GetEnvironmentVariableW(L"FINOBE_SETUP_URL", over, 512)) setup = over;

    setStatus(L"Connecting to Finobe...");

    // self-update: fetch the newer launcher and hand it the same link (it then installs itself over this one)
    if (!GetEnvironmentVariableW(L"FINOBE_LAUNCHER_UPDATED", nullptr, 0)) {
        std::string buildRaw; std::wstring e;
        if (httpGetString(setup + L"/launcher-build", buildRaw, e) && _wtoi(trim(widen(buildRaw)).c_str()) > kLauncherBuild) {
            setStatus(L"Updating Finobe...");
            makeDirs(root);
            std::wstring fresh = root + L"\\FinobeLauncher.update.exe";
            if (httpDownload(setup + L"/FinobeLauncher.exe", fresh, e)) {
                SetEnvironmentVariableW(L"FINOBE_LAUNCHER_UPDATED", L"1");   // the new one doesn't check again
                std::wstring cmd = L"\"" + fresh + L"\"" + (gLaunchUri.empty() ? L"" : L" \"" + gLaunchUri + L"\"");
                std::vector<wchar_t> buf(cmd.begin(), cmd.end()); buf.push_back(0);
                STARTUPINFOW si = { sizeof si }; PROCESS_INFORMATION pi;
                if (CreateProcessW(fresh.c_str(), buf.data(), nullptr, nullptr, FALSE, 0, nullptr, root.c_str(), &si, &pi)) {
                    CloseHandle(pi.hThread); CloseHandle(pi.hProcess);
                    PostMessageW(gWnd, WM_APP_DONE, 0, 0);
                    return 0;
                }
                SetEnvironmentVariableW(L"FINOBE_LAUNCHER_UPDATED", nullptr);
            }
            setStatus(L"Connecting to Finobe...");   // couldn't update: carry on with this one
        }
    }
    // which year's client: from the link, else ask the site which one the place uses
    if (args.present && !isYear(args.year) && !args.placeLauncher.empty()) {
        std::wstring placeId = queryParam(args.placeLauncher, L"placeId");
        std::string y; std::wstring e;
        if (!placeId.empty() && placeId.find_first_not_of(L"0123456789") == std::wstring::npos
            && httpGetString(std::wstring(L"https://assetgame.") + kDomain + L"/game/clientversion.ashx?placeId=" + placeId, y, e))
            args.year = trim(widen(y));
    }
    if (!isYear(args.year)) args.year = L"2016";
    if (args.year != L"2016") versionsDir += L"\\" + args.year;

    std::string versionRaw;
    if (!httpGetString(setup + L"/version" + (args.year == L"2016" ? L"" : L"?year=" + args.year), versionRaw, err)) { PostMessageW(gWnd, WM_APP_FAIL, 0, (LPARAM) new std::wstring(err)); return 0; }
    std::wstring version = trim(widen(versionRaw));
    if (version.rfind(L"version-", 0) != 0 || version.find_first_of(L"\\/:") != std::wstring::npos) {
        PostMessageW(gWnd, WM_APP_FAIL, 0, (LPARAM) new std::wstring(L"The server sent a bad version."));
        return 0;
    }

    setStatus(L"Checking for updates...");
    std::wstring dir = versionsDir + L"\\" + version;
    bool installed = fileExists(dir + L"\\" + clientExe(args.year)) && fileExists(dir + L"\\FinobeInstalled.txt");

    if (!installed) {
        WIN32_FIND_DATAW fd;
        HANDLE h = FindFirstFileW((versionsDir + L"\\version-*").c_str(), &fd);
        bool hadOld = h != INVALID_HANDLE_VALUE;
        if (hadOld) FindClose(h);

        makeDirs(dir);
        makeDirs(versionsDir);
        std::wstring zip = root + L"\\" + version + L"-FinobePlayer.zip";
        setStatus(hadOld ? L"Upgrading Finobe..." : L"Downloading Finobe...");
        setProgress(0);
        if (!httpDownload(setup + L"/" + version + L"-FinobePlayer.zip", zip, err)) {
            DeleteFileW(zip.c_str());
            if (!cancelled()) PostMessageW(gWnd, WM_APP_FAIL, 0, (LPARAM) new std::wstring(err));
            return 0;
        }

        setStatus(L"Installing Finobe...");
        setProgress(0);
        bool ok = unzipTo(zip, dir, err);
        DeleteFileW(zip.c_str());
        if (!ok) { if (!cancelled()) PostMessageW(gWnd, WM_APP_FAIL, 0, (LPARAM) new std::wstring(err)); return 0; }

        FILE* f = _wfopen((dir + L"\\FinobeInstalled.txt").c_str(), L"w");
        if (f) { fputs("ok\n", f); fclose(f); }
        removeOtherVersions(versionsDir, version);
    }

    setStatus(L"Configuring Finobe...");
    setProgress(-1);
    wchar_t self[MAX_PATH]; GetModuleFileNameW(nullptr, self, MAX_PATH);
    std::wstring launcher = root + L"\\FinobeLauncher.exe";
    if (_wcsicmp(self, launcher.c_str()) != 0) CopyFileW(self, launcher.c_str(), FALSE);
    registerProtocol(launcher);

    if (args.present && args.mode == L"play" && !args.token.empty() && !args.placeLauncher.empty()) {
        setStatus(L"Starting Finobe...");
        clearHttpCacheIfUpdated();
        if (!launchPlayer(dir, args, err)) { PostMessageW(gWnd, WM_APP_FAIL, 0, (LPARAM) new std::wstring(err)); return 0; }
        Sleep(1500);
    } else {
        setStatus(L"Finobe is installed! Press Play on a game to join.");
        setProgress(1000);   // done: a full bar, not the "working" animation
        Sleep(2500);
    }
    PostMessageW(gWnd, WM_APP_DONE, 0, 0);
    return 0;
}

// ---------------------------------------------------------------- drawing

static Gdiplus::Image* loadPngResource(int id)
{
    HRSRC r = FindResourceW(gInst, MAKEINTRESOURCEW(id), RT_RCDATA);
    if (!r) return nullptr;
    DWORD size = SizeofResource(gInst, r);
    void* data = LockResource(LoadResource(gInst, r));
    HGLOBAL mem = GlobalAlloc(GMEM_MOVEABLE, size);
    memcpy(GlobalLock(mem), data, size); GlobalUnlock(mem);
    IStream* stream = nullptr;
    if (CreateStreamOnHGlobal(mem, TRUE, &stream) != S_OK) return nullptr;
    Gdiplus::Image* img = Gdiplus::Image::FromStream(stream);
    stream->Release();
    return img;
}

static void paint(HDC hdc)
{
    using namespace Gdiplus;
    Bitmap back(kWidth, kHeight, PixelFormat32bppARGB);
    Graphics g(&back);
    g.SetSmoothingMode(SmoothingModeAntiAlias);
    g.SetInterpolationMode(InterpolationModeHighQualityBicubic);
    g.SetTextRenderingHint(TextRenderingHintClearTypeGridFit);
    g.Clear(Color(255, 255, 255, 255));

    // thin border so the white window doesn't blend into a white desktop
    Pen border(Color(255, 205, 205, 205), 1);
    g.DrawRectangle(&border, 0, 0, kWidth - 1, kHeight - 1);

    // spinning logo
    if (gLogo) {
        const float size = 96, cx = kWidth / 2.0f, cy = 92;
        g.TranslateTransform(cx, cy);
        g.RotateTransform(gAngle);
        g.DrawImage(gLogo, -size / 2, -size / 2, size, size);
        g.ResetTransform();
    }

    FontFamily segoe(L"Segoe UI");   // fall back if the font isn't installed
    const FontFamily* family = segoe.GetLastStatus() == Ok ? &segoe : FontFamily::GenericSansSerif();
    StringFormat center; center.SetAlignment(StringAlignmentCenter); center.SetLineAlignment(StringAlignmentCenter);

    Font statusFont(family, 15, FontStyleRegular, UnitPixel);
    SolidBrush dark(Color(255, 57, 59, 61));
    g.DrawString(gStatus.c_str(), -1, &statusFont, RectF(10, 152, kWidth - 20, 26), &center, &dark);

    if (!gSubStatus.empty()) {
        Font small(family, 12, FontStyleRegular, UnitPixel);
        SolidBrush grey(Color(255, 117, 117, 117));
        g.DrawString(gSubStatus.c_str(), -1, &small, RectF(10, 176, kWidth - 20, 20), &center, &grey);
    }

    // progress bar
    const float bx = 40, by = 202, bw = kWidth - 80, bh = 18;
    SolidBrush track(Color(255, 227, 227, 227));
    g.FillRectangle(&track, bx, by, bw, bh);
    if (gProgress >= 0) {
        float w = bw * gProgress / 1000.0f;
        if (w > 0) {
            SolidBrush fill(Color(255, 10, 176, 58));   // plain green, same as the sliding block
            g.FillRectangle(&fill, bx, by, w, bh);
        }
    } else {
        // indeterminate: a green block sliding along the track
        float t = fmodf(GetTickCount() / 1400.0f, 1.0f), seg = bw * 0.25f, x = bx - seg + (bw + seg) * t;
        float x0 = x < bx ? bx : x, x1 = x + seg > bx + bw ? bx + bw : x + seg;
        if (x1 > x0) {
            SolidBrush fill(Color(255, 10, 176, 58));
            g.FillRectangle(&fill, x0, by, x1 - x0, bh);
        }
    }

    // Cancel button (square corners, crisp 1px border)
    RECT& c = gCancelRect;
    g.SetSmoothingMode(SmoothingModeNone);
    SolidBrush btnFill(gCancelDown ? Color(255, 225, 225, 225) : gCancelHover ? Color(255, 242, 242, 242) : Color(255, 255, 255, 255));
    g.FillRectangle(&btnFill, (INT) c.left, (INT) c.top, (INT) (c.right - c.left), (INT) (c.bottom - c.top));
    Pen btnBorder(Color(255, 170, 170, 170), 1);
    g.DrawRectangle(&btnBorder, (INT) c.left, (INT) c.top, (INT) (c.right - c.left - 1), (INT) (c.bottom - c.top - 1));
    g.SetSmoothingMode(SmoothingModeAntiAlias);
    Font btnFont(family, 15, FontStyleBold, UnitPixel);
    SolidBrush btnText(Color(255, 96, 97, 98));
    g.DrawString(L"Cancel", -1, &btnFont, RectF((float) c.left, (float) c.top, (float) (c.right - c.left), (float) (c.bottom - c.top)), &center, &btnText);

    Graphics screen(hdc);
    screen.DrawImage(&back, 0, 0);
}

// ---------------------------------------------------------------- window

static LRESULT CALLBACK wndProc(HWND h, UINT msg, WPARAM wp, LPARAM lp)
{
    switch (msg) {
    case WM_TIMER:
        gAngle += 4.0f;
        if (gAngle >= 360) gAngle -= 360;
        // after 20s on one slow step, reassure the user
        if (gStepStarted && GetTickCount() - gStepStarted > 20000 && gSubStatus.empty())
            gSubStatus = L"This is taking a while, hang tight!";
        InvalidateRect(h, nullptr, FALSE);
        return 0;
    case WM_ERASEBKGND:
        return 1;
    case WM_PAINT: {
        PAINTSTRUCT ps; HDC dc = BeginPaint(h, &ps);
        paint(dc);
        EndPaint(h, &ps);
        return 0;
    }
    case WM_APP_STATUS: {
        std::wstring* s = (std::wstring*) lp;
        gStatus = *s; delete s;
        gSubStatus.clear();
        bool slow = gStatus.find(L"Downloading") == 0 || gStatus.find(L"Upgrading") == 0 || gStatus.find(L"Installing") == 0;
        gStepStarted = slow ? GetTickCount() : 0;
        return 0;
    }
    case WM_APP_PROGRESS:
        gProgress = (int) wp;
        return 0;
    case WM_APP_FAIL: {
        std::wstring* s = (std::wstring*) lp;
        MessageBoxW(h, s->c_str(), L"Finobe", MB_ICONERROR | MB_OK);
        delete s;
        DestroyWindow(h);
        return 0;
    }
    case WM_APP_DONE:
        DestroyWindow(h);
        return 0;
    case WM_MOUSEMOVE: {
        POINT p = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
        bool hover = PtInRect(&gCancelRect, p) != 0;
        if (hover != gCancelHover) { gCancelHover = hover; InvalidateRect(h, nullptr, FALSE); }
        TRACKMOUSEEVENT t = { sizeof t, TME_LEAVE, h, 0 }; TrackMouseEvent(&t);
        return 0;
    }
    case WM_MOUSELEAVE:
        gCancelHover = gCancelDown = false;
        return 0;
    case WM_LBUTTONDOWN: {
        POINT p = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
        if (PtInRect(&gCancelRect, p)) { gCancelDown = true; SetCapture(h); }
        else { ReleaseCapture(); SendMessageW(h, WM_NCLBUTTONDOWN, HTCAPTION, 0); }   // drag the window
        return 0;
    }
    case WM_LBUTTONUP: {
        POINT p = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
        if (gCancelDown) {
            gCancelDown = false; ReleaseCapture();
            if (PtInRect(&gCancelRect, p)) { InterlockedExchange(&gCancelled, 1); DestroyWindow(h); }
        }
        return 0;
    }
    case WM_KEYDOWN:
        if (wp == VK_ESCAPE) { InterlockedExchange(&gCancelled, 1); DestroyWindow(h); }
        return 0;
    case WM_DESTROY:
        PostQuitMessage(0);
        return 0;
    }
    return DefWindowProcW(h, msg, wp, lp);
}

int WINAPI wWinMain(HINSTANCE inst, HINSTANCE, LPWSTR, int show)
{
    gInst = inst;
    SetProcessDPIAware();

    int argc = 0;
    LPWSTR* argv = CommandLineToArgvW(GetCommandLineW(), &argc);
    for (int i = 1; i < argc; i++)
        if (_wcsnicmp(argv[i], kProtocol, wcslen(kProtocol)) == 0) gLaunchUri = argv[i];
    LocalFree(argv);

    Gdiplus::GdiplusStartupInput gsi; ULONG_PTR token;
    Gdiplus::GdiplusStartup(&token, &gsi, nullptr);
    gLogo = loadPngResource(IDR_LOGO);

    WNDCLASSEXW wc = { sizeof wc };
    wc.style = CS_DROPSHADOW;
    wc.lpfnWndProc = wndProc;
    wc.hInstance = inst;
    wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
    wc.hIcon = LoadIconW(inst, MAKEINTRESOURCEW(1));
    wc.lpszClassName = L"FinobeBootstrapper";
    RegisterClassExW(&wc);

    RECT wa; SystemParametersInfoW(SPI_GETWORKAREA, 0, &wa, 0);
    int x = wa.left + (wa.right - wa.left - kWidth) / 2, y = wa.top + (wa.bottom - wa.top - kHeight) / 2;
    gWnd = CreateWindowExW(WS_EX_APPWINDOW, wc.lpszClassName, kTitle, WS_POPUP | WS_SYSMENU | WS_MINIMIZEBOX,
                           x, y, kWidth, kHeight, nullptr, nullptr, inst, nullptr);
    ShowWindow(gWnd, show);
    UpdateWindow(gWnd);
    SetTimer(gWnd, 1, 16, nullptr);

    CloseHandle(CreateThread(nullptr, 0, worker, nullptr, 0, nullptr));

    MSG m;
    while (GetMessageW(&m, nullptr, 0, 0) > 0) { TranslateMessage(&m); DispatchMessageW(&m); }

    delete gLogo;
    Gdiplus::GdiplusShutdown(token);
    return 0;
}
