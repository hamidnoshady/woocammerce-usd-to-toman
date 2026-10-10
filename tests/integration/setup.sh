#!/usr/bin/env bash
# Canonical integration environment setup.
#
# Downloads WordPress, WooCommerce, the SQLite drop-in, writes wp-config.php
# and installs the plugin (from the working copy or from a built ZIP).
# Replaces the duplicated steps in ci.yml and verify-release.yml.
#
# Usage:
#   bash tests/integration/setup.sh <wp-path> [--url=http://127.0.0.1:8888] [--from-zip=/path/to/zip]
#
# It is idempotent: repeated runs reuse existing downloads when possible.

set -euo pipefail

WP_PATH=""
WP_URL="http://127.0.0.1:8888"
ZIP_PATH=""

for arg in "$@"; do
    case "$arg" in
        --url=*)
            WP_URL="${arg#--url=}"
            ;;
        --from-zip=*)
            ZIP_PATH="${arg#--from-zip=}"
            ;;
        --*)
            echo "::error::Unknown argument: $arg" >&2
            exit 1
            ;;
        *)
            if [ -z "$WP_PATH" ]; then
                WP_PATH="$arg"
            else
                echo "::error::Unexpected argument: $arg" >&2
                exit 1
            fi
            ;;
    esac
done

if [ -z "$WP_PATH" ]; then
    echo "::error::WP_PATH is required: bash tests/integration/setup.sh <wp-path>" >&2
    exit 1
fi

WP_PATH="$(realpath -m "$WP_PATH" 2>/dev/null || echo "$WP_PATH")"
WP_PATH="${WP_PATH%/}"

echo "[setup] WP_PATH=$WP_PATH"
echo "[setup] WP_URL=$WP_URL"
if [ -n "$ZIP_PATH" ]; then
    echo "[setup] ZIP=$ZIP_PATH"
else
    echo "[setup] Source: working copy"
fi

mkdir -p "$(dirname "$WP_PATH")"

# ---------------------------------------------------------------------------
# Download WordPress.
# ---------------------------------------------------------------------------
if [ ! -f "${WP_PATH}/wp-load.php" ]; then
    echo "[setup] Downloading WordPress..."
    tmp_tgz="$(mktemp /tmp/wordpress-XXXXXX.tar.gz)"
    curl -sSL -o "$tmp_tgz" https://wordpress.org/latest.tar.gz
    tar -xzf "$tmp_tgz" -C "$(dirname "$WP_PATH")"
    # wordpress.org extracts to ./wordpress; move to desired path.
    if [ ! -d "${WP_PATH}" ]; then
        # If the target was not wordpress, the extraction created $(dirname)/wordpress
        extracted="$(dirname "$WP_PATH")/wordpress"
        if [ -d "$extracted" ] && [ "$extracted" != "$WP_PATH" ]; then
            mv "$extracted" "$WP_PATH"
        fi
    fi
    rm -f "$tmp_tgz"
else
    echo "[setup] WordPress already present at $WP_PATH"
fi

# ---------------------------------------------------------------------------
# Download WooCommerce.
# ---------------------------------------------------------------------------
if [ ! -f "${WP_PATH}/wp-content/plugins/woocommerce/woocommerce.php" ]; then
    echo "[setup] Downloading WooCommerce..."
    tmp_zip="$(mktemp /tmp/woocommerce-XXXXXX.zip)"
    curl -sSL -o "$tmp_zip" https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip
    unzip -q "$tmp_zip" -d "${WP_PATH}/wp-content/plugins"
    rm -f "$tmp_zip"
else
    echo "[setup] WooCommerce already present"
fi

# ---------------------------------------------------------------------------
# Install SQLite database drop-in.
# ---------------------------------------------------------------------------
if [ ! -f "${WP_PATH}/wp-content/db.php" ] || [ ! -d "${WP_PATH}/wp-content/plugins/sqlite-database-integration" ]; then
    echo "[setup] Installing SQLite drop-in..."
    tmp_zip="$(mktemp /tmp/sqlite-XXXXXX.zip)"
    curl -sSL -o "$tmp_zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
    unzip -q "$tmp_zip" -d "${WP_PATH}/wp-content/plugins"
    rm -f "$tmp_zip"
    cp "${WP_PATH}/wp-content/plugins/sqlite-database-integration/db.copy" "${WP_PATH}/wp-content/db.php"
    # The db.php template contains placeholders that must be replaced with absolute paths.
    # Use | as delimiter to avoid escaping slashes.
    sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${WP_PATH}/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "${WP_PATH}/wp-content/db.php"
else
    echo "[setup] SQLite drop-in already present"
fi

# ---------------------------------------------------------------------------
# Configure WordPress.
# ---------------------------------------------------------------------------
echo "[setup] Configuring WordPress (url $WP_URL)..."
# Always force rewrite so URL changes are reflected.
php tests/integration/make-config.php "$WP_PATH" --url="$WP_URL" --force

