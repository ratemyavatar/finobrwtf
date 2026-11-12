#!/usr/bin/env python3
import gzip
import os
import re
import sys
import xml.etree.ElementTree as ET

SKIP = {'untitledanimationclip', 'a', 'g', 'test', 'automaticsave', 'untitled2', 'sit'}
R6 = {'HumanoidRootPart', 'Torso', 'Head', 'Left Arm', 'Right Arm', 'Left Leg', 'Right Leg'}
POSE_PROPS = ('CFrame', 'MaskWeight', 'Name', 'Weight')
ACTION = 2
BASE_ID = 2140000000


def props(item):
    return {p.get('name'): p for p in item.find('Properties')}


def name(item):
    p = props(item).get('Name')
    return p.text or '' if p is not None else ''


def slug(text):
    return re.sub(r'[^a-z0-9]', '', text.lower())


def num(text):
    v = float(text)
    if v == int(v) and abs(v) < 1e15:
        return str(int(v))
    return ('%.6g' % v)


class Writer:
    def __init__(self):
        self.out = []
        self.ref = 0

    def esc(self, s):
        return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

    def open(self, cls, body):
        self.out.append('<Item class="%s" referent="RBX%d"><Properties>%s</Properties>' % (cls, self.ref, body))
        self.ref += 1

    def close(self):
        self.out.append('</Item>')

    def pose(self, item):
        p = props(item)
        body = []
        for key in POSE_PROPS:
            if key not in p:
                continue
            node = p[key]
            if key == 'CFrame':
                parts = ''.join('<%s>%s</%s>' % (c.tag, num(c.text), c.tag) for c in node)
                body.append('<CoordinateFrame name="CFrame">%s</CoordinateFrame>' % parts)
            elif key == 'Name':
                body.append('<string name="Name">%s</string>' % self.esc(node.text or ''))
            elif node.tag == 'token':
                body.append('<token name="%s">%s</token>' % (key, node.text))
            else:
                body.append('<float name="%s">%s</float>' % (key, num(node.text)))
        self.open('Pose', ''.join(body))
        for child in item.findall('Item'):
            if child.get('class') == 'Pose':
                self.pose(child)
        self.close()

    def sequence(self, item, title):
        p = props(item)
        loop = (p['Loop'].text if 'Loop' in p else 'true')
        self.out.append('<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4"><External>null</External><External>nil</External>')
        self.open('KeyframeSequence', '<bool name="Loop">%s</bool><string name="Name">%s</string><token name="Priority">%d</token>' % (loop, self.esc(title), ACTION))
        frames = [k for k in item.findall('Item') if k.get('class') == 'Keyframe']
        frames.sort(key=lambda k: float(props(k)['Time'].text))
        for k in frames:
            self.open('Keyframe', '<string name="Name">%s</string><float name="Time">%s</float>' % (self.esc(name(k) or 'Keyframe'), num(props(k)['Time'].text)))
            for child in k.findall('Item'):
                if child.get('class') == 'Pose':
                    self.pose(child)
            self.close()
        self.close()
        self.out.append('</roblox>')
        return ''.join(self.out)


def pose_names(item, found):
    for child in item.findall('Item'):
        if child.get('class') == 'Pose':
            found.add(name(child))
            pose_names(child, found)


def main(src, dst):
    root = ET.parse(src).getroot()
    os.makedirs(dst, exist_ok=True)
    seen = {}
    for seq in root.iter('Item'):
        if seq.get('class') != 'KeyframeSequence':
            continue
        title = name(seq).strip()
        key = slug(title)
        if not key or key in SKIP or key in seen:
            continue
        frames = [k for k in seq.findall('Item') if k.get('class') == 'Keyframe']
        if not frames:
            continue
        found = set()
        for k in frames:
            pose_names(k, found)
        if not found or not found <= R6:
            continue
        with gzip.GzipFile(os.path.join(dst, key + '.rbxmx.gz'), 'wb', 9, mtime=0) as f:
            f.write(Writer().sequence(seq, title).encode('utf-8'))
        seen[key] = title
    with open(os.path.join(dst, 'list.txt'), 'w', encoding='utf-8') as f:
        for n, key in enumerate(sorted(seen, key=lambda k: seen[k].lower()), 1):
            f.write('%d\t%s\t%s\n' % (BASE_ID + n, key, seen[key]))
    print(len(seen), 'dances')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
