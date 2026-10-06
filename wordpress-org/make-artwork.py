#!/usr/bin/env python3
"""Draw the WordPress.org artwork: icons, banners and screenshots.

The images that belong to the plugin directory live in the `assets/` folder of the
WordPress.org SVN repository, never in the plugin zip. This script draws them from
the same palette and the same strings the admin screens use, so wording changes are
regenerated instead of redrawn by hand.

Usage:

    python3 wordpress-org/make-artwork.py --font-dir=/path/to/ttf --out=wordpress-org

Persian strings go through `shape_fa.shape()`: Pillow has no complex text layout
(raqm), so without shaping the letters would be drawn disconnected and in the wrong
order. Everything is drawn at the size the listing expects, except the half size
banner and the small icon, which are downscaled from their retina versions.

Requires Pillow (`pip install Pillow`) and the Vazirmatn font (SIL OFL 1.1).
"""

import argparse
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
            self._cache[key] = ImageFont.truetype(str(self.fonts[weight]), size)

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


def button(art: Art, draw, x: int, y: int, label: str, primary: bool = False) -> int:
    font = art.font("SemiBold", 17)
    width, height = art.measure(draw, label, font)
    box = (x, y, x + width + 44, y + 38)

    if primary:
        art.roundrect(draw, box, 4, LINK)
    else:
        art.roundrect(draw, box, 4, "#F6F7F7")
        draw.rounded_rectangle(box, radius=4, outline=LINK, width=1)

    art.text_center(draw, box, label, font, WHITE if primary else LINK)

    return box[2] - box[0]


def input_field(art: Art, draw, x: int, y: int, width: int, height: int, value: str, rtl: bool = False) -> None:
    art.roundrect(draw, (x, y, x + width, y + height), 4, CARD)
    draw.rectangle((x, y, x + width, y + height), outline="#8C8F94")
    font = art.font("Regular", 17)

    if rtl:
        art.text_right(draw, 0, y + (height - 22) / 2, x + width - 12, value, font, INK)

        return

    art.text_at(draw, x + 12, y + (height - 22) / 2, value, font, INK)


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

    art.text_at(draw, 82, 258, "Manual rate  ·  Dry run before every write  ·  Queued background updates", line, SOFT)
    art.text_at(draw, 82, 300, "WooCommerce CRUD writes  ·  Rate history and rollback  ·  Persian translation included", line, SOFT)

    card_box = (1058, 118, 1462, 382)
    art.card(draw, card_box)

    mut = art.font("Medium", 19)
    ink = art.font("Medium", 25)

    art.text_at(draw, 1086, 142, "Rate: 270,000 Toman per $1", mut, MUTED)
    art.text_right(draw, 0, 142, 1434, shape("نرخ ارز"), mut, MUTED)

    art.text_at(draw, 1086, 184, "Canonical Toman price", ink, INK)
    art.text_at(draw, 1086, 214, shape("۵,۰۰۰,۰۰۰ تومان"), art.font("Bold", 34), INK)

    draw.line((1086, 276, 1434, 276), fill=BORDER)

    art.text_at(draw, 1086, 296, "Derived USD price", ink, MUTED)
    art.text_at(draw, 1086, 326, "$19", art.font("Bold", 34), LINK)
    art.text_at(draw, 1180, 342, "ceil(5,000,000 / 270,000) = 19", art.font("Regular", 18), MUTED)

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


class Screen:
    """The WordPress admin chrome the plugin's screens sit in."""

    def __init__(self, art: Art, title: str, rtl: bool = False):
        self.art = art
        self.rtl = rtl
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
                art.text_right(draw, 0, y + 4, self.sidebar_x + 150, label, menu_font, color)
            else:
                art.text_at(draw, 26, y + 4, label, menu_font, color)

        bar_left = 0 if rtl else 170
        bar_right = self.sidebar_x if rtl else 1280
        draw.rectangle((bar_left, 0, bar_right, 46), fill="#1D2327")

        art.text_at(draw, bar_left + 20, 14, "USD/Toman pricing", art.font("Regular", 15), "#C3C4C7")
        art.text_right(
            draw,
            0,
            14,
            bar_right - 20,
            "Howdy, admin",
            art.font("Regular", 15),
            "#C3C4C7",
        )

        self.left = 40 if rtl else 200
        self.right = 1240 - (170 if not rtl else 0)
        self.content_width = 1020

        art.text_at(
            draw,
            self.left,
            74,
            title,
            art.font("Regular", 28),
            INK,
        )

    @property
    def box(self):
        return (self.left, self.content_width)

    def save(self, path: Path) -> None:
        self.image.convert("RGB").save(path)


