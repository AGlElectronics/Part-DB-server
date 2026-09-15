#!/usr/bin/env python3
"""Parse Landefeld Atlas 9 Compact EN PDF into bundled catalog JSON."""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

from pypdf import PdfReader

CHAPTERS = {
    1: "Tube connectors",
    2: "Threaded fittings",
    3: "Couplings",
    4: "Hoses, tubes and clamps",
    5: "Ball valves and shut-off fittings",
    6: "Pressure, temperature and air units",
    7: "Valves, throttles and silencers",
    8: "Cylinders, shock absorbers and vacuum",
    9: "Blow guns and tools",
    10: "Industrial demand",
}

CHAPTER_SLUGS = {
    1: "tube-connectors",
    2: "threaded-fittings",
    3: "couplings",
    4: "hoses-tubes-clamps",
    5: "ball-valves-shut-off",
    6: "pressure-temp-air-units",
    7: "valves-throttles-silencers",
    8: "cylinders-shock-vacuum",
    9: "blow-guns-tools",
    10: "industrial-demand",
}

SKIP_SERIES = {
    "TYPE", "PAGE", "PAGES", "CHAPTER", "MATERIALS", "TEMPERATURE", "OPERATING",
    "MEDIA", "ADVANTAGES", "WEBSHOP", "HOSES", "HOSE", "THREAD", "THREADS",
    "DIMENSIONS", "STANDARD", "MANY", "ONLINE", "ALL", "THE", "FOR", "AND",
    "WITH", "FROM", "TABLE", "UNIT", "CLASS", "MALE", "FEMALE", "METRIC",
    "INCH", "ORDER", "EXAMPLE", "DESIGNATION", "OPTIONS", "ALMOST", "PUSH",
    "CONNECTOR", "CONNECTORS", "FITTING", "FITTINGS", "SCREW", "SCREW-ON",
    "AVAILABLE", "IDEAL", "LARGE", "EXTREMELY", "NUMEROUS", "BODY", "SEAL",
    "HOLDING", "CARTRIDGE", "SILICONE", "COMPRESSED", "NEUTRAL", "WATER",
    "DIMENSION", "EXTERNAL", "NOMINAL", "CONVERSION", "PISTON", "KEYWORD",
    "DICTIONARY", "FAST", "DELIVERY", "STOCK", "ITEMS", "COMPACT", "ATLAS",
    "LANDEFELD", "DRUCKLUFT", "HYDRAULIK", "KONRAD", "KASSEL", "GERMANY",
    "SALES", "WWW", "TECHNICAL", "ADVICE", "SERVICE", "RE", "CODING",
    "PRESSURE", "DATA", "REFERS", "GROUP", "LIQUIDS", "UNBINDING", "LIABILITY",
    "COLLECTION", "CONTENTS", "CONTENTS", "JIC", "ORFS", "AMERICAN",
    "ISO", "DIN", "EN", "FDA", "NSF", "KTW", "DVGW", "POM", "PBT", "NBR",
    "PTFE", "PVC", "PE", "PP", "EPDM", "FKM", "FPM", "CR",
    "NW", "PN", "DN", "BSP", "UNC", "UNS", "NPT", "UNF", "UN",
    "IQS", "CK", "PK", "HP", "AC", "PL", "MS", "ES", "MSV",
    "DE", "NST", "SAE", "S3",
    "SEE", "ALSO", "NOTE", "NOTES", "MAX", "MIN", "TYP", "APPROVALS",
    "TEMPERATURE", "RANGE", "OPERATING", "PRESSURE", "MEDIA",
}

# One/two-letter tokens that appear as table leftovers, not series codes.
SKIP_SHORT = {"G", "R", "D", "A", "E", "P", "L", "T", "M", "I", "Ø"}

SUFFIXES = {"G", "IG", "I", "L", "GL", "R", "ES", "V"}
THREAD_TYPES = {"G", "M", "R", "NPT", "UNF", "UN", "RC", "BSP", "JIC", "ORFS"}

