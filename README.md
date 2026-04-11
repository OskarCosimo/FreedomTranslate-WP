# Freedom Translate WP

**Translate your WordPress site on-the-fly using Local AI (Ollama/Flask), LibreTranslate, or Google Translate.** Includes a smart Unified Translation Queue, Direct batch translation, HTML-aware chunking, static strings management, multiple translation services, user language selection, and a comprehensive tabbed admin interface.

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
  - [Direct Translate & Queue](#direct-translate--queue)
  - [Static Strings](#static-strings)
  - [Tools](#tools)
- [Supported Languages](#supported-languages)
- [Local AI Setup (Ollama / Flask)](#local-ai-setup-ollama--flask)
- [Google Cloud Setup](#google-cloud-setup)
- [Notes](#notes)
- [What's New](#whats-new)
- [Author](#author)
- [License](#license)
- [Disclaimer](#disclaimer)

---

## Features

- 🌐 **Real-time translation** of all frontend content (titles, post content, home, etc.)
- 🧠 **Local AI & Ollama Ready** - Fully optimized to work with a local Flask bridge running advanced LLMs (like Gemma 4) for private, high-quality translations.
- 🚦 **Unified Translation Queue** - Prevents server overload! Background translations are neatly queued and processed asynchronously to protect your AI server's RAM and CPU.
- 🎛️ **Direct Translate Panel** - Manually push specific posts or entire pages to the translation queue for batch/overnight processing.
- 🎚️ **Concurrency Control** - Set the `Max Concurrent Translations` limit to dictate exactly how many tasks hit your AI server at once.
- 🚀 **Auto-Translate on Save (Pre-warming)** - Automatically queues and translates posts in the background for all enabled languages the moment you publish or update them (can be disabled for Strict Manual Mode).
- 🛡️ **Advanced HTML Chunking** - Safely translates massive WordPress posts by splitting them into smart chunks, preserving all HTML tags and shortcodes intact.
- 🧩 **Static Strings Manager** - Translate headers, footers, and widgets once, and display them dynamically using the `[ft_string id="..."]` shortcode.
- 🔄 **Multiple Translation Services**:
  - **Local AI (Ollama via Flask) / LibreTranslate** - Self-hosted for ultimate privacy and zero recurring costs.
  - **Google Translate (Free)** - Hash-based official Google Translator widget.
  - **Google Cloud Translation API** - Official paid service (~$20 per 1M characters).
- 🎯 **Language Detection Modes**: Automatic (Browser) or Manual (Admin default).
- 📦 **Smart Caching system** - Set a custom TTL (days) or set it to `0` for infinite cache.
- 🛑 **Exclude specific pages or posts** / **Excluded words** from being translated.

---

## Requirements

- WordPress 5.x or newer
- PHP 7.4+
- **One of the following**:
  - A local/remote Flask bridge connected to **Ollama** (e.g., running `gemma3n:e2b` or similar LLMs in ollama/library website).
  - A local or remote instance of [LibreTranslate](https://github.com/uav4geo/LibreTranslate) (default: `http://localhost:5000`).
  - Google Cloud Translation API key.
  - Internet connection (for free Google Translate).

---

## Installation

1. Clone or download this repository.
2. Upload the plugin to your WordPress `/wp-content/plugins/` directory.
3. Activate it from the WordPress **Plugins** admin screen.
4. Configure your preferred translation service in **Settings → FreedomTranslate**.

---

## Usage

### Embed Language Selector

Use the shortcode anywhere in your theme or posts:

```text
[freedomtranslate_selector]
```

This will render a dropdown with all enabled languages and a mode indicator icon:
- 🌐 = Automatic browser detection
- 📌 = Manual admin-defined default

### Static Strings (Headers/Footers)

To translate global theme elements that are not part of the standard post content:
1. Go to **Settings → FreedomTranslate → Static Strings**.
2. Add a new string (e.g., ID: `footer_credits`, Text: `All rights reserved`).
3. The plugin will instantly translate it. Use the shortcode inside your theme/widget:
   ```text
   [ft_string id="footer_credits"]
   ```

### Language Detection Modes

**Automatic Mode** 🌐: Detected from user's browser. User can change it anytime.
**Manual Mode** 📌: Admin sets a default language. No browser detection.

---

## Admin Panel

Navigate to:  
**WordPress Admin → Settings → FreedomTranslate**

The panel is now organized into powerful, dynamic tabs:

### General & API
- **Select Engine**: Choose between AI/LibreTranslate, Google Free, or Google Official.
- **API Configuration**: Enter your endpoints (e.g., your Flask Python server IP).
- **Processing Mode**: Choose Async (Background) or Sync (Real-time).
- **Max Concurrent Translations**: Throttle your AI server. Set to `1` if you are sharing your local AI hardware with other apps (like a Chatbot).
- **Automation & Cache**: Enable/Disable "Auto-Translate on Save" (disable for Strict Manual Mode) and manage global Cache TTL.

### Languages
- Enable or disable specific languages from the available list.

### Direct Translate & Queue
- **Direct Translate**: Select a specific Post/Page, select target languages, and push them directly to the queue. Perfect for overnight batch translations.
- **Unified Queue Monitor**: View currently processing translations and clear the queue if needed.

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

## Local AI Setup (Ollama / Flask)

For the ultimate private translation server:
1. Run **Ollama** on your local machine or dedicated server.
2. Pull an LLM optimized for translations (e.g., `ollama pull gemma3n:e2b`).
3. Run a **Flask Python bridge** to handle incoming requests from WordPress, chunk the HTML, and communicate with Ollama.
4. In *FreedomTranslate → General & API*, point the Custom API URL to your Flask server (e.g., `http://localhost:5000/translate` or `http://192.168.1.X:5000/translate`).
5. Set `Max Concurrent Translations` based on your hardware capabilities to avoid Out-Of-Memory (OOM) errors.

### 💡 Bonus: Example Flask Bridge for Ollama

WordPress and LibreTranslate use a specific JSON format for translation requests. Ollama, however, expects a different format (prompts and models). To make them talk to each other perfectly, you can run a lightweight Python "bridge" using Flask.

Here is a ready-to-use example script. 

**1. Install required Python packages:**
```bash
pip install flask requests flask-cors
```

**2. Create a file named `app.py` and paste this code:**

```python
from flask import Flask, request, jsonify
from flask_cors import CORS
import requests

app = Flask(__name__)
CORS(app) # Allow cross-origin requests

# Configuration
OLLAMA_URL = "http://localhost:11434/api/generate"
MODEL_NAME = "gemma3n:e2b" # Change this to your downloaded model (this one is very small and fast good for testing)

@app.route('/translate', methods=['POST'])
def translate():
    # Read the incoming request from FreedomTranslate plugin
    data = request.json
    
    # Handle single string or array of strings (chunks)
    text_input = data.get('q', '')
    if isinstance(text_input, list):
        text_input = '\n'.join(text_input)

    source_lang = data.get('source', 'auto')
    target_lang = data.get('target', 'en')

    # Build the AI Prompt
    # We instruct the AI to act as a professional translator and preserve HTML
    prompt = (
        f"You are a professional web translator. "
        f"Translate the following HTML content from {source_lang} to {target_lang}. "
        f"CRITICAL: Preserve all HTML tags, shortcodes, and attributes exactly as they are. "
        f"Output ONLY the translated text, without any explanations or markdown code blocks.\n\n"
        f"{text_input}"
    )

    # Prepare payload for Ollama
    payload = {
        "model": MODEL_NAME,
        "prompt": prompt,
        "stream": False,
        "options": {
            "num_ctx": 8192,      # Increase context for large HTML chunks
            "num_predict": 4096   # Ensure long responses don't get cut off
        }
    }

    try:
        # Send request to local Ollama server
        response = requests.post(OLLAMA_URL, json=payload)
        response.raise_for_status()
        
        # Extract the translation
        translated_text = response.json().get('response', '').strip()
        
        # Return in LibreTranslate format expected by the plugin
        return jsonify({"translatedText": translated_text})

    except Exception as e:
        print(f"Error during translation: {e}")
        return jsonify({"error": str(e)}), 500

if __name__ == '__main__':
    # Run the server on port 5000 (accessible from your local network)
    print("Starting FreedomTranslate Flask Bridge for Ollama...")
    app.run(host='0.0.0.0', port=5000)
```

**3. Run the bridge:**
```bash
python3 app.py
```

**4. Connect the Plugin:**
In your WordPress admin, go to **FreedomTranslate → General & API**. Select `AI / LibreTranslate` as the engine, and set the Custom API URL to:
`http://YOUR_SERVER_IP:5000/translate` (or `http://localhost:5000/translate` if running on the same machine as WordPress).

---

## Google Cloud Setup

To use the official Google Cloud Translation API (optional):
1. Create a project at [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the **Cloud Translation API** and set up billing.
3. Create an API key and enter it in **FreedomTranslate Settings → General & API**.

---

## Notes

- **AI Server Load Balancing**: The new Unified Queue ensures your server never crashes, even if you translate a massive post into 10 languages at once.
- **Strict Manual Mode**: If you use your AI server for multiple tasks during the day, disable "Auto-Translate on save". Use the *Direct Translate* panel at night to process translations while you sleep.
- Translations are cached using WordPress options to improve performance. Cache TTL can be set to `0` to make translations permanent.

---

## What's New

**v1.9.3 (Massive Backend Overhaul)**
- ✨ **Unified Translation Queue**: No more server crashes! Translations are now elegantly queued and processed sequentially based on your hardware limits.
- 🎛️ **Direct Translate Panel**: Brand new admin tab to manually push posts to the translation queue. Ideal for overnight batch processing.
- 🚦 **Concurrency Control**: Added `Max Concurrent Translations` setting to throttle requests to local AI servers (perfect for Ollama multitasking).
- 🧠 **Local AI & Ollama Optimization**: Enhanced HTML chunking support to work flawlessly with local LLMs (like Gemma 4) via Flask API bridges. 
- ⏸️ **Strict Manual Mode**: Ability to safely decouple translation from the "Save Post" action to manage server load.

**v1.5.5**
- Massive UI Overhaul with dynamic tabbed Admin Panel.
- Auto-Prewarm on Save functionality.
- Static Strings Manager (`[ft_string]`).
- Infinite Cache (TTL `0`).

**v1.4.5 & Below**
- Added Google Translate support (Free + Official API).
- Language detection modes (Auto/Manual).

---

## Author

**Freedom** 2026 – Licensed under [GPLv3 or later](LICENSE)

---

## License

This plugin is released under the GNU GPLv3 license. See [LICENSE](LICENSE) for full details.

---

## Disclaimer

This plugin is not affiliated with or endorsed by LibreTranslate, Google Translate, Ollama, or their respective developers. "LibreTranslate", "Ollama", and "Google Translate" are used solely to describe the APIs and engines that this plugin can interact with.
```
