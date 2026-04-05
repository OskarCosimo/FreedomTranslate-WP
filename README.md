# Freedom Translate WP

**Translate your WordPress site on-the-fly using AI (AI/LibreTranslate) or Google Translate.** Includes smart caching, auto-prewarming, static strings management, multiple translation services, user language selection, HTML-aware translation, and a comprehensive tabbed admin interface.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Embed Language Selector](#embed-language-selector)
  - [Static Strings (Headers/Footers)](#static-strings-headersfooters)
  - [Language Detection Modes](#language-detection-modes)
  - [Exclude Posts or Pages](#exclude-posts-or-pages)
- [Admin Panel](#admin-panel)
  - [General & API](#general--api)
  - [Languages](#languages)
  - [Static Strings](#static-strings)
  - [Tools](#tools)
- [Supported Languages](#supported-languages)
- [Google Cloud Setup](#google-cloud-setup)
- [Notes](#notes)
- [What's New](#whats-new)
- [Author](#author)
- [License](#license)
- [Disclaimer](#disclaimer)

---

## Features

- 🌐 **Real-time translation** of all frontend content (titles, post content, home, etc.)
- 🚀 **Auto-Translate on Save (Pre-warming)** - Automatically translates and caches posts in the background for all enabled languages the moment you publish or update them.
- ⚡ **Asynchronous Background Processing** - Zero page-load delays. Translations happen in the background with real-time UI progress banners.
- 🧩 **Static Strings Manager** - Translate headers, footers, and widgets once, and display them dynamically using the `[ft_string id="..."]` shortcode.
- 🔄 **Multiple Translation Services**:
  - **AI / LibreTranslate (Local/Remote)** - Self-hosted or public server (open-source)
  - **Google Translate (Free)** - Hash-based official Google Translator widget
  - **Google Cloud Translation API** - Official paid service (~$20 per 1M characters)
- 🎯 **Language Detection Modes**:
  - **Automatic** - Detects initial language from browser settings
  - **Manual** - Admin defines default language
- ⏳ **Loading Overlay** - Beautiful animated spinner with "Translation loading..." message during language changes
- 📦 **Smart Caching system** - Set a custom TTL (days) or set it to `0` for infinite cache.
- 🛑 **Exclude specific pages or posts** from being translated via a checkbox in the editor
- 🚫 **Excluded words** - Set words/phrases that should never be translated (brand names, technical terms, etc.)
- ⚙️ **Tabbed Admin panel** - Clean, modern, and dynamic interface to manage all settings easily.

---

## Requirements

- WordPress 5.x or newer
- PHP 7.4+
- **One of the following**:
  - A local or remote instance of AI API / [LibreTranslate](https://github.com/uav4geo/LibreTranslate) (default: `http://localhost:5000`)
  - Google Cloud Translation API key (for official Google service)
  - Internet connection (for free Google Translate)

---

## Installation

1. Clone or download this repository
2. Upload the plugin to your WordPress `/wp-content/plugins/` directory
3. Activate it from the WordPress **Plugins** admin screen
4. Configure your preferred translation service in **Settings → FreedomTranslate**

---

## Usage

### Embed Language Selector

Use the shortcode anywhere in your theme or posts:

```
[freedomtranslate_selector]
```

This will render a dropdown with all enabled languages and a mode indicator icon:
- 🌐 = Automatic browser detection
- 📌 = Manual admin-defined default

### Static Strings (Headers/Footers)

To translate global theme elements that are not part of the standard post content (e.g., Footer credits, Header texts, custom Widgets):
1. Go to **Settings → FreedomTranslate → Static Strings**.
2. Add a new string (e.g., ID: `footer_credits`, Text: `All rights reserved`).
3. The plugin will instantly translate it into all enabled languages.
4. Use the shortcode inside your theme/widget:
   ```
   [ft_string id="footer_credits"]
   ```

### Language Detection Modes

**Automatic Mode** 🌐:
- Initial language is detected from user's browser
- User can change language anytime via selector

**Manual Mode** 📌:
- Admin sets a default language in settings
- No browser detection

### Exclude Posts or Pages

When editing a post or page, check the box:
> **"Exclude this page/post from ALL translations"**
in the **FreedomTranslate Settings** meta box in the editor sidebar.

---

## Admin Panel

Navigate to:  
**WordPress Admin → Settings → FreedomTranslate**

The panel is now organized into 4 simple tabs:

### General & API
- **Select Engine**: Choose between AI/LibreTranslate, Google Free, or Google Official. UI will dynamically adapt.
- **API Configuration**: Enter your endpoints and API keys.
- **Processing Mode**: Choose Async (Background) or Sync (Real-time).
- **Automation & Cache**: Enable "Auto-Translate on Save" and manage global Cache TTL (set to `0` for permanent cache).

### Languages
- Enable or disable specific languages from the available list.

### Static Strings
- Manage and translate custom strings for Headers, Footers, and Widgets.

### Tools
- **Clear translation cache**: One-click button to flush all saved translations.

---

## Supported Languages

| Code | Language       | Code | Language       |
|------|----------------|------|----------------|
| en   | English        | ar   | Arabic         |
| it   | Italiano       | az   | Azerbaijani    |
| es   | Español        | zh   | Chinese        |
| fr   | Français       | cs   | Czech          |
| de   | Deutsch        | da   | Danish         |
| ru   | Русский        | nl   | Dutch          |
| pt   | Português      | fi   | Finnish        |
| el   | Greek          | he   | Hebrew         |
| hi   | Hindi          | hu   | Hungarian      |
| id   | Indonesian     | ga   | Irish          |
| ja   | Japanese       | ko   | Korean         |
| no   | Norwegian      | pl   | Polish         |
| ro   | Romanian       | sk   | Slovak         |
| sv   | Swedish        | tr   | Turkish        |
| uk   | Ukrainian      | vi   | Vietnamese     |

You can limit which languages are available via the admin interface.

---

## Google Cloud Setup

To use the official Google Cloud Translation API (optional):

1. Create a project at [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the **Cloud Translation API**
3. Set up billing (required for API usage)
4. Create an API key in **APIs & Services → Credentials**
5. Enter your API key in **FreedomTranslate Settings → General & API**

**Pricing**: Approximately $20 per 1 million characters translated.

---

## Notes

- **AI / LibreTranslate**: Must be running and reachable at your configured URL.
- **Google Free**: Official Google Translator widget, translates the whole page on the client-side.
- **Google Official**: Requires billing account, most reliable and fastest option.
- **Auto-Prewarm**: When enabled, post translations are staggered by 10 seconds per language to prevent overloading your local AI server.
- Translations are cached using WordPress options to improve performance.
- Cache TTL can be set to `0` to make translations permanent.

---

## What's New

**v1.5.5**
- ✨ **Massive UI Overhaul**: Brand new tabbed Admin Panel for better organization and dynamic option loading.
- 🚀 **Auto-Prewarm on Save**: Automatically translate posts in the background to all enabled languages upon saving.
- 🧩 **Static Strings Manager**: New tool to translate headers, footers, and widgets once, and render them via `[ft_string]` shortcode.
- 💾 **Infinite Cache**: Cache TTL can now be set to `0` for permanent translations.
- 🤖 **AI Modernization**: Replaced legacy MarianMT references with modern AI / LibreTranslate (Local7Remote) support.
- 🗑️ Removed legacy "chunks" mode. Async background processing is now the standard for large texts.

**v1.4.5**
- Fixed shortcodes placeholder
  
**v1.4.4**
- Added Google Translate support (free plugin + official paid API)
- Language detection modes: automatic (browser) or manual (admin-defined)
- Loading overlay with animated spinner during language changes

---

## Author

**Freedom** 2025 – Licensed under [GPLv3 or later](LICENSE)

---

## License

This plugin is released under the GNU GPLv3 license. See [LICENSE](LICENSE) for full details.

---

## Disclaimer

This plugin is not affiliated with or endorsed by LibreTranslate, Google Translate, or their respective developers.  
"LibreTranslate" and "Google Translate" are used solely to describe the APIs that this plugin can interact with.
```
