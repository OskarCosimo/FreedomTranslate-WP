# Freedom Translate WP

**Translate your WordPress site on-the-fly using LibreTranslate, MarianMT, or Google Translate.**  
Includes smart caching, multiple translation services, user language selection, HTML-aware translation, and a comprehensive admin interface with loading indicators.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Embed Language Selector](#embed-language-selector)
  - [Language Detection Modes](#language-detection-modes)
  - [Exclude Posts or Pages](#exclude-posts-or-pages)
- [Admin Panel](#admin-panel)
  - [Translation Service](#translation-service)
  - [Language Detection Mode](#language-detection-mode)
  - [Other Settings](#other-settings)
- [Supported Languages](#supported-languages)
- [Google Cloud Setup](#google-cloud-setup)
- [Notes](#notes)
- [What's New in v2.2](#whats-new-in-v22)
- [Author](#author)
- [License](#license)
- [Disclaimer](#disclaimer)

---

## Features

- 🌐 **Real-time translation** of all frontend content (titles, post content, home, etc.)
- 🔄 **Multiple Translation Services**:
  - **LibreTranslate / MarianMT** - Self-hosted or public server (open-source)
  - **Google Translate (Free)** - Unofficial API endpoint (no cost, may be rate-limited)
  - **Google Cloud Translation API** - Official paid service (~$20 per 1M characters)
- 🎯 **Language Detection Modes**:
  - **Automatic** - Detects initial language from browser settings
  - **Manual** - Admin defines default language
  - Users can always override and manually select their preferred language
- ⏳ **Loading Overlay** - Beautiful animated spinner with "Translation loading..." message during language changes
- 💡 **No need to translate posts manually** or store multiple versions
- 📦 **Caching system** to avoid repeating translation requests
- 🌍 **Language selector** via shortcode with mode indicator icons
- 🛑 **Exclude specific pages or posts** from being translated via a checkbox in the editor
- 🚫 **Excluded words** - Set words/phrases that should never be translated (brand names, technical terms, etc.)
- ⚙️ **Admin panel**:
  - Choose translation service (LibreTranslate, Google Free, or Google Official)
  - Set language detection mode (Automatic or Manual)
  - Clear cache with one click
  - Choose which target languages are allowed
  - Configure API URLs and keys for each service
  - Manage excluded words list
- 🛡️ Flexible hosting - Self-hosted LibreTranslate or cloud services

---

## Requirements

- WordPress 5.x or newer
- PHP 7.4+
- **One of the following**:
  - A local or remote instance of [LibreTranslate](https://github.com/uav4geo/LibreTranslate) (default: `http://localhost:5000`)
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

### Language Detection Modes

**Automatic Mode** 🌐:
- Initial language is detected from user's browser
- User can change language anytime via selector
- Best for international audiences

**Manual Mode** 📌:
- Admin sets a default language in settings
- No browser detection
- User can still change language via selector
- Best for targeting specific regions

### Exclude Posts or Pages

When editing a post or page, check the box:

> **"Exclude this page/post from automatic translation"**

in the **FreedomTranslate** meta box in the editor sidebar.

---

## Admin Panel

Navigate to:  
**WordPress Admin → Settings → FreedomTranslate**

### Translation Service

Choose between:

1. **LibreTranslate / MarianMT** - Configure API URL and optional API key
2. **Google Translate (Free - Unofficial)** - No configuration needed (may be rate-limited)
3. **Google Cloud Translation API (Official - Paid)** - Enter your Google Cloud API key

### Language Detection Mode

- **Automatic**: Detects user's browser language automatically
- **Manual**: Set a default language for all new visitors
- In both modes, users can manually override via the language selector

### Other Settings

- 🔁 **Clear translation cache** with one click
- 🌐 **Enable/disable specific languages** from the available list
- 🚫 **Excluded words** - Words that should never be translated (one per line)
- ⚙️ **API Configuration** - URLs and keys for each translation service

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
5. Enter your API key in **FreedomTranslate Settings → Google Cloud Translation API Configuration**

**Pricing**: Approximately $20 per 1 million characters translated.

---

## Notes

- **LibreTranslate**: Must be running and reachable at your configured URL (default: `http://localhost:5000/translate`)
- **Google Free**: Uses unofficial endpoint, may be rate-limited or blocked by Google
- **Google Official**: Requires billing account, most reliable and fastest option
- Supports both `text` and `html` formats — this plugin uses HTML-aware mode for accurate frontend rendering
- Translations are cached using WordPress options to improve performance
- Cache can be cleared anytime from admin panel
- User language preference is stored in cookies for 30 days

---

## What's New in v1.4.4

- ✨ Added Google Translate support (free unofficial + official paid API)
- 🎯 Language detection modes: automatic (browser) or manual (admin-defined)
- ⏳ Loading overlay with animated spinner during language changes
- 🌍 All code comments and admin UI translated to English
- 🐛 Fixed admin options not saving issue
- 🔧 Improved admin panel organization with visual indicators
- 🔒 Enhanced security with separate nonce per form section

---

## Author

**Freedom**  
2025 – Licensed under [GPLv3 or later](LICENSE)

---

## License

This plugin is released under the GNU GPLv3 license. See [LICENSE](LICENSE) for full details.

---

## Disclaimer

This plugin is not affiliated with or endorsed by LibreTranslate, Google Translate, or their respective developers.  
"LibreTranslate" and "Google Translate" are used solely to describe the APIs that this plugin can interact with.
