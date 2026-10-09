"""Document extraction helpers for plain text, PDF, DOCX, and URLs."""

from __future__ import annotations

import io
from io import BytesIO
import ipaddress
import logging
import os
from pathlib import Path
import re
import socket
from typing import Any, cast
import urllib.parse
from urllib.parse import urljoin, urlparse

from pypdf import PdfReader

from .cleaning import (
    clean_pdf_extracted_text,
    detect_repeated_noise_markers,
    normalize_pdf_line,
    should_drop_line,
)
from .constants import TOKEN_PATTERN, URL_ONLY_PATTERN
from .models import SourceDocument
from .normalization import log_summary_event, normalize_whitespace
from .structure import rebuild_paragraphs_from_lines
from .tokenization import optional_module

LOGGER = logging.getLogger(__name__)

def sanitize_pdf_metadata_title(raw_title: str | None) -> str:
    if not isinstance(raw_title, str):
        return ""

    title = normalize_whitespace(raw_title)
    if title == "":
        return ""
    if title.lower() in {"untitled", "document", "microsoft word"}:
        return ""
    if len(title) > 180:
        return ""
    return title


def extract_pdf_text(file_path: str) -> str:
    return extract_pdf_source(file_path).raw_text


def extract_pdf_source_from_reader(reader: PdfReader, title_hint: str = "") -> SourceDocument:
    try:
        pages = reader.pages
    except Exception as exc:
        log_summary_event("pdf_extraction_failed", error=str(exc))
        raise ValueError("Could not read the uploaded PDF. Please upload a valid text-based PDF.") from exc

    page_texts: list[str] = []
    page_line_sets: list[list[str]] = []
    for page in pages:
        page_text = page.extract_text() or ""
        if page_text.strip():
            page_texts.append(page_text)
            page_line_sets.append([
                normalize_pdf_line(line)
                for line in page_text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
            ])

    page_count = len(pages)
    if page_line_sets == []:
        log_summary_event("pdf_extraction_failed", error="no_readable_page_text", page_count=page_count)
        raise ValueError(
            "This PDF appears to be scanned or poorly extracted. The system could not read enough reliable text to generate an accurate summary."
        )

    repeated_lines, repeated_keys = detect_repeated_noise_markers(page_line_sets)
    # Keep empty slots so later page references remain aligned with PDF page numbers.
    cleaned_pages: list[str] = []

    for lines in page_line_sets:
        filtered_lines: list[str] = []
        for line in lines:
            if line == "":
                filtered_lines.append("")
                continue
            if should_drop_line(line, repeated_lines, repeated_keys):
                continue
            filtered_lines.append(line)

        paragraphs = rebuild_paragraphs_from_lines(filtered_lines)
        cleaned_pages.append("\n\n".join(paragraphs))

    extracted_text = clean_pdf_extracted_text("\f".join(cleaned_pages if any(cleaned_pages) else page_texts))
    if not is_readable_extracted_text(extracted_text, page_count):
        log_summary_event(
            "pdf_extraction_failed",
            error="insufficient_readable_text",
            page_count=page_count,
            extracted_char_count=len(extracted_text),
        )
        raise ValueError(
            "This PDF appears to be scanned or poorly extracted. The system could not read enough reliable text to generate an accurate summary."
        )

    resolved_title_hint = sanitize_pdf_metadata_title(
        title_hint or getattr(getattr(reader, "metadata", None), "title", None)
    )
    retained_pages = tuple(cleaned_pages if any(cleaned_pages) else page_texts)
    return SourceDocument(
        raw_text=extracted_text,
        source_type="pdf",
        title_hint=resolved_title_hint,
        page_texts=retained_pages,
    )


def extract_pdf_source(file_path: str) -> SourceDocument:
    pdf_path = Path(file_path)
    validate_pdf_path(pdf_path)

    try:
        reader = PdfReader(str(pdf_path))
    except Exception as exc:
        log_summary_event("pdf_extraction_failed", error=str(exc))
        raise ValueError("Could not read the uploaded PDF. Please upload a valid text-based PDF.") from exc

    try:
        return extract_pdf_source_from_reader(reader)
    except ValueError as e:
        if "scanned or poorly extracted" in str(e):
            return extract_pdf_source_with_ocr(file_path=file_path)
        raise e


def validate_pdf_path(pdf_path: Path) -> None:
    if not pdf_path.exists():
        raise ValueError("Uploaded PDF file was not found.")
    if pdf_path.suffix.lower() != ".pdf":
        raise ValueError("Uploaded file is not a PDF document.")


