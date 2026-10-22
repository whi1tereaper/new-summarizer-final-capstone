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
import time
from typing import Any, cast
import urllib.parse
from urllib.parse import urljoin, urlparse

from pypdf import PdfReader
from pypdf.errors import PdfReadError, FileNotDecryptedError
from zipfile import BadZipFile

from failure_contract import ProcessingFailure

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
from .runtime import timed

LOGGER = logging.getLogger(__name__)
logging.getLogger("pypdf").setLevel(logging.ERROR)

OCR_DPI = 150
OCR_MAX_PAGES = 12
OCR_PAGE_TIMEOUT_SECONDS = 15
OCR_TOTAL_BUDGET_SECONDS = 55

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
    # Preserve PDFs that can already be opened without a user password.
    if reader.is_encrypted and not reader.decrypt(""):
        raise ProcessingFailure("FILE_ENCRYPTED")
    try:
        pages = reader.pages
    except FileNotDecryptedError as exc:
        raise ProcessingFailure("FILE_ENCRYPTED") from exc
    except PdfReadError as exc:
        raise ProcessingFailure("FILE_CORRUPTED") from exc
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

    page_texts: list[str] = []
    page_line_sets: list[list[str]] = []
    for page in pages:
        try:
            page_text = page.extract_text() or ""
        except PdfReadError as exc:
            raise ProcessingFailure("FILE_CORRUPTED") from exc
        except Exception as exc:
            raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc
        if page_text.strip():
            page_texts.append(page_text)
            page_line_sets.append([
                normalize_pdf_line(line)
                for line in page_text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
            ])

    page_count = len(pages)
    if page_line_sets == []:
        log_summary_event("pdf_extraction_failed", error="no_readable_page_text", page_count=page_count)
        raise ProcessingFailure("FILE_TEXT_UNAVAILABLE", "PDF has no extractable page text")

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
        raise ProcessingFailure("CONTENT_UNREADABLE", "PDF text did not pass readability checks")

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


@timed("pdf_extraction")
def extract_pdf_source(file_path: str) -> SourceDocument:
    pdf_path = Path(file_path)
    validate_pdf_path(pdf_path)

    try:
        reader = PdfReader(str(pdf_path))
    except FileNotDecryptedError as exc:
        raise ProcessingFailure("FILE_ENCRYPTED") from exc
    except PdfReadError as exc:
        raise ProcessingFailure("FILE_CORRUPTED") from exc
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

    try:
        return extract_pdf_source_from_reader(reader)
    except ProcessingFailure as exc:
        if exc.failure_code in {"FILE_TEXT_UNAVAILABLE", "CONTENT_UNREADABLE"}:
            return extract_pdf_source_with_ocr(file_path=file_path, original_failure=exc)
        raise
    except FileNotDecryptedError as exc:
        raise ProcessingFailure("FILE_ENCRYPTED") from exc
    except PdfReadError as exc:
        raise ProcessingFailure("FILE_CORRUPTED") from exc
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc


def validate_pdf_path(pdf_path: Path) -> None:
    if not pdf_path.is_file():
        raise ProcessingFailure("SOURCE_UNAVAILABLE")
    if pdf_path.suffix.lower() != ".pdf":
        raise ProcessingFailure("FILE_UNSUPPORTED")


def extract_pdf_source_from_bytes(pdf_bytes: bytes, title_hint: str = "") -> SourceDocument:
    try:
        reader = PdfReader(BytesIO(pdf_bytes))
    except FileNotDecryptedError as exc:
        raise ProcessingFailure("FILE_ENCRYPTED") from exc
    except PdfReadError as exc:
        raise ProcessingFailure("FILE_CORRUPTED") from exc
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

    try:
        return extract_pdf_source_from_reader(reader, title_hint=title_hint)
    except ProcessingFailure as exc:
        if exc.failure_code in {"FILE_TEXT_UNAVAILABLE", "CONTENT_UNREADABLE"}:
            return extract_pdf_source_with_ocr(pdf_bytes=pdf_bytes, title_hint=title_hint, original_failure=exc)
        raise
    except FileNotDecryptedError as exc:
        raise ProcessingFailure("FILE_ENCRYPTED") from exc
    except PdfReadError as exc:
        raise ProcessingFailure("FILE_CORRUPTED") from exc
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc


def select_ocr_page_numbers(page_count: int, max_pages: int = OCR_MAX_PAGES) -> tuple[int, ...]:
    """Choose representative 1-based pages while preserving the whole document order."""
    if page_count <= 0:
        return ()
    if page_count <= max_pages:
        return tuple(range(1, page_count + 1))

    # Include both endpoints and distribute the remaining pages across the document.
    selected = {
        1 + round(index * (page_count - 1) / (max_pages - 1))
        for index in range(max_pages)
    }
    return tuple(sorted(selected))


def _ocr_page_count(file_path: str, pdf_bytes: bytes | None) -> int:
    reader = PdfReader(file_path) if file_path else PdfReader(BytesIO(pdf_bytes or b""))
    return len(reader.pages)


def extract_pdf_source_with_ocr(file_path: str = "", pdf_bytes: bytes | None = None, title_hint: str = "", original_failure: ProcessingFailure | None = None) -> SourceDocument:
    try:
        import pytesseract  # pyright: ignore[reportMissingImports]
        from pdf2image import convert_from_bytes, convert_from_path  # pyright: ignore[reportMissingImports]
    except ImportError as exc:
        raise (original_failure or ProcessingFailure("FILE_TEXT_UNAVAILABLE")) from exc

    tesseract_cmd = os.getenv("TESSERACT_CMD")
    if tesseract_cmd:
        pytesseract.pytesseract.tesseract_cmd = tesseract_cmd

    poppler_path = os.getenv("POPPLER_PATH")

    if not file_path and not pdf_bytes:
        raise ProcessingFailure("INPUT_EMPTY")

    try:
        page_count = _ocr_page_count(file_path, pdf_bytes)
    except Exception as exc:
        log_summary_event("pdf_ocr_failed", error="page_count_failed", exception_type=type(exc).__name__)
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

    page_numbers = select_ocr_page_numbers(page_count)
    extracted_pages = [""] * page_count
    started_at = time.monotonic()
    for page_number in page_numbers:
        if time.monotonic() - started_at >= OCR_TOTAL_BUDGET_SECONDS:
            log_summary_event(
                "pdf_ocr_budget_reached",
                page_count=page_count,
                completed_pages=sum(bool(page.strip()) for page in extracted_pages),
            )
            break
        try:
            conversion_options = {
                "dpi": OCR_DPI,
                "fmt": "jpeg",
                "grayscale": True,
                "first_page": page_number,
                "last_page": page_number,
                "thread_count": 1,
                "poppler_path": poppler_path,
            }
            images = (
                convert_from_path(file_path, **conversion_options)
                if file_path
                else convert_from_bytes(pdf_bytes or b"", **conversion_options)
            )
            if not images:
                continue
            extracted_pages[page_number - 1] = pytesseract.image_to_string(
                images[0],
                config="--oem 1 --psm 3",
                timeout=OCR_PAGE_TIMEOUT_SECONDS,
            )
        except Exception as exc:
            log_summary_event(
                "pdf_ocr_page_failed",
                page=page_number,
                error=type(exc).__name__,
            )

    extracted_text = clean_pdf_extracted_text("\f".join(extracted_pages))
    if not extracted_text.strip():
        raise original_failure or ProcessingFailure("FILE_TEXT_UNAVAILABLE")

    return SourceDocument(
        raw_text=extracted_text,
        source_type="pdf",
        title_hint=title_hint or "Scanned PDF",
        page_texts=tuple(extracted_pages),
    )