BOILERPLATE = re.compile(
    r"(www\.landefeld\.com|All data are considered|Webshop Service|"
    r"300,000 stock lines|Re-Coding service|Order online|"
    r"Fast Delivery|Dimensions can be|Many additional|"
    r"available in our|Online Shop|Pressure data refer)",
    re.I,
)
CHAPTER_RE = re.compile(r"^Chapter\s+(\d+)\s*[-–]\s*(.+)$", re.I)
PAGE_RE = re.compile(r"^(\d{1,3})\s+(?:Webshop|300,000)")
PAGE_ALT_RE = re.compile(r"^(\d{1,3})300,000")
TYPE_HEADER_RE = re.compile(r"^Type\b", re.I)
SERIES_RE = re.compile(r"^[A-Z][A-Z0-9]{1,12}$")
SIZE_RE = re.compile(r"^[A-Z0-9][A-Z0-9.,/xX\-*]{0,18}$")
TEMP_RE = re.compile(
    r"Temperature range:\s*(.+?)(?:\n|Operating pressure:|Media:|$)",
    re.I | re.S,
)
PRESSURE_RE = re.compile(
    r"Operating pressure:\s*(.+?)(?:\n|Media:|Temperature range:|$)",
    re.I | re.S,
)
MATERIALS_RE = re.compile(
    r"Materials:\s*(.+?)(?:\nTemperature range:|\nOperating pressure:|\nMedia:|$)",
    re.I | re.S,
)
MEDIA_RE = re.compile(
    r"Media:\s*(.+?)(?:\n[A-Z][a-z]|\nType\b|\nIQS|\nPush|\nL push|$)",
    re.I | re.S,
)
FAMILY_HINT_RE = re.compile(
    r"(fitting|connector|connection|valve|hose|tube|cylinder|gauge|"
    r"regulator|silencer|coupling|clamp|nozzle|screw|joint|distributor|"
    r"filter|lubricator|gun|absorber|pad|nipple|flange|compensator)",
    re.I,
)
BAD_FAMILY_RE = re.compile(
    r"(Optional:|Suitable:|Easy to|Li =|Cast rim|please enter|Order |"
    r"Roll length|Shore |Usable with|Very light|Light model|Heavy model|"
    r"Type with|Thread:|page no\.|In your order|Pressure/temperature|"
    r"^Materials?:|^Temperature|^Operating|^Media:|^Advantages:)",
    re.I,
)
MULTI_ARTICLE_RE = re.compile(
    r"\b((?:GE|W|T|RED|EW|EVW|EVL|ET|EL|EM)\s+\d+\s+[A-Z0-9/]+"
    r"(?:\s+(?:ES|NC|ED|ZYL|M\d+|\d/\d+))*)"
)


def normalize_spaces(text: str) -> str:
    text = text.replace("\ufb01", "fi").replace("\ufb02", "fl")
    text = text.replace("\u2013", "-").replace("\u2014", "-")
    text = text.replace("\u201c", '"').replace("\u201d", '"')
    text = text.replace("\u2018", "'").replace("\u2019", "'")
    text = re.sub(r"[ \t]+", " ", text)
    return text.strip()


def clean_line(line: str) -> str:
    return normalize_spaces(line).replace("*", "")


def looks_like_size(token: str) -> bool:
    return bool(re.match(r"^(\d+[.,]?\d*|(\d+/\d+)\"?|[M]\d+)", token))


def split_article(tokens: list[str]) -> tuple[str, str]:
    series, size, *rest = tokens
    size = size.rstrip("*")
    if not rest:
        return f"{series} {size}", ""

    first = rest[0]
    second = rest[1] if len(rest) > 1 else ""

    if first in SUFFIXES and second in THREAD_TYPES:
        return f"{series} {size} {first}", " ".join(rest[1:])
    if first in THREAD_TYPES:
        return f"{series} {size}", " ".join(rest)
    if first in SUFFIXES and (not second or looks_like_size(second)):
        return f"{series} {size}", " ".join(rest)
    if first in SUFFIXES:
        return f"{series} {size} {first}", " ".join(rest[1:])
    return f"{series} {size}", " ".join(rest)


