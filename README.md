# ✝️ Free Christian E-Books and PDFs — File Browser App

A lightweight, SEO-friendly, mobile-first PHP file browser built to serve free Christian e-books, PDFs, audio Bibles and more at [same-website.com](https://same-website.com).

---

## ✨ Features

- 📂 **File Browser** — Browse folders and files inside the `data/` directory
- 📥 **Force Download** — Files are served as attachments (not opened in browser tab), with proper UTF-8 filenames for Tamil, Hindi, Kannada, etc.
- 🔒 **Security** — Path traversal protection; all file access routed through `download.php`; `data/` directory not directly accessible
- 🙈 **Hidden Items** — Folders/files prefixed with `HIDE` are not shown; system files (`.ffs_db`, `.ffs_lock`, `.DS_Store`) are filtered out
- 🔤 **Clean Display Names** — Hyphens in file/folder names are replaced with spaces in the UI
- 🍞 **Breadcrumb Navigation** — Full breadcrumb trail with clickable parent links
- ⬆️ **Up One Level** — Always visible `..` row to go back, even in empty folders
- 📋 **Copy Link** — Button to copy the current page URL in decoded (human-readable) format
- 👁️ **Visitor Counter** — Bot-aware visitor counter with file locking (`counter.php`)
- 🤖 **Bot Honeypot** — Hidden honeypot link that logs bot activity (`bot.php`)
- 📱 **PWA Support** — Installable as an app on Android, iOS and Desktop
  - Auto install banner for Chrome/Edge
  - iOS "Add to Home Screen" instructions
  - Service worker with network-first caching
- 🔍 **SEO Friendly** — Dynamic `<title>`, meta description, canonical URL, Open Graph tags, JSON-LD Schema.org markup
- 🖥️ **Embed Mode** — Append `&embed=1` to any URL to show only the file list (hides header, breadcrumb, footer) — useful for embedding in iframes

---

## 📁 File Structure

```
files-browser-app/
├── index.php          # Main file browser
├── download.php       # Secure file download handler
├── counter.php        # Visitor counter (bot-aware)
├── bot.php            # Bot honeypot logger
├── sw.js              # PWA Service Worker
├── manifest.json      # PWA Web App Manifest
├── styles.css         # All CSS styles
├── .htaccess          # Apache config (security, caching, PHP handler)
├── icons/
│   ├── icon-192.png   # PWA icon (192×192)
│   ├── icon-512.png   # PWA icon (512×512)
│   ├── icon.svg       # Source SVG icon
│   └── generate-icons.php  # One-time icon generator (PHP GD)
└── data/              # ← Put your folders and files here
    ├── Tamil-Christian-Books/
    ├── Hindi-Christian-Books/
    ├── English-Christian-Books/
    └── ...
```

---

## 🚀 Running Locally

```bash
php -S localhost:3000 -t /path/to/files-browser-app
```

Then open **http://localhost:3000** in your browser.

> Requires PHP 8.0+ with the `fileinfo` (`finfo`) and `GD` extensions.

---

## 🌐 Deployment

1. Upload all files to your hosting **public root** (e.g. `public_html/`)
2. Make sure `data/` is populated with your folders and files
3. Ensure `.htaccess` is uploaded (it sets `DirectoryIndex index.php` and blocks direct `data/` access)
4. Set write permissions on `counter.txt` and `bot.log`:
   ```bash
   chmod 644 counter.txt bot.log
   ```

---

## 🔗 Embed Mode

Append `?embed=1` or `&embed=1` to any URL to render only the file list — no header, breadcrumb, or footer. Navigation within the embedded view keeps `embed=1` active.

```
https://same-website.com/?embed=1
https://same-website.com/?path=Tamil-Christian-Books&embed=1
```

---

## 🙈 Hiding Folders / Files

- **Prefix with `HIDE`** — e.g. `HIDE-drafts/` will not appear in the browser
- **System files** automatically hidden: `sync.ffs_db`, `.sync.ffs_db`, `sync.ffs_lock`, `.DS_Store`

---

## 📜 License

See [LICENSE](LICENSE).

---

> We do not own any of the books in this repository. They were collected from various sources — internet, WhatsApp, Cloud Drives, etc. If you want your book removed, email **admin@same-website.com**.
