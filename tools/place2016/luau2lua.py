#!/usr/bin/env python3
import re
import sys

KEYWORDS = {'and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function', 'if', 'in',
            'local', 'nil', 'not', 'or', 'repeat', 'return', 'then', 'true', 'until', 'while'}

SYMBOLS = ['...', '..=', '//=', '::', '->', '==', '~=', '<=', '>=', '..', '+=', '-=', '*=', '/=', '%=', '^=', '//',
           '+', '-', '*', '/', '%', '^', '#', '<', '>', '=', '(', ')', '{', '}', '[', ']', ';', ':', ',', '.', '?', '|', '&', '@']

RISKY_READS = set('''AnchorPoint TextSize AutomaticSize RichText MaxVisibleGraphemes LayoutOrder AbsoluteContentSize Padding
FillDirection SortOrder HorizontalAlignment VerticalAlignment CellSize CellPadding StartCorner FillDirectionMaxCells
CornerRadius Thickness ApplyStrokeMode LineJoinMode AspectRatio AspectType DominantAxis MinSize MaxSize Scale Offset
PaddingTop PaddingBottom PaddingLeft PaddingRight Triggered PromptShown PromptHidden TriggerEnded TriggerStarted
ActionText ObjectText HoldDuration KeyboardKeyCode MaxActivationDistance RequiresLineOfSight Orientation Massless CanTouch
CanQuery CastShadow AssemblyLinearVelocity AssemblyAngularVelocity AssemblyMass CollisionGroup WorldPivot Connected
ClassName Enabled IgnoreGuiInset ResetOnSpawn ZIndexBehavior DisplayOrder PlaybackSpeed UseJumpPower JumpHeight
DisplayName Attachment0 Attachment1 Width0 Width1 FaceCamera LightEmission TextureSpeed TextureLength Segments
CurveSize0 CurveSize1 Lifetime MinLength Responsiveness MaxForce VectorVelocity LineVelocity PlaneVelocity
MaxVelocity RelativeTo VelocityConstraintMode ActuatorType AngularVelocity Length Restitution WinchEnabled
TextTruncate LineHeight FontFace Interactable Selectable SelectionImageObject AutoButtonColor GroupTransparency
GroupColor3 Pivot Brightness Range Shadows HipHeight RootPart MoveDirection FloorMaterial AutoRotate
EvaluateStateMachine RequiresNeck BreakJointsOnDeath AutoJumpEnabled CameraMaxZoomDistance CameraMinZoomDistance
DevComputerMovementMode DevTouchMovementMode CharacterAppearanceId MembershipType AccountAge FollowUserId
LocaleId Team TeamColor Neutral SimulationRadius ReplicationFocus Destroying Archivable AbsoluteRotation
GuiState Transparency Color Size Position Rotation Visible Text Value'''.split())

COMPAT_GLOBALS = ['task', 'typeof', 'Random', 'TweenInfo', 'RaycastParams', 'OverlapParams', 'utf8', 'bit32',
                  'Color3', 'CFrame', 'UDim2', 'UDim', 'Vector3', 'Vector2', 'math', 'table', 'string', 'os', 'Enum',
                  'Instance', 'DateTime', 'NumberRange', 'Font', 'warn', 'Rect', 'PhysicalProperties', 'debug', 'ColorSequence', 'NumberSequence']


class Token:
    __slots__ = ('kind', 'value', 'line', 'parts')

    def __init__(self, kind, value, line, parts=None):
        self.kind = kind
        self.value = value
        self.line = line
        self.parts = parts

    def __repr__(self):
        return f'{self.kind}:{self.value!r}@{self.line}'


class LexError(Exception):
    pass


def long_bracket(src, i):
    m = re.match(r'\[(=*)\[', src[i:])
    if not m:
        return None
    eq = m.group(1)
    close = ']' + eq + ']'
    j = src.find(close, i + len(m.group(0)))
    if j < 0:
        raise LexError('unfinished long bracket')
    return src[i:j + len(close)], j + len(close)


