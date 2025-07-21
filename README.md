# Freedom Translate WP

**Translate your WordPress site on-the-fly using a local instance of LibreTranslate (http://localhost:5000) or a remote URL with API.**  
Includes smart caching, user language selection, HTML-aware translation, and an admin interface.

---

## 🔧 Features

- 🌐 **Real-time translation** of all frontend content (titles, post content, home, etc.).
- 💡 **No need to translate posts manually** or store multiple versions.
- 📦 **Caching system** to avoid repeating translation requests.
- 🌍 **Language selector** via shortcode `[freedomtranslate_selector]`.
- 🧠 **Automatic browser language detection**.
- 🛑 **Exclude specific pages or posts** from being translated via a checkbox in the editor.
- ⚙️ **Admin panel**:
  - Clear cache with one click.
  - Choose which target languages are allowed (restrict visible languages).
  - Excluded words (settings words that no need translations)
  - Changing URL and port (also enable local or remote url for libretranslate API)
  - Changing the api key (optional)
- 🛡️ Fully self-hosted. No external API or keys required.

---

## 🚀 Requirements

- WordPress 5.x or 6.x or newer
- PHP 7.4+
- A local instance of [LibreTranslate](https://github.com/uav4geo/LibreTranslate) running on `http://localhost:5000` (if not, you can edit the url or the port in the admin panel)

---

## 📥 Installation

1. Clone or download this repository.
2. Upload the plugin to your WordPress `/wp-content/plugins/` directory.
3. Activate it from the WordPress **Plugins** admin screen.
4. Ensure that your LibreTranslate server is running at `http://localhost:5000` (or you can edit the field in the admin panel with a custom url or custom port).

---

## 🧩 Usage

### Embed Language Selector

Use the shortcode anywhere in your theme or posts:

[freedomtranslate_selector]


This will render a `<select>` dropdown with all available languages (unless restricted from the admin panel).

### Exclude Posts or Pages

When editing a post or page, check the box:

> **"Exclude this page/post from the automatic translation"**

in the **Freedom Translate** box on the right-hand sidebar.

---

## 🛠️ Admin Panel

Navigate to:  
**WordPress Admin → Settings → Freedom Translate**

You’ll find:
- 🔁 A button to **clear the translation cache**.
- Choose which target languages are allowed (restrict visible languages).
- Excluded words (settings words that no need translations)
- Changing URL and port (also enable local or remote url for libretranslate API or similar)
- Changing the api key (optional)
- 🌐 A list of supported languages — uncheck the ones you don’t want to make available.

Only the selected languages will appear in the `[freedomtranslate_selector]`.

---

## 📝 Supported Languages

| Code | Language       |
|------|----------------|
| en   | English        |
| it   | Italian        |
| es   | Spanish        |
| fr   | French         |
| de   | German         |
| ru   | Russian        |
| pt   | Portuguese     |
| ar   | Arabic         |
| az   | Azerbaijani    |
| zh   | Chinese        |
| cs   | Czech          |
| da   | Danish         |
| nl   | Dutch          |
| fi   | Finnish        |
| el   | Greek          |
| he   | Hebrew         |
| hi   | Hindi          |
| hu   | Hungarian      |
| id   | Indonesian     |
| ga   | Irish          |
| ja   | Japanese       |
| ko   | Korean         |
| no   | Norwegian      |
| pl   | Polish         |
| ro   | Romanian       |
| sk   | Slovak         |
| sv   | Swedish        |
| tr   | Turkish        |
| uk   | Ukrainian      |
| vi   | Vietnamese     |

You can limit which of these are available via the admin interface.

---

## ⚠️ Notes

- LibreTranslate must be running and reachable at `http://localhost:5000/translate` (or a custom url or custom port).
- It supports both `text` and `html` formats — this plugin uses the HTML-aware mode for accurate frontend rendering.
- Translations are cached using WordPress options. Cache can be cleared anytime.

---

## 🧑‍💻 Author

**Freedom**  
2025 – Licensed under [GPLv3 or later](LICENSE)

---

## 📄 License

This plugin is released under the GNU GPLv3 license. See [LICENSE](LICENSE) for full details.

== Disclaimer ==

This plugin is not affiliated with or endorsed by LibreTranslate or its developers.  
"LibreTranslate" is a collaborative open source project. The name is used solely to describe the API that this plugin interacts with.
