#include "util/HttpPlatformImpl.h"
#include "util/Http.h"
#include "FastLog.h"

#include <emscripten/fetch.h>
#include <sstream>
#include <stdio.h>
#include <vector>
#include <string>
#include <ctype.h>

LOGGROUP(Http)

namespace RBX
{
namespace Web
{
std::string proxyOrigin;
std::string proxyDomain;
}
}

static std::string rewriteUrl(const std::string& url)
{
    const std::string& origin = RBX::Web::proxyOrigin;
    const std::string& domain = RBX::Web::proxyDomain;
    if (origin.empty() || domain.empty())
        return url;
    size_t p = url.find("://");
    if (p == std::string::npos)
        return url;
    size_t hs = p + 3;
    size_t he = url.find_first_of("/?#:", hs);
    std::string host = url.substr(hs, he == std::string::npos ? std::string::npos : he - hs);
    for (size_t i = 0; i < host.size(); ++i)
        host[i] = tolower(host[i]);
    std::string suffix = "." + domain;
    if (host.size() <= suffix.size() || host.compare(host.size() - suffix.size(), std::string::npos, suffix) != 0)
        return url;
    std::string sub = host.substr(0, host.size() - suffix.size());
    std::string rest = he == std::string::npos ? "/" : url.substr(he);
    if (!rest.empty() && rest[0] == ':')
    {
        size_t slash = rest.find_first_of("/?#");
        rest = slash == std::string::npos ? "/" : rest.substr(slash);
    }
    if (rest.empty() || rest[0] != '/')
        rest = "/" + rest;
    return origin + "/web/" + sub + rest;
}

namespace RBX
{
namespace HttpPlatformImpl
{
namespace Cache
{
boost::filesystem::path cacheFilePath(const char* url)
{
    return boost::filesystem::path("/tmp/http") / (url ? url : "");
}

CacheResult CacheResult::open(const char*, const char*)
{
    return CacheResult(std::string("disabled"));
}

CacheResult CacheResult::update(const char*, const char*, const uint32_t, const Data&, const Data&)
{
    return CacheResult(std::string("disabled"));
}

void cleanCache(const CacheCleanOptions&)
{
}
}

static std::string proxyHost;
static long proxyPort = 0;

void init(Http::CookieSharingPolicy)
{
}

void setCookiesForDomain(const std::string&, const std::string&)
{
}

void getCookiesForDomain(const std::string&, std::string& cookies)
{
    cookies.clear();
}

boost::filesystem::path getRobloxCookieJarPath()
{
    return boost::filesystem::path("/tmp/cookies");
}

void setProxy(const std::string& host, long port)
{
    proxyHost = host;
    proxyPort = port;
}

void perform(HttpOptions& options, std::string& response)
{
    std::vector<std::string> kv;
    if (options.hdrContentType && !options.hdrContentType->empty())
    {
        kv.push_back("Content-Type");
        kv.push_back(*options.hdrContentType);
    }
    if (options.addlHeaders)
    {
        for (HttpAux::AdditionalHeaders::const_iterator it = options.addlHeaders->begin(); it != options.addlHeaders->end(); ++it)
        {
            kv.push_back(it->first);
            kv.push_back(it->second);
        }
    }
    kv.push_back("Requester");
    kv.push_back("Client");

    std::string body;
    if (options.postData)
    {
        std::ostringstream ss;
        ss << options.postData->rdbuf();
        body = ss.str();
        if (options.compressedPostData)
        {
            kv.push_back("Content-Encoding");
            kv.push_back("gzip");
        }
    }

    std::vector<const char*> hdrs;
    for (size_t i = 0; i < kv.size(); ++i)
        hdrs.push_back(kv[i].c_str());
    hdrs.push_back(NULL);

    emscripten_fetch_attr_t attr;
    emscripten_fetch_attr_init(&attr);
    strcpy(attr.requestMethod, options.postData ? "POST" : "GET");
    attr.attributes = EMSCRIPTEN_FETCH_LOAD_TO_MEMORY | EMSCRIPTEN_FETCH_SYNCHRONOUS | EMSCRIPTEN_FETCH_REPLACE;
    attr.requestHeaders = &hdrs[0];
    attr.withCredentials = !options.externalRequest;
    attr.timeoutMSecs = options.performTimeoutMillis > 0 ? (unsigned long)options.performTimeoutMillis : 0;
    if (options.postData)
    {
        attr.requestData = body.data();
        attr.requestDataSize = body.size();
    }

    std::string url = rewriteUrl(options.url);
    if (!RBX::Web::proxyOrigin.empty() && url.compare(0, RBX::Web::proxyOrigin.size(), RBX::Web::proxyOrigin) != 0)
        throw RBX::http_status_error(0, "blocked request to another site");
    FASTLOGS(FLog::Http, "web fetch %s", url.c_str());
    emscripten_fetch_t* f = emscripten_fetch(&attr, url.c_str());
    if (!f)
        throw RBX::http_status_error(0, "fetch failed to start");

    int status = f->status;
    std::string reason(f->statusText);
    response.assign(f->data ? f->data : "", f->data ? (size_t)f->numBytes : 0);
    emscripten_fetch_close(f);

    if (status == 0)
        throw RBX::http_status_error(0, "network error");
    if (status < 200 || status >= 300)
        throw RBX::http_status_error(status, reason);
}

}
}

