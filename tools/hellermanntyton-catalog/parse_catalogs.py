#!/usr/bin/env python3
"""Parse HellermannTyton catalog PDFs into one bundled info-provider extract."""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter
from pathlib import Path

from pypdf import PdfReader

ARTICLE_RE = re.compile(r"\b(\d{3}-\d{4,6})\b")
COLOR_RE = re.compile(
    r"((?:[A-Z][a-z]+(?:[ \t/-][A-Za-z][a-z]+){0,8})(?:\s*,\s*[A-Z][a-z]+(?:[ \t/-][A-Za-z][a-z]+){0,6})*)"
    r"\s+\(([A-Z]{2,10}(?:\s*,\s*[A-Z]{2,10})*)\)"
)
TOOL_TAIL_RE = re.compile(r"(?:\s+\d+(?:-\d+)?(?:;\d+(?:-\d+)?)*)+$")
TEMP_RE = re.compile(
    r"Operating Temperature\s+(-?\d+\s*°C\s+to\s+\+?-?\d+\s*°C)",
    re.I,
)
MATERIAL_RE = re.compile(r"^MATERIAL\s+(?!information\b)(.+)$", re.I)
SHRINK_RE = re.compile(r"Shrink Ratio\s+(.+)$", re.I)
PAGE_NO_RE = re.compile(r"^\d{1,4}$")
MATERIAL_CODES = {
    "PA6", "PA11", "PA12", "PA46", "PA66", "PA66HS", "PA66W", "PA66V0",
    "PA66MP", "PA66MP+", "PA66HIR", "PA66HIRHS", "PA66HIRHSUV", "PA66HIR(S)",
    "PA6FR", "PA6W", "PET", "PO", "PO-X", "TPE", "PP", "PPMP", "PPMP+",
    "PVC", "PE", "PEEK", "TPU", "SS304", "SS316", "EPDM", "SP",
}

SKIP_TYPE_EXACT = {
    "TYPE", "MATERIAL", "INSULATION", "CABLE", "COLOUR", "COLOR", "LENGTH",
    "WIDTH", "TOOLS", "ARTICLE-NO", "ARTICLE-NO.", "PACK", "CONT", "REEL",
    "SIZE", "VARIANT", "FLAMMABILITY", "WEIGHT", "BLACK", "NATURAL", "WHITE",
    "STANDARD", "FEATURES", "BENEFITS", "OVERVIEW", "PRODUCT", "SELECTION",
    "FURTHER", "INFORMATION", "RECOMMENDED", "DIMENSIONS", "PLEASE", "NOTE",
    "SUBJECT", "MINIMUM", "ORDER", "QUANTITY", "OTHER", "PACKAGING",
    *MATERIAL_CODES,
}

SKIP_LINE_PREFIX = (
    "date of issue",
    "further information",
    "recommended tools",
    "all dimensions",
    "please note",
    "features and benefits",
    "material information",
    "for more information",
    "subject to technical",
    "minimum order",
    "www.hellermann",
)

BOILERPLATE_RE = re.compile(
    r"(Date of issue|Further information at|Recommended Tools|"
    r"All dimensions in mm|Please note|Minimum Order Quantity|"
    r"Subject to technical|www\.HellermannTyton)",
    re.I,
)

CATALOGS = {
    "heat-shrink": {
        "id": "heat-shrink",
        "name": "Heat shrink and insulation",
        "category": "Insulation -> Heat shrink",
        "kind": "Heat shrink tubing",
        "catalog": "Insulation 2025 UK",
        "source": "ht_insulation_2025_uk.pdf",
        "section_default": "Heat shrink tubing",
    },
    "cable-protection": {
        "id": "cable-protection",
        "name": "Cable protection systems",
        "category": "Cable protection -> Sleeves",
        "kind": "Protective sleeve",
        "catalog": "Automotive Cable Protection Systems 2024",
        "source": "ht_automotive_catalogue_2024_cable_protection_systems_com.pdf",
        "section_default": "Protective sleeve",
    },
    "cable-ties-fixings": {
        "id": "cable-ties-fixings",
        "name": "Cable ties and fixings",
        "category": "Cable ties and fixings -> Ties",
        "kind": "Cable tie",
        "catalog": "Automotive Cable Ties and Fixings 2024",
        "source": "ht_automotive_catalogue_2024_cable_ties_and_fixings_com.pdf",
        "section_default": "Cable tie",
    },
}

