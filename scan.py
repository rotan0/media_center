"""Kept for compatibility: same as `python mc.py scan`."""
import sys
import mc

if __name__ == "__main__":
    mc.main(["scan"] + sys.argv[1:])
