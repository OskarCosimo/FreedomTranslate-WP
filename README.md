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

LibreTranslate use a specific JSON format for translation requests and no need of other scripts.
Ollama, however, expects a different format (prompts and models), to make them talk to each other perfectly you can run a lightweight Python "bridge" using Flask.

Here is a ready-to-use example script. 

**1. Install required Python packages:**
```bash
pip install flask requests flask-cors
```

**2. Create a file named `app.py` and paste this code:**

```python
"""
Ollama Translation Proxy with Async Job Management
Provides a Flask API layer between local LLMs (via Ollama) and web applications.
Includes anti-hallucination regex for markdown artifacts and thread-safe job cancellation.
"""

from flask import Flask, request, jsonify
import requests
import re
import threading

app = Flask(__name__)

# =============================================================
# OLLAMA & MODEL CONFIGURATION
# =============================================================
OLLAMA_URL = "http://127.0.0.1:11434/api/generate"
MODEL_NAME = "gemma3:4b" # Customize this with your preferred local model

# =============================================================
# ACTIVE JOBS REGISTRY (hashkey → threading.Event)
# =============================================================
_jobs_lock = threading.Lock()
_active_jobs = {}

def register_job(job_id):
    stop_event = threading.Event()
    with _jobs_lock:
        _active_jobs[job_id] = stop_event
    return stop_event

def unregister_job(job_id):
    with _jobs_lock:
        _active_jobs.pop(job_id, None)

def cancel_job(job_id):
    with _jobs_lock:
        event = _active_jobs.get(job_id)
    if event:
        event.set()
        return True
    return False

# =============================================================
# OUTPUT CLEANING FUNCTION (ANTI-HALLUCINATION)
# =============================================================
def clean_translation(text: str) -> str:
    """
    Cleans the output from thinking blocks, spurious HTML tags,
    markdown formatting, or labels erroneously added by the model.
    """
    # 1. Remove the entire <thinking>...</thinking> block (typical in newer models)
    cleaned = re.sub(r'<thinking>.*?</thinking>', '', text, flags=re.DOTALL | re.IGNORECASE).strip()

    # 2. Remove any prefixes like "Text:" or "Testo:"
    cleaned = re.sub(r'^(Text:\s*|Testo:\s*)', '', cleaned, flags=re.IGNORECASE).strip()
    
    # 3. Strip hallucinated markdown code blocks (e.g., ```html and closing ```)
    cleaned = re.sub(r'^```[a-zA-Z]*\s*', '', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'\s*```$', '', cleaned)

    # 4. List of single labels to preserve if the text consists ONLY of them
    single_labels = [
        "city name", "country", "address", "street", "zip code",
        "postal code", "state", "province", "email", "phone number"
    ]

    if cleaned.lower() in single_labels:
        return cleaned

    # 5. Cleanup of complex patterns (e.g., "City name (optional)")
    pattern = r"\b(city name|country|address|street|zip code|postal code|state|province|email|phone number)\s*\([^)]*(optional|probably|translation|untranslated)[^)]*\)"
    cleaned = re.sub(pattern, "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\([^)]*(optional|probably|translation|untranslated)[^)]*\)", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"(City name|Country|Address|Street|Zip code|Postal code|State|Province|Email|Phone number)", "", cleaned, flags=re.IGNORECASE)

    # 6. Remove double spaces and trim edges
    cleaned = re.sub(r"\s{2,}", " ", cleaned).strip()

    return cleaned

# =============================================================
# OLLAMA DIRECT CALL
# =============================================================
def call_ollama(prompt, stop_event=None):
    """
    Sends the request to Ollama with optimized parameters.
    If stop_event is already set before the call, it aborts immediately.
    """
    if stop_event and stop_event.is_set():
        print(f"[OLLAMA] Job cancelled before initiating the call.")
        return None

    payload = {
        "model": MODEL_NAME,
        "prompt": prompt,
        "stream": False,
        "raw": False,      # Allows Ollama to use the model's native template
        "keep_alive": -1,  # KEEPS MODEL IN RAM FOREVER (Prevents slow cold starts)
        "options": {
            "temperature": 0.1,    # Low temp for highly accurate translations
            "top_p": 0.9,
            "num_predict": 4096,   # Max length of the generated response
            "num_ctx": 8192        # Context window optimized for large text chunks
        }
    }

    try:
        # Timeout at 900s (15m) to allow heavy local processing without breaking connections
        response = requests.post(OLLAMA_URL, json=payload, timeout=900)
        response.raise_for_status()

        # Post-response check: discard the result if cancellation arrived during generation
        if stop_event and stop_event.is_set():
            print(f"[OLLAMA] Job cancelled after generation, result discarded.")
            return None

        return response.json().get('response', '').strip()
    except Exception as e:
        print(f"Ollama Connection Error: {e}")
        return None

# =============================================================
# TRANSLATION LOGIC & PROMPTING
# =============================================================
def translate_logic(text, source_lang, target_lang, is_html=False, stop_event=None):
    """
    Builds the correct prompt and handles the translation pipeline.
    """
    if source_lang.lower() == target_lang.lower():
        return text

    if stop_event and stop_event.is_set():
        return text

    if is_html:
        prompt = (
            f"You are a professional web systems translator. Translate the following HTML content from '{source_lang}' to '{target_lang}'.\n"
            "CRITICAL RULES:\n"
            f"- You MUST translate 100% of the visible text into '{target_lang}'.\n"
            f"- DO NOT leave any words or phrases in '{source_lang}' unless they are specific brand names.\n"
            "- DO NOT modify HTML tags, attributes (href, src, class, id), or entities.\n"
            "- Return ONLY the translated HTML, without any thinking process or explanation.\n\n"
            f"HTML to translate:\n{text}"
        )
    else:
        prompt = (
            f"You are a professional translator from '{source_lang}' to '{target_lang}'.\n"
            "Provide ONLY the translated text. NO explanations, NO introductions, NO quotes, do NOT echo the original text.\n\n"
            f"Text to translate:\n{text}"
        )

    translated = call_ollama(prompt, stop_event=stop_event)
    if translated:
        return clean_translation(translated)
    return text

# =============================================================
# FLASK API ROUTES
# =============================================================
@app.route('/translate', methods=['POST'])
def translate_route():
    """
    Main endpoint for text and HTML translation.
    Accepts 'q' (text), 'source', 'target', and 'format' parameters.
    Optionally supports 'job_id' to allow asynchronous cancellation.
    """
    data = request.get_json() if request.is_json else request.form

    text = data.get('q', data.get('text', ''))
    source = data.get('source', 'auto')
    target = data.get('target', 'en')
    format_type = data.get('format', 'text')  
    job_id = data.get('job_id', None)         

    if not text:
        return jsonify({'error': 'Missing text parameter'}), 400

    # Enhanced HTML detection
    is_html = (format_type == 'html') or ('<' in text and '>' in text)

    # Register the job if a job_id is provided
    stop_event = None
    if job_id:
        stop_event = register_job(job_id)
        print(f"[JOB] Registered job_id={job_id}")

    try:
        translated = translate_logic(text, source, target, is_html, stop_event=stop_event)
    finally:
        if job_id:
            unregister_job(job_id)
            print(f"[JOB] Removed job_id={job_id}")

    # Signal cancellation in the response if the stop event was triggered
    if stop_event and stop_event.is_set():
        return jsonify({'translatedText': None, 'cancelled': True}), 200

    return jsonify({'translatedText': translated})


@app.route('/cancel', methods=['POST'])
def cancel_route():
    """
    Endpoint to cancel an ongoing translation job.

    Expected JSON body for single cancellation:
        { "job_id": "<hashkey>" }
    Expected JSON body for bulk cancellation:
        { "job_ids": ["<hashkey1>", "<hashkey2>"] }
    """
    data = request.get_json(silent=True) or {}

    # Single job cancellation
    if 'job_id' in data:
        job_id = data['job_id']
        found = cancel_job(job_id)
        print(f"[CANCEL] job_id={job_id} → {'cancelled' if found else 'not found'}")
        return jsonify({'status': 'cancelled' if found else 'not_found', 'job_id': job_id})

    # Bulk cancellation
    if 'job_ids' in data:
        results = {}
        for jid in data['job_ids']:
            found = cancel_job(jid)
            results[jid] = 'cancelled' if found else 'not_found'
            print(f"[CANCEL BULK] job_id={jid} → {results[jid]}")
        return jsonify({'status': 'ok', 'results': results})

    return jsonify({'error': 'Missing job_id or job_ids parameter'}), 400


@app.route('/status', methods=['GET'])
def status_route():
    """
    Debug endpoint: lists currently active translation jobs.
    """
    with _jobs_lock:
        active = {jid: ev.is_set() for jid, ev in _active_jobs.items()}
    return jsonify({'active_jobs': active, 'count': len(active)})


# =============================================================
# SERVER STARTUP
# =============================================================
if __name__ == '__main__':
    # Running on 127.0.0.1 to prevent unauthorized external network access.
    # Change to '0.0.0.0' only if you need to expose the API to your local LAN.
    app.run(host='127.0.0.1', port=5005, threaded=True)
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