SECTION_RULES = [
    (re.compile(r"Heat Shrinkable Tubing|Heat Shrink Tubing", re.I), "Heat shrink tubing"),
    (re.compile(r"Heat Shrink Moulded|Helashrink", re.I), "Heat shrink moulded shape"),
    (re.compile(r"Insulating Tubing|Helsyn", re.I), "Insulating tubing"),
    (re.compile(r"Protective Sleeve|Helagaine|braided sleeving", re.I), "Protective sleeve"),
    (re.compile(r"Cable ties", re.I), "Cable tie"),
    (re.compile(r"Fixing products|Bundling clip|cable tie mount", re.I), "Cable fixing"),
    (re.compile(r"Connector clip|Backshell", re.I), "Connector fixing"),
]


def normalize_spaces(text: str) -> str:
    text = text.replace("\ufb01", "fi").replace("\ufb02", "fl")
    text = text.replace("\u2013", "-").replace("\u2014", "-")
    text = text.replace("\u00a0", " ").replace("\u202f", " ")
    text = text.replace("\u201c", '"').replace("\u201d", '"')
    text = re.sub(r"[ \t]+", " ", text)
    return text.strip()


def looks_like_type(token: str) -> bool:
    token = token.strip()
    if not token or len(token) < 3 or len(token) > 42:
        return False
    upper = token.upper()
    if upper in SKIP_TYPE_EXACT or upper.split()[0] in MATERIAL_CODES:
        return False
    if any(token.lower().startswith(prefix) for prefix in SKIP_LINE_PREFIX):
        return False
    if token.startswith(("MIL", "UL-", "IEC", "ASTM", "FMVSS", "CISPR", "VG-")):
        return False
    if re.match(r"^(Quick|Non)-r", token, re.I):
        return False
    if re.match(r"^[A-Z]{1,12}\d[A-Za-z0-9()./-]*$", token):
        return True
    if re.match(r"^[A-Z][A-Za-z0-9]*-[A-Za-z0-9./()-]+$", token):
        return True
    if re.match(r"^[A-Z][A-Za-z]+(?:-[A-Z][a-z]+)+\s+\d{1,3}$", token):
        return True
    if re.match(r"^\d{2,4}-\d{1,2}-[A-Z0-9]+$", token):
        return True
    if re.match(r"^\d{3}[A-Z]\d+[A-Z0-9-]*$", token):
        return True
    if re.match(r"^[A-Z]{2,}(?:-[A-Z0-9]+){1,3}$", token):
        return True
    return False


def take_type_prefix(text: str) -> tuple[str, str]:
    tokens = text.split()
    if not tokens:
        return "", text
    two = " ".join(tokens[:2])
    if len(tokens) >= 2 and looks_like_type(two):
        return two, " ".join(tokens[2:])
    if looks_like_type(tokens[0]):
        return tokens[0], " ".join(tokens[1:])
    return "", text


def family_from_type(typ: str) -> str:
    if not typ:
        return ""
    sized = re.match(r"^(.+?)[-_\s]+(\d+(?:[./]\d+)*)$", typ)
    if sized:
        return sized.group(1)
    digits_only_suffix = re.match(r"^([A-Za-z][A-Za-z-]*?)(\d{2,})$", typ)
    if digits_only_suffix and re.search(r"\d[A-Z]", typ) is None:
        return digits_only_suffix.group(1)
    return typ