def lex(src, i=0, line=1, stop_at_brace=False):
    tokens = []
    depth = 0
    n = len(src)
    while i < n:
        c = src[i]
        if c == '\n':
            line += 1
            i += 1
            continue
        if c in ' \t\r\f\v':
            i += 1
            continue
        if src.startswith('--', i):
            lb = long_bracket(src, i + 2)
            if lb:
                text, end = lb
                line += text.count('\n')
                i = end
            else:
                j = src.find('\n', i)
                i = n if j < 0 else j
            continue
        if c.isalpha() or c == '_':
            m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', src[i:])
            word = m.group(0)
            tokens.append(Token('kw' if word in KEYWORDS else 'name', word, line))
            i += len(word)
            continue
        if c.isdigit() or (c == '.' and i + 1 < n and src[i + 1].isdigit()):
            m = re.match(r'0[xX][0-9a-fA-F_]+|0[bB][01_]+|(\d[\d_]*\.?[\d_]*|\.\d[\d_]*)([eE][+-]?\d+)?', src[i:])
            text = m.group(0)
            tokens.append(Token('number', text, line))
            i += len(text)
            continue
        if c in '"\'':
            j = i + 1
            while j < n and src[j] != c:
                if src[j] == '\\':
                    if src[j + 1] == 'z':
                        j += 2
                        while j < n and src[j] in ' \t\r\n':
                            j += 1
                        continue
                    j += 2
                    continue
                if src[j] == '\n':
                    raise LexError(f'unfinished string on line {line}')
                j += 1
            text = src[i:j + 1]
            line_after = line + text.count('\n')
            tokens.append(Token('string', text, line))
            line = line_after
            i = j + 1
            continue
        if c == '[':
            lb = long_bracket(src, i)
            if lb:
                text, end = lb
                tokens.append(Token('string', text, line))
                line += text.count('\n')
                i = end
                continue
        if c == '`':
            parts = []
            start_line = line
            j = i + 1
            buf = ''
            while True:
                if j >= n:
                    raise LexError('unfinished interpolated string')
                ch = src[j]
                if ch == '`':
                    parts.append(('s', buf))
                    j += 1
                    break
                if ch == '\\':
                    buf += src[j:j + 2]
                    j += 2
                    continue
                if ch == '{':
                    parts.append(('s', buf))
                    buf = ''
                    inner, j, line = lex(src, j + 1, line, stop_at_brace=True)
                    parts.append(('e', inner))
                    continue
                if ch == '\n':
                    line += 1
                buf += ch
                j += 1
            tokens.append(Token('interp', None, start_line, parts))
            i = j
            continue
        if stop_at_brace:
            if c == '{':
                depth += 1
            elif c == '}':
                if depth == 0:
                    tokens.append(Token('eof', None, line))
                    return tokens, i + 1, line
                depth -= 1
        for s in SYMBOLS:
            if src.startswith(s, i):
                tokens.append(Token('op', s, line))
                i += len(s)
                break
        else:
            raise LexError(f'unexpected character {c!r} on line {line}')
    tokens.append(Token('eof', None, line))
    if stop_at_brace:
        raise LexError('unfinished interpolation')
    return tokens, i, line


class ParseError(Exception):
    pass


class Node(dict):
    def __getattr__(self, k):
        try:
            return self[k]
        except KeyError:
            raise AttributeError(k)


def N(kind, line, **kw):
    n = Node(kw)
    n['k'] = kind
    n['line'] = line
    return n


BINPRI = {'or': (1, 1), 'and': (2, 2), '<': (3, 3), '>': (3, 3), '<=': (3, 3), '>=': (3, 3), '~=': (3, 3), '==': (3, 3),
          '..': (5, 4), '+': (6, 6), '-': (6, 6), '*': (7, 7), '/': (7, 7), '//': (7, 7), '%': (7, 7), '^': (10, 9)}
UNARY_PRI = 8


