"""Replace the icon inside a Windows .exe/.sfx (used to give MediaCenter-Setup.exe the hundo icon).

Pure Python, no dependencies: walks the PE resource tree, and rewrites each RT_ICON image in place with a PNG of the
new picture (PNG icons are valid on Windows Vista and later). New data must fit in the old slot, so sizes are chosen
to fit; the group-icon directory and resource sizes are updated to match.
"""
import io
import struct


def _sections(b):
    pe = struct.unpack_from("<I", b, 0x3C)[0]
    assert b[pe:pe + 4] == b"PE\0\0", "not a PE file"
    coff = pe + 4
    nsec = struct.unpack_from("<H", b, coff + 2)[0]
    optsz = struct.unpack_from("<H", b, coff + 16)[0]
    opt = coff + 20
    magic = struct.unpack_from("<H", b, opt)[0]
    dd = opt + (96 if magic == 0x10B else 112)
    rsrc_rva, _ = struct.unpack_from("<II", b, dd + 2 * 8)
    secs = []
    st = opt + optsz
    for i in range(nsec):
        o = st + 40 * i
        vsize, va, rawsize, rawptr = struct.unpack_from("<IIII", b, o + 8)
        secs.append((va, max(vsize, rawsize), rawptr))
    return rsrc_rva, secs


def _off(secs, rva):
    for va, size, raw in secs:
        if va <= rva < va + size:
            return raw + rva - va
    raise ValueError("rva not mapped")


def _entries(b, base, off):
    named, ids = struct.unpack_from("<HH", b, off + 12)
    for i in range(named + ids):
        name, data = struct.unpack_from("<II", b, off + 16 + 8 * i)
        yield name, data, off + 16 + 8 * i


def _leafs(b, base, type_id):
    """Yield (id, data_entry_offset) for every resource of a type."""
    for name, data, _ in _entries(b, base, base):
        if name != type_id or not data & 0x80000000:
            continue
        lvl2 = base + (data & 0x7FFFFFFF)
        for rid, d2, _ in _entries(b, base, lvl2):
            lvl3 = base + (d2 & 0x7FFFFFFF)
            for _lang, d3, _ in _entries(b, base, lvl3):
                yield rid, base + d3


def dib4(img, side):
    """16-colour (4 bpp) icon bitmap, the format old SFX stubs use."""
    from PIL import Image
    q = img.convert("RGB").resize((side, side), Image.LANCZOS).quantize(16)
    pal = (q.getpalette() or [])[:48] + [0] * max(0, 48 - len(q.getpalette() or []))
    row = ((side * 4 + 31) // 32) * 4
    mrow = ((side + 31) // 32) * 4
    hdr = struct.pack("<IiiHHIIiiII", 40, side, side * 2, 1, 4, 0, row * side + mrow * side, 0, 0, 16, 0)
    palette = b"".join(bytes((pal[i * 3 + 2], pal[i * 3 + 1], pal[i * 3], 0)) for i in range(16))
    px = q.load()
    xor = bytearray()
    for y in range(side - 1, -1, -1):
        line = bytearray(row)
        for x in range(side):
            v = px[x, y] & 15
            line[x // 2] |= v << 4 if x % 2 == 0 else v
        xor += line
    return hdr + palette + bytes(xor) + b"\0" * (mrow * side)


def replace_icon(exe_bytes, image_path):
    from PIL import Image
    b = bytearray(exe_bytes)
    rsrc_rva, secs = _sections(b)
    base = _off(secs, rsrc_rva)
    icons = {rid: de for rid, de in _leafs(b, base, 3)}
    src = Image.open(image_path).convert("RGBA")
    replaced = 0
    for gid, gde in _leafs(b, base, 14):
        grva, gsize = struct.unpack_from("<II", b, gde)
        goff = _off(secs, grva)
        count = struct.unpack_from("<H", b, goff + 4)[0]
        for i in range(count):
            e = goff + 6 + 14 * i
            w, h = b[e], b[e + 1]
            nid = struct.unpack_from("<H", b, e + 12)[0]
            if nid not in icons:
                continue
            de = icons[nid]
            rva, size = struct.unpack_from("<II", b, de)
            off = _off(secs, rva)
            side = w or 256
            data = None
            bits = struct.unpack_from("<H", b, e + 6)[0]
            if bits == 4 and w and len(dib4(src, w)) == size:   # same-size 16-colour bitmap
                b[off:off + size] = dib4(src, w)
                replaced += 1
                continue
            for s in (side, 48, 32, 24, 16):         # shrink until the PNG fits the old slot
                if s > side:
                    continue
                for colors in (None, 256, 64, 16):
                    im = src.resize((s, s), Image.LANCZOS)
                    if colors:
                        im = im.quantize(colors).convert("RGBA")
                    buf = io.BytesIO()
                    im.save(buf, "PNG", optimize=True)
                    if len(buf.getvalue()) <= size:
                        data, side_used = buf.getvalue(), s
                        break
                if data:
                    break
            if not data:
                continue
            b[off:off + size] = data + b"\0" * (size - len(data))
            struct.pack_into("<I", b, de + 4, len(data))
            b[e] = b[e + 1] = side_used % 256
            struct.pack_into("<HHI", b, e + 4, 1, 32, len(data))
            replaced += 1
    return bytes(b), replaced


if __name__ == "__main__":
    import sys
    out, n = replace_icon(open(sys.argv[1], "rb").read(), sys.argv[2])
    open(sys.argv[3], "wb").write(out)
    print("icons replaced:", n)
