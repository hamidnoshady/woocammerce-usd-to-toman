#!/usr/bin/env python3
"""Draw the WordPress.org artwork: icons, banners and screenshots.

The images that belong to the plugin directory live in the `assets/` folder of the
WordPress.org SVN repository, never in the plugin zip. This script draws them from
the same palette and the same strings the admin screens use, so wording changes are
regenerated instead of redrawn by hand.

Usage:

    python3 wordpress-org/make-artwork.py --font-dir=/path/to/ttf --out=wordpress-org

The screens use the plugin's own wording: every plugin string is looked up by its
msgid in `languages/*-fa_IR.po`, so the English text is the real source string and
the Persian screenshot shows the translation that ships with the plugin.

Persian strings go through `shape_fa.shape()`: Pillow has no complex text layout
(raqm), so without shaping the letters would be drawn disconnected and in the wrong
order. Everything is drawn at the size the listing expects, except the half size
banner and the small icon, which are downscaled from their retina versions.

Requires Pillow (`pip install Pillow`) and the Vazirmatn font (SIL OFL 1.1).
"""

import argparse
import json
import re
import sys
from pathlib import Path

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:  # pragma: no cover - tooling guard
    sys.exit("This tool needs Pillow: pip install Pillow")

sys.path.insert(0, str(Path(__file__).resolve().parent))

from shape_fa import shape  # noqa: E402  (import after sys.path tweak)

NAVY = "#0B2447"
TEAL = "#0E7C86"
GOLD = "#F4B740"
WHITE = "#FFFFFF"
PANEL = "#F0F0F1"
CARD = "#FFFFFF"
BORDER = "#DCDCDE"
INK = "#1D2327"
MUTED = "#646970"
LINK = "#2271B1"
SIDEBAR = "#1C1F23"
SIDEBAR_ON = "#2271B1"
NOTICE_BG = "#FBF5E6"
NOTICE_INK = "#7A5A12"
OK_BG = "#EEF4FB"
OK_INK = "#2271B1"
GREEN = "#007017"
RED = "#B32D2E"
SOFT = "#D7E8EA"

WEIGHTS = ("Regular", "Medium", "SemiBold", "Bold")


class Art:
    """Fonts, a canvas and the drawing helpers every image shares."""

    def __init__(self, font_dir: Path):
        self.fonts = {
            weight: font_dir / f"Vazirmatn-{weight}.ttf"
            for weight in WEIGHTS
        }
        self._cache = {}

        for weight, path in self.fonts.items():
            if not path.is_file():
                sys.exit(f"Missing font: {path}")

    def font(self, weight: str, size: int):
        key = (weight, size)

        if key not in self._cache:
            # The basic layout engine on purpose: the Persian text is shaped and
            # reordered by shape_fa already, and raqm would reorder it a second time.
            self._cache[key] = ImageFont.truetype(
                str(self.fonts[weight]), size, layout_engine=ImageFont.Layout.BASIC
            )

        return self._cache[key]

    # -- canvas ----------------------------------------------------------

    @staticmethod
    def canvas(width: int, height: int) -> Image.Image:
        return Image.new("RGBA", (width, height), WHITE)

    @staticmethod
    def gradient(image: Image.Image, start: str, end: str) -> None:
        """Fill the canvas with a diagonal gradient."""
        draw = ImageDraw.Draw(image)
        width, height = image.size
        start_rgb = tuple(int(start[i : i + 2], 16) for i in (1, 3, 5))
        end_rgb = tuple(int(end[i : i + 2], 16) for i in (1, 3, 5))

        for y in range(height):
            ratio_y = y / height

            for x in range(0, width, 8):
                ratio = (x / width + ratio_y) / 2
                color = tuple(
                    round(start_rgb[channel] + (end_rgb[channel] - start_rgb[channel]) * ratio)
                    for channel in range(3)
                )
                draw.rectangle([x, y, x + 7, y], fill=color)

    # -- shapes ----------------------------------------------------------

    @staticmethod
    def roundrect(draw: ImageDraw.ImageDraw, box, radius: int, fill):
        draw.rounded_rectangle(box, radius=radius, fill=fill)

    @staticmethod
    def card(draw: ImageDraw.ImageDraw, box, radius: int = 6):
        draw.rounded_rectangle(box, radius=radius, fill=CARD, outline=BORDER, width=1)

    # -- text ------------------------------------------------------------

    @staticmethod
    def measure(draw: ImageDraw.ImageDraw, text: str, font) -> tuple:
        box = draw.textbbox((0, 0), text, font=font)

        return box[2] - box[0], box[3] - box[1]

    @classmethod
    def text_at(cls, draw, x: int, y: int, text: str, font, fill) -> tuple:
        """Draw text with the top left of its ink box at (x, y)."""
        box = draw.textbbox((0, 0), text, font=font)
        draw.text((x - box[0], y - box[1]), text, font=font, fill=fill)

        return box[2] - box[0], box[3] - box[1]

    @classmethod
    def text_center(cls, draw, box, text: str, font, fill) -> None:
        width, height = cls.measure(draw, text, font)
        x = box[0] + (box[2] - box[0] - width) / 2
        y = box[1] + (box[3] - box[1] - height) / 2

        cls.text_at(draw, round(x), round(y), text, font, fill)

    @classmethod
    def text_right(cls, draw, x: int, y: int, right: int, text: str, font, fill) -> None:
        width, _ = cls.measure(draw, text, font)

        cls.text_at(draw, round(right - width), y, text, font, fill)


