#include "ContextGL.h"
#include "HeadersGL.h"
#include "rbx/Debug.h"
#include "FastLog.h"

#include <emscripten/html5.h>
#include <stdexcept>

LOGGROUP(Graphics)

GLvoid *glMapBuffer(GLenum, GLenum)
{
    return NULL;
}

namespace RBX
{
namespace Graphics
{
class ContextGLWeb : public ContextGL
{
public:
    explicit ContextGLWeb(void* windowHandle)
        : selector(windowHandle ? static_cast<const char*>(windowHandle) : "#canvas")
    {
        EmscriptenWebGLContextAttributes attrs;
        emscripten_webgl_init_context_attributes(&attrs);
        attrs.alpha = EM_FALSE;
        attrs.depth = EM_TRUE;
        attrs.stencil = EM_TRUE;
        attrs.antialias = EM_FALSE;
        attrs.premultipliedAlpha = EM_FALSE;
        attrs.preserveDrawingBuffer = EM_FALSE;
        attrs.majorVersion = 2;
        attrs.minorVersion = 0;
        attrs.enableExtensionsByDefault = EM_TRUE;
        attrs.explicitSwapControl = EM_FALSE;
        attrs.renderViaOffscreenBackBuffer = EM_FALSE;

        context = emscripten_webgl_create_context(selector.c_str(), &attrs);
        if (context <= 0)
        {
            attrs.majorVersion = 1;
            context = emscripten_webgl_create_context(selector.c_str(), &attrs);
        }
        if (context <= 0)
            throw RBX::runtime_error("Error creating WebGL context (%d)", (int)context);

        emscripten_webgl_make_context_current(context);
        emscripten_webgl_get_drawing_buffer_size(context, &width, &height);
        FASTLOG2(FLog::Graphics, "WebGL context %dx%d", width, height);
    }

    ~ContextGLWeb()
    {
        if (context > 0)
            emscripten_webgl_destroy_context(context);
    }

    virtual void setCurrent()
    {
        emscripten_webgl_make_context_current(context);
    }

    virtual void swapBuffers()
    {
    }

    virtual unsigned int getMainFramebufferId()
    {
        return 0;
    }

    virtual bool isMainFramebufferRetina()
    {
        return false;
    }

    virtual std::pair<unsigned int, unsigned int> updateMainFramebuffer(unsigned int, unsigned int)
    {
        emscripten_webgl_get_drawing_buffer_size(context, &width, &height);
        return std::make_pair((unsigned int)width, (unsigned int)height);
    }

private:
    std::string selector;
    EMSCRIPTEN_WEBGL_CONTEXT_HANDLE context;
    int width;
    int height;
};

ContextGL* ContextGL::create(void* windowHandle)
{
    return new ContextGLWeb(windowHandle);
}
}
}