def is_type_row(line: str) -> bool:
    tokens = clean_line(line).split()
    if len(tokens) < 2:
        return False
    series, size = tokens[0], tokens[1]
    if series in SKIP_SERIES or series in SKIP_SHORT:
        return False
    if not SERIES_RE.match(series):
        return False
    if not SIZE_RE.match(size.rstrip("*")):
        return False
    if series.startswith("HTTP") or series in {"DIN", "ISO", "EN"}:
        return False
    return True


def is_family_heading(line: str) -> bool:
    text = clean_line(line)
    if not text or BOILERPLATE.search(text) or BAD_FAMILY_RE.search(text):
        return False
    if text.startswith(("Ÿ", "•", "-", "ü", "Ø")):
        return False
    if TYPE_HEADER_RE.match(text) or is_type_row(text):
        return False
    if CHAPTER_RE.match(text) or PAGE_RE.match(text):
        return False
    if text.lower() in {"advantages:", "materials:", "temperature range:", "operating pressure:", "media:"}:
        return False
    if text.startswith(("Materials:", "Temperature range:", "Operating pressure:", "Media:", "Advantages:")):
        return False
    if len(text) < 8 or len(text) > 90:
        return False
    if text.endswith(":"):
        text = text[:-1]
    words = text.split()
    if len(words) < 2:
        return False
    if FAMILY_HINT_RE.search(text):
        return True
    if text[0].isupper() and not text.isupper() and len(words) <= 12:
        return True
    return False


def articles_from_line(line: str) -> list[tuple[str, str]]:
    found: list[tuple[str, str]] = []
    for match in MULTI_ARTICLE_RE.finditer(line):
        article = normalize_spaces(match.group(1))
        if article.startswith("---"):
            continue
        found.append((article, ""))
    if found:
        leftover = MULTI_ARTICLE_RE.sub(" ", line)
        leftover = re.sub(r"\s+", " ", leftover).replace("---", "").strip()
        return [(article, leftover) for article, _ in found]

    if is_type_row(line):
        article, specs = split_article(line.split())
        return [(article, specs)]
    return []


def extract_spec_block(text: str, pattern: re.Pattern[str]) -> str | None:
    match = pattern.search(text)
    if not match:
        return None
    value = normalize_spaces(match.group(1))
    value = re.sub(r"\s+", " ", value).strip(" .;")
    return value or None


def extract_pages(pdf_path: Path, cache_path: Path) -> list[str]:
    if cache_path.is_file():
        return json.loads(cache_path.read_text(encoding="utf-8"))

    reader = PdfReader(str(pdf_path))
    pages: list[str] = []
    for index, page in enumerate(reader.pages, start=1):
        pages.append(page.extract_text() or "")
        if index % 50 == 0:
            print(f"extracted {index}/{len(reader.pages)} pages", file=sys.stderr)
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(pages, ensure_ascii=False), encoding="utf-8")
    return pages