def make_screenshot_pricing(art: Art, out: Path, rtl: bool = False) -> None:
    title = shape("قیمت‌گذاری دلار / تومان") if rtl else "USD / Toman Pricing"
    screen = Screen(art, title, rtl)
    draw = screen.draw
    left, width = screen.box

    cards = (
        (
            ("۱۲۴۸", shape("محصولات مدیریت‌شده")),
            ("۳۱۲", shape("ممکن است نیاز به به‌روزرسانی داشته باشد")),
            ("۹۳۶", shape("از پیش به‌روز")),
            ("۴", shape("نیاز به توجه")),
        )
        if rtl
        else (
            ("1,248", "Managed products"),
            ("312", "May need an update"),
            ("936", "Already current"),
            ("4", "Needs attention"),
        )
    )

    card_width, gap = 246, 20

    for index, (value, label) in enumerate(cards):
        x = left + index * (card_width + gap)

        art.card(draw, (x, 130, x + card_width, 230))
        art.text_at(draw, x + 20, 146, value, art.font("Bold", 34), LINK)
        art.text_at(draw, x + 20, 194, label, art.font("Regular", 15), MUTED)

    top, height = 254, 318

    art.card(draw, (left, top, left + width, top + height))

    label = shape("نرخ ارز دستی (تومان برای هر ۱ دلار)") if rtl else "Manual exchange rate (Toman per 1 USD)"

    art.text_at(draw, left + 24, top + 22, label, art.font("SemiBold", 21), INK)
    input_field(art, draw, left + 24, top + 64, 320, 42, "270000", rtl)

    save_label = shape("ذخیرهٔ نرخ") if rtl else "Save rate"
    preview_label = shape("پیش‌نمایش تغییرها") if rtl else "Preview changes"

    button(art, draw, left + 360, top + 66, save_label, True)
    button(art, draw, left + 520, top + 66, preview_label)

    note = (
        shape("نرخ پیشین: ۲۶۸,۰۰۰ · آخرین به‌روزرسانی: ۳ ساعت پیش · ذخیرهٔ نرخ هرگز قیمت‌ها را تغییر نمی‌دهد.")
        if rtl
        else "Previous rate: 268,000 · Last updated: 3 hours ago · Saving the rate never rewrites prices."
    )

    art.text_at(draw, left + 24, top + 130, note, art.font("Regular", 16), MUTED)

    warning = (
        shape("تغییری بزرگ‌تر از ۱۵٪ باید صریحاً تأیید شود. یک تغییر نرخ هرگز خودبه‌خود قیمت‌ها را بازنویسی نمی‌کند.")
        if rtl
        else "A change larger than 15% must be confirmed explicitly. Saving a rate never rewrites prices by itself."
    )

    art.roundrect(draw, (left + 24, top + 168, left + 664, top + 212), 4, NOTICE_BG)
    draw.rectangle((left + 24, top + 168, left + 28, top + 212), fill="#DBA617")
    art.text_at(draw, left + 44, top + 180, warning, art.font("Regular", 16), NOTICE_INK)

    # Rate history: the audit trail behind every price change.
    history_label = shape("تاریخچهٔ نرخ") if rtl else "Rate history"

    art.text_at(draw, left + 24, top + 232, history_label, art.font("SemiBold", 17), INK)

    history = (
        (
            (shape("۲۷۰,۰۰۰ تومان برای هر ۱ دلار"), shape("امروز · توسط مدیر"), "فعال"),
            (shape("۲۶۸,۰۰۰ تومان برای هر ۱ دلار"), shape("۳ روز پیش · توسط مدیر"), ""),
        )
        if rtl
        else (
            ("270,000 Toman per 1 USD", "today · by admin", "active"),
            ("268,000 Toman per 1 USD", "3 days ago · by admin", ""),
        )
    )

    for index, (row_label, meta, badge) in enumerate(history):
        y = top + 264 + index * 30

        art.text_at(draw, left + 24, y, row_label, art.font("Regular", 16), INK)
        art.text_at(draw, left + 300, y, meta, art.font("Regular", 16), MUTED)

        if badge:
            art.text_at(draw, left + 500, y, badge, art.font("SemiBold", 16), GREEN)

    health = (
        shape("Action Scheduler در دسترس است: به‌روزرسانی‌ها در پس‌زمینه اجرا می‌شوند و به باز ماندن مرورگر نیاز ندارند.")
        if rtl
        else "Action Scheduler is available: updates run in the background and do not need an open browser tab."
    )

    art.roundrect(draw, (left, 596, left + width, 642), 4, OK_BG)
    art.text_at(draw, left + 20, 610, health, art.font("Regular", 16), OK_INK)

    queue = (
        shape("صف: ۳ کنش در انتظار · ۰ ناموفق · ۱۰ محصول در هر اجرا · ۲۵ سقف دسته")
        if rtl
        else "Queue: 3 pending actions · 0 failed · 10 products per run · batch size capped at 25"
    )

    art.text_at(draw, left + 24, 666, queue, art.font("Regular", 16), MUTED)

    screen.save(out)