def extract_color(text: str) -> tuple[str, str, str]:
    matches = list(COLOR_RE.finditer(text))
    if not matches:
        return "", "", text
    names: list[str] = []
    codes: list[str] = []
    for match in matches:
        names.append(normalize_spaces(match.group(1)))
        codes.append(normalize_spaces(match.group(2)))
    cleaned = text
    for match in reversed(matches):
        cleaned = (cleaned[: match.start()] + " " + cleaned[match.end() :]).strip()
    return ", ".join(names), ", ".join(codes), normalize_spaces(cleaned)


def strip_tool_codes(text: str) -> str:
    return normalize_spaces(TOOL_TAIL_RE.sub("", text))


def infer_section(text: str, default: str) -> str:
    for pattern, label in SECTION_RULES:
        if pattern.search(text):
            return label
    return default


def printed_page(lines: list[str], fallback: int) -> int:
    for line in lines[:8]:
        if PAGE_NO_RE.match(line):
            return int(line)
    return fallback


def extract_page_specs(text: str) -> dict[str, str | None]:
    material = None
    for raw in text.splitlines():
        line = normalize_spaces(raw)
        match = MATERIAL_RE.match(line)
        if match:
            material = normalize_spaces(match.group(1))
            break
    temperature = None
    temp_match = TEMP_RE.search(text.replace("\n", " "))
    if temp_match:
        temperature = normalize_spaces(temp_match.group(1))
    shrink = None
    shrink_match = SHRINK_RE.search(text.replace("\n", " "))
    if shrink_match:
        shrink = normalize_spaces(shrink_match.group(1).split("Operating")[0])
    return {"material": material, "temperature": temperature, "shrink_ratio": shrink}


def extract_pages(pdf_path: Path, cache_path: Path) -> list[dict]:
    if cache_path.is_file():
        cached = json.loads(cache_path.read_text(encoding="utf-8"))
        if cached.get("source") == str(pdf_path) and cached.get("pages"):
            return cached["pages"]

    reader = PdfReader(str(pdf_path))
    pages = []
    for index, page in enumerate(reader.pages, start=1):
        pages.append({"pdf_page": index, "text": page.extract_text() or ""})
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(
        json.dumps({"source": str(pdf_path), "pages": pages}, ensure_ascii=False),
        encoding="utf-8",
    )
    return pages


def parse_pages(pages: list[dict], catalog: dict) -> list[dict]:
    articles: dict[str, dict] = {}
    current_type = ""
    current_section = catalog["section_default"]

    for page in pages:
        text = page["text"]
        lines = [normalize_spaces(line) for line in text.splitlines() if normalize_spaces(line)]
        page_no = printed_page(lines, page["pdf_page"])
        specs = extract_page_specs(text)
        current_section = infer_section(text, current_section)

        for line in lines:
            if line.upper() == "TYPE":
                current_type = ""
                continue
            if BOILERPLATE_RE.search(line) or any(line.lower().startswith(p) for p in SKIP_LINE_PREFIX):
                continue

            article_match = list(ARTICLE_RE.finditer(line))
            if not article_match:
                if looks_like_type(line):
                    current_type = line
                continue

            article_number = article_match[-1].group(1)
            before = strip_tool_codes(line[: article_match[-1].start()].strip())
            typ, before = take_type_prefix(before)
            if typ:
                current_type = typ
            else:
                typ = current_type

            color, color_code, rest = extract_color(before)
            rest = strip_tool_codes(rest)
            if not typ:
                lifted, rest = take_type_prefix(rest)
                if lifted:
                    typ = lifted
                    current_type = lifted
            family = family_from_type(typ) or current_section
            row = {
                "article_number": article_number,
                "type": typ,
                "family": family,
                "page": page_no,
                "color": color,
                "color_code": color_code,
                "specs": rest,
                "material": specs["material"],
                "temperature": specs["temperature"],
                "shrink_ratio": specs["shrink_ratio"],
                "section": current_section,
                "kind": catalog["kind"] if current_section == catalog["section_default"] else current_section,
            }
            articles[article_number] = row

    return list(articles.values())