class Parser:
    def __init__(self, tokens):
        self.t = tokens
        self.p = 0

    def peek(self, o=0):
        return self.t[min(self.p + o, len(self.t) - 1)]

    def next(self):
        tok = self.t[self.p]
        self.p += 1
        return tok

    def check(self, value, o=0):
        tok = self.peek(o)
        return tok.kind in ('op', 'kw') and tok.value == value

    def accept(self, value):
        if self.check(value):
            return self.next()
        return None

    def expect(self, value):
        tok = self.next()
        if tok.kind not in ('op', 'kw') or tok.value != value:
            raise ParseError(f'expected {value!r} near {tok.value!r} on line {tok.line}')
        return tok

    def name(self):
        tok = self.next()
        if tok.kind != 'name':
            raise ParseError(f'expected a name near {tok.value!r} on line {tok.line}')
        return tok

    def skip_balanced(self, open_, close):
        depth = 0
        while True:
            tok = self.next()
            if tok.kind == 'eof':
                raise ParseError('unbalanced brackets in type')
            if tok.kind == 'op' and tok.value == open_:
                depth += 1
            elif tok.kind == 'op' and tok.value == close:
                depth -= 1
                if depth == 0:
                    return

    def skip_generic(self):
        depth = 0
        while True:
            tok = self.next()
            if tok.kind == 'eof':
                raise ParseError('unbalanced generic')
            if tok.kind == 'op' and tok.value == '<':
                depth += 1
            elif tok.kind == 'op' and tok.value == '>':
                depth -= 1
                if depth == 0:
                    return
            elif tok.kind == 'op' and tok.value == '>=':
                depth -= 1
                if depth == 0:
                    self.p -= 1
                    self.t[self.p] = Token('op', '=', tok.line)
                    return

    def skip_type(self):
        self.skip_simple_type()
        while True:
            if self.check('?'):
                self.next()
                continue
            if self.check('|') or self.check('&'):
                self.next()
                self.skip_simple_type()
                continue
            break

    def skip_simple_type(self):
        tok = self.peek()
        if self.check('|') or self.check('&'):
            self.next()
            tok = self.peek()
        if tok.kind == 'op' and tok.value == '<':
            self.skip_generic()
            tok = self.peek()
        if tok.kind == 'op' and tok.value == '(':
            self.skip_balanced('(', ')')
            if self.check('->'):
                self.next()
                self.skip_type()
            return
        if tok.kind == 'op' and tok.value == '{':
            self.skip_balanced('{', '}')
            return
        if tok.kind == 'op' and tok.value == '...':
            self.next()
            if self.peek().kind == 'name' or self.check('(') or self.check('{'):
                self.skip_type()
            return
        if tok.kind == 'name' and tok.value == 'typeof' and self.check('(', 1):
            self.next()
            self.skip_balanced('(', ')')
            return
        if tok.kind in ('name', 'string') or (tok.kind == 'kw' and tok.value in ('nil', 'true', 'false')):
            self.next()
            while self.check('.') and self.peek(1).kind == 'name':
                self.next()
                self.next()
            if self.check('<'):
                self.skip_generic()
            if self.check('...'):
                self.next()
            return
        raise ParseError(f'bad type near {tok.value!r} on line {tok.line}')

    def skip_attributes(self):
        while self.check('@'):
            self.next()
            self.name()

    def chunk(self):
        body = self.block()
        if self.peek().kind != 'eof':
            tok = self.peek()
            raise ParseError(f'unexpected {tok.value!r} on line {tok.line}')
        return body

    def block_end(self):
        tok = self.peek()
        return tok.kind == 'eof' or (tok.kind == 'kw' and tok.value in ('end', 'else', 'elseif', 'until'))

    def block(self):
        stats = []
        while not self.block_end():
            if self.check('return'):
                line = self.next().line
                exprs = []
                if not self.block_end() and not self.check(';'):
                    exprs = self.exprlist()
                self.accept(';')
                stats.append(N('return', line, exprs=exprs))
                break
            st = self.statement()
            if st is not None:
                stats.append(st)
        return stats

    def is_contextual(self, word):
        tok = self.peek()
        return tok.kind == 'name' and tok.value == word

    def statement(self):
        tok = self.peek()
        line = tok.line
        if self.accept(';'):
            return None
        self.skip_attributes()
        tok = self.peek()
        if tok.kind == 'kw':
            v = tok.value
            if v == 'if':
                self.next()
                clauses = []
                cond = self.expr()
                self.expect('then')
                clauses.append((cond, self.block()))
                orelse = None
                while True:
                    if self.accept('elseif'):
                        cond = self.expr()
                        self.expect('then')
                        clauses.append((cond, self.block()))
                    elif self.accept('else'):
                        orelse = self.block()
                        self.expect('end')
                        break
                    else:
                        self.expect('end')
                        break
                return N('if', line, clauses=clauses, orelse=orelse)
            if v == 'while':
                self.next()
                cond = self.expr()
                self.expect('do')
                body = self.block()
                self.expect('end')
                return N('while', line, cond=cond, body=body)
            if v == 'do':
                self.next()
                body = self.block()
                self.expect('end')
                return N('do', line, body=body)
            if v == 'for':
                self.next()
                n1 = self.name()
                if self.accept(':'):
                    self.skip_type()
                if self.accept('='):
                    start = self.expr()
                    self.expect(',')
                    stop = self.expr()
                    step = self.expr() if self.accept(',') else None
                    self.expect('do')
                    body = self.block()
                    self.expect('end')
                    return N('fornum', line, var=n1.value, start=start, stop=stop, step=step, body=body)
                names = [n1.value]
                while self.accept(','):
                    names.append(self.name().value)
                    if self.accept(':'):
                        self.skip_type()
                self.expect('in')
                exprs = self.exprlist()
                self.expect('do')
                body = self.block()
                self.expect('end')
                return N('forin', line, names=names, exprs=exprs, body=body)
            if v == 'repeat':
                self.next()
                body = self.block()
                self.expect('until')
                cond = self.expr()
                return N('repeat', line, body=body, cond=cond)
            if v == 'function':
                self.next()
                target = N('name', self.peek().line, name=self.name().value)
                method = None
                while self.check('.') or self.check(':'):
                    sep = self.next().value
                    key = self.name()
                    if sep == ':':
                        method = key.value
                        break
                    target = N('index', key.line, obj=target, key=N('str', key.line, raw=repr_str(key.value)), dot=key.value)
                func = self.funcbody(line, method is not None)
                if method is not None:
                    target = N('index', line, obj=target, key=N('str', line, raw=repr_str(method)), dot=method)
                return N('assign', line, targets=[target], exprs=[func], fn_stat=True)
            if v == 'local':
                self.next()
                self.skip_attributes()
                if self.accept('function'):
                    nm = self.name().value
                    func = self.funcbody(line, False)
                    return N('localfunc', line, name=nm, func=func)
                names = [self.name().value]
                if self.accept(':'):
                    self.skip_type()
                while self.accept(','):
                    names.append(self.name().value)
                    if self.accept(':'):
                        self.skip_type()
                exprs = self.exprlist() if self.accept('=') else []
                return N('local', line, names=names, exprs=exprs)
            if v == 'return':
                raise ParseError(f'return must be last on line {line}')
            if v == 'break':
                self.next()
                return N('break', line)
        if tok.kind == 'name' and tok.value == 'continue' and not (self.check('(', 1) or self.check('=', 1) or self.check('.', 1) or self.check(':', 1) or self.check('[', 1) or self.check(',', 1) or self.peek(1).kind in ('string', 'interp') or self.check('{', 1)):
            self.next()
            return N('continue', line)
        if tok.kind == 'name' and tok.value == 'export' and self.peek(1).kind == 'name' and self.peek(1).value == 'type':
            self.next()
            tok = self.peek()
        if tok.kind == 'name' and tok.value == 'type' and self.peek(1).kind == 'name' and (self.check('=', 2) or self.check('<', 2)):
            self.next()
            self.name()
            if self.check('<'):
                self.skip_generic()
            self.expect('=')
            self.skip_type()
            return None
        if tok.kind == 'name' and tok.value == 'type' and self.peek(1).kind == 'name' and self.peek(1).value == 'function':
            self.next()
            self.next()
            self.name()
            self.funcbody(line, False)
            return None
        expr = self.suffixedexp()
        if self.check('=') or self.check(','):
            targets = [expr]
            while self.accept(','):
                targets.append(self.suffixedexp())
            self.expect('=')
            exprs = self.exprlist()
            for t in targets:
                if t.k not in ('name', 'index'):
                    raise ParseError(f'cannot assign on line {line}')
            return N('assign', line, targets=targets, exprs=exprs)
        tok = self.peek()
        if tok.kind == 'op' and tok.value in ('+=', '-=', '*=', '/=', '%=', '^=', '..=', '//='):
            self.next()
            value = self.expr()
            return N('compound', line, target=expr, op=tok.value[:-1], value=value)
        if expr.k not in ('call', 'mcall'):
            raise ParseError(f'syntax error near {self.peek().value!r} on line {self.peek().line}')
        return N('exprstat', line, expr=expr)

    def funcbody(self, line, is_method):
        if self.check('<'):
            self.skip_generic()
        self.expect('(')
        params = []
        vararg = False
        if not self.check(')'):
            while True:
                if self.accept('...'):
                    vararg = True
                    if self.accept(':'):
                        self.skip_type()
                    break
                params.append(self.name().value)
                if self.accept(':'):
                    self.skip_type()
                if not self.accept(','):
                    break
        self.expect(')')
        if self.accept(':'):
            self.skip_type()
        body = self.block()
        end = self.expect('end')
        if is_method:
            params = ['self'] + params
        return N('function', line, params=params, vararg=vararg, body=body, end_line=end.line)

    def exprlist(self):
        exprs = [self.expr()]
        while self.accept(','):
            exprs.append(self.expr())
        return exprs

    def expr(self, limit=0):
        tok = self.peek()
        if (tok.kind == 'kw' and tok.value == 'not') or (tok.kind == 'op' and tok.value in ('-', '#')):
            self.next()
            operand = self.expr(UNARY_PRI)
            left = N('unop', tok.line, op=tok.value, operand=operand)
        else:
            left = self.simpleexp()
        while True:
            tok = self.peek()
            if tok.kind in ('op', 'kw') and tok.value in BINPRI and BINPRI[tok.value][0] > limit:
                self.next()
                right = self.expr(BINPRI[tok.value][1])
                left = N('binop', tok.line, op=tok.value, left=left, right=right)
                continue
            break
        return left

    def simpleexp(self):
        tok = self.peek()
        line = tok.line
        if tok.kind == 'number':
            self.next()
            e = N('num', line, raw=fix_number(tok.value))
        elif tok.kind == 'string':
            self.next()
            e = N('str', line, raw=tok.value)
        elif tok.kind == 'interp':
            self.next()
            e = N('interp', line, parts=[(k, v if k == 's' else Parser(v).sub_expr()) for k, v in tok.parts])
        elif tok.kind == 'kw' and tok.value in ('nil', 'true', 'false'):
            self.next()
            e = N('const', line, raw=tok.value)
        elif self.check('...'):
            self.next()
            e = N('vararg', line)
        elif self.check('{'):
            e = self.table()
        elif self.check('function'):
            self.next()
            e = self.funcbody(line, False)
        elif self.check('if'):
            self.next()
            clauses = []
            cond = self.expr()
            self.expect('then')
            clauses.append((cond, self.expr()))
            while self.accept('elseif'):
                cond = self.expr()
                self.expect('then')
                clauses.append((cond, self.expr()))
            self.expect('else')
            orelse = self.expr()
            e = N('ifexp', line, clauses=clauses, orelse=orelse)
        else:
            e = self.suffixedexp()
        while self.check('::'):
            self.next()
            self.skip_type()
        return e

    def sub_expr(self):
        e = self.expr()
        if self.peek().kind != 'eof':
            raise ParseError('bad interpolation')
        return e

    def primaryexp(self):
        tok = self.peek()
        if tok.kind == 'name':
            self.next()
            return N('name', tok.line, name=tok.value)
        if self.accept('('):
            e = self.expr()
            while self.check('::'):
                self.next()
                self.skip_type()
            self.expect(')')
            return N('paren', tok.line, expr=e)
        raise ParseError(f'unexpected {tok.value!r} on line {tok.line}')

    def suffixedexp(self):
        e = self.primaryexp()
        while True:
            tok = self.peek()
            if self.check('.'):
                self.next()
                key = self.name()
                e = N('index', key.line, obj=e, key=N('str', key.line, raw=repr_str(key.value)), dot=key.value)
            elif self.check('['):
                self.next()
                key = self.expr()
                self.expect(']')
                e = N('index', tok.line, obj=e, key=key, dot=None)
            elif self.check(':'):
                self.next()
                m = self.name()
                args = self.callargs()
                e = N('mcall', m.line, obj=e, method=m.value, args=args)
            elif self.check('(') or self.check('{') or tok.kind in ('string', 'interp'):
                if self.check('(') and tok.line != self.t[self.p - 1].line and False:
                    break
                args = self.callargs()
                e = N('call', tok.line, func=e, args=args)
            else:
                return e

    def callargs(self):
        tok = self.peek()
        if tok.kind == 'string':
            self.next()
            return [N('str', tok.line, raw=tok.value)]
        if tok.kind == 'interp':
            return [self.simpleexp()]
        if self.check('{'):
            return [self.table()]
        self.expect('(')
        if self.accept(')'):
            return []
        args = self.exprlist()
        self.expect(')')
        return args

    def table(self):
        line = self.expect('{').line
        items = []
        while not self.check('}'):
            if self.check('['):
                self.next()
                k = self.expr()
                self.expect(']')
                self.expect('=')
                items.append(('k', k, self.expr()))
            elif self.peek().kind == 'name' and self.check('=', 1):
                k = self.next()
                self.next()
                items.append(('n', k.value, self.expr()))
            else:
                items.append(('v', None, self.expr()))
            if not (self.accept(',') or self.accept(';')):
                break
        end = self.expect('}')
        return N('table', line, items=items, end_line=end.line)