# ---------------------------------------------------------------------------
# Icons and banners.
# ---------------------------------------------------------------------------


def make_icon(art: Art, out: Path, size: int) -> None:
    image = art.canvas(size, size)
    art.gradient(image, NAVY, TEAL)
    draw = ImageDraw.Draw(image)

    # Gold band low on the tile, the dollar sign above the Toman word.
    draw.rectangle((0, int(size * 0.74), size, int(size * 0.79)), fill=GOLD)

    dollar_font = art.font("Bold", int(size * 0.46))
    toman_font = art.font("Bold", int(size * 0.19))
    toman = shape("تومان")

    dollar_w, dollar_h = art.measure(draw, "$", dollar_font)
    toman_w, toman_h = art.measure(draw, toman, toman_font)

    gap = int(size * 0.06)
    total = dollar_h + gap + toman_h
    top = (size - total) / 2

    art.text_at(draw, round((size - dollar_w) / 2), round(top), "$", dollar_font, WHITE)
    art.text_at(
        draw,
        round((size - toman_w) / 2),
        round(top + dollar_h + gap),
        toman,
        toman_font,
        WHITE,
    )

    image.convert("RGB").save(out)


def make_banner(art: Art, out: Path) -> None:
    width, height = 1544, 500
    image = art.canvas(width, height)
    art.gradient(image, NAVY, TEAL)
    draw = ImageDraw.Draw(image)

    draw.rectangle((0, height - 10, width, height), fill=GOLD)

    art.text_at(draw, 78, 88, "USD to Toman Price Sync", art.font("Bold", 64), WHITE)
    art.text_at(draw, 82, 186, "for WooCommerce", art.font("SemiBold", 36), GOLD)

    line = art.font("Regular", 24)

    art.text_at(draw, 82, 258, "USD-pegged prices  ·  Manual rate  ·  Dry run before every write", line, SOFT)
    art.text_at(draw, 82, 300, "Queued background updates  ·  Rate history and rollback  ·  Persian translation included", line, SOFT)

    card_box = (1058, 118, 1462, 382)
    art.card(draw, card_box)

    mut = art.font("Medium", 19)
    ink = art.font("Medium", 25)

    # One product, as the plugin shows it: the WooCommerce price, the USD price it
    # is pegged to, and the price after a rate change (270,000 -> 300,000).
    art.text_at(draw, 1086, 142, "Rate: 270,000 Toman per $1", mut, MUTED)
    art.text_right(draw, 0, 142, 1434, shape("نرخ ارز"), mut, MUTED)

    art.text_at(draw, 1086, 184, "WooCommerce price", ink, INK)
    art.text_at(draw, 1086, 214, "5,000,000 Toman", art.font("Bold", 34), INK)

    draw.line((1086, 276, 1434, 276), fill=BORDER)

    art.text_at(draw, 1086, 296, "USD price", ink, MUTED)
    art.text_at(draw, 1086, 326, "$18.52", art.font("Bold", 34), LINK)
    art.text_at(draw, 1214, 342, "At 300,000: 5,556,000", art.font("Regular", 18), MUTED)

    image.convert("RGB").save(out)

    # WordPress.org shows this next to the retina banner.
    half = Image.open(out).resize((772, 250), Image.LANCZOS)
    half.save(out.with_name("banner-772x250.png"))


# ---------------------------------------------------------------------------
# Screenshots.
# ---------------------------------------------------------------------------

MENU = (
    ("WooCommerce", True),
    ("Dashboard", False),
    ("Posts", False),
    ("Media", False),
    ("Pages", False),
    ("Products", False),
    ("Orders", False),
    ("Analytics", False),
    ("Appearance", False),
    ("Plugins", False),
    ("Settings", False),
)

# The same WordPress menu in the fa_IR admin. Core and WooCommerce labels are not
# part of this plugin's catalog, so they are written out here.
MENU_FA = {
    "WooCommerce": "ووکامرس",
    "Dashboard": "پیشخوان",
    "Posts": "نوشته‌ها",
    "Media": "رسانه",
    "Pages": "برگه‌ها",
    "Products": "محصولات",
    "Orders": "سفارش‌ها",
    "Analytics": "تحلیل‌ها",
    "Appearance": "نمایش",
    "Plugins": "افزونه‌ها",
    "Settings": "تنظیمات",
}

# Colors of the status pills, as in assets/css/admin.css.
PILLS = {
    "changed": ("#EDFAEF", "#1E6B2F"),
    "managed": ("#EDFAEF", "#1E6B2F"),
    "unchanged": ("#EEF4FB", "#1D4D7A"),
    "generated": ("#F3EEFB", "#4B2A7A"),
    "rebased": ("#F3EEFB", "#4B2A7A"),
    "skipped": ("#FBF5E6", "#7A5A12"),
    "excluded": ("#FBF5E6", "#7A5A12"),
    "failed": ("#FCEBEA", "#8A1F11"),
    "conflict": ("#FCEBEA", "#8A1F11"),
}

