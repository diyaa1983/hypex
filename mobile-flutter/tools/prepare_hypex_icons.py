from pathlib import Path

from PIL import Image

src = Path(r"c:\xampp\htdocs\Hypex\uploads\Logo.png")
out_dir = Path(r"c:\xampp\htdocs\Hypex\mobile-flutter\assets\icon")
brand_dir = Path(r"c:\xampp\htdocs\Hypex\mobile-flutter\assets\branding")
out_dir.mkdir(parents=True, exist_ok=True)
brand_dir.mkdir(parents=True, exist_ok=True)

im = Image.open(src).convert("RGBA")

size = 1024
canvas = Image.new("RGBA", (size, size), (255, 255, 255, 255))
target = int(size * 0.90)
logo = im.resize((target, target), Image.Resampling.LANCZOS)
ox = (size - target) // 2
oy = (size - target) // 2
canvas.paste(logo, (ox, oy), logo)
canvas.convert("RGB").save(out_dir / "app_icon.png", "PNG")

fg_size = 1024
fg_canvas = Image.new("RGBA", (fg_size, fg_size), (255, 255, 255, 255))
inner = int(fg_size * 0.68)
logo2 = im.resize((inner, inner), Image.Resampling.LANCZOS)
fx = (fg_size - inner) // 2
fy = (fg_size - inner) // 2
fg_canvas.paste(logo2, (fx, fy), logo2)
fg_canvas.save(out_dir / "app_icon_fg.png", "PNG")

im.save(brand_dir / "logo.png", "PNG")
print("Wrote icons OK")