SAFE_READS = {'Parent', 'Name', 'LocalPlayer', 'Character', 'X', 'Y', 'Z', 'Offset'}


def hook_read(e):
    if e.dot in SAFE_READS or not (e.dot[0].isupper() or e.dot in RISKY_READS):
        return False
    o = e.obj
    while o.k == 'index':
        o = o.obj
    return not (o.k == 'name' and o.name == 'Enum')


def repr_str(s):
    return '"' + s + '"'


def fix_number(text):
    t = text.replace('_', '')
    if t[:2] in ('0b', '0B'):
        return str(int(t[2:], 2))
    return t


def contains_continue(stats):
    for st in stats:
        if st is None:
            continue
        k = st.k
        if k == 'continue':
            return True
        if k == 'if':
            for _, b in st.clauses:
                if contains_continue(b):
                    return True
            if st.orelse and contains_continue(st.orelse):
                return True
        elif k == 'do':
            if contains_continue(st.body):
                return True
    return False


class Emitter:
    def __init__(self, hooks=True):
        self.out = []
        self.line = 1
        self.tmp = 0
        self.hooks = hooks
        self.loops = []
        self.vararg = [True]

    def at(self, line):
        if line is not None and line > self.line:
            self.out.append('\n' * (line - self.line))
            self.line = line

    def w(self, text, line=None):
        self.at(line)
        if not text:
            return
        if self.out and self.out[-1] and text:
            prev = self.out[-1][-1]
            if (prev.isalnum() or prev == '_') and (text[0].isalnum() or text[0] == '_'):
                self.out.append(' ')
            elif prev == '-' and text[0] == '-':
                self.out.append(' ')
            elif prev == '.' and (text[0] == '.' or text[0].isdigit()):
                self.out.append(' ')
            elif prev == '[' and text[0] in '[=':
                self.out.append(' ')
        self.out.append(text)
        self.line += text.count('\n')

    def new_tmp(self):
        self.tmp += 1
        return f'__t{self.tmp}'

    def block(self, stats):
        for st in stats:
            self.stat(st)

    def stat(self, st):
        k = st.k
        line = st.line
        if k == 'local':
            self.w('local', line)
            self.w(', '.join(st.names))
            if st.exprs:
                self.w('=')
                self.exprlist(st.exprs)
            self.w(';')
        elif k == 'localfunc':
            self.w('local function', line)
            self.w(st.name)
            self.funcrest(st.func)
            self.w(';')
        elif k == 'assign':
            self.assign(st)
        elif k == 'compound':
            self.compound(st)
        elif k == 'exprstat':
            self.expr(st.expr)
            self.w(';')
        elif k == 'do':
            self.w('do', line)
            self.block(st.body)
            self.w('end')
        elif k == 'if':
            for i, (cond, body) in enumerate(st.clauses):
                self.w('if' if i == 0 else 'elseif', cond.line if i else line)
                self.expr(cond)
                self.w('then')
                self.block(body)
            if st.orelse is not None:
                self.w('else')
                self.block(st.orelse)
            self.w('end')
        elif k == 'while':
            self.w('while', line)
            self.expr(st.cond)
            self.w('do')
            self.loop_body(st.body)
            self.w('end')
        elif k == 'repeat':
            self.w('repeat', line)
            self.loop_body(st.body, repeat_cond=st.cond)
        elif k == 'fornum':
            self.w('for', line)
            self.w(st.var + '=')
            self.expr(st.start)
            self.w(',')
            self.expr(st.stop)
            if st.step is not None:
                self.w(',')
                self.expr(st.step)
            self.w('do')
            self.loop_body(st.body)
            self.w('end')
        elif k == 'forin':
            self.w('for', line)
            self.w(', '.join(st.names))
            self.w('in')
            if len(st.exprs) == 1 and st.exprs[0].k != 'vararg' and self.hooks:
                self.w('__iter(')
                self.expr(st.exprs[0])
                self.w(')')
            else:
                self.exprlist(st.exprs)
            self.w('do')
            self.loop_body(st.body)
            self.w('end')
        elif k == 'return':
            self.w('return', line)
            if st.exprs:
                self.exprlist(st.exprs)
            self.w(';')
        elif k == 'break':
            if self.loops and self.loops[-1]:
                self.w(f'{self.loops[-1]} = true break', line)
            else:
                self.w('break', line)
        elif k == 'continue':
            if not self.loops or not self.loops[-1]:
                raise ParseError(f'continue outside a loop on line {line}')
            self.w('break', line)
        else:
            raise ParseError(f'unknown statement {k}')

    def loop_body(self, body, repeat_cond=None):
        if not contains_continue(body):
            self.loops.append(None)
            self.block(body)
            self.loops.pop()
            if repeat_cond is not None:
                self.w('until')
                self.expr(repeat_cond)
            return
        flag = self.new_tmp()
        self.w(f'local {flag} = false repeat')
        self.loops.append(flag)
        self.block(body)
        self.loops.pop()
        self.w(f'until true if {flag} then break end')
        if repeat_cond is not None:
            self.w('until')
            self.expr(repeat_cond)

    def funcrest(self, f):
        params = list(f.params)
        if f.vararg:
            params.append('...')
        self.w('(' + ', '.join(params) + ')')
        saved = self.loops
        self.loops = []
        self.vararg.append(f.vararg)
        self.block(f.body)
        self.vararg.pop()
        self.loops = saved
        self.w('end', f.end_line)

    def is_prop_key(self, target):
        return self.hooks and target.k == 'index' and target.dot and target.dot[0].isupper()

    def assign(self, st):
        line = st.line
        targets = st.targets
        hooked = [self.is_prop_key(t) and not st.get('fn_stat') for t in targets]
        if not any(hooked):
            self.at(line)
            for i, t in enumerate(targets):
                if i:
                    self.w(',')
                self.expr(t, as_target=True)
            self.w('=')
            self.exprlist(st.exprs)
            self.w(';')
            return
        if len(targets) == 1:
            t = targets[0]
            self.w('__s(', line)
            self.expr(t.obj)
            self.w(',')
            self.w(repr_str(t.dot))
            self.w(',')
            self.expr(st.exprs[0])
            for extra in st.exprs[1:]:
                self.w(',')
                self.expr(extra)
            self.w(');')
            return
        tmps = [self.new_tmp() for _ in targets]
        self.w('do local ' + ', '.join(tmps) + ' =', line)
        self.exprlist(st.exprs)
        self.w(';')
        for t, tmp, h in zip(targets, tmps, hooked):
            if h:
                self.w('__s(')
                self.expr(t.obj)
                self.w(',' + repr_str(t.dot) + ',' + tmp + ');')
            else:
                self.expr(t, as_target=True)
                self.w('=' + tmp + ';')
        self.w('end')

    def compound(self, st):
        t = st.target
        line = st.line
        op = st.op
        if t.k == 'name':
            self.w(t.name, line)
            self.w('=')
            self.binary(op, lambda: self.w(t.name), lambda: self.expr(st.value, wrap=True))
            self.w(';')
            return
        if t.k != 'index':
            raise ParseError(f'bad compound assignment on line {line}')
        obj, key = self.new_tmp(), self.new_tmp()
        self.w(f'do local {obj}, {key} =', line)
        self.expr(t.obj)
        self.w(',')
        self.expr(t.key)
        self.w(';')
        prop = self.is_prop_key(t)
        if prop:
            self.w(f'__s({obj}, {key},')
        else:
            self.w(f'{obj}[{key}] =')
        if prop and t.dot in RISKY_READS:
            self.binary(op, lambda: self.w(f'__g({obj}, {key})'), lambda: self.expr(st.value, wrap=True))
        else:
            self.binary(op, lambda: self.w(f'{obj}[{key}]'), lambda: self.expr(st.value, wrap=True))
        if prop:
            self.w(')')
        self.w('; end')

    def binary(self, op, left, right):
        if op == '//':
            self.w('math.floor((')
            left()
            self.w(')/(')
            right()
            self.w('))')
            return
        self.w('(')
        left()
        self.w(')' + op + '(')
        right()
        self.w(')')

    def exprlist(self, exprs):
        for i, e in enumerate(exprs):
            if i:
                self.w(',')
            self.expr(e)

    def expr(self, e, as_target=False, wrap=False):
        k = e.k
        line = e.line
        if k == 'name':
            self.w(e.name, line)
        elif k == 'num' or k == 'const':
            self.w(e.raw, line)
        elif k == 'str':
            self.w(fix_string(e.raw), line)
        elif k == 'vararg':
            self.w('...', line)
        elif k == 'paren':
            self.w('(', line)
            self.expr(e.expr)
            self.w(')')
        elif k == 'index':
            if self.hooks and not as_target and e.dot and hook_read(e):
                self.w('__g(', line)
                self.expr(e.obj)
                self.w(',' + repr_str(e.dot) + ')')
                return
            self.prefix(e.obj)
            if e.dot:
                self.w('.' + e.dot, line)
            else:
                self.w('[', line)
                self.expr(e.key)
                self.w(']')
        elif k == 'call':
            self.prefix(e.func)
            self.w('(', line)
            self.exprlist(e.args)
            self.w(')')
        elif k == 'mcall':
            if self.hooks:
                self.w('__m(', line)
                self.expr(e.obj)
                self.w(',' + repr_str(e.method))
                for a in e.args:
                    self.w(',')
                    self.expr(a)
                self.w(')')
            else:
                self.prefix(e.obj)
                self.w(':' + e.method, line)
                self.w('(')
                self.exprlist(e.args)
                self.w(')')
        elif k == 'function':
            self.w('function', line)
            self.funcrest(e)
        elif k == 'table':
            self.w('{', line)
            for i, (kind, key, val) in enumerate(e['items']):
                if i:
                    self.w(',')
                if kind == 'k':
                    self.w('[')
                    self.expr(key)
                    self.w(']=')
                elif kind == 'n':
                    self.w(key + '=')
                self.expr(val)
            self.w('}', e.end_line)
        elif k == 'unop':
            self.w(e.op, line)
            self.w('(' if e.op in ('-', 'not') else '')
            self.expr(e.operand)
            self.w(')' if e.op in ('-', 'not') else '')
        elif k == 'binop':
            if e.op == '//':
                self.w('math.floor((', line)
                self.expr(e.left)
                self.w(')/(')
                self.expr(e.right)
                self.w('))')
            else:
                self.w('(', line)
                self.expr(e.left)
                self.w(')' + (' ' + e.op + ' ' if e.op in ('and', 'or') else e.op) + '(')
                self.expr(e.right)
                self.w(')')
        elif k == 'ifexp':
            va = '...' if self.vararg[-1] else ''
            self.w('(function(' + va + ') ', line)
            for i, (cond, val) in enumerate(e.clauses):
                self.w('if' if i == 0 else 'elseif')
                self.expr(cond)
                self.w('then return')
                self.expr(val)
            self.w('else return')
            self.expr(e.orelse)
            self.w('end end)(' + va + ')')
        elif k == 'interp':
            self.w('(', line)
            first = True
            for kind, val in e.parts:
                if kind == 's':
                    if val == '' and not first:
                        continue
                    if not first:
                        self.w('..')
                    self.w(interp_string(val))
                else:
                    if not first:
                        self.w('..')
                    self.w('__tostr(')
                    self.expr(val)
                    self.w(')')
                first = False
            if first:
                self.w('""')
            self.w(')')
        else:
            raise ParseError(f'unknown expression {k}')

    def prefix(self, e):
        if e.k in ('name', 'index', 'call', 'mcall', 'paren'):
            self.expr(e)
        else:
            self.w('(', e.line)
            self.expr(e)
            self.w(')')