#include <boost/filesystem.hpp>
#include <boost/thread/mutex.hpp>
#include <set>
#include <fstream>
#include <map>
#include <set>
#include <cctype>
#include <mutex>
#include <pthread.h>

namespace RBX
{
namespace Web
{
static boost::mutex missingLock;
static std::set<std::string> missing;

bool fetchContentFile(const std::string& relative, const std::string& target)
{
    if (proxyOrigin.empty() || relative.empty() || relative.find("..") != std::string::npos || relative[0] == '/')
        return false;
    {
        boost::mutex::scoped_lock lock(missingLock);
        if (missing.count(relative))
            return false;
    }

    std::string url = proxyOrigin + "/play/web/files/" + relative;
    emscripten_fetch_attr_t attr;
    emscripten_fetch_attr_init(&attr);
    strcpy(attr.requestMethod, "GET");
    attr.attributes = EMSCRIPTEN_FETCH_LOAD_TO_MEMORY | EMSCRIPTEN_FETCH_SYNCHRONOUS | EMSCRIPTEN_FETCH_REPLACE;
    emscripten_fetch_t* f = emscripten_fetch(&attr, url.c_str());
    if (!f)
        return false;

    bool ok = f->status == 200;
    if (ok)
    {
        boost::system::error_code ec;
        boost::filesystem::path p(target);
        boost::filesystem::create_directories(p.parent_path(), ec);
        char suffix[32];
        snprintf(suffix, sizeof(suffix), ".part%lu", (unsigned long)pthread_self());
        std::string tmp = target + suffix;
        {
            std::ofstream out(tmp.c_str(), std::ios::binary | std::ios::trunc);
            out.write(f->data, f->numBytes);
            ok = out.good();
        }
        if (ok)
            boost::filesystem::rename(tmp, target, ec);
        ok = ok && !ec;
    }
    else
    {
        boost::mutex::scoped_lock lock(missingLock);
        missing.insert(relative);
    }
    emscripten_fetch_close(f);
    return ok;
}

static std::string lowerPath(const std::string& s)
{
    std::string r(s);
    for (size_t i = 0; i < r.size(); ++i)
        r[i] = (char)tolower((unsigned char)r[i]);
    return r;
}

static void loadManifest(const char* path, std::set<std::string>& names, std::map<std::string, std::string>& folded)
{
    std::ifstream in(path);
    std::string line;
    while (std::getline(in, line))
        if (!line.empty())
        {
            names.insert(line);
            folded.insert(std::make_pair(lowerPath(line), line));
        }
}

bool fetchAssetFile(const std::string& name, const std::string& platformTarget, const std::string& contentTarget)
{
    static std::once_flag once;
    static std::set<std::string>* platformFiles = new std::set<std::string>();
    static std::map<std::string, std::string>* platformFolded = new std::map<std::string, std::string>();
    static std::set<std::string>* contentFiles = new std::set<std::string>();
    static std::map<std::string, std::string>* contentFolded = new std::map<std::string, std::string>();
    std::call_once(once, [] {
        loadManifest("/pcfiles.txt", *platformFiles, *platformFolded);
        loadManifest("/contentfiles.txt", *contentFiles, *contentFolded);
    });
    if (platformFiles->empty())
        return fetchContentFile("PlatformContent/pc/" + name, platformTarget) || fetchContentFile("content/" + name, contentTarget);
    if (platformFiles->count(name))
        return fetchContentFile("PlatformContent/pc/" + name, platformTarget);
    if (contentFiles->empty() || contentFiles->count(name))
        return fetchContentFile("content/" + name, contentTarget);
    std::string folded = lowerPath(name);
    std::map<std::string, std::string>::const_iterator it = platformFolded->find(folded);
    if (it != platformFolded->end())
        return fetchContentFile("PlatformContent/pc/" + it->second, platformTarget);
    it = contentFolded->find(folded);
    if (it != contentFolded->end())
        return fetchContentFile("content/" + it->second, contentTarget);
    return fetchContentFile("content/" + name, contentTarget);
}
}
}