def extract_pdf_source_from_bytes(pdf_bytes: bytes, title_hint: str = "") -> SourceDocument:
    try:
        reader = PdfReader(BytesIO(pdf_bytes))
    except Exception as exc:
        log_summary_event("pdf_extraction_failed", error=str(exc))
        raise ValueError("Could not read the linked PDF. Please use a valid text-based PDF URL.") from exc

    try:
        return extract_pdf_source_from_reader(reader, title_hint=title_hint)
    except ValueError as e:
        if "scanned or poorly extracted" in str(e):
            return extract_pdf_source_with_ocr(pdf_bytes=pdf_bytes, title_hint=title_hint)
        raise e


def extract_pdf_source_with_ocr(file_path: str = "", pdf_bytes: bytes | None = None, title_hint: str = "") -> SourceDocument:
    try:
        import pytesseract  # pyright: ignore[reportMissingImports]
        from pdf2image import convert_from_bytes, convert_from_path  # pyright: ignore[reportMissingImports]
    except ImportError:
        raise ValueError("This PDF appears to be scanned, and OCR dependencies (pytesseract/pdf2image) are not installed.")

    import os
    tesseract_cmd = os.getenv("TESSERACT_CMD")
    if tesseract_cmd:
        pytesseract.pytesseract.tesseract_cmd = tesseract_cmd

    poppler_path = os.getenv("POPPLER_PATH")

    try:
        if file_path:
            images = convert_from_path(file_path, poppler_path=poppler_path, last_page=5)
        elif pdf_bytes:
            images = convert_from_bytes(pdf_bytes, poppler_path=poppler_path, last_page=5)
        else:
            raise ValueError("No PDF provided for OCR.")
    except Exception as exc:
        log_summary_event("pdf_ocr_failed", error="pdf2image_failed", details=str(exc))
        raise ValueError("This PDF appears to be scanned, and the OCR tools (Poppler) are not correctly configured on this server.") from exc

    extracted_pages: list[str] = []
    for idx, image in enumerate(images):
        try:
            text = pytesseract.image_to_string(image)
            extracted_pages.append(text)
        except Exception as exc:
            log_summary_event("pdf_ocr_failed", error="tesseract_failed", details=str(exc))
            raise ValueError("Failed to run OCR on this scanned PDF. Please check your Tesseract installation.") from exc

    extracted_text = clean_pdf_extracted_text("\f".join(extracted_pages))
    if not extracted_text.strip():
        raise ValueError("OCR scanning failed to extract any readable text from this PDF.")

    return SourceDocument(
        raw_text=extracted_text,
        source_type="pdf",
        title_hint=title_hint or "Scanned PDF",
        page_texts=tuple(extracted_pages),
    )


def validate_public_url_for_fetch(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("Invalid URL.")

    host = parsed.hostname.lower()
    if host == "localhost" or host.endswith(".localhost") or host.endswith(".local"):
        raise ValueError("Invalid URL.")

    try:
        address_infos = socket.getaddrinfo(host, parsed.port or (443 if parsed.scheme == "https" else 80))
    except OSError as exc:
        raise ValueError("Invalid URL.") from exc

    addresses = {
        info[4][0]
        for info in address_infos
        if info and len(info) >= 5 and info[4]
    }
    if not addresses:
        raise ValueError("Invalid URL.")

    for address in addresses:
        try:
            parsed_ip = ipaddress.ip_address(address)
        except ValueError as exc:
            raise ValueError("Invalid URL.") from exc
        if (
            parsed_ip.is_private
            or parsed_ip.is_loopback
            or parsed_ip.is_link_local
            or parsed_ip.is_multicast
            or parsed_ip.is_reserved
            or parsed_ip.is_unspecified
        ):
            raise ValueError("Invalid URL.")


def fetch_public_url_response(url: str):
    try:
        import requests
    except ImportError:
        raise ValueError("URL support is not installed on this server. Please install 'requests'.")

    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
    }

    current_url = url
    try:
        for _ in range(4):
            validate_public_url_for_fetch(current_url)
            response = requests.get(
                current_url,
                headers=headers,
                timeout=15,
                allow_redirects=False,
            )
            if 300 <= response.status_code < 400:
                redirect_target = response.headers.get("Location", "").strip()
                if not redirect_target:
                    raise ValueError("Invalid URL.")
                current_url = urljoin(current_url, redirect_target)
                continue
            response.raise_for_status()
            return response, current_url
        else:
            raise ValueError("Invalid URL.")

    except ValueError:
        raise
    except Exception as exc:
        raise ValueError(
            "Failed to fetch readable content from the provided link."
        ) from exc


