# Freedom Translate WP

**Translate your WordPress site using Local AI (Ollama / MLX-LM / LM Studio / vLLM), OpenAI, LibreTranslate, or Google Translate with background queuing and custom database caching.** Includes a smart Unified Translation Queue, Direct batch translation, role-based access control, a per-user translation credits system, HTML-aware chunking, static strings management, and a comprehensive tabbed interface.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Embed Language Selector](#embed-language-selector)
  - [Static Strings (Headers/Footers)](#static-strings-headersfooters)
  - [Language Detection Modes](#language-detection-modes)
  - [Manual Translation Editing](#manual-translation-editing)
  - [Exclude Posts or Pages & Target Languages](#exclude-posts-or-pages--target-languages)
- [Admin & Workspace Panel](#admin--workspace-panel)
  - [General & API](#general--api)
  - [Languages](#languages)
  - [Credits & Permissions](#credits--permissions)
  - [Direct Translate & Queue Monitor](#direct-translate--queue-monitor)
  - [Static Strings](#static-strings)
  - [Tools & Database](#tools--database)
  - [Roles & My Limits](#roles--my-limits)
- [Supported Languages](#supported-languages)
- [Local AI Setup (Ollama / MLX-LM / Flask)](#local-ai-setup-ollama--mlx-lm--flask)
- [Google Cloud Setup](#google-cloud-setup)
- [Notes](#notes)
- [What's New](#whats-new)
- [Author](#author)
- [License](#license)
- [Disclaimer](#disclaimer)

---

## Features

- ⚡ **Zero On-Visit Overhead (Read-Only Frontend)** - Frontend page visits only read completed translations from the custom database cache. No live API calls or background jobs are triggered by site visitors or bots.
- 🧠 **Native Ollama & OpenAI / MLX-LM Ready** - Connects directly to **Ollama** (`/api/generate`) or any **OpenAI-compatible** endpoint (`/v1/chat/completions`) such as **Apple MLX-LM**, **LM Studio**, **vLLM**, and **OpenAI** (with Bearer authentication, `max_tokens`, and optional `chat_template_kwargs.enable_thinking = false` for local reasoning models).
- 💳 **Per-User Credits / Quota System** - Admin-only configurable credit limits (`1 credit = 1 target language translation for a post`) with automatic **Daily** or **Monthly** resets, global default limits, and per-user overrides (including `-1` for Unlimited).
- 👥 **Role-Based Access Control (RBAC)** - Supports Administrators, Editors, Moderators, Authors, and custom WordPress roles. Non-admin users can access *Direct Translate* and *Queue Monitor* scoped strictly to posts they can edit (`edit_post`).
- 🌐 **Author Language Restrictions** - Administrators can choose which enabled languages are selectable by writers in the Post Editor metabox and *Direct Translate*.
- 🚦 **Unified Translation Queue** - Background translations are queued and processed asynchronously via WP-Cron to protect your AI server's RAM and CPU.
- 🎛️ **Direct Translate Panel** - Manually push specific posts or pages to the translation queue for batch processing without relying on *Auto-Translate on Save*.
- ✍️ **Manual Visual Editor** - Fine-tune and correct AI translations directly from the WordPress post editor using a dedicated AJAX-powered TinyMCE visual interface (does not consume credits).
- 🎚️ **Concurrency Control** - Set `Max Concurrent Translations` to limit how many background jobs hit your AI server simultaneously.
- 🚀 **Auto-Translate on Save (Pre-warming)** - Optionally queues and translates posts in the background for selected target languages when published or updated.
- 🛡️ **Advanced HTML Chunking** - Safely translates long WordPress posts by splitting them into smart HTML/Gutenberg-aware chunks while preserving tags and shortcodes.
- 🧩 **Static Strings Manager** - Translate headers, footers, and widgets once, and display them dynamically using `[ft_string id="..."]` (accessible to Admins and, optionally, Editors/Moderators).
- 🔄 **Multiple Translation Services**:
  - **Ollama (Local AI Server)** - Direct integration with local LLMs.
  - **OpenAI Engine (OpenAI / MLX-LM / LM Studio / vLLM)** - Standard OpenAI Chat Completions API.
  - **LibreTranslate API** - Self-hosted or remote LibreTranslate-compatible endpoints.
  - **Google Cloud Translation API** - Official paid API.
  - **Google Hash (Free/On-the-fly)** - Client-side Google Translate widget.
- 🎯 **Language Detection Modes**: Automatic (Browser) or Manual (Admin default), plus optional SEO-friendly subdirectory permalinks (`/it/my-post/`).
- 📦 **Custom Database Cache** - Dedicated database table with configurable TTL (days) or `0` for permanent cache.
- 🛑 **Exclude specific posts** and define **Excluded words** that must never be translated.

---

## Requirements

- WordPress 5.8 or newer
- PHP 7.4+
- **One of the following**:
  - A local or remote **Ollama** server (e.g., `http://localhost:11434`).
  - **Apple MLX-LM** (`mlx_lm.server`), **LM Studio**, **vLLM**, or an **OpenAI** API key (`https://api.openai.com/v1/chat/completions`).
  - A local or remote instance of [LibreTranslate](https://github.com/uav4geo/LibreTranslate) or a Flask bridge (default: `http://localhost:5000/translate`).
  - Google Cloud Translation API key.
  - Internet connection (for client-side Google Hash mode).

---

## Installation

1. Clone or download this repository.
2. Upload the plugin to your WordPress `/wp-content/plugins/` directory.
3. Activate it from the WordPress **Plugins** admin screen.
4. Configure your preferred translation service in **WordPress Admin → FreedomTranslate**.

---

## Usage

### Embed Language Selector

Use the shortcode anywhere in your theme or posts:

```text
[freedomtranslate_selector]
```

This renders a dropdown with all site-enabled languages and a mode indicator icon:

* 🌐 = Automatic browser detection
* 📌 = Manual admin-defined default

### Static Strings (Headers/Footers)

To translate global theme elements that are not part of standard post content:

1. Go to **FreedomTranslate → Static Strings**.
2. Add a new string (e.g., ID: `footer_credits`, Text: `All rights reserved`).
3. The plugin queues translations for all enabled languages. Use the shortcode inside your theme/widget:
```text
[ft_string id="footer_credits"]
```

### Language Detection Modes

* **Automatic Mode** 🌐: Detected from the visitor's browser `Accept-Language` header. Visitors can switch languages anytime.
* **Manual Mode** 📌: Admin sets a default language when no cookie/query/pretty URL is present.

### Manual Translation Editing

If you need to correct or write a translation manually (without consuming credits):

1. Open the post or page in the WordPress editor.
2. Scroll down to the **FreedomTranslate - Manual Editor** metabox.
3. Select the target language from the dropdown to fetch the current translation from the cache.
4. Edit the Translated Title, Excerpt, or Content using the integrated visual editor (TinyMCE).
5. Click **Save Manual Translation**.

*Note: Manual edits are saved directly to the custom translation cache. Triggering a new background translation for that post and language via Direct Translate will overwrite manual edits.*

### Exclude Posts or Pages & Target Languages

In the sidebar **FreedomTranslate Options** metabox of any public post type, users with `edit_post` permission can:
* **Exclude from translation**: Prevent cached translations from being displayed on that post.
* **Show language selector**: Automatically prepend `[freedomtranslate_selector]` to the post content.
* **Target Languages (Auto-Translate)**: Select which admin-approved languages should be translated when *Auto-Translate on Save* is active, and view their remaining credit balance.

---

## Admin & Workspace Panel

Navigate to:

**WordPress Admin → FreedomTranslate**

Tabs are dynamically shown based on your WordPress role and permissions:

### General & API *(Administrator only)*

* **Select Engine**: Choose between Ollama (Local AI Server), OpenAI Engine (OpenAI / MLX-LM / LM Studio / vLLM), LibreTranslate API, Google Translate Official API, or Google Hash.
* **API & Model Configuration**:
  * **Ollama**: Configure server URL, model name, `num_ctx`, `keep_alive`, and `think: false`.
  * **OpenAI Engine**: Configure API URL (e.g., `http://localhost:11435/v1/chat/completions` or `https://api.openai.com/v1/chat/completions`), optional Bearer `API Secret Key`, `OpenAI / MLX Model`, `Max Tokens`, and `Disable AI Thinking (MLX / vLLM)` (`chat_template_kwargs.enable_thinking = false`, automatically omitted when calling `api.openai.com`).
* **Display Settings**: Toggle and customize the AI translation notice label shown on translated posts.
* **Cache & Background Performance**: Enable/disable *Auto-Translate on Save*, set global Cache TTL, `Max Concurrent Translations`, `HTML Chunk Size`, and `AI API Timeout`.
* **Content Protection**: Define excluded words/brand names.

### Languages *(Administrator only)*

* Drag and drop to set language translation priority.
* Enable/disable languages site-wide and independently control which languages are **Selectable in Editor / Direct Translate** by authors.
* Enable or disable SEO-friendly subdirectory permalinks (`/it/my-post/`).

### Credits & Permissions *(Administrator only)*

* Enable or disable the translation credits system (`1 credit = 1 target language translation for a post`).
* Set the automatic reset schedule (**Monthly** or **Daily**) and **Global Default Limit**.
* Select which WordPress roles (including custom roles such as `Moderator`) can access the FreedomTranslate workspace.
* Optionally allow Editors/Moderators (`edit_others_posts`) to manage **Static Strings**.
* Search users to set custom per-user credit overrides (`-1` for Unlimited, empty to inherit Global Default) or manually reset a user's current usage.

### Direct Translate & Queue Monitor *(Enabled Roles)*

* **Direct Translate**: Paste a post URL or ID, choose from admin-allowed languages, and push jobs directly to the queue (scoped to posts you can edit via `edit_post`, checking and deducting credits atomically).
* **Queue Monitor**: Monitor live chunk progress, pause, resume, or cancel jobs. Administrators see all jobs (plus the global Panic Button); non-admin users only see and control jobs for posts they are allowed to edit.

### Static Strings *(Administrators & optionally Editors/Moderators)*

* Manage and translate custom strings for Headers, Footers, and Widgets. Global repair and purge tools remain restricted to Administrators.

### Tools & Database *(Administrator only)*

* **Clear ALL Database Cache**: Flush all cached translations across the site.
* **Translated Posts Registry**: Inspect and selectively clear cached translations per post.

### Roles & My Limits *(All Enabled Roles)*

* View your current WordPress role(s), credit usage, remaining balance, next reset date, active capabilities, allowed target languages, and the full role permissions matrix configured by the Administrator.

---

## Supported Languages

| Code | Language | Code | Language |
| --- | --- | --- | --- |
| en | English | ar | Arabic |
| it | Italiano | az | Azerbaijani |
| es | Español | zh | Chinese |
| fr | Français | cs | Czech |
| de | Deutsch | da | Danish |
| ru | Русский | nl | Dutch |
| pt | Português | fi | Finnish |
| el | Greek | he | Hebrew |
| hi | Hindi | hu | Hungarian |
| id | Indonesian | ga | Irish |
| ja | Japanese | ko | Korean |
| no | Norwegian | pl | Polish |
| ro | Romanian | sk | Slovak |
| sv | Swedish | tr | Turkish |
| uk | Ukrainian | vi | Vietnamese |

You can limit both site-wide languages and author-selectable languages via the **Languages** tab.

---

## Local AI Setup (Ollama / MLX-LM / Flask)

You can connect FreedomTranslate **directly** to your AI server without needing any intermediate script:

* **Ollama**: Select `Ollama (Local AI Server)` in *General & API* and set the API Server URL to `http://localhost:11434`.
* **Apple MLX-LM / LM Studio / vLLM / OpenAI**: Select `OpenAI Engine (OpenAI / MLX-LM / LM Studio / vLLM)` in *General & API*, set the API Server URL to your `/v1/chat/completions` endpoint (e.g., `http://localhost:11435/v1/chat/completions` or `https://api.openai.com/v1/chat/completions`), enter your model name (e.g., `mlx-community/gemma-4-12B-it-4bit` or `gpt-4o-mini`), and optionally provide your `API Secret Key` (sent as `Authorization: Bearer ...`).

### 💡 Bonus: Optional Flask Bridge for Custom Setups

If you still prefer routing requests through a custom Python Flask bridge using the `LibreTranslate API` format, here is an example script:

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
MODEL_NAME = "gemma3:4b"  # Customize this with your preferred local model

# =============================================================
# ACTIVE JOBS REGISTRY (hashkey -> threading.Event)
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
    cleaned = re.sub(r'<thinking>.*?</thinking>', '', text, flags=re.DOTALL | re.IGNORECASE).strip()
    cleaned = re.sub(r'^(Text:\s*|Testo:\s*)', '', cleaned, flags=re.IGNORECASE).strip()
    cleaned = re.sub(r'^```[a-zA-Z]*\s*', '', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'\s*```$', '', cleaned)

    single_labels = [
        "city name", "country", "address", "street", "zip code",
        "postal code", "state", "province", "email", "phone number"
    ]

    if cleaned.lower() in single_labels:
        return cleaned

    pattern = r"\b(city name|country|address|street|zip code|postal code|state|province|email|phone number)\s*\([^)]*(optional|probably|translation|untranslated)[^)]*\)"
    cleaned = re.sub(pattern, "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\([^)]*(optional|probably|translation|untranslated)[^)]*\)", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"(City name|Country|Address|Street|Zip code|Postal code|State|Province|Email|Phone number)", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s{2,}", " ", cleaned).strip()

    return cleaned

# =============================================================
# OLLAMA DIRECT CALL
# =============================================================
def call_ollama(prompt, stop_event=None):
    if stop_event and stop_event.is_set():
        print("[OLLAMA] Job cancelled before initiating the call.")
        return None

    payload = {
        "model": MODEL_NAME,
        "prompt": prompt,
        "stream": False,
        "raw": False,
        "keep_alive": -1,
        "options": {
            "temperature": 0.1,
            "top_p": 0.9,
            "num_predict": 4096,
            "num_ctx": 8192
        }
    }

    try:
        response = requests.post(OLLAMA_URL, json=payload, timeout=900)
        response.raise_for_status()

        if stop_event and stop_event.is_set():
            print("[OLLAMA] Job cancelled after generation, result discarded.")
            return None

        return response.json().get('response', '').strip()
    except Exception as e:
        print(f"Ollama Connection Error: {e}")
        return None

# =============================================================
# TRANSLATION LOGIC & PROMPTING
# =============================================================
def translate_logic(text, source_lang, target_lang, is_html=False, stop_event=None):
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
    data = request.get_json() if request.is_json else request.form

    text = data.get('q', data.get('text', ''))
    source = data.get('source', 'auto')
    target = data.get('target', 'en')
    format_type = data.get('format', 'text')
    job_id = data.get('job_id', None)

    if not text:
        return jsonify({'error': 'Missing text parameter'}), 400

    is_html = (format_type == 'html') or ('<' in text and '>' in text)

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

    if stop_event and stop_event.is_set():
        return jsonify({'translatedText': None, 'cancelled': True}), 200

    return jsonify({'translatedText': translated})


@app.route('/cancel', methods=['POST'])
def cancel_route():
    data = request.get_json(silent=True) or {}

    if 'job_id' in data:
        job_id = data['job_id']
        found = cancel_job(job_id)
        print(f"[CANCEL] job_id={job_id} -> {'cancelled' if found else 'not found'}")
        return jsonify({'status': 'cancelled' if found else 'not_found', 'job_id': job_id})

    if 'job_ids' in data:
        results = {}
        for jid in data['job_ids']:
            found = cancel_job(jid)
            results[jid] = 'cancelled' if found else 'not_found'
            print(f"[CANCEL BULK] job_id={jid} -> {results[jid]}")
        return jsonify({'status': 'ok', 'results': results})

    return jsonify({'error': 'Missing job_id or job_ids parameter'}), 400


@app.route('/status', methods=['GET'])
def status_route():
    with _jobs_lock:
        active = {jid: ev.is_set() for jid, ev in _active_jobs.items()}
    return jsonify({'active_jobs': active, 'count': len(active)})


# =============================================================
# SERVER STARTUP
# =============================================================
if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, threaded=True)
```

**3. Run the bridge:**

```bash
python3 app.py
```

**4. Connect the Plugin:**
In your WordPress admin, go to **FreedomTranslate → General & API**. Select `LibreTranslate API` and set the API Server URL to `http://127.0.0.1:5000/translate`.

---

## Google Cloud Setup

To use the official Google Cloud Translation API (optional):

1. Create a project at [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the **Cloud Translation API** and set up billing.
3. Create an API key and enter it in **FreedomTranslate → General & API**.

---

## Notes

* **AI Server Load Balancing**: The Unified Queue ensures your server never crashes, even if you translate a massive post into multiple languages at once.
* **No On-Visit Bottlenecks**: Because frontend page visits only perform read-only lookups against cached translations, traffic spikes and crawlers never overload your translation server.
* Translations are stored in a dedicated custom database table (`{$wpdb->prefix}freedomtranslate_cache`) to keep `wp_options` clean and fast. Cache TTL can be set to `0` to make translations permanent.

---

## What's New

**v2.4.0**

* 🤖 **OpenAI Engine (OpenAI / MLX-LM / LM Studio / vLLM)**: Added native `/v1/chat/completions` support with Bearer auth, configurable model, dynamic `max_tokens`, and optional `chat_template_kwargs.enable_thinking = false` (automatically omitted for `api.openai.com`).
* 💳 **Credits & Quotas System**: Added admin-only configurable translation credits (`1 credit = 1 target language translation for a post`) with automatic Daily or Monthly resets and per-user overrides (`-1` for Unlimited).
* 👥 **Role-Based Access Control**: Moved to a dedicated top-level **FreedomTranslate** menu. Admins can enable specific WordPress roles (Editors, Moderators, Authors, custom roles) to use *Direct Translate* and *Queue Monitor* scoped to posts they can edit (`edit_post`), and optionally allow Editors/Moderators to manage *Static Strings*.
* 🌐 **Author Target Language Restrictions**: Admins can control which enabled languages authors/editors may select in the Post Editor metabox and *Direct Translate*.
* 📊 **New Tabs**: Added **Credits & Permissions** (Admin-only) and **Roles & My Limits** (clear quota and capability overview for every enabled user).
* ⚡ **Performance & Security Hardening**: Removed on-visit/synchronous translation, *Strict Manual Mode*, and bot signature filtering. Frontend requests now strictly serve pre-cached translations.

**v2.0.2**

* ✍️ **Manual Translation Editor**: Added a dedicated AJAX-powered visual editor (Metabox) to safely fine-tune and override AI translations per post without touching the core WordPress content.

**v1.9.3**

* ✨ **Unified Translation Queue**: Translations are queued and processed sequentially/concurrently based on your hardware limits.
* 🎛️ **Direct Translate Panel**: Manually push posts to the translation queue.
* 🚦 **Concurrency Control**: Added `Max Concurrent Translations` setting to throttle requests to local AI servers.
* 🧠 **Local AI & Ollama Optimization**: Enhanced HTML chunking support to work with local LLMs.

**v1.5.5**

* Tabbed Admin Panel, Auto-Prewarm on Save, Static Strings Manager (`[ft_string]`), and Infinite Cache (`TTL 0`).

**v1.4.5 & Below**

* Added Google Translate support and language detection modes (Auto/Manual).

---

## Author

**Freedom** 2026 – Licensed under [GPLv3 or later](https://www.gnu.org/licenses/gpl-3.0.html)

---

## License

This plugin is released under the GNU GPLv3 license. See [LICENSE](LICENSE) for full details.

---

## Disclaimer

This plugin is not affiliated with or endorsed by LibreTranslate, Google Translate, Ollama, OpenAI, Apple MLX, or their respective developers. "LibreTranslate", "Ollama", "OpenAI", "MLX-LM", and "Google Translate" are used solely to describe the APIs and engines that this plugin can interact with.
