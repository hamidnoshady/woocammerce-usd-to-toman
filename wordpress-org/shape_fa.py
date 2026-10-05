#!/usr/bin/env python3
"""Shape Persian text into Arabic presentation forms for renderers without HarfBuzz.

Pillow (without raqm) and ImageMagick have no complex text layout, so a Persian
string drawn with them comes out with disconnected letters in the wrong order.
This module does what a shaping engine does before the glyphs are drawn:

* every letter is mapped to its isolated, initial, medial or final form, which
  the bundled Vazirmatn font covers in the Unicode presentation forms block;
* runs of digits or Latin words keep their own left to right order (the Unicode
  bidi rule for weak characters), everything else is reversed as a block.

It is intentionally small: it powers the artwork generator and covers the letters
used by the plugin's Persian strings.
"""

import re
import sys

# code point -> (isolated, final, initial, medial); None means "not available".
FORMS = {
    0x0621: (0xFE80, None, None, None),                        # hamza
    0x0622: (0xFE81, 0xFE82, None, None),                      # alef madda
    0x0623: (0xFE83, 0xFE84, None, None),                      # alef hamza above
    0x0624: (0xFE85, 0xFE86, None, None),                      # waw hamza
    0x0625: (0xFE87, 0xFE88, None, None),                      # alef hamza below
    0x0626: (0xFE89, 0xFE8A, 0xFE8B, 0xFE8C),                  # yeh hamza
    0x0627: (0xFE8D, 0xFE8E, None, None),                      # alef
    0x0628: (0xFE8F, 0xFE90, 0xFE91, 0xFE92),                  # beh
    0x0629: (0xFE93, 0xFE94, None, None),                      # teh marbuta
    0x062A: (0xFE95, 0xFE96, 0xFE97, 0xFE98),                  # teh
    0x062B: (0xFE99, 0xFE9A, 0xFE9B, 0xFE9C),                  # theh
    0x062C: (0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0),                  # jeem
    0x062D: (0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4),                  # hah
    0x062E: (0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8),                  # khah
    0x062F: (0xFEA9, 0xFEAA, None, None),                      # dal
    0x0630: (0xFEAB, 0xFEAC, None, None),                      # thal
    0x0631: (0xFEAD, 0xFEAE, None, None),                      # reh
    0x0632: (0xFEAF, 0xFEB0, None, None),                      # zain
    0x0633: (0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4),                  # seen
    0x0634: (0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8),                  # sheen
    0x0635: (0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC),                  # sad
    0x0636: (0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0),                  # dad
    0x0637: (0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4),                  # tah
    0x0638: (0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8),                  # zah
    0x0639: (0xFEC9, 0xFECA, 0xFECB, 0xFECC),                  # ain
    0x063A: (0xFECD, 0xFECE, 0xFECF, 0xFED0),                  # ghain
    0x0641: (0xFED1, 0xFED2, 0xFED3, 0xFED4),                  # feh
    0x0642: (0xFED5, 0xFED6, 0xFED7, 0xFED8),                  # qaf
    0x0644: (0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0),                  # lam
    0x0645: (0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4),                  # meem
    0x0646: (0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8),                  # noon
    0x0647: (0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC),                  # heh
    0x0648: (0xFEED, 0xFEEE, None, None),                      # waw
    0x0649: (0xFEEF, 0xFEF0, None, None),                      # alef maksura
    0x064A: (0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4),                  # yeh
    0x067E: (0xFB56, 0xFB57, 0xFB58, 0xFB59),                  # peh
    0x0686: (0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D),                  # tcheh
    0x0698: (0xFB8A, 0xFB8B, None, None),                      # jeh
    0x06A9: (0xFB8E, 0xFB8F, 0xFB90, 0xFB91),                  # keheh
    0x06AF: (0xFB92, 0xFB93, 0xFB94, 0xFB95),                  # gaf
    0x06CC: (0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF),                  # farsi yeh
}

# Letters that never connect to the letter after them.
RIGHT_JOINING = {0x0621, 0x0622, 0x0623, 0x0624, 0x0625, 0x0627, 0x0629, 0x062F, 0x0630, 0x0631, 0x0632, 0x0648, 0x0649, 0x0698}

# Diacritics belong to the letter they follow and do not interrupt joining.
TRANSPARENT = {0x064B, 0x064C, 0x064D, 0x064E, 0x064F, 0x0650, 0x0651, 0x0652, 0x0670}

# A zero width non joiner is not drawn, but it stops a connection.
ZWNJ = 0x200C

# Digits, Latin words and currency amounts keep their own order inside a right to
# left line. Matching them as whole tokens means the reversal happens between
# runs and never inside one.
LTR_TOKEN = re.compile(
    r'[$\u20AC]?[\u06F0-\u06F9\u0660-\u06690-9]+(?:[,.][\u06F0-\u06F9\u0660-\u06690-9]+)*'
    r'|[A-Za-z][A-Za-z0-9._+-]*'
)


def _runs(text):
    """Split the text into alternating right to left and left to right runs."""
    runs = []
    position = 0

    for match in LTR_TOKEN.finditer(text):
        if match.start() > position:
            runs.append(('rtl', text[position:match.start()]))

        runs.append(('ltr', match.group(0)))
        position = match.end()

    if position < len(text):
        runs.append(('rtl', text[position:]))

    return runs


def _shape_rtl_run(text):
    """Shape one right to left run into presentation forms, in drawing order."""
    characters = [ord(character) for character in text]
    shaped = []
    count = len(characters)

    for index in range(count):
        code = characters[index]

        if code in TRANSPARENT or code == ZWNJ:
            continue

        if code not in FORMS:
            shaped.append(code)

            continue

        table = FORMS[code]

        back = index - 1

        while back >= 0 and characters[back] in TRANSPARENT:
            back -= 1

        forward = index + 1

        while forward < count and characters[forward] in TRANSPARENT:
            forward += 1

        previous_connects = (
            back >= 0
            and characters[back] in FORMS
            and characters[back] not in RIGHT_JOINING
            and characters[back] != ZWNJ
        )
        next_connects = forward < count and characters[forward] in FORMS and characters[forward] != ZWNJ

        if previous_connects and next_connects and table[3]:
            glyph = table[3]
        elif previous_connects and table[1]:
            glyph = table[1]
        elif next_connects and table[2]:
            glyph = table[2]
        else:
            glyph = table[0]

        shaped.append(glyph)

    return ''.join(chr(code) for code in reversed(shaped))


def shape(text):
    """Return the string as presentation forms, in visual (right to left) order."""
    visual = []

    for direction, run in reversed(_runs(text)):
        visual.append(_shape_rtl_run(run) if 'rtl' == direction else run)

    return ''.join(visual)


if __name__ == '__main__':
    for argument in sys.argv[1:]:
        print(argument, '->', shape(argument))