STRIPE = "#F6F7F7"
FIELD_BORDER = "#8C8F94"

_PLACEHOLDER = re.compile(r"%(?:(\d+)\$)?[sd]|%%")

_CATALOG = {}


def load_catalog(path: Path) -> dict:
    """Read the msgid => msgstr pairs of a PO file (fuzzy, plural and context entries are skipped)."""
    catalog = {}

    for block in path.read_text(encoding="utf-8").split("\n\n"):
        lines = block.strip("\n").split("\n")

        if any(line.startswith(("#, fuzzy", "#~", "msgctxt", "msgid_plural")) for line in lines):
            continue

        fields = {"msgid": [], "msgstr": []}
        current = None

        for line in lines:
            if line.startswith("msgid "):
                current, line = "msgid", line[6:]
            elif line.startswith("msgstr "):
                current, line = "msgstr", line[7:]
            elif not line.startswith('"'):
                continue

            if current:
                fields[current].append(json.loads(line))

        msgid, msgstr = "".join(fields["msgid"]), "".join(fields["msgstr"])

        if msgid and msgstr:
            catalog[msgid] = msgstr

    return catalog


class Tx:
    """The plugin's own strings, in English or from the bundled fa_IR translation.

    Every plugin string goes through here by its msgid, so the mock-ups can only
    use wording that exists in the plugin, and the Persian ones are the shipped
    translation. A msgid that is not in the catalog stops the generator.
    """

    def __init__(self, rtl: bool):
        self.rtl = rtl

        if not _CATALOG:
            po = Path(__file__).resolve().parent.parent / "languages" / "usd-to-toman-price-sync-for-woocommerce-fa_IR.po"
            _CATALOG.update(load_catalog(po))

    def raw(self, msgid: str, *args) -> str:
        """The string in the screenshot's language, placeholders filled, not shaped yet."""
        if msgid not in _CATALOG:
            sys.exit(f"The string is not in the Persian catalog (wrong wording?): {msgid!r}")

        text = _CATALOG[msgid] if self.rtl else msgid
        sequence = iter(args)

        def fill(match):
            if match.group(0) == "%%":
                return "%"

            return str(args[int(match.group(1)) - 1] if match.group(1) else next(sequence))

        return _PLACEHOLDER.sub(fill, text)

    def show(self, text: str) -> str:
        """Prepare drawable text: shaped and reordered for Persian."""
        return shape(text) if self.rtl else text

    def __call__(self, msgid: str, *args) -> str:
        return self.show(self.raw(msgid, *args))