def make_screenshot_preview(art: Art, out: Path) -> None:
    screen = Screen(art, "Preview changes (dry run)")
    draw = screen.draw
    left, width = screen.box

    art.roundrect(draw, (left, 122, left + width, 168), 4, OK_BG)
    draw.rectangle((left, 122, left + 4, 168), fill=LINK)
    art.text_at(draw, left + 20, 136, "Dry run finished. Nothing was changed.", art.font("Medium", 16), OK_INK)

    art.card(draw, (left, 186, left + width, 304))
    art.text_at(draw, left + 24, 206, "Would change: 312", art.font("SemiBold", 18), INK)
    art.text_at(draw, left + 220, 206, "Unchanged: 936", art.font("Regular", 18), MUTED)
    art.text_at(draw, left + 370, 206, "Skipped: 4", art.font("Regular", 18), MUTED)
    art.text_at(draw, left + 500, 206, "Failed: 0", art.font("Regular", 18), MUTED)
    art.text_at(draw, left + 630, 206, "Conflicts: 2", art.font("Regular", 18), NOTICE_INK)

    art.text_at(
        draw,
        left + 24,
        240,
        "Rate: 270,000 Toman per 1 USD · Products scanned: 1,252 · Variations affected: 96 · No write was performed",
        art.font("Regular", 16),
        MUTED,
    )

    first = button(art, draw, left, 322, "Run the update", True)
    button(art, draw, left + first + 16, 322, "Download CSV")

    art.card(draw, (left, 388, left + width, 744))

    columns = ((224, "Product"), (560, "Toman price"), (740, "Derived USD"), (920, "New"), (1080, "Change"))

    for x, label in columns:
        art.text_at(draw, x, 408, label, art.font("SemiBold", 16), MUTED)

    draw.line((216, 436, 1204, 436), fill=BORDER)

    rows = (
        ("Canvas tote bag", "5,400,000", "$19", "$20", "+ 1"),
        ("Ceramic mug", "2,700,000", "$10", "$10", "0"),
        ("Desk lamp", "4,320,000", "$16", "$15", "− 1"),
        ("Notebook set", "1,890,000", "$7", "$7", "0"),
        ("Leather wallet", "8,100,000", "$30", "$29", "− 1"),
    )

    try:
        art.font("Regular", 17)
    except OSError:  # pragma: no cover - font guard
        pass

    for index, row in enumerate(rows):
        y = 452 + index * 54

        art.text_at(draw, 224, y, row[0], art.font("Regular", 17), LINK)
        art.text_at(draw, 560, y, row[1], art.font("Regular", 17), INK)
        art.text_at(draw, 740, y, row[2], art.font("Regular", 17), MUTED)
        art.text_at(draw, 920, y, row[3], art.font("SemiBold", 17), INK)
        art.text_at(draw, 1080, y, row[4], art.font("Regular", 17), MUTED)

    screen.save(out)