def utf8_escape(cp):
    return ''.join('\\%03d' % b for b in chr(cp).encode('utf-8', 'surrogatepass'))


def fix_string(raw):
    if raw[0] not in '"\'':
        return raw
    out = []
    i = 0
    while i < len(raw):
        c = raw[i]
        if c == '\\' and i + 1 < len(raw):
            d = raw[i + 1]
            if d == 'x':
                out.append('\\%03d' % int(raw[i + 2:i + 4], 16))
                i += 4
                continue
            if d == 'u':
                j = raw.index('}', i)
                out.append(utf8_escape(int(raw[i + 3:j], 16)))
                i = j + 1
                continue
            if d == 'z':
                i += 2
                while i < len(raw) and raw[i] in ' \t\r\n':
                    i += 1
                continue
            out.append(raw[i:i + 2])
            i += 2
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def interp_string(text):
    out = []
    i = 0
    while i < len(text):
        c = text[i]
        if c == '\\' and i + 1 < len(text):
            d = text[i + 1]
            out.append(d if d in '`{}' else text[i:i + 2])
            i += 2
            continue
        out.append({'"': '\\"', '\n': '\\n'}.get(c, c))
        i += 1
    return fix_string('"' + ''.join(out) + '"')


def uses_vararg_function(stats):
    return False