class Screen:
    """The WordPress admin chrome the plugin's screens sit in, plus layout helpers.

    Positions are offsets from the leading edge of the content column: the left
    edge in English and the right edge in Persian, so one layout serves both.
    """

    def __init__(self, art: Art, title: str, rtl: bool = False):
        self.art = art
        self.rtl = rtl
        self.tx = Tx(rtl)
        self.image = art.canvas(1280, 800)
        self.draw = ImageDraw.Draw(self.image)

        draw = self.draw
        draw.rectangle((0, 0, 1280, 800), fill=PANEL)

        self.sidebar_x = 1280 - 170 if rtl else 0
        draw.rectangle((self.sidebar_x, 0, self.sidebar_x + 170, 800), fill=SIDEBAR)

        menu_font = art.font("Regular", 15)

        for index, (label, current) in enumerate(MENU):
            y = 40 + index * 34
            color = SIDEBAR_ON if current else "#C3C4C7"

            if rtl:
                # Right aligned inside the sidebar, twenty pixels off the edge.
                art.text_right(draw, 0, y + 4, self.sidebar_x + 150, shape(MENU_FA[label]), menu_font, color)
            else:
                art.text_at(draw, 26, y + 4, label, menu_font, color)

        bar_left = 0 if rtl else 170
        bar_right = self.sidebar_x if rtl else 1280
        draw.rectangle((bar_left, 0, bar_right, 46), fill="#1D2327")

        bar_font = art.font("Regular", 15)
        site = self.tx("USD/Toman pricing")
        greeting = shape("سلام، admin") if rtl else "Howdy, admin"

        if rtl:
            art.text_right(draw, 0, 14, bar_right - 20, site, bar_font, "#C3C4C7")
            art.text_at(draw, bar_left + 20, 14, greeting, bar_font, "#C3C4C7")
        else:
            art.text_at(draw, bar_left + 20, 14, site, bar_font, "#C3C4C7")
            art.text_right(draw, 0, 14, bar_right - 20, greeting, bar_font, "#C3C4C7")

        self.left = 40 if rtl else 200
        self.width = 1020
        self.right = self.left + self.width

        self.text(0, 74, title, art.font("Regular", 28), INK)

    # -- geometry --------------------------------------------------------

    def span(self, off: int, width: int) -> tuple:
        """Absolute x range of a box that starts `off` from the leading edge."""
        start = self.right - off - width if self.rtl else self.left + off

        return start, start + width

    # -- text ------------------------------------------------------------

    def width_of(self, text: str, font) -> float:
        return self.draw.textlength(text, font=font)

    def text(self, off: int, top: int, text: str, font, fill) -> float:
        """Draw text starting `off` from the leading edge. `top` is where capital letters start."""
        baseline = top + round(font.size * 0.72)

        if self.rtl:
            self.draw.text((self.right - off, baseline), text, font=font, fill=fill, anchor="rs")
        else:
            self.draw.text((self.left + off, baseline), text, font=font, fill=fill, anchor="ls")

        return self.width_of(text, font)

    def text_trail(self, off: int, top: int, text: str, font, fill) -> float:
        """Draw text ending `off` from the trailing edge."""
        baseline = top + round(font.size * 0.72)

        if self.rtl:
            self.draw.text((self.left + off, baseline), text, font=font, fill=fill, anchor="ls")
        else:
            self.draw.text((self.right - off, baseline), text, font=font, fill=fill, anchor="rs")

        return self.width_of(text, font)

    def lines(self, raw: str, font, max_width: int) -> list:
        """Break a raw string into drawable lines that fit `max_width`."""
        words, rows, current = raw.split(" "), [], []

        for word in words:
            attempt = " ".join(current + [word])

            if current and self.width_of(self.tx.show(attempt), font) > max_width:
                rows.append(" ".join(current))
                current = [word]
            else:
                current.append(word)

        rows.append(" ".join(current))

        return [self.tx.show(row) for row in rows]

    def paragraph(self, off: int, top: int, raw: str, font, fill, max_width: int, step: int) -> int:
        """Draw wrapped text and return the y below the last line."""
        for line in self.lines(raw, font, max_width):
            self.text(off, top, line, font, fill)
            top += step

        return top

    # -- shapes ----------------------------------------------------------

    def card(self, off: int, top: int, width: int, height: int) -> None:
        x0, x1 = self.span(off, width)
        self.art.card(self.draw, (x0, top, x1, top + height))

    def rule(self, off: int, y: int, width: int, color: str = BORDER) -> None:
        x0, x1 = self.span(off, width)
        self.draw.line((x0, y, x1, y), fill=color)

    def fill(self, off: int, top: int, width: int, height: int, color: str) -> None:
        x0, x1 = self.span(off, width)
        self.draw.rectangle((x0, top, x1, top + height), fill=color)

    def button(self, off: int, top: int, label: str, primary: bool = False) -> int:
        """A WordPress button; returns its width."""
        font = self.art.font("SemiBold", 16)
        width = round(self.width_of(label, font)) + 40
        x0, x1 = self.span(off, width)
        box = (x0, top, x1, top + 38)

        if primary:
            self.art.roundrect(self.draw, box, 4, LINK)
        else:
            self.art.roundrect(self.draw, box, 4, "#F6F7F7")
            self.draw.rounded_rectangle(box, radius=4, outline=LINK, width=1)

        self.art.text_center(self.draw, box, label, font, WHITE if primary else LINK)

        return width

    def input(self, off: int, top: int, width: int, height: int, value: str, color: str = INK) -> None:
        x0, x1 = self.span(off, width)
        self.draw.rectangle((x0, top, x1, top + height), fill=CARD, outline=FIELD_BORDER)
        self.text(off + 12, top + round((height - 17 * 0.72) / 2) - 1, value, self.art.font("Regular", 17), color)

    def select(self, off: int, top: int, width: int, height: int, label: str, size: int = 15) -> None:
        """A closed select box with its arrow on the trailing side."""
        x0, x1 = self.span(off, width)
        self.draw.rectangle((x0, top, x1, top + height), fill=CARD, outline=FIELD_BORDER)

        font = self.art.font("Regular", size)
        self.text(off + 12, top + round((height - size * 0.72) / 2) - 1, label, font, INK)

        cx = x0 + 16 if self.rtl else x1 - 16
        cy = top + height // 2
        self.draw.polygon([(cx - 5, cy - 2), (cx + 5, cy - 2), (cx, cy + 4)], fill=MUTED)

    def pill(self, off: int, top: int, label: str, kind: str) -> int:
        """A status badge; returns its width."""
        font = self.art.font("SemiBold", 12)
        width = round(self.width_of(label, font)) + 18
        x0, x1 = self.span(off, width)
        background, ink = PILLS[kind]

        self.art.roundrect(self.draw, (x0, top, x1, top + 22), 11, background)
        self.draw.text(((x0 + x1) / 2, top + 11), label, font=font, fill=ink, anchor="mm")

        return width

    def notice(self, off: int, top: int, width: int, height: int, background: str, bar: str) -> None:
        x0, x1 = self.span(off, width)
        self.art.roundrect(self.draw, (x0, top, x1, top + height), 4, background)
        bar_x = x1 - 4 if self.rtl else x0
        self.draw.rectangle((bar_x, top, bar_x + 4, top + height), fill=bar)

    def checkbox(self, off: int, top: int, checked: bool) -> None:
        x0, _ = self.span(off, 18)
        self.draw.rectangle((x0, top, x0 + 17, top + 17), fill=CARD, outline=FIELD_BORDER)

        if checked:
            self.draw.line((x0 + 4, top + 9, x0 + 7, top + 13, x0 + 14, top + 4), fill=LINK, width=2)

    def save(self, path: Path) -> None:
        self.image.convert("RGB").save(path)


