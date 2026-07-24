from PIL import Image

src_path = '/home/ramesh-chandra/Desktop/Baby Olympic/assets/logos/download__2_-removebg-preview.png'
dest_path = '/home/ramesh-chandra/Desktop/Baby Olympic/assets/images/logo.png'

im = Image.open(src_path).convert('RGBA')
w, h = im.size
pixels = im.load()

# Remove any light/white/pale background fill pixels inside or outside the logo bubble
cleaned = 0
for y in range(h):
    for x in range(w):
        r, g, b, a = pixels[x, y]
        # Any white/off-white/light-grey/light-purple fill
        if a > 0 and r > 230 and g > 220 and b > 230:
            pixels[x, y] = (0, 0, 0, 0)
            cleaned += 1

# Crop tight to the actual graphic bounds
bbox = im.getbbox()
if bbox:
    im = im.crop(bbox)

# Save to assets/images/logo.png
im.save(dest_path, 'PNG')
print(f"SUCCESS: Cleaned {cleaned} pixels, cropped to {im.size}, saved to logo.png!")
