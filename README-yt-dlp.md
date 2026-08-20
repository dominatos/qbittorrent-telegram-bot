# YouTube & Video Downloading with yt-dlp

The bot supports downloading videos and extracting audio from YouTube, Twitter, TikTok, and many other websites using `yt-dlp`. This guide covers how to set it up, common pitfalls, and how to bypass YouTube's recent anti-bot mechanisms.

## Prerequisites
To successfully download videos and extract audio, your system must have the following installed:
1. **yt-dlp** (The core downloader)
2. **ffmpeg** (Used by yt-dlp to extract and convert audio)
3. **NodeJS** (Crucial for bypassing YouTube's JavaScript challenges)

Install ffmpeg and NodeJS via apt:
```bash
sudo apt update
sudo apt install ffmpeg nodejs
```

## Installing yt-dlp (CRITICAL)
**Do NOT install yt-dlp using `apt`.** The version in the official repositories is often months or years out of date and will fail to download from YouTube. You must install the latest binary manually.

```bash
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
```

## Bot Configuration (`config.php`)
In your `config.php`, ensure the yt-dlp feature is enabled and pointing to the manual binary you just installed:

```php
    'ytdlp_enabled' => true,
    'ytdlp_binary' => '/usr/local/bin/yt-dlp', 
```

### The NodeJS Challenge Solver (Bypassing YouTube Blocks)
YouTube heavily restricts downloads. `yt-dlp` uses a JavaScript solver to bypass these restrictions. 

**Important Note on yt-dlp v2026.08+:**
Recent versions of `yt-dlp` changed their default behavior to only look for the `deno` runtime. Because the bot runs as a background `systemd` service, `yt-dlp` will completely ignore `nodejs` unless you explicitly tell it to use it. 

To ensure the bot can solve YouTube's challenges, you **must** add `--js-runtimes node` to your extra arguments:

```php
    'ytdlp_extra_args' => [
        '--js-runtimes',
        'node'
    ],
```

### Why you probably shouldn't use `--cookies`
You might be tempted to pass a `cookies.txt` file to `yt-dlp` to bypass age-restrictions. 
However, **using `--cookies` can actually break standard downloads!** 

When cookies are enabled, `yt-dlp` intentionally skips certain fallback clients (like the `android` client). If YouTube blocks the standard web player (e.g. forcing "SABR streaming" or PO Tokens), `yt-dlp` will fail with `Requested format is not available` because the fallback client was skipped. 

**Recommendation:** Do not use `--cookies` unless absolutely necessary for private/age-restricted videos. The native NodeJS challenge solver works flawlessly for standard public videos without requiring cookies.

## Managing the systemd PATH
If you ever encounter an error like `n challenge solving failed`, it means `yt-dlp` could not execute `node`. 

The bot's code (`qbot.php`) automatically injects the default system paths (`/usr/local/bin:/usr/bin`) into the background process environment to prevent this. However, if you installed NodeJS via a custom manager (like `nvm`) into a private home directory, the bot will not be able to find it. Always ensure `node` is available globally at `/usr/bin/node`.