def number(value: int) -> str:
    return f"{value:,}"


def make_screenshot_pricing(art: Art, out: Path, rtl: bool = False) -> None:
    """The rate screen: the manual rate, the typo warning, the rate history and the queue diagnostics."""
    screen = Screen(art, Tx(rtl)("USD / Toman Pricing"), rtl)
    t, text, font = screen.tx, screen.text, art.font
    unit = t.raw("Toman per 1 USD")

    # -- Manual exchange rate -------------------------------------------
    screen.card(0, 122, 660, 382)
    text(24, 140, t("Manual exchange rate"), font("SemiBold", 21), INK)

    value_width = text(24, 178, "270,000", font("Bold", 34), INK)
    text(24 + value_width + 12, 190, t.show(unit), font("Regular", 17), MUTED)
    text(24, 226, t("Previous rate: %s", "268,000"), font("Regular", 15), MUTED)

    text(24, 256, t("New rate (%s)", unit), font("Medium", 16), INK)
    screen.input(24, 282, 290, 40, "270000", MUTED)
    first = screen.button(330, 283, t("Save rate"), True)
    screen.button(330 + first + 14, 283, t("Preview changes"))

    threshold = t.raw(
        "A change larger than %s%% must be confirmed explicitly. Saving a rate never rewrites product prices by itself.",
        "15.00",
    )
    screen.paragraph(24, 340, threshold, font("Regular", 14), MUTED, 612, 20)

    # The typo guard: a tenfold rate waits for an explicit confirmation.
    screen.notice(24, 382, 612, 54, NOTICE_BG, "#DBA617")
    text(44, 393, t("A rate is waiting for confirmation:"), font("SemiBold", 15), NOTICE_INK)
    pending = t.raw("%1$s (%2$s%% change) submitted by %3$s.", "2,700,000 " + unit, "900.00", "admin")
    text(44, 414, t.show(pending), font("Regular", 15), NOTICE_INK)

    update = screen.button(24, 450, t("Update prices now"), True)
    screen.button(24 + update + 14, 450, t("Dry run"))

    # -- Catalog overview -----------------------------------------------
    screen.card(680, 122, 340, 382)
    text(704, 140, t("Catalog overview"), font("SemiBold", 21), INK)

    stats = (
        ("1,248", "Managed products"),
        ("612", "Managed variations"),
        ("1,196", "May need an update"),
        ("4", "Excluded products"),
    )

    for index, (value, label) in enumerate(stats):
        column, row = index % 2, index // 2
        x, y = 704 + column * 158, 178 + row * 80

        text(x, y, value, font("Bold", 30), LINK)
        screen.paragraph(x, y + 38, t.raw(label), font("Regular", 14), MUTED, 140, 19)

    hint = t.raw(
        "“May need an update” counts managed products without a USD price or whose stored rate is not the current rate. "
        "The exact number of price changes is calculated by the dry run."
    )
    screen.paragraph(704, 346, hint, font("Regular", 13), MUTED, 292, 18)

    currency = t.raw("%1$s (%2$s)", t.raw("Toman"), "IRT")
    meta = t.raw("Store currency: %1$s. Products in the catalog: %2$s.", currency, "1,252")
    screen.paragraph(704, 442, meta, font("Regular", 13), MUTED, 292, 18)

    # -- Rate history ---------------------------------------------------
    screen.card(0, 520, 660, 232)
    text(24, 538, t("Rate history"), font("SemiBold", 21), INK)

    columns = ((24, "Rate"), (118, "Change"), (204, "By"), (270, "Changed"), (424, "Products"))
    head = font("SemiBold", 13)

    for off, label in columns:
        text(off, 580, t(label), head, MUTED)

    screen.rule(12, 602, 636)

    history = (
        ("270,000", "0.75%", "admin", "2026-10-08 10:42", ("1,248", "1,196")),
        ("268,000", "1.13%", "admin", "2026-10-02 09:15", ("1,239", "1,231")),
        ("265,000", "—", "admin", "2026-09-24 16:03", ("1,226", "0")),
    )
    body = font("Regular", 14)

    for index, (rate, change, user, when, (checked, changed)) in enumerate(history):
        y = 614 + index * 30

        text(24, y, rate, font("SemiBold", 14), INK)
        text(118, y, change, body, INK)
        text(204, y, user, body, INK)
        text(270, y, when, body, MUTED)
        text(424, y, t("%1$s checked / %2$s changed", checked, changed), body, MUTED)

    text(24, 714, t("Full history and CSV export"), font("Regular", 14), LINK)

    # -- Background queue -----------------------------------------------
    screen.card(680, 520, 340, 232)
    text(704, 538, t("Background queue"), font("SemiBold", 21), INK)

    queue = (
        ("Scheduling backend", "action_scheduler"),
        ("Action Scheduler available", t("Yes")),
        ("Pending worker actions", "3"),
        ("Failed worker actions", "0"),
        ("Interrupted jobs", t("None")),
    )

    for index, (label, value) in enumerate(queue):
        y = 584 + index * 31

        if index % 2 == 0:
            screen.fill(692, y - 8, 316, 30, STRIPE)

        text(704, y, t(label), body, MUTED)
        screen.text_trail(32, y, value, font("SemiBold", 14), INK)

    screen.save(out)