def parse_pages(pages: list[str]) -> list[dict]:
    articles: dict[str, dict] = {}
    chapter = 0
    catalog_page = 0
    family = ""
    in_table = False

    for pdf_page, raw in enumerate(pages, start=1):
        text = raw.replace("\x00", "")
        page_temp = extract_spec_block(text, TEMP_RE)
        page_pressure = extract_spec_block(text, PRESSURE_RE)
        page_materials = extract_spec_block(text, MATERIALS_RE)
        page_media = extract_spec_block(text, MEDIA_RE)
        allow_articles = pdf_page >= 27

        for raw_line in text.splitlines():
            line = clean_line(raw_line)
            if not line:
                continue

            chapter_match = CHAPTER_RE.match(line)
            if chapter_match:
                chapter = int(chapter_match.group(1))
                in_table = False
                continue

            page_match = PAGE_RE.match(line) or PAGE_ALT_RE.match(line)
            if page_match:
                catalog_page = int(page_match.group(1))
                continue

            if TYPE_HEADER_RE.match(line):
                in_table = True
                continue

            if is_family_heading(line):
                family = line.rstrip(":")
                in_table = False
                continue

            if BOILERPLATE.search(line):
                in_table = False
                continue

            if not allow_articles:
                continue

            extracted = articles_from_line(line)
            if not extracted:
                if in_table and not re.match(r"^[\dØGARP .\-\"“”/x]+$", line):
                    in_table = False
                continue

            for article_number, specs in extracted:
                key = article_number.upper()
                series = article_number.split()[0]
                if key in articles:
                    existing = articles[key]
                    if family and family not in existing["families"]:
                        existing["families"].append(family)
                    continue

                articles[key] = {
                    "article_number": article_number,
                    "series": series,
                    "family": family or CHAPTERS.get(chapter, "Atlas 9 Compact"),
                    "families": [family] if family else [],
                    "chapter": chapter,
                    "category": CHAPTERS.get(chapter, "Atlas 9 Compact"),
                    "page": catalog_page,
                    "pdf_page": pdf_page,
                    "specs": specs,
                    "temperature": page_temp,
                    "pressure": page_pressure,
                    "materials": page_materials,
                    "media": page_media,
                }

    return list(articles.values())


def article_description(article: dict) -> str:
    parts = [article["family"]]
    if article.get("specs"):
        parts.append(article["specs"])
    return ", ".join(part for part in parts if part)


def write_catalog(articles: list[dict], output_dir: Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    for old in output_dir.glob("*.json"):
        if old.name != "manufacturer.json":
            old.unlink()

    grouped: dict[int, list[dict]] = defaultdict(list)
    for article in articles:
        grouped[int(article["chapter"] or 0)].append(article)

    written = 0
    for chapter, rows in sorted(grouped.items()):
        if chapter not in CHAPTERS:
            continue
        slug = CHAPTER_SLUGS[chapter]
        payload = {
            "id": slug,
            "name": CHAPTERS[chapter],
            "category": f"Pneumatics -> {CHAPTERS[chapter]}",
            "chapter": chapter,
            "articles": [],
        }
        for article in sorted(rows, key=lambda item: item["article_number"]):
            payload["articles"].append(
                {
                    "article_number": article["article_number"],
                    "series": article["series"],
                    "family": article["family"],
                    "page": article["page"],
                    "specs": article["specs"],
                    "temperature": article["temperature"],
                    "pressure": article["pressure"],
                    "materials": article["materials"],
                    "media": article["media"],
                    "description": article_description(article),
                }
            )
        path = output_dir / f"{slug}.json"
        path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        written += 1
        print(f"wrote {len(payload['articles']):5d} articles -> {path.name}", file=sys.stderr)

    print(f"wrote {written} chapter files, {len(articles)} unique articles", file=sys.stderr)


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--pdf",
        default=str(root / "catalogs" / "Landefeld_Atlas9_compact_EN.pdf"),
    )
    parser.add_argument(
        "--output",
        default=str(root / "src" / "Services" / "InfoProviderSystem" / "Resources" / "landefeld"),
    )
    parser.add_argument(
        "--cache",
        default=str(root / "var" / "landefeld_atlas9_pages.json"),
    )
    parser.add_argument("--stats-only", action="store_true")
    args = parser.parse_args()

    pdf_path = Path(args.pdf)
    if not pdf_path.is_file():
        print(f"PDF not found: {pdf_path}", file=sys.stderr)
        return 1

    pages = extract_pages(pdf_path, Path(args.cache))
    articles = parse_pages(pages)

    series_counts = Counter(article["series"] for article in articles)
    chapter_counts = Counter(article["category"] for article in articles)
    print("unique articles", len(articles), file=sys.stderr)
    print("top series", series_counts.most_common(20), file=sys.stderr)
    print("chapters", chapter_counts.most_common(), file=sys.stderr)
    print("sample", [a["article_number"] for a in articles[:15]], file=sys.stderr)

    if args.stats_only:
        return 0

    write_catalog(articles, Path(args.output))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
