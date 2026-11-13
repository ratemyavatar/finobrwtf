#include "WebUserInput.h"
#include "v8datamodel/Game.h"
#include "v8datamodel/DataModel.h"
#include "v8datamodel/UserInputService.h"
#include "v8datamodel/UserController.h"
#include "v8datamodel/Workspace.h"
#include "v8datamodel/Camera.h"
#include "util/RunStateOwner.h"

#include <emscripten.h>
#include <emscripten/html5.h>
#include <string.h>

namespace RBX
{
WebUserInput* WebUserInput::current = NULL;

enum { EV_KEY = 1, EV_MOUSEBUTTON, EV_MOUSEMOVE, EV_WHEEL };

static int mapKey(const EmscriptenKeyboardEvent* e)
{
    int k = (int)e->keyCode;
    if (k >= 65 && k <= 90) return k + 32;
    if (k >= 48 && k <= 57) return k;
    if (k >= 112 && k <= 126) return 282 + (k - 112);
    if (k >= 96 && k <= 105) return 256 + (k - 96);
    switch (k)
    {
    case 8: return 8;
    case 9: return 9;
    case 13: return e->location == 3 ? 271 : 13;
    case 27: return 27;
    case 32: return 32;
    case 46: return 127;
    case 37: return 276;
    case 38: return 273;
    case 39: return 275;
    case 40: return 274;
    case 45: return 277;
    case 36: return 278;
    case 35: return 279;
    case 33: return 280;
    case 34: return 281;
    case 16: return e->location == 2 ? 303 : 304;
    case 17: return e->location == 2 ? 305 : 306;
    case 18: return e->location == 2 ? 307 : 308;
    case 20: return 301;
    case 186: return 59;
    case 187: return 61;
    case 188: return 44;
    case 189: return 45;
    case 190: return 46;
    case 191: return 47;
    case 192: return 96;
    case 219: return 91;
    case 220: return 92;
    case 221: return 93;
    case 222: return 39;
    case 106: return 268;
    case 107: return 270;
    case 109: return 269;
    case 110: return 266;
    case 111: return 267;
    }
    return 0;
}

static EM_BOOL onKey(int type, const EmscriptenKeyboardEvent* e, void*)
{
    if (!WebUserInput::current)
        return EM_FALSE;
    WebUserInput::Event ev;
    memset(&ev, 0, sizeof(ev));
    ev.kind = EV_KEY;
    ev.a = mapKey(e);
    ev.b = type == EMSCRIPTEN_EVENT_KEYDOWN ? 1 : 0;
    ev.x = (strlen(e->key) == 1) ? (float)(unsigned char)e->key[0] : 0.0f;
    if (ev.a == 0)
        return EM_FALSE;
    WebUserInput::current->push(ev);
    return (ev.a >= 282 && ev.a <= 293) ? EM_FALSE : EM_TRUE;
}

static EM_BOOL onMouse(int type, const EmscriptenMouseEvent* e, void*)
{
    if (!WebUserInput::current)
        return EM_FALSE;
    double dpr = emscripten_get_device_pixel_ratio();
    WebUserInput::Event ev;
    memset(&ev, 0, sizeof(ev));
    ev.x = (float)(e->targetX * dpr);
    ev.y = (float)(e->targetY * dpr);
    ev.dx = (float)e->movementX;
    ev.dy = (float)e->movementY;
    if (type == EMSCRIPTEN_EVENT_MOUSEMOVE)
        ev.kind = EV_MOUSEMOVE;
    else
    {
        ev.kind = EV_MOUSEBUTTON;
        ev.a = e->button;
        ev.b = type == EMSCRIPTEN_EVENT_MOUSEDOWN ? 1 : 0;
    }
    WebUserInput::current->push(ev);
    return EM_TRUE;
}

static EM_BOOL onWheel(int, const EmscriptenWheelEvent* e, void*)
{
    if (!WebUserInput::current)
        return EM_FALSE;
    WebUserInput::Event ev;
    memset(&ev, 0, sizeof(ev));
    ev.kind = EV_WHEEL;
    ev.dy = e->deltaY > 0 ? -1.0f : (e->deltaY < 0 ? 1.0f : 0.0f);
    WebUserInput::current->push(ev);
    return EM_TRUE;
}

WebUserInput::WebUserInput(shared_ptr<Game> game)
    : forcedKey(0), rightDown(false)
{
    memset(keys, 0, sizeof(keys));
    current = this;
    const char* t = "#canvas";
    emscripten_set_keydown_callback_on_thread(EMSCRIPTEN_EVENT_TARGET_WINDOW, NULL, EM_TRUE, onKey, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    emscripten_set_keyup_callback_on_thread(EMSCRIPTEN_EVENT_TARGET_WINDOW, NULL, EM_TRUE, onKey, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    emscripten_set_mousedown_callback_on_thread(t, NULL, EM_TRUE, onMouse, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    emscripten_set_mouseup_callback_on_thread(EMSCRIPTEN_EVENT_TARGET_WINDOW, NULL, EM_TRUE, onMouse, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    emscripten_set_mousemove_callback_on_thread(t, NULL, EM_TRUE, onMouse, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    emscripten_set_wheel_callback_on_thread(t, NULL, EM_TRUE, onWheel, EM_CALLBACK_THREAD_CONTEXT_MAIN_BROWSER_THREAD);
    setGame(game);
}

WebUserInput::~WebUserInput()
{
    if (current == this)
        current = NULL;
}

void WebUserInput::setGame(shared_ptr<Game> g)
{
    game = g;
    steppedConnection.disconnect();
    if (!game)
        return;
    shared_ptr<DataModel> dm = game->getDataModel();
    RunService* runService = ServiceProvider::create<RunService>(dm.get());
    steppedConnection = runService->earlyRenderSignal.connect(boost::bind(&WebUserInput::processInput, this));
    ControllerService* service = ServiceProvider::create<ControllerService>(dm.get());
    service->setHardwareDevice(this);
}

void WebUserInput::push(const Event& e)
{
    boost::mutex::scoped_lock l(lock);
    if (queue.size() < 512)
        queue.push_back(e);
}

Vector2 WebUserInput::getCursorPosition()
{
    return cursor;
}

bool WebUserInput::keyDown(KeyCode code) const
{
    if (forcedKey && code == forcedKey)
        return true;
    return code > 0 && code < 512 && keys[code];
}

void WebUserInput::setKeyState(KeyCode code, ModCode, char, bool isDown)
{
    forcedKey = isDown ? code : 0;
}

ModCode WebUserInput::modCode() const
{
    int m = 0;
    if (keys[SDLK_LSHIFT]) m |= KMOD_LSHIFT;
    if (keys[SDLK_RSHIFT]) m |= KMOD_RSHIFT;
    if (keys[SDLK_LCTRL]) m |= KMOD_LCTRL;
    if (keys[SDLK_RCTRL]) m |= KMOD_RCTRL;
    if (keys[SDLK_LALT]) m |= KMOD_LALT;
    if (keys[SDLK_RALT]) m |= KMOD_RALT;
    return (ModCode)m;
}

void WebUserInput::sendEvent(shared_ptr<InputObject> event)
{
    if (UserInputService* uis = ServiceProvider::find<UserInputService>(game->getDataModel().get()))
        uis->fireInputEvent(event, NULL);
}

void WebUserInput::sendMouse(InputObject::UserInputType type, InputObject::UserInputState state, const Vector3& pos, const Vector3& delta)
{
    shared_ptr<InputObject>& obj = mouseObjects[(int)type];
    if (!obj)
        obj = Creatable<Instance>::create<InputObject>(type, state, pos, delta, game->getDataModel().get());
    else
    {
        obj->setInputState(state);
        obj->setPosition(pos);
        obj->setDelta(delta);
    }
    sendEvent(obj);
}

void WebUserInput::processInput()
{
    if (!game)
        return;
    std::deque<Event> events;
    {
        boost::mutex::scoped_lock l(lock);
        events.swap(queue);
    }
    shared_ptr<DataModel> dm = game->getDataModel();
    UserInputService* uis = ServiceProvider::find<UserInputService>(dm.get());
    bool wantsLock = false;
    if (uis)
    {
        UserInputService::WrapMode mode = uis->getMouseWrapMode();
        wantsLock = mode == UserInputService::WRAP_CENTER || mode == UserInputService::WRAP_NONEANDCENTER;
    }
    for (size_t i = 0; i < events.size(); ++i)
    {
        const Event& e = events[i];
        switch (e.kind)
        {
        case EV_KEY:
        {
            KeyCode code = (KeyCode)e.a;
            if (e.a > 0 && e.a < 512)
                keys[e.a] = e.b != 0;
            ModCode mod = modCode();
            char ch = e.x > 0 ? (char)(int)e.x : UserInputService::getModifiedKey(code, mod);
            if (uis)
                uis->setKeyState(code, mod, ch, e.b != 0);
            sendEvent(Creatable<Instance>::create<InputObject>(InputObject::TYPE_KEYBOARD,
                e.b ? InputObject::INPUT_STATE_BEGIN : InputObject::INPUT_STATE_END, code, mod, ch, dm.get()));
            break;
        }
        case EV_MOUSEBUTTON:
        {
            InputObject::UserInputType type = e.a == 0 ? InputObject::TYPE_MOUSEBUTTON1 : (e.a == 2 ? InputObject::TYPE_MOUSEBUTTON2 : InputObject::TYPE_MOUSEBUTTON3);
            if (type == InputObject::TYPE_MOUSEBUTTON2)
                rightDown = e.b != 0;
            if (!wantsLock)
                cursor = Vector2(e.x, e.y);
            sendMouse(type, e.b ? InputObject::INPUT_STATE_BEGIN : InputObject::INPUT_STATE_END, Vector3(cursor, 0), Vector3(0, 0, 0));
            break;
        }
        case EV_MOUSEMOVE:
        {
            if (!wantsLock)
                cursor = Vector2(e.x, e.y);
            Vector3 delta(e.dx, e.dy, 0);
            if (wantsLock || rightDown)
                sendMouse(InputObject::TYPE_MOUSEDELTA, InputObject::INPUT_STATE_CHANGE, Vector3(cursor, 0), delta);
            sendMouse(InputObject::TYPE_MOUSEMOVEMENT, InputObject::INPUT_STATE_CHANGE, Vector3(cursor, 0), delta);
            break;
        }
        case EV_WHEEL:
            sendMouse(InputObject::TYPE_MOUSEWHEEL, InputObject::INPUT_STATE_CHANGE, Vector3(cursor, e.dy), Vector3(0, 0, 0));
            break;
        }
    }
    static bool locked = false;
    if (wantsLock != locked)
    {
        locked = wantsLock;
        if (locked)
            MAIN_THREAD_ASYNC_EM_ASM({ var c = document.getElementById('canvas'); if (c && c.requestPointerLock) c.requestPointerLock(); });
        else
            MAIN_THREAD_ASYNC_EM_ASM({ if (document.exitPointerLock) document.exitPointerLock(); });
    }
}
}