def items_table(screen: Screen, top: int, rows, details_font_size: int = 13, off: int = 16) -> int:
    """The items table of a job; returns the y below the last row."""
    art, t = screen.art, screen.tx
    head, cell = art.font("SemiBold", 14), art.font("Regular", 15)
    note = art.font("Regular", details_font_size)

    columns = (0, 204, 306, 446, 536, 668)
    labels = ("Product", "Status", "Current Woo price", "USD price", "New Woo price", "Details")

    for column, label in zip(columns, labels):
        screen.text(off + column, top, t(label), head, MUTED)

    screen.rule(off - 6, top + 24, 1020 - 2 * (off - 6))
    y = top + 34

    for index, row in enumerate(rows):
        name, badge, status, old, usd, new, details = row
        detail_lines = screen.lines(details, note, 1020 - off - columns[5] - 16)
        height = max(40, 16 + 17 * len(detail_lines))

        if index % 2 == 0:
            screen.fill(off - 6, y - 8, 1020 - 2 * (off - 6), height, STRIPE)

        used = screen.text(off, y, name, cell, LINK)

        if badge:
            screen.pill(off + used + 8, y - 4, t(badge), "unchanged")

        screen.pill(off + columns[1], y - 4, t(Admin_status[status]), status)
        screen.text(off + columns[2], y, old, cell, INK)
        screen.text(off + columns[3], y, usd, cell, INK)
        screen.text(off + columns[4], y, new, art.font("SemiBold", 15), INK)

        for line_index, line in enumerate(detail_lines):
            screen.text(off + columns[5], y + 1 + line_index * 17, line, note, MUTED)

        y += height

    return y


# Item status key => the plugin's label (Admin::item_status_label()).
Admin_status = {
    "changed": "Updated",
    "generated": "USD set",
    "rebased": "Re-based",
    "unchanged": "Unchanged",
    "skipped": "Skipped",
    "failed": "Failed",
    "conflict": "Conflict",
}


def make_screenshot_preview(art: Art, out: Path) -> None:
    """Dry run: what would change, what is unchanged, what is skipped, the variations, no write."""
    screen = Screen(art, Tx(False)("USD / Toman Pricing"))
    t, text, font = screen.tx, screen.text, art.font
    unit = t.raw("Toman per 1 USD")

    screen.notice(0, 122, 1020, 40, OK_BG, LINK)
    text(20, 134, t("Dry run finished. Nothing was changed."), font("Medium", 16), OK_INK)

    # The rate is previewed before it is saved: the dry run uses the typed rate.
    screen.card(0, 176, 1020, 108)
    text(24, 192, t("New rate (%s)", unit), font("Medium", 16), INK)
    screen.input(24, 218, 200, 40, "300000")

    x = 240
    for label, primary in (("Save rate", True), ("Preview changes", False), ("Update prices now", True), ("Dry run", False)):
        x += screen.button(x, 219, t(label), primary) + 14

    screen.card(0, 300, 1020, 452)
    text(24, 318, t("Last dry run"), font("SemiBold", 21), INK)
    text(
        24,
        354,
        t(
            "Would update: %1$s · USD set: %2$s · re-based: %3$s · unchanged: %4$s · skipped: %5$s · failed: %6$s · conflicts: %7$s",
            "1,196", "31", "9", "6", "4", "0", "2",
        ),
        font("Regular", 15),
        INK,
    )

    prefix = t.raw("Dry run, nothing was written.")
    regular = t.raw("Regular price")

    def update(old: str, new: str, usd: str) -> str:
        return f"{prefix} " + t.raw("%1$s: %2$s to %3$s (%4$s USD).", regular, old, new, usd)

    rows = (
        ("Canvas tote bag", None, "changed", "5,000,000", "$18.52", "5,556,000", update("5,000,000", "5,556,000", "18.52")),
        ("Linen shirt – M", "variation", "changed", "4,000,000", "$14.81", "4,443,000", update("4,000,000", "4,443,000", "14.81")),
        ("Linen shirt – L", "variation", "changed", "4,200,000", "$15.56", "4,668,000", update("4,200,000", "4,668,000", "15.56")),
        (
            "Notebook set", None, "generated", "1,890,000", "$6.30", "1,890,000",
            f"{prefix} " + t.raw("%1$s: USD price set to %2$s.", regular, "6.30"),
        ),
        (
            "Leather wallet", None, "rebased", "8,500,000", "$28.33", "8,500,000",
            f"{prefix} " + t.raw("%1$s: edited by hand, USD price re-based to %2$s.", regular, "28.33"),
        ),
    )

    bottom = items_table(screen, 392, rows)

    text(24, bottom + 4, t("Showing up to 20 products. The full report is available in the job details, including a CSV download."), font("Regular", 13), MUTED)
    screen.button(24, bottom + 32, t("Open the dry run report"))

    screen.save(out)


