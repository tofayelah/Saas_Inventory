# -*- coding: utf-8 -*-
"""
Generate docs/ERP_SYSTEM_DESIGN.md from modular sections
"""
import os
import sys

# Add sections directory to path
script_dir = os.path.dirname(os.path.abspath(__file__))
sections_dir = os.path.join(script_dir, 'sections')
sys.path.insert(0, sections_dir)

from sec01_10 import CONTENT as C1
from sec11_22 import CONTENT as C2
from sec23_31 import CONTENT as C3
from sec32_40 import CONTENT as C4
from sec41_50 import CONTENT as C5

full_document = C1 + "\n" + C2 + "\n" + C3 + "\n" + C4 + "\n" + C5

output_path = os.path.join(script_dir, 'ERP_SYSTEM_DESIGN.md')
with open(output_path, 'w', encoding='utf-8') as f:
    f.write(full_document)

print(f"Successfully generated {output_path}")
print(f"Total lines: {len(full_document.splitlines())}")
print(f"Total characters: {len(full_document)}")
print(f"Total bytes: {len(full_document.encode('utf-8'))}")
