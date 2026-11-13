#pragma once
#include "util/UserInputBase.h"
#include "v8datamodel/InputObject.h"
#include "rbx/signal.h"

#include <boost/thread/mutex.hpp>
#include <boost/unordered_map.hpp>
#include <deque>

namespace RBX
{
class Game;
class RunService;

class WebUserInput : public UserInputBase
{
public:
    struct Event
    {
        int kind;
        int a;
        int b;
        float x;
        float y;
        float dx;
        float dy;
    };

    WebUserInput(shared_ptr<Game> game);
    ~WebUserInput();

    void setGame(shared_ptr<Game> game);
    void push(const Event& e);

    Vector2 getCursorPosition();
    bool keyDown(KeyCode code) const;
    void setKeyState(KeyCode code, ModCode modCode, char modifiedKey, bool isDown);
    void centerCursor() {}

    static WebUserInput* current;

private:
    shared_ptr<Game> game;
    rbx::signals::scoped_connection steppedConnection;
    boost::mutex lock;
    std::deque<Event> queue;
    bool keys[512];
    int forcedKey;
    bool rightDown;
    Vector2 cursor;
    boost::unordered_map<int, shared_ptr<InputObject> > mouseObjects;

    void processInput();
    void sendEvent(shared_ptr<InputObject> event);
    void sendMouse(InputObject::UserInputType type, InputObject::UserInputState state, const Vector3& pos, const Vector3& delta);
    ModCode modCode() const;
};
}
