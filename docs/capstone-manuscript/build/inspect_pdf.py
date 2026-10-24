"""Create local PDF layout evidence; no application or source files are changed."""
import argparse
import json
from pathlib import Path
import re
import sys

DOCS = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(DOCS / "system-audit/evidence/tool-dependencies"))
import pymupdf as fitz
from PIL import Image, ImageDraw


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", type=Path)
    parser.add_argument("--output", type=Path, default=Path(__file__).parent / "layout-preview")
    parser.add_argument("--pages", default="", help="Comma-separated physical page numbers for full-resolution inspection")
    args = parser.parse_args()
    output = args.output.resolve()
    if not output.is_relative_to(DOCS):
        raise ValueError("Inspection artifacts must remain beneath docs/.")
    output.mkdir(parents=True, exist_ok=True)
    document = fitz.open(args.pdf)
    pages = []
    blank_pages = []
    outside_page = []
    unresolved_fields = []
    samples = {0, 1, 2, 3, 4, len(document) - 1}
    manual_pages = [int(value.strip()) - 1 for value in args.pages.split(",") if value.strip()]
    samples.update(manual_pages)
    heading_samples = set()
    fonts = {}
    for index, page in enumerate(document):
        text = page.get_text()
        image_info = page.get_image_info()
        if len(text.strip()) < 4 and not image_info:
            blank_pages.append(index + 1)
        if "Update this field in Word" in text:
            unresolved_fields.append(index + 1)
        for key in ("CHAPTER I\n", "CHAPTER II\n", "CHAPTER III\n", "CHAPTER IV\n", "Table of Contents", "List of Tables", "List of Figures", "APPENDIX F", "APPENDIX G"):
            matches = bool(re.match(r"^\d+\s+" + re.escape(key), text)) if key.startswith(("CHAPTER", "APPENDIX")) else key in text
            if matches and key not in heading_samples:
                samples.add(index)
                heading_samples.add(key)
        for block in page.get_text("dict")["blocks"]:
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    font_key = f"{span['font']} {span['size']:.2f}pt"
                    fonts[font_key] = fonts.get(font_key, 0) + len(span["text"])
                    box = fitz.Rect(span["bbox"])
                    if not page.rect.contains(box) and span["text"].strip():
                        outside_page.append({"page": index + 1, "text": span["text"][:100], "bbox": list(box)})
        pages.append({"physical_page": index + 1, "width_pt": page.rect.width, "height_pt": page.rect.height, "characters": len(text), "images": len(image_info), "start": text[:200], "end": text[-80:]})
    # Contact sheets cover every physical page, including image-heavy appendices.
    thumb_width, thumb_height = 220, 330
    for start in range(0, len(document), 12):
        sheet = Image.new("RGB", (thumb_width * 3, thumb_height * 4), "#d9d9d9")
        draw = ImageDraw.Draw(sheet)
        for slot, index in enumerate(range(start, min(start + 12, len(document)))):
            page = document[index]
            pix = page.get_pixmap(matrix=fitz.Matrix(0.4, 0.4), alpha=False)
            thumbnail = Image.frombytes("RGB", [pix.width, pix.height], pix.samples)
            thumbnail.thumbnail((thumb_width - 12, thumb_height - 25))
            x, y = (slot % 3) * thumb_width + 6, (slot // 3) * thumb_height + 20
            sheet.paste(thumbnail, (x, y))
            draw.text((x, y - 16), f"PDF page {index + 1}", fill="black")
        sheet.save(output / f"contact-{start + 1:03d}-{min(start + 12, len(document)):03d}.png")
    for index in sorted(samples):
        if 0 <= index < len(document):
            document[index].get_pixmap(matrix=fitz.Matrix(1.6, 1.6), alpha=False).save(output / f"page-{index + 1:03d}.png")
    report = {"pdf": str(args.pdf), "page_count": len(document), "blank_or_nearly_blank_pages": blank_pages, "text_outside_page": outside_page, "unresolved_index_placeholder_pages": unresolved_fields, "font_character_counts": dict(sorted(fonts.items(), key=lambda item: item[1], reverse=True)), "rendered_full_pages": [index + 1 for index in sorted(samples) if 0 <= index < len(document)], "pages": pages}
    (output / "LAYOUT_REPORT.json").write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps({key: report[key] for key in ("page_count", "blank_or_nearly_blank_pages", "text_outside_page", "unresolved_index_placeholder_pages", "rendered_full_pages")}, ensure_ascii=False))


if __name__ == "__main__":
    main()
