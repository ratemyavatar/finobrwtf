#!/usr/bin/env python3
import math
import sys
from PIL import Image, ImageDraw

SCALE = 4
SIZE = 512
OUTER = 250
INNER = 92


def canvas():
    return Image.new('RGBA', (SIZE * SCALE, SIZE * SCALE), (0, 0, 0, 0))


def circle(draw, radius, **kw):
    c = SIZE * SCALE / 2
    r = radius * SCALE
    draw.ellipse((c - r, c - r, c + r, c + r), **kw)


def finish(img, path):
    img.resize((SIZE, SIZE), Image.LANCZOS).save(path)


def wheel(path):
    img = canvas()
    d = ImageDraw.Draw(img)
    circle(d, OUTER, fill=(0, 0, 0, 120), outline=(255, 255, 255, 110), width=2 * SCALE)
    c = SIZE * SCALE / 2
    for k in range(8):
        a = math.radians(22.5 + 45 * k - 90)
        d.line((c + math.cos(a) * INNER * SCALE, c + math.sin(a) * INNER * SCALE,
                c + math.cos(a) * OUTER * SCALE, c + math.sin(a) * OUTER * SCALE),
               fill=(255, 255, 255, 90), width=2 * SCALE)
    hole = canvas()
    circle(ImageDraw.Draw(hole), INNER, fill=(255, 255, 255, 255))
    img.paste((0, 0, 0, 0), (0, 0), hole)
    d = ImageDraw.Draw(img)
    circle(d, INNER - 4, fill=(22, 22, 26, 225), outline=(255, 255, 255, 60), width=2 * SCALE)
    finish(img, path)


def highlight(path):
    img = canvas()
    d = ImageDraw.Draw(img)
    c = SIZE * SCALE / 2
    box = (c - OUTER * SCALE, c - OUTER * SCALE, c + OUTER * SCALE, c + OUTER * SCALE)
    d.pieslice(box, -90 - 22.5, -90 + 22.5, fill=(255, 255, 255, 70))
    circle(d, INNER, fill=(0, 0, 0, 0))
    finish(img, path)


if __name__ == '__main__':
    wheel(sys.argv[1] + '/wheel.png')
    highlight(sys.argv[1] + '/highlight.png')