def fetch_url_content(url: str) -> tuple[str, str]:
    """Fetch and extract main article content from an HTML article URL."""
    bs4_module = optional_module("bs4")
    if bs4_module is None or not hasattr(bs4_module, "BeautifulSoup"):
        raise ValueError("URL support is not installed on this server. Please install 'beautifulsoup4'.")
    BeautifulSoup = cast(Any, bs4_module.BeautifulSoup)

    response, _resolved_url = fetch_public_url_response(url)
    try:
        soup = BeautifulSoup(response.text, "html.parser")

        # remove scripts, styles, and other noise
        for tag in soup(["script", "style", "nav", "footer", "header", "aside", "form"]):
            tag.decompose()

        # extract title
        title = ""
        if soup.title:
            title = normalize_whitespace(soup.title.string or "")
        
        # look for common article content tags
        article_body = soup.find("article") or soup.find("main") or soup.body
        if not article_body:
            raise ValueError("Could not find readable content on this page.")

        # extract paragraphs
        paragraphs: list[str] = []
        for p in article_body.find_all("p"):
            text = normalize_whitespace(p.get_text())
            if text and len(text.split()) > 5:  # avoid very short fragments
                paragraphs.append(text)

        if not paragraphs:
            # fallback: just get all text if no paragraphs found
            text = normalize_whitespace(article_body.get_text(separator="\n\n"))
            paragraphs = [p.strip() for p in text.split("\n\n") if p.strip()]

        return "\n\n".join(paragraphs), title

    except Exception as exc:
        raise ValueError(
            "Failed to fetch readable content from the provided link."
        ) from exc


def fetch_url_source(url: str) -> SourceDocument:
    response, resolved_url = fetch_public_url_response(url)
    content_type = (response.headers.get("Content-Type") or "").lower()
    remote_title = sanitize_pdf_metadata_title(response.headers.get("Content-Disposition", ""))

    if (
        "application/pdf" in content_type
        or urlparse(resolved_url).path.lower().endswith(".pdf")
        or response.content.startswith(b"%PDF")
    ):
        return extract_pdf_source_from_bytes(response.content, title_hint=remote_title)

    html_text, title = fetch_url_content(resolved_url)
    return SourceDocument(raw_text=html_text, source_type="url", title_hint=title)


def load_source_document(text: str = "", file_path: str = "") -> SourceDocument:
    import sys
    _tu = sys.modules.get('summarizer_core.text_utils')
    _pdf_extractor = getattr(_tu, 'extract_pdf_source', extract_pdf_source) if _tu is not None else extract_pdf_source
    _url_fetcher = getattr(_tu, 'fetch_url_source', fetch_url_source) if _tu is not None else fetch_url_source
    source_text = text.strip()
    normalized_path = file_path.strip()
    source_type = "text"
    title_hint = ""

    # 1. Handle URL input first if no file is uploaded
    if not normalized_path and URL_ONLY_PATTERN.fullmatch(source_text):
        return _url_fetcher(source_text)

    # 2. Handle File upload
    elif normalized_path:
        source_path = Path(normalized_path)
        if not source_path.exists():
            raise ValueError("Uploaded source file was not found.")

        if source_path.suffix.lower() == ".pdf":
            return _pdf_extractor(str(source_path))
        if source_path.suffix.lower() == ".docx":
            docx_module = optional_module("docx")
            if docx_module is None or not hasattr(docx_module, "Document"):
                raise ValueError("DOCX support is not installed. Run: .venv\\Scripts\\python -m pip install python-docx")
            Document = cast(Any, docx_module.Document)

            try:
                document = Document(str(source_path))
                paragraphs = [
                    normalize_whitespace(paragraph.text)
                    for paragraph in document.paragraphs
                    if normalize_whitespace(paragraph.text) != ""
                ]
                source_text = "\n\n".join(paragraphs)
                source_type = "docx"
            except Exception as exc:
                raise ValueError("Could not read the uploaded DOCX file.") from exc
        else:
            try:
                source_text = source_path.read_text(encoding="utf-8")
                source_type = source_path.suffix.lower().lstrip(".") or "file"
            except Exception as exc:
                raise ValueError(f"Could not read source file: {exc}") from exc

    if source_text.strip() == "":
        raise ValueError(
            "No continuous readable text found. The source may be empty or image-only."
        )

    return SourceDocument(raw_text=source_text, source_type=source_type, title_hint=title_hint)


def load_source_text(text: str = "", file_path: str = "") -> str:
    return load_source_document(text=text, file_path=file_path).raw_text


def is_readable_extracted_text(text: str, page_count: int) -> bool:
    alpha_chars = sum(character.isalpha() for character in text)
    words = TOKEN_PATTERN.findall(text)
    if alpha_chars < 120:
        return False
    if len(words) < 30:
        return False
    if page_count > 0 and (len(words) / page_count) < 25:
        return False
    return True


__all__ = ['extract_pdf_source', 'extract_pdf_source_from_bytes', 'extract_pdf_source_from_reader', 'extract_pdf_source_with_ocr', 'extract_pdf_text', 'fetch_public_url_response', 'fetch_url_content', 'fetch_url_source', 'is_readable_extracted_text', 'load_source_document', 'load_source_text', 'sanitize_pdf_metadata_title', 'validate_pdf_path', 'validate_public_url_for_fetch']