def make_screenshot_job(art: Art, out: Path) -> None:
    """Jobs & history: the running job with its progress and the per product log."""
    screen = Screen(art, Tx(False)("Jobs & history"))
    t, text, font = screen.tx, screen.text, art.font
    unit = t.raw("Toman per 1 USD")

    screen.card(0, 122, 1020, 630)
    text(24, 140, t("Job #%1$d — %2$s", 43, t.raw("Price update")), font("SemiBold", 21), INK)

    meta = t(
        "Status: %1$s · Rate: %2$s · Started by %3$s · Scope: %4$s",
        t.raw("Running"), "300,000 " + unit, "admin", t.raw("Every managed product"),
    )
    text(24, 176, meta, font("Regular", 15), MUTED)

    label_width = text(24, 202, t("Currently processing:"), font("Regular", 15), MUTED)
    text(24 + label_width + 8, 202, "Linen shirt – S", font("SemiBold", 15), INK)

    screen.fill(24, 230, 972, 20, "#E0E0E0")
    screen.fill(24, 230, round(972 * 480 / 1248), 20, LINK)

    counters = (
        ("Total", "1,248"), ("Processed", "480"), ("Updated", "454"), ("USD set", "12"), ("Re-based", "4"),
        ("Unchanged", "2"), ("Skipped", "3"), ("Failed", "0"), ("Conflicts", "5"),
        ("Variations processed", "96"), ("Variations changed", "88"),
    )

    x, y = 24, 268
    for label, value in counters:
        label_text = t(label) + ": "
        part = screen.width_of(label_text, font("Regular", 14))
        total = part + screen.width_of(value, font("SemiBold", 14))

        if x + total > 996:
            x, y = 24, y + 22

        text(x, y, label_text, font("Regular", 14), MUTED)
        text(x + part, y, value, font("SemiBold", 14), INK)
        x += total + 20

    x = 24
    for label, primary in (("Pause", False), ("Cancel", False), ("Recalculate conflicts", False), ("Download CSV", False)):
        x += screen.button(x, 326, t(label), primary) + 12

    text(x + 6, 336, t("Back to all jobs"), font("Regular", 15), LINK)

    tabs = (
        ("All", "480"), ("Updated", "454"), ("USD set", "12"), ("Re-based", "4"),
        ("Unchanged", "2"), ("Skipped", "3"), ("Failed", "0"), ("Conflicts", "5"),
    )

    x = 24
    for index, (label, count) in enumerate(tabs):
        label_text = f"{t(label)} ({count})"
        weight, color = ("SemiBold", INK) if index == 0 else ("Regular", LINK)

        x += text(x, 386, label_text, font(weight, 15), color) + 18

    regular = t.raw("Regular price")
    rows = (
        (
            "Linen shirt", None, "changed", "5,000,000", "$18.52", "5,556,000",
            t.raw("%1$s: %2$s to %3$s (%4$s USD).", regular, "5,000,000", "5,556,000", "18.52"),
        ),
        (
            "Wool scarf", None, "changed", "6,000,000", "$22.22", "6,666,000",
            t.raw("%1$s: %2$s to %3$s (%4$s USD).", regular, "6,000,000", "6,666,000", "22.22"),
        ),
        (
            "Silk tie", None, "generated", "3,400,000", "$11.33", "3,400,000",
            t.raw("%1$s: USD price set to %2$s.", regular, "11.33"),
        ),
        (
            "Rain coat", None, "rebased", "4,500,000", "$15.00", "4,500,000",
            t.raw("%1$s: edited by hand, USD price re-based to %2$s.", regular, "15.00"),
        ),
        (
            "Straw hat", None, "skipped", "—", "—", "—",
            t.raw("%1$s: %2$s", regular, t.raw("There is no price to peg to USD.")),
        ),
        (
            "Tweed cap", None, "conflict", "2,250,000", "$7.78", "2,334,000",
            t.raw("The price was edited in WooCommerce while it was being synchronized, so nothing was written. The next update re-bases the USD price on the edited price."),
        ),
    )

    bottom = items_table(screen, 418, rows)

    text(24, bottom + 4, t("Showing up to 50 items. Use the CSV download for the complete report."), font("Regular", 13), MUTED)

    screen.save(out)


