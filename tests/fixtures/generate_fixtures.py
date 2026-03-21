"""
StarRate – Test Fixture Generator (Python version)
Run: python tests/fixtures/generate_fixtures.py
"""
import struct, os

DIR = os.path.dirname(os.path.abspath(__file__))

def build_minimal_jpeg() -> bytes:
    soi  = b'\xff\xd8'
    app0 = b'\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00'
    dqt  = b'\xff\xdb\x00\x43\x00' + b'\x08' * 64
    sof0 = b'\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00'
    dht  = (b'\xff\xc4\x00\x1f\x00'
            b'\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00'
            b'\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b')
    sos  = b'\xff\xda\x00\x08\x01\x01\x00\x00\x3f\x00\xf8\x00'
    eoi  = b'\xff\xd9'
    return soi + app0 + dqt + sof0 + dht + sos + eoi

def build_jpeg_with_xmp(rating: int, label: str = '') -> bytes:
    soi  = b'\xff\xd8'
    app0 = b'\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00'

    label_attr = f' xmp:Label="{label}"' if label else ''
    xmp_payload = (
        '<?xpacket begin="\xef\xbb\xbf" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
        '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        f'<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
        f' xmp:Rating="{rating}"{label_attr}/>'
        '</rdf:RDF>'
        '</x:xmpmeta>'
        '<?xpacket end="w"?>'
    ).encode('utf-8')

    magic   = b'http://ns.adobe.com/xap/1.0/\x00'
    segment = magic + xmp_payload
    length  = len(segment) + 2  # includes the 2-byte length field
    app1    = b'\xff\xe1' + struct.pack('>H', length) + segment

    dqt  = b'\xff\xdb\x00\x43\x00' + b'\x08' * 64
    sof0 = b'\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00'
    dht  = (b'\xff\xc4\x00\x1f\x00'
            b'\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00'
            b'\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b')
    sos  = b'\xff\xda\x00\x08\x01\x01\x00\x00\x3f\x00\xf8\x00'
    eoi  = b'\xff\xd9'
    return soi + app0 + app1 + dqt + sof0 + dht + sos + eoi

def build_dummy_cr3() -> bytes:
    # Minimal ISOBMFF/CR3 container: ftyp box with 'crx ' major brand
    ftyp = struct.pack('>I', 24) + b'ftyp' + b'crx ' + struct.pack('>I', 0) + b'crx isom'
    mdat = struct.pack('>I', 8) + b'mdat'
    return ftyp + mdat

def build_large_jpeg() -> bytes:
    base = build_minimal_jpeg()
    # Insert a COM (comment) segment with 64 KB padding after SOI
    padding = b'X' * 65530
    com = b'\xff\xfe' + struct.pack('>H', 65532) + padding
    return base[:2] + com + base[2:]

files = {
    'test_image_no_exif.jpg':     build_minimal_jpeg(),
    'test_image_with_rating.jpg': build_jpeg_with_xmp(4, 'Red'),
    'test_image_rated_zero.jpg':  build_jpeg_with_xmp(0),
    'test_image_rated_5.jpg':     build_jpeg_with_xmp(5, 'Purple'),
    'test_image_large.jpg':       build_large_jpeg(),
    'dummy.cr3':                  build_dummy_cr3(),
    'IMG_0001.cr3':               build_dummy_cr3(),
    'IMG_0002.cr3':               build_dummy_cr3(),
}

for name, content in files.items():
    path = os.path.join(DIR, name)
    with open(path, 'wb') as f:
        f.write(content)
    print(f"Created: {name} ({len(content)} bytes)")

print("\nAll fixtures generated successfully.")
