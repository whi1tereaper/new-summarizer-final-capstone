"""Compatibility entry point for the current registry-required HTTP checks."""
import runpy
from pathlib import Path

runpy.run_path(str(Path(__file__).with_name('check_registry_required.py')),run_name='__main__')