def validate_public_url_for_fetch(url: str) -> None:
    if any(character.isspace() or ord(character) < 32 for character in url):
        raise ProcessingFailure("URL_INVALID")
    try:
        parsed = urlparse(url)
        port = parsed.port
    except ValueError as exc:
        raise ProcessingFailure("URL_INVALID") from exc
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ProcessingFailure("URL_INVALID")
    if parsed.username is not None or parsed.password is not None:
        raise ProcessingFailure("URL_UNSAFE")

    host = parsed.hostname.lower()
    if host == "localhost" or host.endswith(".localhost") or host.endswith(".local"):
        raise ProcessingFailure("URL_UNSAFE")

    try:
        address_infos = socket.getaddrinfo(host, port or (443 if parsed.scheme == "https" else 80))
    except OSError as exc:
        raise ProcessingFailure("URL_UNREACHABLE") from exc

    addresses = {
        info[4][0]
        for info in address_infos
        if info and len(info) >= 5 and info[4]
    }
    if not addresses:
        raise ProcessingFailure("URL_UNREACHABLE")

    for address in addresses:
        try:
            parsed_ip = ipaddress.ip_address(address)
        except ValueError as exc:
            raise ProcessingFailure("URL_UNREACHABLE") from exc
        if (
            parsed_ip.is_private
            or parsed_ip.is_loopback
            or parsed_ip.is_link_local
            or parsed_ip.is_multicast
            or parsed_ip.is_reserved
            or parsed_ip.is_unspecified
        ):
            raise ProcessingFailure("URL_UNSAFE")


def fetch_public_url_response(url: str):
    try:
        import requests
    except ImportError as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

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
                    raise ProcessingFailure("URL_CONTENT_UNAVAILABLE")
                current_url = urljoin(current_url, redirect_target)
                response.close()
                continue
            if response.status_code in {401, 403, 407, 451}:
                raise ProcessingFailure("URL_ACCESS_RESTRICTED")
            if response.status_code == 429:
                raise ProcessingFailure("URL_RATE_LIMITED")
            if response.status_code in {404, 410, 204, 205}:
                raise ProcessingFailure("URL_CONTENT_UNAVAILABLE")
            response.raise_for_status()
            return response, current_url
        else:
            raise ProcessingFailure("URL_CONTENT_UNAVAILABLE", "Redirect limit exceeded")

    except ProcessingFailure:
        raise
    except requests.exceptions.Timeout as exc:
        raise ProcessingFailure("URL_TIMEOUT") from exc
    except requests.exceptions.SSLError as exc:
        raise ProcessingFailure("URL_TLS_FAILED") from exc
    except requests.exceptions.InvalidURL as exc:
        raise ProcessingFailure("URL_INVALID") from exc
    except requests.exceptions.RequestException as exc:
        raise ProcessingFailure("URL_UNREACHABLE") from exc


def fetch_url_content(url: str, *, response: Any = None) -> tuple[str, str]:
    """Fetch and extract main article content from an HTML article URL."""
    bs4_module = optional_module("bs4")
    if bs4_module is None or not hasattr(bs4_module, "BeautifulSoup"):
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED")
    BeautifulSoup = cast(Any, bs4_module.BeautifulSoup)

    if response is None:
        response, _resolved_url = fetch_public_url_response(url)
    try:
        soup = BeautifulSoup(response.text, "html.parser")
        if soup.find("input", attrs={"type": "password"}) and not (soup.find("article") or soup.find("main")):
            raise ProcessingFailure("URL_ACCESS_RESTRICTED")

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
            raise ProcessingFailure("URL_CONTENT_UNAVAILABLE")

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

        if not paragraphs:
            raise ProcessingFailure("URL_CONTENT_UNAVAILABLE")
        return "\n\n".join(paragraphs), title

    except ProcessingFailure:
        raise
    except Exception as exc:
        raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc


@timed("source_retrieval")
def fetch_url_source(url: str) -> SourceDocument:
    response, resolved_url = fetch_public_url_response(url)
    content_type = (response.headers.get("Content-Type") or "").lower()
    remote_title = sanitize_pdf_metadata_title(response.headers.get("Content-Disposition", ""))

    if (
        "application/pdf" in content_type
        or response.content.startswith(b"%PDF")
    ):
        return extract_pdf_source_from_bytes(response.content, title_hint=remote_title)

    if urlparse(resolved_url).path.lower().endswith(".pdf"):
        raise ProcessingFailure("URL_CONTENT_UNAVAILABLE", "The PDF URL returned different content")
    if content_type and not any(kind in content_type for kind in ("text/html", "application/xhtml+xml")):
        raise ProcessingFailure("URL_CONTENT_UNAVAILABLE")
    html_text, title = fetch_url_content(resolved_url, response=response)
    return SourceDocument(raw_text=html_text, source_type="url", title_hint=title)


@timed("source_extraction")
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
            raise ProcessingFailure("SOURCE_UNAVAILABLE")

        if source_path.suffix.lower() == ".pdf":
            return _pdf_extractor(str(source_path))
        if source_path.suffix.lower() == ".docx":
            docx_module = optional_module("docx")
            if docx_module is None or not hasattr(docx_module, "Document"):
                raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED")
            Document = cast(Any, docx_module.Document)

            try:
                document = Document(str(source_path))
                paragraphs: list[str] = []
                body_elements = getattr(getattr(document, "_element", None), "body", None)
                if body_elements is not None:
                    from docx.text.paragraph import Paragraph
                    from docx.table import Table

                    for child in body_elements:
                        tag = getattr(child, "tag", "")
                        if tag.endswith("p"):
                            p = Paragraph(child, document)
                            text = normalize_whitespace(p.text)
                            if text:
                                paragraphs.append(text)
                        elif tag.endswith("tbl"):
                            tbl = Table(child, document)
                            for row in tbl.rows:
                                row_cells: list[str] = []
                                for cell in row.cells:
                                    c_text = normalize_whitespace(cell.text)
                                    if c_text and (not row_cells or c_text != row_cells[-1]):
                                        row_cells.append(c_text)
                                if row_cells:
                                    paragraphs.append(" | ".join(row_cells))
                else:
                    for paragraph in document.paragraphs:
                        text = normalize_whitespace(paragraph.text)
                        if text:
                            paragraphs.append(text)
                    for tbl in getattr(document, "tables", []):
                        for row in tbl.rows:
                            row_cells = []
                            for cell in row.cells:
                                c_text = normalize_whitespace(cell.text)
                                if c_text and (not row_cells or c_text != row_cells[-1]):
                                    row_cells.append(c_text)
                            if row_cells:
                                paragraphs.append(" | ".join(row_cells))

                source_text = "\n\n".join(paragraphs)
                source_type = "docx"
            except BadZipFile as exc:
                raise ProcessingFailure("FILE_CORRUPTED") from exc
            except Exception as exc:
                from docx.opc.exceptions import PackageNotFoundError
                from lxml.etree import XMLSyntaxError
                if isinstance(exc, (PackageNotFoundError, XMLSyntaxError)):
                    raise ProcessingFailure("FILE_CORRUPTED") from exc
                raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc
        else:
            try:
                source_text = source_path.read_text(encoding="utf-8")
                source_type = source_path.suffix.lower().lstrip(".") or "file"
            except Exception as exc:
                raise ProcessingFailure("DOCUMENT_EXTRACTION_FAILED") from exc

    if source_text.strip() == "":
        raise ProcessingFailure("FILE_TEXT_UNAVAILABLE" if normalized_path else "INPUT_EMPTY")

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


__all__ = ['extract_pdf_source', 'extract_pdf_source_from_bytes', 'extract_pdf_source_from_reader', 'extract_pdf_source_with_ocr', 'extract_pdf_text', 'fetch_public_url_response', 'fetch_url_content', 'fetch_url_source', 'is_readable_extracted_text', 'load_source_document', 'load_source_text', 'sanitize_pdf_metadata_title', 'select_ocr_page_numbers', 'validate_pdf_path', 'validate_public_url_for_fetch']