def make_screenshot_job(art: Art, out: Path) -> None:
    screen = Screen(art, "Jobs & history")
    draw = screen.draw
    left, width = screen.box

    art.card(draw, (left, 122, left + width, 290))
    art.text_at(draw, left + 24, 140, "Job #42 — running", art.font("SemiBold", 21), INK)
    art.text_at(
        draw,
        left + 24,
        176,
        "Rate: 270,000 Toman · Started by admin · Scope: every managed product · Heartbeat 4 seconds ago",
        art.font("Regular", 16),
        MUTED,
    )

    art.roundrect(draw, (left + 24, 212, left + 744, 238), 13, "#E0E0E0")
    art.roundrect(draw, (left + 24, 212, left + 302, 238), 13, LINK)

    art.text_at(draw, left + 764, 212, "480 / 1,248 processed", art.font("SemiBold", 17), INK)

    button(art, draw, left + 24, 250, "Pause")
    button(art, draw, left + 130, 250, "Cancel")

    art.card(draw, (left, 308, left + width, 744))
    art.text_at(draw, left + 24, 328, "Recent log entries", art.font("SemiBold", 19), INK)

    for x, label in ((224, "Product"), (560, "Old price"), (720, "New price"), (900, "Status"), (1085, "Toman source")):
        art.text_at(draw, x, 364, label, art.font("SemiBold", 16), MUTED)

    draw.line((216, 392, 1204, 392), fill=BORDER)

    rows = (
        ("Linen shirt", "$18", "$19", "Changed", "5,000,000", GREEN),
        ("Wool scarf", "$22", "$22", "Unchanged", "6,000,000", MUTED),
        ("Cotton socks", "$5", "$5", "Unchanged", "1,350,000", MUTED),
        ("Straw hat", "—", "—", "Skipped", "—", RED),
        ("Silk tie", "$12", "$13", "Changed", "3,400,000", GREEN),
        ("Rain coat", "$27", "$27", "Conflict", "7,300,000", NOTICE_INK),
    )

    for index, row in enumerate(rows):
        y = 410 + index * 52

        art.text_at(draw, 224, y, row[0], art.font("Regular", 17), LINK)
        art.text_at(draw, 560, y, row[1], art.font("Regular", 17), INK)
        art.text_at(draw, 720, y, row[2], art.font("SemiBold", 17), INK)
        art.text_at(draw, 900, y, row[3], art.font("Regular", 17), row[5])
        art.text_at(draw, 1085, y, row[4], art.font("Regular", 17), MUTED)

    screen.save(out)


def make_screenshot_product(art: Art, out: Path) -> None:
    screen = Screen(art, "Edit product — Canvas tote bag")
    draw = screen.draw
    left, _width = screen.box

    art.card(draw, (left, 122, left + 660, 422))
    art.text_at(draw, left + 24, 140, "USD / Toman pricing", art.font("SemiBold", 21), INK)

    rows = (
        ("Pricing mode", "Toman managed", GREEN),
        ("Canonical Toman regular price", "5,400,000", INK),
        ("Canonical Toman sale price", "4,500,000", INK),
        ("Derived USD price", "$20 regular · $17 sale", LINK),
        ("Rate used · last synchronized", "270,000 · 3 hours ago", INK),
    )

    for index, (label, value, color) in enumerate(rows):
        y = 186 + index * 38

        art.text_at(draw, left + 24, y, label, art.font("Regular", 17), MUTED)
        art.text_at(draw, left + 300, y, value, art.font("SemiBold", 17), color)

    button(art, draw, left + 24, 372, "Recalculate this product", True)

    art.card(draw, (left + 680, 122, left + 1020, 422))
    art.text_at(draw, left + 704, 140, "Bulk actions", art.font("SemiBold", 19), INK)

    actions = (
        "Enable Toman pricing (import current price)",
        "Mark as native USD price",
        "Exclude from synchronization",
    )

    for index, action in enumerate(actions):
        art.text_at(draw, left + 704, 184 + index * 36, action, art.font("Regular", 16), LINK)

    note = (
        "Editing the price during a run marks the product",
        "as a conflict instead of overwriting your edit.",
        "The Toman source price is never destroyed.",
    )

    for index, line in enumerate(note):
        art.text_at(draw, left + 704, 308 + index * 24, line, art.font("Regular", 16), MUTED)

    art.card(draw, (left, 444, left + 1020, 744))
    art.text_at(draw, left + 24, 462, "Products list", art.font("SemiBold", 19), INK)

    for x, label in ((224, "Name"), (700, "Price"), (900, "Toman source"), (1090, "Pricing mode")):
        art.text_at(draw, x, 500, label, art.font("SemiBold", 16), MUTED)

    draw.line((216, 528, 1204, 528), fill=BORDER)

    rows = (
        ("Canvas tote bag", "$20", "5,400,000", "Toman managed", GREEN),
        ("Ceramic mug", "$10", "2,700,000", "Toman managed", GREEN),
        ("Vintage poster", "$45", "—", "Native USD", MUTED),
        ("Gift card", "$25", "—", "Excluded", MUTED),
    )

    for index, row in enumerate(rows):
        y = 546 + index * 50

        art.text_at(draw, 224, y, row[0], art.font("Regular", 17), LINK)
        art.text_at(draw, 700, y, row[1], art.font("Regular", 17), INK)
        art.text_at(draw, 900, y, row[2], art.font("Regular", 17), MUTED)
        art.text_at(draw, 1090, y, row[3], art.font("Regular", 17), row[4])

    screen.save(out)


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
