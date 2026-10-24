"""Run with LibreOffice's bundled Python to update indexes and export the PDF.

No system-wide LibreOffice settings are changed.  The isolated user profile,
connection, DOCX, PDF, and conversion metadata all remain beneath docs/.
"""
import argparse
import json
import os
import re
from pathlib import Path
import socket
import subprocess
import time
import uuid

import uno
from com.sun.star.beans import PropertyValue


def properties(**items):
    result = []
    for name, value in items.items():
        prop = PropertyValue()
        prop.Name = name
        prop.Value = value
        result.append(prop)
    return tuple(result)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("docx", type=Path)
    parser.add_argument("--soffice", type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    docx = args.docx.resolve()
    if not docx.is_relative_to(root):
        raise ValueError("Only manuscript files beneath docs/ may be converted.")
    profile = Path(__file__).parent / "libreoffice-profile"
    profile.mkdir(parents=True, exist_ok=True)
    token = uuid.uuid4().hex
    with socket.socket() as available:
        available.bind(("127.0.0.1", 0))
        port = available.getsockname()[1]
    command = [str(args.soffice), "--headless", "--nologo", "--nodefault", "--nofirststartwizard", "--norestore", f"-env:UserInstallation={profile.as_uri()}", f"--accept=socket,host=127.0.0.1,port={port};urp;StarOffice.ComponentContext"]
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    desktop = None
    document = None
    try:
        local_context = uno.getComponentContext()
        resolver = local_context.ServiceManager.createInstanceWithContext("com.sun.star.bridge.UnoUrlResolver", local_context)
        context = None
        for attempt in range(150):
            try:
                context = resolver.resolve(f"uno:socket,host=127.0.0.1,port={port};urp;StarOffice.ComponentContext")
                break
            except Exception:
                time.sleep(0.3)
        if context is None:
            raise RuntimeError("LibreOffice did not start its isolated document conversion connection within 45 seconds.")
        desktop = context.ServiceManager.createInstanceWithContext("com.sun.star.frame.Desktop", context)
        document = desktop.loadComponentFromURL(docx.as_uri(), "_blank", 0, properties(Hidden=True, ReadOnly=False, UpdateDocMode=0, MacroExecutionMode=4))
        if document is None:
            raise RuntimeError("LibreOffice could not open the generated Word manuscript.")
        indexes = document.getDocumentIndexes()
        index_count = indexes.getCount()
        paragraph_styles = document.getStyleFamilies().getByName("ParagraphStyles")
        for index_number in range(index_count):
            index_object = indexes.getByIndex(index_number)
            for level in range(1, 4):
                property_name = f"ParaStyleLevel{level}"
                if not index_object.getPropertySetInfo().hasPropertyByName(property_name):
                    continue
                inherited_style = index_object.getPropertyValue(property_name)
                style_name = f"Manuscript Index {index_number + 1} Level {level}"
                if not paragraph_styles.hasByName(style_name):
                    style = document.createInstance("com.sun.star.style.ParagraphStyle")
                    paragraph_styles.insertByName(style_name, style)
                style = paragraph_styles.getByName(style_name)
                if inherited_style and paragraph_styles.hasByName(inherited_style):
                    style.ParentStyle = inherited_style
                style.CharFontName = "Courier New"
                style.CharHeight = 10
                style.ParaAdjust = 0
                style.ParaFirstLineIndent = 0
                style.ParaTopMargin = 0
                style.ParaBottomMargin = 106
                spacing = uno.createUnoStruct("com.sun.star.style.LineSpacing")
                spacing.Mode = uno.getConstantByName("com.sun.star.style.LineSpacingMode.FIX")
                spacing.Height = 494
                style.ParaLineSpacing = spacing
                index_object.setPropertyValue(property_name, style_name)
        # A second layout pass accounts for pages added by the generated indexes.
        for _ in range(3):
            document.refresh()
            document.getTextFields().refresh()
            for index in range(indexes.getCount()):
                indexes.getByIndex(index).update()
        cursor = document.getCurrentController().getViewCursor()
        cursor.jumpToLastPage()
        pages = cursor.getPage()
        intermediate = docx.with_name(f".{docx.stem}-{token}.docx")
        document.storeToURL(intermediate.as_uri(), properties(FilterName="Office Open XML Text", Overwrite=True))
        pdf = docx.with_suffix(".pdf")
        document.storeToURL(pdf.as_uri(), properties(FilterName="writer_pdf_Export", Overwrite=True, FilterData=properties(ExportBookmarks=True, UseTaggedPDF=True)))
        document.close(True)
        document = None
        os.replace(intermediate, docx)
        pdf_page_count = len(re.findall(rb"/Type\s*/Page\b", pdf.read_bytes()))
        metadata = {"docx": str(docx), "pdf": str(pdf), "pages": pdf_page_count, "writer_layout_pages_before_export": pages, "updated_indexes": index_count, "renderer": "LibreOffice with internal indexes refreshed; no external links updated"}
        (Path(__file__).parent / "EXPORT_REPORT.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
        print(json.dumps(metadata))
    finally:
        if document is not None:
            try:
                document.close(True)
            except Exception:
                pass
        if desktop is not None:
            try:
                desktop.terminate()
            except Exception:
                pass
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.terminate()


if __name__ == "__main__":
    main()
