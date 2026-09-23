"""One-shot builder for the DEUTSCH DT wedgelock Part-DB import CSV."""

import csv
from pathlib import Path

OUT = Path(__file__).with_name("deutsch-dt-wedgelocks.csv")

# Pages confirmed on te.com. W4S is an alias of W4S-ZZ; product-W4S.html 404s.
TE_PAGE = {
    "W2P", "W2PA", "W2PB", "W2PC", "W2PD",
    "W2S", "W2SA", "W2SB", "W2SC", "W2SD",
    "W3P", "W3P-1939", "W3S", "W3S-1939",
    "W4P", "W4PA", "W4PB", "W4PC", "W4PD",
    "W4S-ZZ", "W4SA", "W4SB", "W4SC", "W4SD",
    "W6P", "W6PA", "W6PB", "W6S", "W6SA",
    "W8P", "W8S",
    "W12P", "W12S",
}

STATUS = {
    "W4SA": "nrfnd",
    "W4SB": "nrfnd",
    "W4SC": "nrfnd",
    "W4SD": "nrfnd",
    "W6PB": "discontinued",
    "W6SA": "discontinued",
}

# TE-published color when it does not follow the 2/4-pin key code.
COLOR_OVERRIDE = {
    "W6PA": "black",
}

KEY_COLOR = {
    "": None,
    "A": "gray",
    "B": "black",
    "C": "green",
    "D": "brown",
}

PINS = (2, 3, 4, 6, 8, 12)
SIDES = (
    ("P", "receptacle", "DT04"),
    ("S", "plug", "DT06"),
)
KEYS = ("", "A", "B", "C", "D")

CATEGORY = "Automotive Connectors->Deutsch->Wedgelocks"


def storage_location(pins: int) -> str:
    return f"Deutsch->Deutsch {pins} Pos"


def part_number(pins: int, side: str, key: str) -> str:
    if pins == 4 and side == "S" and key == "":
        return "W4S-ZZ"
    return f"W{pins}{side}{key}"


def color_for(pn: str, side: str, key: str) -> str:
    if pn in COLOR_OVERRIDE:
        return COLOR_OVERRIDE[pn]
    if key == "":
        return "green" if side == "P" else "orange"
    return KEY_COLOR[key]


def description(pn: str, pins: int, side_name: str, key: str, color: str) -> str:
    key_text = "standard" if key == "" else f"{key} key"
    text = f"DEUTSCH DT wedgelock, {pins}-pin {side_name}, {key_text}, {color}"
    if pn == "W4S-ZZ":
        text += ". TE internal number W4S-ZZ, alias W4S"
    return text


def notes(pn: str, housing: str, key: str) -> str:
    lines = [f"Fits {housing} {('pin' if housing == 'DT04' else 'socket')} housing."]
    if pn == "W4S-ZZ":
        lines.append("Orderable alias W4S. The TE product page is W4S-ZZ.")
    if pn in ("W4SA", "W4SB", "W4SC", "W4SD"):
        lines.append(
            "TE marks this standard plug wedge do-not-use-for-new-design. "
            "The current seal-retention part is "
            + pn
            + "-P012."
        )
    if pn == "W6PA":
        lines.append("TE lists this A-key receptacle wedge as black, not gray.")
    if pn in ("W6PB", "W6SA"):
        lines.append(
            "TE lists this part as obsolete and does not publish a color. "
            "Color here follows the DT 2-pin and 4-pin key code."
        )
    if pn not in TE_PAGE:
        lines.append(
            "No TE product page. Key letter and color follow the DT 2-pin and 4-pin "
            "wedge code (A gray, B black, C green, D brown; plain receptacle green; "
            "plain plug orange). Confirm color and availability with the distributor."
        )
    elif key == "" and pn not in COLOR_OVERRIDE:
        lines.append("Plain wedge, no key letter.")
    return " ".join(lines)


def row(pn: str, pins: int, side: str, side_name: str, housing: str, key: str) -> dict:
    color = color_for(pn, side, key)
    has_page = pn in TE_PAGE
    status = STATUS.get(pn, "active" if has_page else "")
    tags = [
        "Deutsch",
        "DT",
        "Wedgelock",
        f"{pins}-pin",
        side_name,
        "standard" if key == "" else f"{key}-key",
    ]
    if not has_page:
        tags.append("no-te-page")
    url = f"https://www.te.com/en/product-{pn}.html" if has_page else ""
    return {
        "name": pn,
        "description": description(pn, pins, side_name, key, color),
        "category": CATEGORY,
        "notes": notes(pn, housing, key),
        "tags": ",".join(tags),
        "mpn": pn,
        "manufacturing_status": status,
        "manufacturer": "TE Connectivity",
        "manufacturer_product_url": url,
        "needs_review": "0" if has_page else "1",
        "minamount": "10",
        "partUnit": "pcs",
        "storage_location": storage_location(pins),
        "amount": "0",
    }


def main() -> None:
    fieldnames = [
        "name",
        "description",
        "category",
        "notes",
        "tags",
        "mpn",
        "manufacturing_status",
        "manufacturer",
        "manufacturer_product_url",
        "needs_review",
        "minamount",
        "partUnit",
        "storage_location",
        "amount",
    ]
    rows = []
    for pins in PINS:
        for side, side_name, housing in SIDES:
            for key in KEYS:
                if key == "":
                    continue
                pn = part_number(pins, side, key)
                rows.append(row(pn, pins, side, side_name, housing, key))
            if pins == 3:
                j1939 = "W3P-1939" if side == "P" else "W3S-1939"
                rows.append(
                    {
                        "name": j1939,
                        "description": (
                            f"DEUTSCH DT wedgelock, 3-pin {side_name}, J1939 key, blue"
                        ),
                        "category": CATEGORY,
                        "notes": (
                            f"Fits {housing} "
                            + ("pin" if side == "P" else "socket")
                            + " housing. J1939 key."
                        ),
                        "tags": f"Deutsch,DT,Wedgelock,3-pin,{side_name},J1939",
                        "mpn": j1939,
                        "manufacturing_status": "active",
                        "manufacturer": "TE Connectivity",
                        "manufacturer_product_url": (
                            f"https://www.te.com/en/product-{j1939}.html"
                        ),
                        "needs_review": "0",
                        "minamount": "10",
                        "partUnit": "pcs",
                        "storage_location": storage_location(3),
                        "amount": "0",
                    }
                )
    with OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=fieldnames,
            delimiter=";",
            lineterminator="\n",
            quoting=csv.QUOTE_MINIMAL,
        )
        writer.writeheader()
        writer.writerows(rows)
    missing = sum(1 for item in rows if item["needs_review"] == "1")
    print(f"Wrote {OUT} rows={len(rows)} no_te_page={missing}")


if __name__ == "__main__":
    main()