def build_description(article: dict, catalog: dict) -> str:
    kind = article.get("kind") or catalog["kind"]
    typ = article.get("type") or ""
    color = article.get("color") or ""
    color_code = article.get("color_code") or ""
    specs = article.get("specs") or ""
    parts: list[str] = []
    if typ:
        parts.append(f"{kind} {typ}" if kind and kind.lower() not in typ.lower() else typ)
    else:
        parts.append(kind)
    if color:
        label = color
        if color_code and f"({color_code})" not in color:
            label = f"{color} ({color_code})"
        parts.append(label)
    size = first_size(typ, specs)
    if size:
        parts.append(size)
    return ", ".join(part for part in parts if part)


def first_size(typ: str, specs: str) -> str:
    type_size = re.search(r"(\d+(?:\.\d+)?\s*/\s*\d+(?:\.\d+)?)", typ)
    if type_size:
        return normalize_spaces(type_size.group(1).replace(" ", "")) + " mm"
    pair = re.match(r"^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)", specs)
    if pair:
        left = pair.group(1)
        right = pair.group(2)
        if float(right) >= 10:
            return f"{left} x {right} mm"
        return f"{left}-{right} mm"
    return ""


def write_catalog(articles: list[dict], catalog: dict, output_dir: Path) -> None:
    payload = {
        "id": catalog["id"],
        "name": catalog["name"],
        "category": catalog["category"],
        "catalog": catalog["catalog"],
        "source": catalog["source"],
        "articles": [],
    }
    for article in sorted(articles, key=lambda item: item["article_number"]):
        payload["articles"].append(
            {
                "article_number": article["article_number"],
                "type": article["type"],
                "family": article["family"],
                "page": article["page"],
                "color": article["color"],
                "color_code": article["color_code"],
                "specs": article["specs"],
                "material": article["material"],
                "temperature": article["temperature"],
                "shrink_ratio": article["shrink_ratio"],
                "section": article["section"],
                "kind": article["kind"],
                "description": build_description(article, catalog),
            }
        )
    path = output_dir / f"{catalog['id']}.json"
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {len(payload['articles']):5d} articles -> {path.name}", file=sys.stderr)


def resolve_pdf(downloads: Path, catalog: dict) -> Path:
    exact = downloads / catalog["source"]
    if exact.is_file():
        return exact
    stem = Path(catalog["source"]).stem
    matches = sorted(downloads.glob(f"{stem}*.pdf"))
    if matches:
        return matches[0]
    raise FileNotFoundError(f"PDF not found for {catalog['id']}: {catalog['source']}")


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--downloads",
        default=str(Path.home() / "Downloads"),
    )
    parser.add_argument(
        "--output",
        default=str(root / "src" / "Services" / "InfoProviderSystem" / "Resources" / "hellermanntyton"),
    )
    parser.add_argument(
        "--cache-dir",
        default=str(root / "var" / "hellermanntyton-pages"),
    )
    parser.add_argument("--stats-only", action="store_true")
    args = parser.parse_args()

    downloads = Path(args.downloads)
    output_dir = Path(args.output)
    cache_dir = Path(args.cache_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    totals = Counter()
    for key, catalog in CATALOGS.items():
        pdf_path = resolve_pdf(downloads, catalog)
        print(f"parsing {key}: {pdf_path.name}", file=sys.stderr)
        pages = extract_pages(pdf_path, cache_dir / f"{key}.json")
        articles = parse_pages(pages, catalog)
        totals[key] = len(articles)
        types = Counter(article["type"] or "?" for article in articles)
        print(f"  unique articles {len(articles)}", file=sys.stderr)
        print(f"  top types {types.most_common(8)}", file=sys.stderr)
        print(f"  sample {[a['article_number'] for a in articles[:8]]}", file=sys.stderr)
        if not args.stats_only:
            write_catalog(articles, catalog, output_dir)

    print(f"total unique articles {sum(totals.values())} {dict(totals)}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