def make_screenshot_product(art: Art, out: Path) -> None:
    """The product panel: the pricing mode, the Woo price, the USD price, the rate used and the bulk actions."""
    screen = Screen(art, "Edit product — Canvas tote bag")
    t, text, font = screen.tx, screen.text, art.font
    unit = t.raw("Toman per 1 USD")

    # -- The meta box ---------------------------------------------------
    screen.card(0, 122, 660, 388)
    text(24, 140, t("USD / Toman pricing"), font("SemiBold", 21), INK)

    intro = t.raw(
        "The WooCommerce price fields hold the store price. The first price update records a USD price from it; "
        "after that, a new exchange rate rewrites the store price from the USD price. "
        "Editing the store price by hand re-bases the USD price on the next update."
    )
    below = screen.paragraph(24, 176, intro, font("Regular", 14), MUTED, 612, 20)

    text(24, below + 10, t("Pricing mode"), font("SemiBold", 15), INK)
    mode = t.raw("Managed — the WooCommerce price follows the USD price and the exchange rate")
    screen.select(24, below + 32, 612, 38, mode)

    rows = (
        ("Woo price", "5,556,000"),
        ("USD price", "$18.52"),
        ("Rate used", "300,000 " + unit),
        ("Last sync", "2026-10-11 09:41:07"),
    )
    top = below + 88

    for index, (label, value) in enumerate(rows):
        y = top + index * 30

        if index % 2 == 0:
            screen.fill(12, y - 8, 636, 30, STRIPE)

        text(24, y + 1, t(label), font("SemiBold", 14), MUTED)
        text(190, y, value, font("Regular", 15), INK)

    button_top = top + 4 * 30 + 12
    wide = screen.button(24, button_top, t("Recalculate this product"))
    text(24 + wide + 18, button_top + 11, t("Open jobs and history"), font("Regular", 15), LINK)

    # -- The bulk actions drop-down of the product list ------------------
    options = (
        ("Bulk actions", False),
        ("Edit", False),
        ("Move to Trash", False),
        (t("USD/Toman: set Managed"), False),
        (t("USD/Toman: set Excluded"), False),
        (t("USD/Toman: Recalculate selected"), True),
    )

    screen.card(680, 122, 340, len(options) * 34 + 16)

    for index, (label, active) in enumerate(options):
        y = 130 + index * 34

        if active:
            screen.fill(688, y, 324, 32, LINK)

        text(704, y + 8, label, font("Regular", 15), WHITE if active else INK)

    filter_top = 130 + len(options) * 34 + 36
    screen.select(680, filter_top, 230, 36, t("All pricing modes"))
    screen.button(924, filter_top - 1, "Filter")

    # -- The product list -----------------------------------------------
    list_top = 526
    screen.card(0, list_top, 1020, 226)

    heads = ((52, "Name"), (500, "Price"), (700, t("USD price")))

    for off, label in heads:
        text(off, list_top + 18, label, font("SemiBold", 14), MUTED)

    screen.rule(12, list_top + 42, 996)

    products = (
        ("Canvas tote bag", "5,556,000", "managed", "$18.52", None, True),
        ("Ceramic mug", "3,000,000", "managed", "$10.00", None, True),
        ("Rain coat", "7,300,000", "managed", "$24.33", "Conflict – recalculate", False),
        ("Gift card", "500,000", "excluded", None, None, False),
    )

    for index, (name, price, mode_key, usd, flag, checked) in enumerate(products):
        y = list_top + 56 + index * 40

        if index % 2 == 0:
            screen.fill(12, y - 10, 996, 40, STRIPE)

        screen.checkbox(20, y - 1, checked)
        text(52, y, name, font("Regular", 15), LINK)
        text(500, y, price, font("Regular", 15), INK)

        x = 700 + screen.pill(700, y - 4, t(Admin_mode[mode_key]), mode_key) + 10

        if usd:
            x += text(x, y, usd, font("SemiBold", 15), INK) + 12

        if flag:
            screen.pill(x, y - 4, t(flag), "conflict")

    screen.save(out)


Admin_mode = {"managed": "Managed", "excluded": "Excluded"}


# ---------------------------------------------------------------------------


def main() -> int:
    parser = argparse.ArgumentParser(description="Draw the WordPress.org artwork.")
    parser.add_argument("--font-dir", default="/tmp/vaz/fonts/ttf", help="Folder with the Vazirmatn TTFs")
    parser.add_argument("--out", default=str(Path(__file__).resolve().parent), help="Where the PNGs are written")

    arguments = parser.parse_args()

    art = Art(Path(arguments.font_dir))
    out = Path(arguments.out)
    out.mkdir(parents=True, exist_ok=True)

    make_icon(art, out / "icon-256x256.png", 256)
    make_icon(art, out / "icon-128x128.png", 128)
    make_banner(art, out / "banner-1544x500.png")
    make_screenshot_pricing(art, out / "screenshot-1.png")
    make_screenshot_preview(art, out / "screenshot-2.png")
    make_screenshot_job(art, out / "screenshot-3.png")
    make_screenshot_pricing(art, out / "screenshot-4.png", rtl=True)
    make_screenshot_product(art, out / "screenshot-5.png")

    expected = {
        "icon-128x128.png": (128, 128),
        "icon-256x256.png": (256, 256),
        "banner-772x250.png": (772, 250),
        "banner-1544x500.png": (1544, 500),
        "screenshot-1.png": (1280, 800),
        "screenshot-2.png": (1280, 800),
        "screenshot-3.png": (1280, 800),
        "screenshot-4.png": (1280, 800),
        "screenshot-5.png": (1280, 800),
    }

    problems = 0

    for name, size in expected.items():
        image = Image.open(out / name)

        if image.size != size:
            print(f"error: {name} is {image.size}, expected {size}")

            problems += 1
        else:
            print(f"ok: {name} ({size[0]}x{size[1]}, {image.size[0] * image.size[1] // 1000}k pixels)")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
