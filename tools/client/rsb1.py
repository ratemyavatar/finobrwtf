import struct
import lz4.block
import xxhash

MAGIC = b"RSB1"
SEED = 42
MUL = 41


def _xor(buf: bytearray, key: bytes) -> None:
    for i in range(len(buf)):
        buf[i] ^= (key[i % 4] + i * MUL) & 0xFF


def decode(blob: bytes) -> bytes:
    buf = bytearray(blob)
    key = bytes(((buf[i] ^ MAGIC[i]) - i * MUL) & 0xFF for i in range(4))
    _xor(buf, key)
    if xxhash.xxh32(bytes(buf), seed=SEED).intdigest() != struct.unpack("<I", key)[0]:
        raise ValueError("hash mismatch: not an RSB1 blob for this format")
    size = struct.unpack_from("<I", buf, 4)[0]
    data = lz4.block.decompress(bytes(buf[8:]), uncompressed_size=size)
    if data[0] != 0:
        raise ValueError("blob holds a compile error, not bytecode")
    return data


def encode(data: bytes) -> bytes:
    payload = MAGIC + struct.pack("<I", len(data)) + lz4.block.compress(data, store_size=False)
    h = xxhash.xxh32(payload, seed=SEED).intdigest()
    buf = bytearray(payload)
    _xor(buf, struct.pack("<I", h))
    return bytes(buf)


def split_strings(data: bytes):
    off = struct.unpack_from("<I", data, 1)[0] + 1
    count = struct.unpack_from("<I", data, off)[0]
    lens = struct.unpack_from("<%dI" % count, data, off + 4)
    p = off + 4 + 4 * count
    strings = []
    for n in lens:
        strings.append(data[p:p + n]); p += n
    if p != len(data):
        raise ValueError("string table doesn't end at the end of the data")
    return data[:off], strings


def join_strings(prefix: bytes, strings) -> bytes:
    return prefix + struct.pack("<I", len(strings)) + struct.pack("<%dI" % len(strings), *map(len, strings)) + b"".join(strings)
