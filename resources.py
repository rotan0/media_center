import os
import requests
import json
from PIL import Image

# Correct ROOT: stay inside media_center folder
ROOT = os.path.dirname(__file__)

# Correct image directory
IMG_DIR = os.path.join(ROOT, "assets", "img")
os.makedirs(IMG_DIR, exist_ok=True)

# URLs
LOGO_URL = "https://equal-page.free.nf/code/logo.gif"
THEME_URL = "https://equal-page.free.nf/code/hundo.jpg"

# Local paths
LOGO_PATH = os.path.join(IMG_DIR, "logo.gif")
THEME_PATH = os.path.join(IMG_DIR, "theme.jpg")
THEME_JSON = os.path.join(ROOT, "data", "theme.json")
THEME_PATH = os.path.join(IMG_DIR, "hundo.jpg")

def download_if_missing(url, path):
    """Download only if missing AND ensure it's a real image."""
    if not os.path.exists(path):
        print(f"Downloading {url}...")
        try:
            r = requests.get(url, timeout=10)

            # Ensure the server returned an actual image
            if "image" not in r.headers.get("Content-Type", ""):
                print("ERROR: URL did not return an image. Using fallback.")
                return False

            with open(path, "wb") as f:
                f.write(r.content)

            print(f"Saved: {path}")
            return True

        except Exception as e:
            print("Download error:", e)
            return False

    return True


# Download logo
download_if_missing(LOGO_URL, LOGO_PATH)

# Download theme art
theme_ok = download_if_missing(THEME_URL, THEME_PATH)

# If theme image is invalid or corrupted, create fallback
if not theme_ok:
    print("Theme image invalid or missing. Creating fallback theme image.")
    fallback = os.path.join(IMG_DIR, "fallback_theme.jpg")
    img = Image.new("RGB", (100, 100), (80, 80, 80))  # neutral gray
    img.save(fallback)
    THEME_PATH = fallback


def extract_theme_colors(image_path):
    """Extract average colors from the theme image."""
    try:
        img = Image.open(image_path).convert("RGB").resize((50, 50))
    except Exception as e:
        print("Theme image could not be opened. Using fallback colors.", e)
        theme = {
            "primary_color": "rgb(80,80,80)",
            "secondary_color": "rgb(60,60,60)",
            "accent_color": "rgb(120,120,120)"
        }
        with open(THEME_JSON, "w") as f:
            json.dump(theme, f, indent=4)
        return

    pixels = list(img.getdata())

    r = sum([p[0] for p in pixels]) // len(pixels)
    g = sum([p[1] for p in pixels]) // len(pixels)
    b = sum([p[2] for p in pixels]) // len(pixels)

    theme = {
        "primary_color": f"rgb({r},{g},{b})",
        "secondary_color": f"rgb({max(0,r-40)},{max(0,g-40)},{max(0,b-40)})",
        "accent_color": f"rgb({min(255,r+40)},{min(255,g+40)},{min(255,b+40)})"
    }

    with open(THEME_JSON, "w") as f:
        json.dump(theme, f, indent=4)

    print("Theme colors generated:", theme)


# Generate theme colors
extract_theme_colors(THEME_PATH)
