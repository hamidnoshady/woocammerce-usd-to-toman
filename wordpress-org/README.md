# WordPress.org listing artwork

The images in this folder are the **plugin directory listing** artwork: the icon, the banner and
the screenshots that appear on the plugin's page at wordpress.org. They are committed here so the
listing can be regenerated from source, and they are **not** part of the plugin zip (the `.distignore`
excludes this folder).

## Where the files go

WordPress.org reads the artwork from the `assets/` folder of the **plugin SVN repository**, never
from the plugin zip and never from the `trunk/` folder:

```
https://plugins.svn.wordpress.org/<plugin-slug>/assets/
```

So a deployment copies the files below into that folder (the manual `Release` workflow in
`.github/workflows/release.yml` pushes trunk and tags with `svn`; the artwork folder is uploaded the
same way, or with a small script such as:

```bash
svn checkout https://plugins.svn.wordpress.org/usd-to-toman-price-sync-for-woocommerce/ svn-checkout
cp wordpress-org/*.png svn-checkout/assets/
cd svn-checkout && svn add assets --force && svn commit assets -m "Update the listing artwork"
```

The filenames are fixed by WordPress.org and must not be changed:

| File | Size | Shown as |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Icon in search results and the plugin list. |
| `icon-256x256.png` | 256×256 | Retina icon (used on high density screens). |
| `banner-772x250.png` | 772×250 | Banner above the plugin description. |
| `banner-1544x500.png` | 1544×500 | Retina banner. |
| `screenshot-1.png` … `screenshot-5.png` | any | Screenshots, in this order, with the captions from the `== Screenshots ==` section of `readme.txt`. |

`screenshot-4.png` is the Persian admin: it shows the bundled `fa_IR` translation, so it belongs
directly under the captions in the readme.

## Regenerating

```bash
pip install Pillow                                  # the only runtime dependency
curl -sSL -o /tmp/vaz.tar.gz \
  https://codeload.github.com/rastikerdar/vazirmatn/tar.gz/refs/tags/v33.003
mkdir -p /tmp/vaz && tar -xzf /tmp/vaz.tar.gz -C /tmp/vaz --strip-components=1
python3 wordpress-org/make-artwork.py --font-dir=/tmp/vaz/fonts/ttf
```

The generator draws every file from the plugin's own wording and the WordPress admin palette, prints
the size of each image and exits non-zero when a file has the wrong dimensions. It needs PHP with GD
and FreeType only if you prefer the PHP tooling; the Python version has no such requirement.

Persian text goes through `shape_fa.py` first. Pillow without raqm and ImageMagick both lack complex
text layout, so without shaping the letters are drawn disconnected and in the wrong order; the module
maps every letter to its Unicode presentation form and reverses the runs, keeping digits and Latin
words in their own order (the bidi rule). The rendered Persian text therefore needs the font's
presentation form coverage, which Vazirmatn has.

## Font

Vazirmatn by Saber Rastikerdar, licensed under the **SIL Open Font License 1.1**. The font is not
committed to this repository: download it as shown above. The same fonts power an Iranian store's
front end, so the artwork looks like the product.
