import os
import subprocess
import sys

MEDIA_ROOT = r"C:\media"  # your movies/music folder
THUMB_ROOT = r"C:\xampp\htdocs\media_center\thumbnails"

def create_thumbnail(input_path, output_path):
    subprocess.run([
        "ffmpeg", "-y",
        "-i", input_path,
        "-ss", "00:00:05",
        "-vframes", "1",
        output_path
    ], check=True)

def main():
    for root, dirs, files in os.walk(MEDIA_ROOT):
        for f in files:
            if f.lower().endswith((".mp4", ".mkv", ".avi")):
                full = os.path.join(root, f)
                thumb_name = f + ".jpg"
                thumb_path = os.path.join(THUMB_ROOT, thumb_name)
                os.makedirs(THUMB_ROOT, exist_ok=True)
                create_thumbnail(full, thumb_path)
                print(full, thumb_path)

if __name__ == "__main__":
    main()