def needs_vararg_wrap(src):
    return True


PRELUDE = ('local __C = require(game:GetService("ReplicatedStorage"):WaitForChild("__Compat2016")) '
           'local __m, __g, __s, __iter, __tostr = __C.m, __C.g, __C.s, __C.iter, __C.tostr '
           + ' '.join(f'local {g} = __C.globals.{g}' for g in COMPAT_GLOBALS) + ' ')


def translate(src, prelude=True):
    src = src.lstrip('\ufeff')
    if src.startswith('#!'):
        src = '--' + src
    tokens, _, _ = lex(src)
    tree = Parser(tokens).chunk()
    em = Emitter(hooks=prelude)
    em.block(tree)
    body = ''.join(em.out)
    if prelude:
        return PRELUDE + body
    return body


def main():
    import json
    import os
    import subprocess
    if len(sys.argv) >= 3 and sys.argv[1] == '--dir':
        folder = sys.argv[2]
        out = sys.argv[3]
        os.makedirs(out, exist_ok=True)
        bad = 0
        for name in sorted(os.listdir(folder), key=lambda x: int(x.split('.')[0]) if x.split('.')[0].isdigit() else 0):
            if not name.endswith('.lua'):
                continue
            src = open(os.path.join(folder, name), encoding='utf-8', errors='surrogateescape').read()
            try:
                res = translate(src)
            except (LexError, ParseError, RecursionError) as e:
                bad += 1
                print(f'{name}: {e}')
                continue
            open(os.path.join(out, name), 'w', encoding='utf-8', errors='surrogateescape').write(res)
        print('failed', bad)
        return
    src = open(sys.argv[1], encoding='utf-8').read()
    sys.stdout.write(translate(src))


if __name__ == '__main__':
    sys.setrecursionlimit(20000)
    main()