# ---------------------------------------------------------------------------
# Install plugin.
# ---------------------------------------------------------------------------
if [ -n "$ZIP_PATH" ]; then
    echo "[setup] Installing plugin from ZIP: $ZIP_PATH"
    php tests/integration/prepare.php "$WP_PATH" --from-zip="$ZIP_PATH"
else
    echo "[setup] Installing plugin from working copy..."
    php tests/integration/prepare.php "$WP_PATH"
fi

# ---------------------------------------------------------------------------
# Summary.
# ---------------------------------------------------------------------------
PLUGIN_SLUG="usd-to-toman-price-sync-for-woocommerce"
if [ -f "${WP_PATH}/wp-content/plugins/${PLUGIN_SLUG}/${PLUGIN_SLUG}.php" ]; then
    # Prefer PHP header parsing; grep fallback only if php fails.
    PLUGIN_VERSION="$(php -r 'preg_match("/Version:\s*([\d.]+)/", file_get_contents(getenv("WP_PATH")."/wp-content/plugins/usd-to-toman-price-sync-for-woocommerce/usd-to-toman-price-sync-for-woocommerce.php"), $m); echo $m[1] ?? "";' 2>/dev/null || true)"
    if [ -z "${PLUGIN_VERSION:-}" ]; then
        PLUGIN_VERSION="$(grep -m1 -E '^[ \t\/*#@]*Version:' "${WP_PATH}/wp-content/plugins/${PLUGIN_SLUG}/${PLUGIN_SLUG}.php" 2>/dev/null | sed -E 's/.*Version:[ \t]*//' | tr -d '\r' | xargs || echo "?")"
    fi
    if [ -z "${PLUGIN_VERSION:-}" ]; then PLUGIN_VERSION="?"; fi
    # Escape backticks for markdown.
    PLUGIN_VERSION_ESC="$(printf '%s' "$PLUGIN_VERSION" | sed 's/`/\`/g; s/|/\|/g')"
    echo "[setup] Plugin ${PLUGIN_SLUG} version ${PLUGIN_VERSION_ESC} installed"
else
    echo "::warning::Plugin not found at ${WP_PATH}/wp-content/plugins/${PLUGIN_SLUG}" >&2
fi

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        echo "### Integration environment"
        echo
        # Escape backticks/pipes for markdown code spans.
        wp_path_esc="$(printf '%s' "$WP_PATH" | sed 's/`/\\`/g; s/|/\\|/g')"
        wp_url_esc="$(printf '%s' "$WP_URL" | sed 's/`/\\`/g; s/|/\\|/g')"
        zip_esc="$(printf '%s' "${ZIP_PATH:-}" | sed 's/`/\\`/g; s/|/\\|/g')"
        echo "- **WP_PATH**: \`${wp_path_esc}\`"
        echo "- **URL**: \`${wp_url_esc}\`"
        if [ -n "$ZIP_PATH" ]; then
            echo "- **Plugin**: from ZIP \`${zip_esc}\`"
            if [ -f "$ZIP_PATH.sha256" ]; then
                cksum="$(cut -d' ' -f1 "$ZIP_PATH.sha256" 2>/dev/null | head -c 16 | tr -d '\`' | tr -d '|')"
                echo "- **Checksum**: \`${cksum}...\`"
            fi
        else
            echo "- **Plugin**: from working copy"
        fi
        # Robust version extraction via php when available, fallback to grep.
        wp_ver="$(php -r 'include getenv("WP_PATH")."/wp-includes/version.php"; echo $wp_version ?? "";' 2>/dev/null || true)"
        if [ -z "${wp_ver:-}" ]; then
            wp_ver="$(grep -m1 "\$wp_version" "${WP_PATH}/wp-includes/version.php" 2>/dev/null | sed -E "s/.*'([^']+)'.*/\1/" | head -n1 || echo "?")"
        fi
        wp_ver="$(printf '%s' "$wp_ver" | sed 's/`/\\`/g; s/|/\\|/g')"
        wc_ver="$(php -r '$f=getenv("WP_PATH")."/wp-content/plugins/woocommerce/woocommerce.php"; $c=file_get_contents($f); if(preg_match("/WC_VERSION.*'\''([^'\'']+)'\''/",$c,$m)) echo $m[1];' 2>/dev/null || true)"
        if [ -z "${wc_ver:-}" ]; then
            wc_ver="$(grep -m1 "WC_VERSION" "${WP_PATH}/wp-content/plugins/woocommerce/woocommerce.php" 2>/dev/null | sed -E "s/.*WC_VERSION.*'([^']+)'.*/\1/" | head -n1 || echo "?")"
        fi
        wc_ver="$(printf '%s' "$wc_ver" | sed 's/`/\\`/g; s/|/\\|/g')"
        echo "- **WordPress**: \`${wp_ver}\`"
        echo "- **WooCommerce**: \`${wc_ver}\`"
        echo
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "[setup] Done"
