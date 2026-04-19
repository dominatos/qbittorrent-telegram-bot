#!/usr/bin/env php
<?php
//coded by Sviatoslav 
// https://github.com/dominatos/qbittorrent-telegram-bot
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

date_default_timezone_set('UTC');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/bot_errors.log');
ini_set('memory_limit', '256M');

interface LoggerInterface
{
    /**
     * Logs an error message.
     *
     * @param string $msg The error message to log.
     * @return void
     */
    public function error(string $msg): void;

    /**
     * Logs an informational message.
     *
     * @param string $msg The informational message to log.
     * @return void
     */
    public function info(string $msg): void;

    /**
     * Logs a warning message.
     *
     * @param string $msg The warning message to log.
     * @return void
     */
    public function warning(string $msg): void;
}

final class QBittorrentBot
{
    public const VERSION = '1.2.6';

    // =================== CONFIGURATION ===================
    private array $config;

    // =================== STATE ===================
    private array $apiBase;
    private array $pendingDownloads = [];
    private array $lastStatusMessageIds = [];
    private array $knownChatIds = [];
    private array $notifiedTorrentIds = [];
    private array $notifiedTorrHashes = [];
    private array $torrServerMsgIds = []; // hash => [['chat_id'=>int,'message_id'=>int], ...]
    private array $pendingDeletions = [];
    private array $ytdlpProcesses = []; // ['pid'=>int,'chat_id'=>int,'url'=>string,'dir'=>string,'log_file'=>string,'started'=>int]
    private ?string $qbCookie = null;
    private array $pendingTorrents = []; // ['hash' => ['attempts' => 0, 'first_seen' => timestamp]]

    private int $offset = 0;
    private int $lastCheck = 0;
    private int $lastTorrCheck = 0;
    private array $pendingLimits = [];
    private int $lastSave = 0;
    private int $torrServerFailCount = 0;
    private LoggerInterface $logger;

    /**
     * Initialize the bot: load configuration, prepare logging and API endpoints, restore state, and reattach background jobs.
     *
     * Loads and validates config.php (terminates the process with a brief message if the file is missing), builds Telegram API base URLs, ensures the configured log directory exists, instantiates a file-backed logger, restores persisted bot state, reattaches any tracked yt-dlp processes, and initializes the status timestamp.
     */
    public function __construct()
    {
        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            die("Error: config.php not found. Copy config.php.example to config.php and configure it.\n");
        }
        $this->config = require $configFile;

        // Basic validation
        $requiredKeys = ['bot_token', 'allowed_user_ids', 'disks', 'qb_url', 'categories']; // 'dirs' was renamed to 'categories' in example, need to match
        // Let's stick to 'categories' as used in example, but legacy code used DIRS. 
        // I should use 'categories' in config and code.

        $this->apiBase = [
            'api' => 'https://api.telegram.org/bot' . $this->config['bot_token'] . '/',
            'file' => 'https://api.telegram.org/file/bot' . $this->config['bot_token'] . '/'
        ];

        $logFile = $this->config['log_file'] ?? __DIR__ . '/data/bot.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $this->logger = new class ($logFile) implements LoggerInterface {
            private string $logFile;
            /**
             * Set the log file path used by the instance.
             *
             * @param string $logFile Path to the log file where messages will be written.
             */
            public function __construct(string $logFile)
            {
                $this->logFile = $logFile;
            }
            /**
             * Logs an error message to the configured log file and echoes it to stdout.
             *
             * @param string $msg The error message to record.
             * @return void
             */
            public function error(string $msg): void
            {
                @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: $msg\n", FILE_APPEND);
                echo "ERROR: $msg\n";
            }
            /**
             * Logs an informational message to the configured log file and to stdout, prefixed with a timestamp and "INFO:".
             *
             * @param string $msg The message to log.
             */
            public function info(string $msg): void
            {
                @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] INFO: $msg\n", FILE_APPEND);
                echo "INFO: $msg\n";
            }
            /**
             * Logs a warning message to the configured log file and to stdout, prefixed with a timestamp and "WARN:".
             *
             * @param string $msg The message to log.
             */
            public function warning(string $msg): void
            {
                @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] WARN: $msg\n", FILE_APPEND);
                echo "WARN: $msg\n";
            }
        };

        $this->loadState();
        $this->reattachYtdlpProcesses();
    }

    /**
     * Loads persisted bot state from the configured state file (or legacy file) and restores in-memory fields.
     *
     * Restores known chats, notified torrent IDs, notified TorrServer hashes, TorrServer message ID mappings,
     * last status message IDs (supports both single-ID and array formats), and validated yt-dlp process entries.
     * If the configured state file is missing the legacy location is checked; if the file cannot be read the method logs an error and returns without modifying state.
     *
     * Also merges any configured `allowed_user_ids` into the known chats list so those users receive notifications even if they have not interacted with the bot.
     */
    private function loadState(): void
    {
        $stateFile = $this->config['state_file'] ?? __DIR__ . '/data/bot_state.json';
        if (!file_exists($stateFile)) {
            // Check legacy location just in case
            $legacy = __DIR__ . '/bot_state.json';
            if (file_exists($legacy)) {
                $stateFile = $legacy;
            } else {
                return;
            }
        }
        $raw = @file_get_contents($stateFile);
        if ($raw === false) {
            $error = error_get_last();
            $this->logger->error("Failed to read state file: $stateFile. Error: " . ($error['message'] ?? 'Unknown'));
            return;
        }
        $state = json_decode($raw, true);
        if (is_array($state)) {
            $this->knownChatIds = $state['known_chats'] ?? [];
            $this->notifiedTorrentIds = $state['notified_torrents'] ?? [];
            $this->notifiedTorrHashes = $state['notified_torr_hashes'] ?? [];
            $this->torrServerMsgIds = $state['torrServerMsgIds'] ?? [];
            // Handle both old format (single ID) and new format (array of IDs)
            $statusIds = $state['last_status_ids'] ?? [];
            foreach ($statusIds as $chatId => $ids) {
                $this->lastStatusMessageIds[$chatId] = is_array($ids) ? $ids : [$ids];
            }

            // Restore yt-dlp processes (backward-compatible: key may be absent)
            $requiredYtdlpKeys = ['pid', 'chat_id', 'url', 'dir', 'log_file', 'started'];
            foreach ($state['ytdlp_processes'] ?? [] as $proc) {
                if (!is_array($proc)) {
                    continue;
                }
                // Validate all required fields are present
                $valid = true;
                foreach ($requiredYtdlpKeys as $rk) {
                    if (!array_key_exists($rk, $proc)) {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    $this->ytdlpProcesses[] = $proc;
                }
            }
        }

        // Feature: Automatically add allowed users to known chats so they receive
        // notifications even if they haven't messaged the bot yet (e.g. fresh state).
        if (!empty($this->config['allowed_user_ids'])) {
            foreach ($this->config['allowed_user_ids'] as $uid) {
                if (!in_array($uid, $this->knownChatIds)) {
                    $this->knownChatIds[] = $uid;
                }
            }
        }
    }

    /**
     * Save selected runtime state to the configured state file for later restoration.
     *
     * Writes a JSON snapshot containing known chat IDs, notified torrent IDs and hashes,
     * TorrServer notification message IDs, last status message IDs, tracked yt-dlp processes,
     * and a timestamp to the configured state file (defaults to __DIR__/data/bot_state.json).
     * Ensures the state directory exists and logs an error if the file write fails.
     */
    private function saveState(): void
    {
        $state = [
            'known_chats' => array_values(array_unique($this->knownChatIds)),
            'notified_torrents' => $this->notifiedTorrentIds,
            'notified_torr_hashes' => $this->notifiedTorrHashes,
            'torrServerMsgIds' => $this->torrServerMsgIds,
            'last_status_ids' => $this->lastStatusMessageIds,
            'ytdlp_processes' => $this->ytdlpProcesses,
            'timestamp' => time()
        ];
        $stateFile = $this->config['state_file'] ?? __DIR__ . '/data/bot_state.json';
        $stateDir = dirname($stateFile);
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0775, true);
        }
        if (file_put_contents($stateFile, json_encode($state)) === false) {
            $this->logger->error("saveState failed to write state to $stateFile");
        }
    }

    /**
     * Escape Telegram Markdown special characters in a text string.
     *
     * @param string $text The input text to escape.
     * @return string The text with Markdown-special characters escaped for safe Telegram markup.
     */

    private function escapeMarkdown(string $text): string
    {
        return str_replace(['_', '*', '[', '`'], ['\_', '\*', '\[', '\`'], $text);
    }

    /**
     * Calls the Telegram Bot API method and returns the method result on success.
     *
     * Executes an HTTP POST to the configured Telegram API base URL with the provided parameters.
     *
     * @param string $method Telegram API method name (e.g., "sendMessage").
     * @param array $params Parameters to include in the POST request.
     * @return mixed The `result` field from the Telegram response, or `null` if the request failed or the response's `ok` flag is false (an error is logged on failure).
     */
    private function tgApiRequest(string $method, array $params = []): mixed
    {
        $ch = curl_init($this->apiBase['api'] . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => $this->config['poll_timeout'] + 5
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $res, true);
        if (!$data || !isset($data['ok']) || !$data['ok']) {
            $this->logger->error("tgApiRequest failed for method $method. Response: " . (string) $res);
            return null;
        }
        return $data['result'];
    }

    /**
     * Send a text message to a Telegram chat.
     *
     * @param int $chatId The target chat identifier.
     * @param string $text The message text to send. May include markup understood by Telegram when `$parseMode` is set.
     * @param string|null $parseMode Optional Telegram parse mode (for example "MarkdownV2" or "HTML").
     * @param array|null $replyMarkup Optional reply markup (inline keyboard or other). This array will be JSON-encoded.
     * @return mixed The decoded Telegram API response on success, or `null` on failure.
     */
    private function tgSendMessage(int $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): mixed
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($parseMode)
            $params['parse_mode'] = $parseMode;
        if ($replyMarkup)
            $params['reply_markup'] = json_encode($replyMarkup);
        return $this->tgApiRequest('sendMessage', $params);
    }

    /**
     * Send a photo message to a Telegram chat.
     *
     * @param int $chatId The target chat identifier.
     * @param string $photo The photo to send (Telegram file_id, HTTP URL, or multipart upload reference).
     * @param string $caption The caption to include with the photo.
     * @param string|null $parseMode Optional parse mode for the caption (e.g., "MarkdownV2" or "HTML").
     * @param array|null $replyMarkup Optional reply markup array; it will be JSON-encoded for the API.
     * @return mixed The decoded Telegram API response on success, or `null` on failure.
     */
    private function tgSendPhoto(int $chatId, string $photo, string $caption, ?string $parseMode = null, ?array $replyMarkup = null): mixed
    {
        $params = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption];
        if ($parseMode)
            $params['parse_mode'] = $parseMode;
        if ($replyMarkup)
            $params['reply_markup'] = json_encode($replyMarkup);
        return $this->tgApiRequest('sendPhoto', $params);
    }

    /**
     * Builds an inline Telegram keyboard with category buttons and disk selection buttons.
     *
     * The keyboard contains one row per configured category (each button callback: `dl:{categoryKey}`)
     * and a final row with one button per configured disk. Disk buttons indicate the currently selected
     * disk with a checkmark and append available free space in GB when determinable; disk callbacks use
     * `set_disk:{index}`.
     *
     * @param int $currentDiskIdx Index of the currently selected disk (0-based).
     * @return array The Telegram inline keyboard payload: `['inline_keyboard' => [...]]`.
     */
    private function tgCategoryKeyboard(int $currentDiskIdx): array
    {
        $buttons = [];
        foreach ($this->config['categories'] as $key => $label) {
            $buttons[] = [['text' => $label, 'callback_data' => "dl:$key"]];
        }
        $diskRow = [];
        foreach ($this->config['disks'] as $idx => $path) {
            $freeInfo = "";
            if (is_dir($path)) {
                $freeSpace = @disk_free_space($path);
                if ($freeSpace !== false) {
                    $freeGb = round($freeSpace / 1024 / 1024 / 1024, 1);
                    $freeInfo = " [{$freeGb}GB]";
                }
            }
            $label = (($idx === $currentDiskIdx) ? "✅ Disk " . ($idx + 1) : "💾 D" . ($idx + 1)) . $freeInfo;
            $diskRow[] = ['text' => $label, 'callback_data' => "set_disk:$idx"];
        }
        $buttons[] = $diskRow;
        return ['inline_keyboard' => $buttons];
    }

    /**
     * Attempt to log in to the configured qBittorrent server and store the session SID cookie.
     *
     * On success the extracted `SID` cookie is saved to `$this->qbCookie` for subsequent API requests.
     *
     * @return bool `true` if login succeeded and the SID cookie was stored, `false` otherwise.
     */

    private function qbLogin(): bool
    {
        $this->logger->info("Attempting qBittorrent login at " . $this->config['qb_url']);
        $ch = curl_init($this->config['qb_url'] . '/api/v2/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => $this->config['qb_user'], 'password' => $this->config['qb_pass']]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($resp === false) {
            $this->logger->error("qbLogin curl failed: $err");
            curl_close($ch);
            return false;
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr((string) $resp, 0, $headerSize);
        curl_close($ch);

        if (preg_match('/set-cookie:\s*(SID=[^;\s]+)/i', $header, $matches)) {
            $this->qbCookie = $matches[1];
            $this->logger->info("qBittorrent login successful. Cookie set.");
            return true;
        }

        $this->logger->error("qBittorrent login failed. HTTP Code: $code. Response: " . substr((string) $resp, $headerSize));
        return false;
    }

    /**
     * Send an authenticated request to the qBittorrent WebAPI and return its response.
     *
     * Ensures a valid qBittorrent session cookie (attempting login if missing), retries once on HTTP 403 by re-establishing the session, and treats only HTTP 200/201 responses as successful.
     *
     * @param string $endpoint API endpoint path appended to the configured qBittorrent base URL.
     * @param array $params Query parameters for GET or body parameters for POST.
     * @param bool $isPost If true, use POST; otherwise use GET.
     * @param bool $isFile When true and using POST, send $params as multipart/form-data (for file uploads); otherwise encode as application/x-www-form-urlencoded.
     * @param bool $hasRetried Internal flag to prevent repeated re-login attempts when a 403 has already been retried.
     * @return mixed Decoded JSON as an associative array when JSON decodes to a truthy value, the raw response string when HTTP 200/201 but JSON decode is falsy, or `null` on HTTP errors, cURL failures, or non-200/201 responses.
     */
    private function qbRequest(string $endpoint, array $params = [], bool $isPost = false, bool $isFile = false, bool $hasRetried = false)
    {
        if (!$this->qbCookie && !$this->qbLogin()) {
            $this->logger->error("qbRequest failed: No login cookie.");
            return null;
        }
        $url = $this->config['qb_url'] . $endpoint;
        $this->logger->info("qbRequest: $endpoint " . ($isPost ? "POST" : "GET") . " params: " . json_encode($params));

        $ch = curl_init($url . (!$isPost ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->qbCookie,
            CURLOPT_TIMEOUT => 15
        ]);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($isFile) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code === 403) {
            if (!$hasRetried) {
                $this->logger->info("qbRequest got 403, re-logging in...");
                $this->qbCookie = null;
                return $this->qbRequest($endpoint, $params, $isPost, $isFile, true);
            }
            $this->logger->error("qbRequest got 403 again after re-login. Aborting.");
            return null;
        }

        if ($res === false) {
            $this->logger->error("qbRequest curl failed: $err");
            return null;
        }

        $this->logger->info("qbRequest $endpoint returned code $code");
        return in_array($code, [200, 201]) ? (json_decode((string) $res, true) ?: $res) : null;
    }

    /**
     * Handle a single Telegram update: route callbacks, commands, magnets, yt-dlp URLs, or media.
     *
     * Processes the provided Telegram update array by:
     * - delegating callback_query updates to handleCallback;
     * - ignoring non-message updates or messages from users not in configured allowed_user_ids;
     * - registering new chats into known chat list and persisting state;
     * - handling the /status command by removing the trigger message and sending a status summary;
     * - detecting magnet links and creating a pending magnet download session (includes type, magnet, disk_idx, user_id, expires) then prompting for destination;
     * - detecting allowed yt-dlp URLs (when enabled) and creating a pending ytdlp session (includes type, url, disk_idx, user_id, expires) then prompting for destination;
     * - forwarding other messages to processMedia for document/video/photo handling.
     *
     * @param array $u Telegram update object as received from getUpdates.
     */

    public function handleUpdate(array $u): void
    {
        if (isset($u['callback_query'])) {
            $this->handleCallback($u['callback_query']);
            return;
        }
        $m = $u['message'] ?? null;
        if (!$m)
            return;
        $chatId = $m['chat']['id'];
        if (!in_array($m['from']['id'], $this->config['allowed_user_ids']))
            return;
        if (!in_array($chatId, $this->knownChatIds)) {
            $this->knownChatIds[] = $chatId;
            $this->saveState();
        }

        $text = $m['text'] ?? '';
        if ($text === '/status') {
            $this->logger->info("Status command received from chat $chatId");
            $this->tgApiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $m['message_id']]);
            $this->sendTorrentStatusToChat($chatId, true);
            return;
        }

        if (stripos($text, 'magnet:?') === 0) {
            $this->pendingDownloads[$chatId] = ['type' => 'magnet', 'magnet' => $text, 'disk_idx' => $this->config['default_disk_idx'], 'user_id' => $m['from']['id'], 'expires' => time() + 600];
            $this->tgSendMessage($chatId, "🔗 Magnet detected. Choose destination:", 'Markdown', $this->tgCategoryKeyboard($this->config['default_disk_idx']));
            return;
        }

        if (($this->config['ytdlp_enabled'] ?? false) && $this->isYtdlpUrl($text)) {
            $this->pendingDownloads[$chatId] = ['type' => 'ytdlp', 'url' => $text, 'disk_idx' => $this->config['default_disk_idx'], 'user_id' => $m['from']['id'], 'expires' => time() + 600];
            $this->tgSendMessage($chatId, "🎬 Video URL detected. Choose destination:", 'Markdown', $this->tgCategoryKeyboard($this->config['default_disk_idx']));
            return;
        }

        $this->processMedia($m, $chatId);
    }

    /**
     * Processes an incoming Telegram media message and initiates a pending download session.
     *
     * Extracts a file identifier and metadata from a `document`, `video`, or `photo` entry in the provided Telegram
     * message payload. If the file exceeds 20 MB, sends a Telegram warning and aborts. Otherwise registers a
     * pending download entry for the chat containing `type`, `file_id`, `name`, selected `disk_idx`, `user_id`, and
     * an expiration timestamp, then sends a destination selection message with the category/disk keyboard.
     *
     * @param array $m The Telegram message payload containing `document`, `video`, or `photo` fields and a `from.id`.
     * @param int $chatId The Telegram chat identifier where responses and destination selection should be sent.
     */
    private function processMedia(array $m, int $chatId): void
    {
        $fileId = null;
        $name = 'file';
        $type = '';
        $size = 0;
        if (isset($m['document'])) {
            $fileId = $m['document']['file_id'];
            $name = $m['document']['file_name'] ?? 'file';
            $type = 'file';
            $size = $m['document']['file_size'];
        } elseif (isset($m['video'])) {
            $fileId = $m['video']['file_id'];
            $name = $m['video']['file_name'] ?? 'video.mp4';
            $type = 'video';
            $size = $m['video']['file_size'];
        } elseif (isset($m['photo'])) {
            $p = end($m['photo']);
            $fileId = $p['file_id'];
            $name = "photo_" . time() . ".jpg";
            $type = 'photo';
            $size = $p['file_size'];
        }

        if ($fileId) {
            if ($size > 20 * 1024 * 1024) {
                $this->tgSendMessage($chatId, "⚠️ *File too large* (" . round($size / 1024 / 1024, 1) . "MB).\nBot API limit is *20MB*.", 'Markdown');
                return;
            }
            $this->pendingDownloads[$chatId] = ['type' => $type, 'file_id' => $fileId, 'name' => $name, 'disk_idx' => $this->config['default_disk_idx'], 'user_id' => $m['from']['id'], 'expires' => time() + 600];
            $this->tgSendMessage($chatId, "📥 Received: `{$name}`\nChoose destination:", 'Markdown', $this->tgCategoryKeyboard($this->config['default_disk_idx']));
        }
    }

    /**
     * Handle an incoming Telegram callback query, authorize the caller, and dispatch actions for disk selection, download confirmation, or TorrServer interactions.
     *
     * Processes the callback payload to:
     * - enforce authorization (allowed users or owner of a pending download),
     * - validate session freshness for TorrServer callbacks,
     * - update pending download disk selection and edit the original message (uses editMessageCaption for photos),
     * - finalize downloads for selected category paths,
     * - handle TorrServer download requests (creating a pending magnet) and ignore actions, including deleting other notifications.
     *
     * @param array $cb The callback query object from Telegram. Expected keys used:
     *                   - 'id' (string) callback query id for answerCallbackQuery
     *                   - 'data' (string) callback data (prefixes: 'set_disk:', 'dl:', 'ts_dl:', 'ts_ignore:')
     *                   - 'from' => ['id' => int] the user id invoking the callback
     *                   - 'message' => [
     *                       'chat' => ['id' => int],
     *                       'message_id' => int,
     *                       optionally 'photo' when the original message is a photo,
     *                       ...other Telegram message fields required by downstream handlers
     *                     ]
     */
    private function handleCallback(array $cb): void
    {
        $chatId = $cb['message']['chat']['id'];
        $data = $cb['data'];
        $this->logger->info("Received callback data: $data from chat: $chatId");

        $callerId = $cb['from']['id'];
        $isPendingOwner = isset($this->pendingDownloads[$chatId]) && ($this->pendingDownloads[$chatId]['user_id'] ?? null) === $callerId;
        $isWhitelisted = in_array($callerId, $this->config['allowed_user_ids'] ?? []);

        if (!$isWhitelisted && !$isPendingOwner) {
            $this->logger->warning("Unauthorized callback ($data) attempt from user $callerId in chat $chatId.");
            $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }

        // Verify authorization for dl / set_disk
        if (str_starts_with($data, 'set_disk:') || str_starts_with($data, 'dl:')) {
            if (!isset($this->pendingDownloads[$chatId])) {
                $this->logger->warning("Callback ($data) rejected: no pending download for chat $chatId.");
                $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "No pending download or session expired.", 'show_alert' => true]);
                return;
            }
            if (($this->pendingDownloads[$chatId]['user_id'] ?? $callerId) !== $callerId) {
                $this->logger->warning("Callback ($data) rejected: user $callerId attempted to interact with a download owned by another user in chat $chatId.");
                $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Not authorized. This isn't your download.", 'show_alert' => true]);
                return;
            }

            // Verify disk_idx validity
            if (str_starts_with($data, 'set_disk:')) {
                $idx = substr($data, 9);
                if (!is_numeric($idx) || !isset($this->config['disks'][(int) $idx])) {
                    $this->logger->warning("Callback ($data) rejected: invalid disk_idx '$idx'.");
                    $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Invalid disk selected.", 'show_alert' => true]);
                    return;
                }
            } elseif (str_starts_with($data, 'dl:')) {
                $idx = $this->pendingDownloads[$chatId]['disk_idx'] ?? 0;
                if (!is_numeric($idx) || !isset($this->config['disks'][(int) $idx])) {
                    $this->logger->warning("Callback ($data) rejected: invalid disk_idx '$idx' configured.");
                    $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Invalid disk configured for download.", 'show_alert' => true]);
                    return;
                }
            }
        }

        // Validate ts_dl and ts_ignore freshness
        if (str_starts_with($data, 'ts_dl:') || str_starts_with($data, 'ts_ignore:')) {
            $hash = str_starts_with($data, 'ts_dl:') ? substr($data, 6) : substr($data, 10);
            if (!isset($this->torrServerMsgIds[$hash])) {
                if (in_array($hash, $this->notifiedTorrHashes)) {
                    $this->logger->warning("Callback ($data) rejected for user $callerId: TorrServer item already processed.");
                    $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "This TorrServer item was already processed.", 'show_alert' => true]);
                } else {
                    $this->logger->warning("Callback ($data) rejected for user $callerId: Unrecognized TorrServer item.");
                    $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Unrecognized TorrServer item or session expired.", 'show_alert' => true]);
                }
                return;
            }
        }

        $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

        if (str_starts_with($data, 'set_disk:')) {
            $idx = (int) substr($data, 9);
            $this->pendingDownloads[$chatId]['disk_idx'] = $idx;

            // If the original message is a photo, we can only edit its caption and markup using editMessageCaption
            if (isset($cb['message']['photo'])) {
                $this->tgApiRequest('editMessageCaption', [
                    'chat_id' => $chatId,
                    'message_id' => $cb['message']['message_id'],
                    'caption' => "💿 Disk: " . $this->config['disks'][$idx] . "\nChoose category:",
                    'reply_markup' => json_encode($this->tgCategoryKeyboard($idx))
                ]);
            } else {
                $this->tgApiRequest('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $cb['message']['message_id'],
                    'text' => "💿 Disk: " . $this->config['disks'][$idx] . "\nChoose category:",
                    'reply_markup' => json_encode($this->tgCategoryKeyboard($idx))
                ]);
            }
            $this->logger->info("set_disk updated message.");
        } elseif (str_starts_with($data, 'dl:')) {
            $sub = substr($data, 3);
            $sub = str_replace(['..', '/', '\\'], '', $sub);
            $diskPath = rtrim($this->config['disks'][$this->pendingDownloads[$chatId]['disk_idx'] ?? 0], '/');
            $path = $diskPath . '/' . $sub;
            $this->tgApiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $cb['message']['message_id']]);
            $this->finalizeDownload($chatId, $path);
        } elseif (str_starts_with($data, 'ts_dl:')) {
            $hash = substr($data, 6);
            $this->logger->info("ts_dl requested for hash: $hash");
            $this->handleTorrServerDownload($chatId, $hash, $cb['message'], $cb['message']['message_id'], $cb['from']['id']);
            $this->deleteOtherTorrServerMessages($hash, $chatId);
        } elseif (str_starts_with($data, 'ts_ignore:')) {
            $hash = substr($data, 10);
            if (!in_array($hash, $this->notifiedTorrHashes)) {
                $this->notifiedTorrHashes[] = $hash;
                $this->saveState();
            }
            $this->tgApiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $cb['message']['message_id']]);
            $this->deleteOtherTorrServerMessages($hash, $chatId);
        }
    }

    /**
     * Creates a pending magnet download from a TorrServer torrent and prompts the chat to choose a destination.
     *
     * If the TorrServer is unreachable or the torrent/hash is not valid or missing, sends an error message to the chat and returns. On success this method
     * derives or uses an existing magnet URI, stores a `pendingDownloads` entry for the chat (type `magnet`, source `torrserver`, default disk and expiry),
     * edits the original Telegram message to present the destination/category keyboard (uses editMessageCaption for photo messages), and records the torrent
     * hash in persisted `notifiedTorrHashes`.
     *
     * @param int   $chatId    Telegram chat identifier where the action is anchored.
     * @param string $hash     TorrServer torrent hash to locate.
     * @param array $message   The original Telegram message object that triggered the action.
     * @param int   $messageId Telegram message id to edit with the destination prompt.
     * @param int   $userId    Telegram user id of the requester; stored as the pending download owner.
     */
    private function handleTorrServerDownload(int $chatId, string $hash, array $message, int $messageId, int $userId): void
    {
        $this->logger->info("handleTorrServerDownload started for $hash");
        $res = $this->torrServerRequest('/torrents', ['action' => 'list']);
        if (!is_array($res)) {
            $this->logger->error("Could not reach TorrServer during handleTorrServerDownload");
            $this->tgSendMessage($chatId, "❌ Could not reach TorrServer.");
            return;
        }

        $target = null;
        foreach ($res as $t) {
            if ($t['hash'] === $hash) {
                $target = $t;
                break;
            }
        }

        if (!$target) {
            $this->logger->error("Torrent $hash not found in TorrServer list");
            $this->tgSendMessage($chatId, "❌ Torrent not found in TorrServer.");
            return;
        }

        $magnet = "";
        if (!empty($target['magnet'])) {
            $magnet = $target['magnet'];
        } else {
            $hash = preg_replace('/[^a-fA-F0-9]/', '', $target['hash']);
            if (strlen($hash) !== 40 && strlen($hash) !== 32) {
                $this->logger->error("Invalid torrent hash: {$target['hash']}");
                $this->tgSendMessage($chatId, "❌ Invalid torrent hash format.");
                return;
            }
            $title = urlencode($target['title'] ?? 'Unknown');
            $magnet = "magnet:?xt=urn:btih:{$hash}&dn={$title}";
        }
        $this->logger->info("Generated/Found magnet: $magnet");

        $this->pendingDownloads[$chatId] = [
            'type' => 'magnet',
            'magnet' => $magnet,
            'disk_idx' => $this->config['default_disk_idx'],
            'source' => 'torrserver',
            'user_id' => $userId,
            'expires' => time() + 600
        ];

        // If the original message was a photo, we must use editMessageCaption.
        // Or we can delete the photo message and send a text one.
        if (isset($message['photo'])) {
            $this->tgApiRequest('editMessageCaption', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'caption' => "📥 From TorrServer: `{$target['title']}`\nChoose destination:",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($this->tgCategoryKeyboard($this->config['default_disk_idx']))
            ]);
        } else {
            $this->tgApiRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "📥 From TorrServer: `{$target['title']}`\nChoose destination:",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($this->tgCategoryKeyboard($this->config['default_disk_idx']))
            ]);
        }
        $this->logger->info("Edited message to show destination keyboard.");

        if (!in_array($hash, $this->notifiedTorrHashes)) {
            $this->notifiedTorrHashes[] = $hash;
            $this->saveState();
        }
    }

    /**
     * Finalizes a pending download for a chat by performing the selected action and notifying the chat.
     *
     * Performs one of three actions based on the pending request: add a magnet to qBittorrent, start a yt-dlp job,
     * or retrieve a Telegram file/media and place it in the specified destination directory. Sends a status message
     * to the chat and, on success, schedules that notification for later deletion according to configured cleanup time.
     *
     * @param int $chatId Telegram chat identifier that owns the pending download.
     * @param string $dir Destination directory where the downloaded content should be saved.
     */
    private function finalizeDownload(int $chatId, string $dir): void
    {
        $p = $this->pendingDownloads[$chatId] ?? null;
        if (!$p)
            return;
        unset($this->pendingDownloads[$chatId]);

        if (!is_dir($dir))
            @mkdir($dir, 0775, true);

        $msgText = "";
        $success = false;
        if ($p['type'] === 'magnet') {
            $payload = ['urls' => $p['magnet'], 'savepath' => $dir];

            // Figure out speed limit
            $limitBytesPerSec = 0;
            $limitMbit = 0;
            if (($p['source'] ?? '') === 'torrserver') {
                $limitMbit = (int) ($this->config['torrserver_dl_limit_mbit'] ?? 100);
                if ($limitMbit > 0) {
                    $limitBytesPerSec = (int) ($limitMbit * 1024 * 1024 / 8);
                }
            }

            $res = $this->qbRequest('/api/v2/torrents/add', $payload, true);
            $this->logger->info("qbRequest torrents/add returned: " . json_encode($res));
            if ($res !== null) {
                $limitInfo = "";

                // qBittorrent often ignores dlLimit when adding magnets. Enforce it directly (async):
                if ($limitBytesPerSec > 0 && preg_match('/urn:btih:([a-zA-Z0-9]+)/i', $p['magnet'], $m)) {
                    $hash = strtolower($m[1]);
                    $this->pendingLimits[$hash] = [
                        'limit' => $limitBytesPerSec,
                        'mbit' => $limitMbit,
                        'attempts' => 0
                    ];
                    $limitInfo = "\nSpeed Limit: {$limitMbit} Mbit/s (Pending)";
                }

                $msgText = "✅ Magnet added to qBit.\nDir: `{$dir}`$limitInfo";
                $success = true;
            } else {
                $msgText = "❌ Failed to add magnet to qBit. Check bot.log for details.";
            }
        } elseif ($p['type'] === 'ytdlp') {
            $result = $this->startYtdlpDownload($p['url'], $dir, $chatId);
            if ($result) {
                $msgText = "⬇️ yt-dlp download started.\nURL: `" . $p['url'] . "`\nDir: `{$dir}`";
                $success = true;
            } else {
                $msgText = "❌ Failed to start yt-dlp download. Check bot.log for details.";
            }
        } else {
            $fileInfo = $this->tgApiRequest('getFile', ['file_id' => $p['file_id']]);
            if (!$fileInfo) {
                $this->tgSendMessage($chatId, "❌ Could not get file from Telegram (Check 20MB limit).");
                return;
            }

            $this->tgSendMessage($chatId, "📥 Downloading file...", 'Markdown');

            $local = __DIR__ . '/' . preg_replace('/[^a-zA-Z0-9\._\-]/', '_', $p['name']);
            $fp = fopen($local, 'w+');
            if ($fp === false) {
                $this->logger->error("Failed to open file for writing: $local");
                $this->tgSendMessage($chatId, "❌ Failed to create local file.");
                return;
            }
            $ch = curl_init($this->apiBase['file'] . $fileInfo['file_path']);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);

            if ($p['type'] === 'file') {
                $res = $this->qbRequest('/api/v2/torrents/add', ['torrents' => new CURLFile($local), 'savepath' => $dir], true, true);
                if ($res !== null) {
                    $msgText = "✅ Torrent added.\nDir: `{$dir}`";
                    $success = true;
                } else {
                    $msgText = "❌ Failed to add torrent to qBit.";
                }
                @unlink($local);
            } else {
                if (@rename($local, $dir . '/' . basename($local))) {
                    $msgText = "✅ Media saved to `{$dir}`";
                    $success = true;
                } else {
                    $msgText = "❌ Failed to move media file.";
                }
            }
        }

        $res = $this->tgSendMessage($chatId, $msgText, 'Markdown');
        if ($success && $res && isset($res['message_id'])) {
            $this->pendingDeletions[] = ['chat_id' => $chatId, 'message_id' => $res['message_id'], 'expires' => time() + $this->config['notification_cleanup_time']];
        }
    }

    /**
     * Determines whether a given text is an allowed yt-dlp URL based on configured domains.
     *
     * Trims the input, requires an http(s) URL with a valid host, and checks the host against
     * the configured `ytdlp_domains` (defaults to `youtube.com` and `youtu.be`), allowing exact
     * matches or subdomains.
     *
     * @return bool `true` if the text is an allowed yt-dlp URL, `false` otherwise.
     */
    private function isYtdlpUrl(string $text): bool
    {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }
        // Must look like a URL
        if (!preg_match('#^https?://#i', $text)) {
            return false;
        }
        $host = parse_url($text, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);
        $domains = $this->config['ytdlp_domains'] ?? ['youtube.com', 'youtu.be'];
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            // Exact match or subdomain match (e.g. www.youtube.com matches youtube.com)
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Starts a background yt-dlp job to download the given URL into the specified directory and tracks the job for later completion handling.
     *
     * Creates a per-job log file, launches yt-dlp as a background process, records the process metadata into bot state and persists it so completion can be monitored.
     *
     * @param string $url The yt-dlp-allowed URL to download.
     * @param string $dir Destination directory where yt-dlp will write files.
     * @param int $chatId Telegram chat ID to attribute the job to for notifications.
     * @return bool `true` if the yt-dlp process was started and tracked successfully, `false` otherwise.
     */
    private function startYtdlpDownload(string $url, string $dir, int $chatId): bool
    {
        $binary = $this->config['ytdlp_binary'] ?? 'yt-dlp';
        if (strpos($binary, DIRECTORY_SEPARATOR) === false) {
            $resolved = shell_exec("command -v " . escapeshellarg($binary));
            if (!empty($resolved)) {
                $binary = trim($resolved);
            }
        }

        if (!file_exists($binary) || !is_executable($binary)) {
            $this->logger->error("yt-dlp binary not found or not executable: $binary");
            return false;
        }

        // Defense-in-depth: re-validate URL
        if (!$this->isYtdlpUrl($url)) {
            $this->logger->error("startYtdlpDownload called with non-allowed URL: $url");
            return false;
        }

        $format = $this->config['ytdlp_format'] ?? 'bestvideo[height<=1080]+bestaudio/best[height<=1080]';
        $extraArgs = $this->config['ytdlp_extra_args'] ?? [];

        // Build command
        $cmd = escapeshellarg($binary)
            . ' -f ' . escapeshellarg($format)
            . ' -o ' . escapeshellarg($dir . '/%(title)s.%(ext)s')
            . ' --no-playlist'
            . ' --newline'
            . ' --restrict-filenames';

        foreach ($extraArgs as $arg) {
            if (is_scalar($arg)) {
                $cmd .= ' ' . escapeshellarg((string) $arg);
            } else {
                $this->logger->warning("Invalid ytdlp_extra_args entry skipped.");
            }
        }
        $cmd .= ' ' . escapeshellarg($url);

        // Log file for this download
        $logDir = dirname($this->config['log_file'] ?? __DIR__ . '/data/bot.log');
        $logFile = $logDir . '/ytdlp_' . time() . '_' . mt_rand(1000, 9999) . '.log';

        // Fire background process
        $fullCmd = $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $this->logger->info("Starting yt-dlp: $cmd");

        $pid = trim((string) shell_exec($fullCmd));
        if (empty($pid) || !is_numeric($pid)) {
            $this->logger->error("Failed to start yt-dlp process. shell_exec returned: $pid");
            return false;
        }

        $pid = (int) $pid;
        $this->logger->info("yt-dlp started with PID $pid, log: $logFile");

        // Track for completion polling
        $this->ytdlpProcesses[] = [
            'pid' => $pid,
            'chat_id' => $chatId,
            'url' => $url,
            'dir' => $dir,
            'log_file' => $logFile,
            'started' => time()
        ];

        $this->saveState(); // Persist immediately

        return true;
    }

    /**
     * Monitors tracked yt-dlp background jobs, finalizes completed jobs, and removes finished entries from tracking.
     *
     * For each tracked process that is no longer running, sends a Telegram message to the originating chat indicating
     * success or failure (including the last `ERROR:` line when present), logs the outcome, deletes the job's log file,
     * removes the job from internal tracking, triggers a Jellyfin library refresh on successful completion, and schedules
     * the success message for later deletion. If any jobs were pruned, compacts the tracking array and persists state.
     */
    private function checkYtdlpProcesses(): void
    {
        if (empty($this->ytdlpProcesses)) {
            return;
        }
        $initialCount = count($this->ytdlpProcesses);
        foreach ($this->ytdlpProcesses as $k => $proc) {
            // Check if process is still running via /proc/{pid} and verify identity to prevent PID recycle matches
            $isRunning = false;
            if (file_exists("/proc/{$proc['pid']}")) {
                $cmdline = (string)@file_get_contents("/proc/{$proc['pid']}/cmdline");
                if ($cmdline === '' || stripos($cmdline, $proc['url']) !== false || stripos($cmdline, 'yt-dlp') !== false) {
                    $isRunning = true;
                }
            }
            if ($isRunning) {
                continue; // Still running
            }

            $this->logger->info("yt-dlp process PID {$proc['pid']} finished.");

            // Read the last lines of the log to determine success/failure
            $logContent = '';
            if (file_exists($proc['log_file'])) {
                $logContent = (string) file_get_contents($proc['log_file']);
            }

            // yt-dlp prints "ERROR:" on failure. If no ERROR lines and log is non-empty, assume success.
            $hasError = stripos($logContent, 'ERROR:') !== false;
            $isEmpty = trim($logContent) === '';

            if ($hasError || $isEmpty) {
                $errorDetail = '';
                if ($hasError) {
                    // Extract last ERROR line
                    $lines = explode("\n", trim($logContent));
                    foreach (array_reverse($lines) as $line) {
                        if (stripos($line, 'ERROR:') !== false) {
                            $errorDetail = "\n" . trim($line);
                            break;
                        }
                    }
                }
                $this->tgSendMessage(
                    $proc['chat_id'],
                    "❌ yt-dlp download failed.\nURL: `{$proc['url']}`$errorDetail",
                    'Markdown'
                );
                $this->logger->error("yt-dlp PID {$proc['pid']} failed. Log: {$proc['log_file']}");
            } else {
                $res = $this->tgSendMessage(
                    $proc['chat_id'],
                    "✅ yt-dlp download finished.\nURL: `{$proc['url']}`\nDir: `{$proc['dir']}`",
                    'Markdown'
                );
                if ($res && isset($res['message_id'])) {
                    $this->pendingDeletions[] = ['chat_id' => $proc['chat_id'], 'message_id' => $res['message_id'], 'expires' => time() + $this->config['notification_cleanup_time']];
                }
                $this->logger->info("yt-dlp PID {$proc['pid']} completed successfully.");
                $this->jellyfinRefreshLibrary();
            }

            // Clean up log file
            @unlink($proc['log_file']);
            unset($this->ytdlpProcesses[$k]);
        }
        if (count($this->ytdlpProcesses) !== $initialCount) {
            $this->ytdlpProcesses = array_values($this->ytdlpProcesses);
            $this->saveState(); // Flush pruned jobs dynamically to prevent polling ghosts.
        }
    }

    /**
     * Reattaches and reconciles previously tracked yt-dlp background jobs from persisted state.
     *
     * For each tracked process: if the PID is still running, keeps it under monitoring; if the PID is gone,
     * inspects the job log to determine success or failure, sends a Telegram notification to the originating chat,
     * schedules deletion of the notification on success, triggers a Jellyfin library refresh on success, removes the job's log file,
     * and removes the job from the tracked list. Persists the updated ytdlp process list to state.
     */
    private function reattachYtdlpProcesses(): void
    {
        if (empty($this->ytdlpProcesses)) {
            return;
        }

        $this->logger->info("Reattaching " . count($this->ytdlpProcesses) . " restored yt-dlp process(es).");

        $stillActive = [];
        foreach ($this->ytdlpProcesses as $proc) {
            $pid = (int) $proc['pid'];

            // Validate both PID existence and process identity to prevent PID recycle matches
            $isRunning = false;
            if (file_exists("/proc/{$pid}")) {
                $cmdline = (string)@file_get_contents("/proc/{$pid}/cmdline");
                if ($cmdline === '' || stripos($cmdline, $proc['url']) !== false || stripos($cmdline, 'yt-dlp') !== false) {
                    $isRunning = true;
                }
            }

            if ($isRunning) {
                // PID still running — keep tracking; checkYtdlpProcesses() handles the rest
                $this->logger->info("yt-dlp PID $pid is still running. Resuming monitoring.");
                $stillActive[] = $proc;
                continue;
            }

            // PID is gone — process finished (or was killed) while bot was down
            $this->logger->info("yt-dlp PID $pid is no longer running. Processing result.");

            $logContent = '';
            if (file_exists($proc['log_file'])) {
                $logContent = (string) file_get_contents($proc['log_file']);
            }

            $hasError = stripos($logContent, 'ERROR:') !== false;
            $isEmpty = trim($logContent) === '';

            if ($hasError || $isEmpty) {
                $errorDetail = '';
                if ($hasError) {
                    $lines = explode("\n", trim($logContent));
                    foreach (array_reverse($lines) as $line) {
                        if (stripos($line, 'ERROR:') !== false) {
                            $errorDetail = "\n" . trim($line);
                            break;
                        }
                    }
                }
                $this->tgSendMessage(
                    (int) $proc['chat_id'],
                    "❌ yt-dlp download failed (detected after restart).\nURL: `{$proc['url']}`$errorDetail",
                    'Markdown'
                );
                $this->logger->error("yt-dlp PID $pid failed (post-restart). Log: {$proc['log_file']}");
            } else {
                $res = $this->tgSendMessage(
                    (int) $proc['chat_id'],
                    "✅ yt-dlp download finished (detected after restart).\nURL: `{$proc['url']}`\nDir: `{$proc['dir']}`",
                    'Markdown'
                );
                if ($res && isset($res['message_id'])) {
                    $this->pendingDeletions[] = [
                        'chat_id' => (int) $proc['chat_id'],
                        'message_id' => $res['message_id'],
                        'expires' => time() + $this->config['notification_cleanup_time']
                    ];
                }
                $this->logger->info("yt-dlp PID $pid completed successfully (post-restart).");
                $this->jellyfinRefreshLibrary();
            }

            // Clean up log file
            @unlink($proc['log_file']);
        }

        $this->ytdlpProcesses = $stillActive;

        // Persist cleaned-up state immediately
        $this->saveState();
    }

    /**
     * Trigger a Jellyfin library refresh when Jellyfin integration is enabled.
     *
     * Sends a POST request to {jellyfin_url}/Library/Refresh with the header
     * `X-Emby-Token: {jellyfin_api_key}` and logs whether the refresh was triggered successfully.
     *
     * If `jellyfin_enabled` is falsey the method does nothing. If the API key is missing the method
     * logs an error and returns without making a request.
     */
    private function jellyfinRefreshLibrary(): void
    {
        if (!($this->config['jellyfin_enabled'] ?? false)) {
            return;
        }

        $url = rtrim($this->config['jellyfin_url'] ?? '', '/') . '/Library/Refresh';
        $apiKey = $this->config['jellyfin_api_key'] ?? '';

        if (empty($apiKey)) {
            $this->logger->error("Jellyfin API key is not configured.");
            return;
        }

        $this->logger->info("Triggering Jellyfin library refresh: $url");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-Emby-Token: ' . $apiKey],
            CURLOPT_TIMEOUT => 10
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code === 204 || $code === 200) {
            $this->logger->info("Jellyfin library refresh triggered successfully.");
        } else {
            $this->logger->error("Jellyfin library refresh failed. HTTP $code. Error: $err");
        }
    }

    /**
     * Send a Markdown-formatted snapshot of qBittorrent torrents to a Telegram chat and record the sent message IDs for later cleanup.
     *
     * Deletes any previously stored status messages for the chat, fetches torrents from qBittorrent, applies the configured
     * status_filter and status_show_limit, formats each torrent with name, progress, and state, and stores the sent message IDs
     * (retaining up to the last 5) so they can be removed on subsequent updates.
     *
     * @param int $chatId Telegram chat identifier to receive the status summary.
     * @param bool $interactive Currently unused; reserved for callers that may differentiate interactive updates.
     */
    private function sendTorrentStatusToChat(int $chatId, bool $interactive): void
    {
        // Delete all previous status messages for this chat
        if (isset($this->lastStatusMessageIds[$chatId]) && is_array($this->lastStatusMessageIds[$chatId])) {
            foreach ($this->lastStatusMessageIds[$chatId] as $msgId) {
                $this->tgApiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
            }
            $this->lastStatusMessageIds[$chatId] = []; // Clear array
        }

        $torrents = $this->qbRequest('/api/v2/torrents/info', ['filter' => 'all']);
        $this->logger->info("qBittorrent returned: " . (is_array($torrents) ? count($torrents) . " torrents" : "null"));
        if (!is_array($torrents))
            return;

        // Apply filter based on config
        if ($this->config['status_filter'] === 'downloading') {
            $torrents = array_filter(
                $torrents,
                fn($t) =>
                str_contains($t['state'], 'DL') || $t['state'] === 'downloading'
            );
            $torrents = array_values($torrents); // Reset array keys
        }

        // Apply limit based on config
        $limit = $this->config['status_show_limit'] ?? 10;
        if ($limit > 0) {
            $torrents = array_slice($torrents, 0, $limit);
        }

        $lines = [];
        foreach ($torrents as $t) {
            $prog = round((float) $t['progress'] * 100, 1);
            $name = str_replace(['`', '_', '*'], '', $t['name']);
            $lines[] = "• `{$name}`\n  {$prog}% | {$t['state']}";
        }
        $text = empty($lines) ? "📭 No active torrents." : "📊 *qBit Status*\n\n" . implode("\n", $lines);

        $links = [];
        if (!empty($this->config['qb_url'])) {
            $links[] = "[qBittorrent](" . $this->config['qb_url'] . ")";
        }
        if (!empty($this->config['torrserver_enabled']) && !empty($this->config['torrserver_url'])) {
            $links[] = "[TorrServer](" . $this->config['torrserver_url'] . ")";
        }
        if (!empty($this->config['jellyfin_enabled']) && !empty($this->config['jellyfin_url'])) {
            $links[] = "[Jellyfin](" . $this->config['jellyfin_url'] . ")";
        }
        if (!empty($links)) {
            $text .= "\n\n🔗 *Links*: " . implode(" | ", $links);
        }

        $this->logger->info("Sending status message with " . count($lines) . " torrents");
        $res = $this->tgSendMessage($chatId, $text, 'Markdown');
        if ($res && isset($res['message_id'])) {
            // Store new message ID in array
            if (!isset($this->lastStatusMessageIds[$chatId])) {
                $this->lastStatusMessageIds[$chatId] = [];
            }
            $this->lastStatusMessageIds[$chatId][] = $res['message_id'];
            // Keep only last 5 message IDs to prevent unbounded growth
            if (count($this->lastStatusMessageIds[$chatId]) > 5) {
                $this->lastStatusMessageIds[$chatId] = array_slice($this->lastStatusMessageIds[$chatId], -5);
            }
        } else {
            $this->logger->error("Failed to send status message. Response: " . json_encode($res));
        }
    }

    /**
     * Detects newly completed qBittorrent torrents, applies the configured post-completion action, notifies all known chats, and records the torrent as notified.
     *
     * The configured action is read from `config['action_on_complete']` and supports:
     * - `remove_data` / `delete_data`: remove torrent and delete files,
     * - `remove` / `delete`: remove torrent without deleting files,
     * - any other value: pause the torrent.
     *
     * After performing the action, a "Finished" message containing the torrent name is sent to every known chat, the torrent hash is added to the bot's notified list, and state is persisted.
     */
    private function checkTorrentCompletions(): void
    {
        $torrents = $this->qbRequest('/api/v2/torrents/info', ['filter' => 'completed']);
        if (!is_array($torrents))
            return;
        foreach ($torrents as $t) {
            if (in_array($t['hash'], $this->notifiedTorrentIds))
                continue;

            $action = strtolower(trim((string) ($this->config['action_on_complete'] ?? 'stop')));
            if (in_array($action, ['remove_data', 'delete_data'])) {
                $this->qbRequest('/api/v2/torrents/delete', ['hashes' => $t['hash'], 'deleteFiles' => 'true'], true);
            } elseif (in_array($action, ['remove', 'delete'])) {
                $this->qbRequest('/api/v2/torrents/delete', ['hashes' => $t['hash']], true);
            } else {
                $this->qbRequest('/api/v2/torrents/pause', ['hashes' => $t['hash']], true);
            }

            foreach ($this->knownChatIds as $cid) {
                $this->tgSendMessage($cid, "✅ *Finished:* `{$t['name']}`", 'Markdown');
            }
            $this->notifiedTorrentIds[] = $t['hash'];
            $this->saveState();
        }
    }

    /**
     * Perform a request to the configured TorrServer endpoint and decode its JSON response.
     *
     * When $data is empty a GET is performed; when $data is provided a JSON POST is sent.
     * If TorrServer credentials are configured, HTTP Basic authentication will be used.
     *
     * @param string $endpoint Path appended to the configured TorrServer base URL (e.g. '/torrents').
     * @param array $data Optional payload sent as JSON in a POST request when non-empty.
     * @return array|null Decoded response as an associative array, or `null` if the response is empty or not valid JSON.
     */
    private function torrServerRequest(string $endpoint, array $data = []): mixed
    {
        $url = rtrim($this->config['torrserver_url'], '/') . $endpoint;
        $this->logger->info("Requesting TorrServer: $url " . (empty($data) ? "POST action=list" : json_encode($data)));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if (!empty($this->config['torrserver_user'])) {
            $this->logger->info("Using TorrServer with credentials configured.");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['torrserver_user'] . ":" . ($this->config['torrserver_pass'] ?? ''));
        }
        if (!empty($data)) {
            $payload = json_encode($data);
            if ($payload === false) {
                $this->logger->error("Failed to JSON encode TorrServer request data: " . json_last_error_msg());
                curl_close($ch);
                return null;
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            $this->logger->error("TorrServer request failed: Curl error");
        } else {
            $this->logger->info("TorrServer responded with code $code");
        }
        return json_decode((string) $res, true);
    }

    /**
     * Polls TorrServer for torrents and notifies known chats with an inline "Download / Ignore" prompt for new items.
     *
     * If TorrServer is disabled in configuration this is a no-op. On non-array or failed responses this increments
     * an internal failure counter; after 5 consecutive failures a warning is sent to all known chats and the counter is reset.
     *
     * Torrents with empty or "Unknown" titles are tracked in a pending map and retried until either metadata is available,
     * more than 10 attempts have occurred, or 600 seconds have elapsed — at which point the torrent is marked as notified and skipped.
     *
     * When a torrent has a valid title, a notification is sent to each known chat. If the torrent provides a valid poster URL
     * the poster is sent as a photo with the caption; otherwise a text message is sent. Each successful delivery stores the
     * chat/message id in the per-hash notification map and, if at least one delivery succeeds, the hash is added to the
     * notified-hashes list and state is persisted.
     */
    private function checkTorrServer(): void
    {
        if (!($this->config['torrserver_enabled'] ?? false))
            return;

        $this->logger->info("Checking TorrServer for new torrents...");
        $torrents = $this->torrServerRequest('/torrents', ['action' => 'list']);
        if (!is_array($torrents)) {
            $this->logger->error("TorrServer response is not an array: " . gettype($torrents));
            $this->torrServerFailCount++;
            if ($this->torrServerFailCount >= 5) {
                foreach ($this->knownChatIds as $cid) {
                    $this->tgSendMessage($cid, "⚠️ TorrServer unavailable after 5 attempts. Please check the service.");
                }
                $this->torrServerFailCount = 0;
            }
            return;
        }
        $this->torrServerFailCount = 0;

        $this->logger->info("Found " . count($torrents) . " torrents in TorrServer.");

        foreach ($torrents as $t) {
            $hash = $t['hash'];
            if (in_array($hash, $this->notifiedTorrHashes))
                continue;

            $name = $t['title'] ?? '';
            // If TorrServer hasn't finished loading the torrent, title might be empty or "Unknown"
            if (empty($name) || $name === 'Unknown') {
                if (!isset($this->pendingTorrents[$hash])) {
                    $this->pendingTorrents[$hash] = ['attempts' => 1, 'first_seen' => time()];
                } else {
                    $this->pendingTorrents[$hash]['attempts']++;
                    // If metadata doesn't load within 10 attempts or 10 minutes - give up and mark as notified
                    if (
                        $this->pendingTorrents[$hash]['attempts'] > 10 ||
                        (time() - $this->pendingTorrents[$hash]['first_seen']) > 600
                    ) {
                        $this->notifiedTorrHashes[] = $hash;
                        $this->saveState();
                        unset($this->pendingTorrents[$hash]);
                    }
                }
                continue;
            }
            // Clean up from pending if successfully processed
            unset($this->pendingTorrents[$hash]);

            $this->logger->info("Processing new torrent $hash ($name)");

            $poster = $t['poster'] ?? '';
            $fileInfoStr = "";
            $totalSize = $t['torrent_size'] ?? 0;

            if (!empty($t['data'])) {
                $parsedData = json_decode($t['data'], true);
                if ($parsedData && isset($parsedData['TorrServer']['Files']) && is_array($parsedData['TorrServer']['Files'])) {
                    $files = $parsedData['TorrServer']['Files'];
                    if (count($files) === 1) {
                        $fName = basename($files[0]['path'] ?? '');
                        $fSize = $files[0]['length'] ?? 0;
                        if ($fSize > 0) {
                            $fSizeMb = round($fSize / 1024 / 1024, 1);
                            $fileInfoStr = "\n📄 File: `{$fName}`\n📦 Size: {$fSizeMb} MB";
                        }
                    } else {
                        $totalSizeMb = round($totalSize / 1024 / 1024, 1);
                        $fileInfoStr = "\n📺 Episodes: " . count($files) . "\n📦 Total Size: {$totalSizeMb} MB";
                    }
                }
            }

            if (empty($fileInfoStr) && $totalSize > 0) {
                $totalSizeMb = round($totalSize / 1024 / 1024, 1);
                $fileInfoStr = "\n📦 Size: {$totalSizeMb} MB";
            }

            $safeName = $this->escapeMarkdown($name);
            $safeFileInfoStr = $this->escapeMarkdown($fileInfoStr);
            $msg = "🎬 *New in TorrServer:*\n\n{$safeName}{$safeFileInfoStr}\n\nDownload to qBit?";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Download', 'callback_data' => "ts_dl:{$hash}"],
                        ['text' => '🙈 Ignore', 'callback_data' => "ts_ignore:{$hash}"]
                    ]
                ]
            ];

            $this->logger->info("Sending message to known chats. Count: " . count($this->knownChatIds));
            $delivered = false;
            foreach ($this->knownChatIds as $cid) {
                $res = null;
                if (!empty($poster) && filter_var($poster, FILTER_VALIDATE_URL)) {
                    $res = $this->tgSendPhoto($cid, $poster, $msg, 'Markdown', $keyboard);
                    if (!$res || !isset($res['message_id'])) {
                        $this->logger->error("Failed to send photo to $cid, falling back to text.");
                        $res = null;
                    }
                }

                if (empty($poster) || !$res) {
                    $res = $this->tgSendMessage($cid, $msg, 'Markdown', $keyboard);
                    if (!$res || !isset($res['message_id'])) {
                        $this->logger->error("Failed to send text to $cid");
                    }
                }

                if ($res && isset($res['message_id'])) {
                    $this->torrServerMsgIds[$hash][] = ['chat_id' => $cid, 'message_id' => $res['message_id']];
                    $delivered = true;
                }
            }

            if ($delivered) {
                $this->notifiedTorrHashes[] = $hash;
                $this->saveState();
                $this->logger->info("Saved state for hash $hash");
            } else {
                $this->logger->error("Skipped saving hash $hash as all notifications failed.");
            }
        }
    }

    /**
     * Remove TorrServer notification messages for a torrent hash from all chats except the acting one.
     *
     * Deletes each recorded Telegram message for the given torrent hash that was sent to chats other than
     * the specified acting chat, and clears the internal per-hash message tracking entry.
     *
     * @param string $hash The TorrServer torrent hash whose notification messages should be removed.
     * @param int $actingChatId The chat ID to exclude from deletion (the notification in this chat will be kept).
     * @return void
     */
    private function deleteOtherTorrServerMessages(string $hash, int $actingChatId): void
    {
        if (empty($this->torrServerMsgIds[$hash])) {
            return;
        }
        foreach ($this->torrServerMsgIds[$hash] as $entry) {
            if ((int) $entry['chat_id'] !== $actingChatId) {
                $this->tgApiRequest('deleteMessage', [
                    'chat_id' => $entry['chat_id'],
                    'message_id' => $entry['message_id']
                ]);
                $this->logger->info("Deleted TorrServer notification for hash $hash from chat {$entry['chat_id']}");
            }
        }
        unset($this->torrServerMsgIds[$hash]);
    }

    /**
     * Checks torrents in the pendingLimits queue and applies their download limits.
     * Removes them from the queue on success or after 10 attempts.
     */
    private function checkPendingLimits(): void
    {
        foreach ($this->pendingLimits as $hash => $limitData) {
            $this->pendingLimits[$hash]['attempts']++;
            $info = $this->qbRequest('/api/v2/torrents/info', ['hashes' => $hash]);
            if (is_array($info) && count($info) > 0) {
                $this->qbRequest('/api/v2/torrents/setDownloadLimit', ['hashes' => $hash, 'limit' => $limitData['limit']], true);
                $this->logger->info("Applied manual download limit {$limitData['limit']} to $hash");
                unset($this->pendingLimits[$hash]);
            } elseif ($this->pendingLimits[$hash]['attempts'] >= 10) {
                $this->logger->warning("Failed to apply manual download limit to $hash after 10 attempts (gave up).");
                unset($this->pendingLimits[$hash]);
            }
        }
    }

    /**
     * Run the bot's main loop, polling Telegram updates and executing periodic background tasks.
     *
     * Continuously fetches Telegram updates and dispatches them to the update handler, and periodically:
     * - checks for completed qBittorrent torrents,
     * - polls TorrServer for new torrents,
     * - monitors background yt-dlp jobs,
     * - saves persistent state,
     * - deletes scheduled Telegram messages,
     * - expires pending download requests and notifies the requesting chat.
     */
    public function run(): void
    {
        $this->logger->info("Bot v" . self::VERSION . " started.");
        while (true) {
            try {
                $updates = $this->tgApiRequest('getUpdates', ['offset' => $this->offset + 1, 'timeout' => $this->config['poll_timeout']]);
                if ($updates) {
                    foreach ($updates as $u) {
                        $this->offset = $u['update_id'];
                        $this->handleUpdate($u);
                    }
                }
                $now = time();
                if ($now - $this->lastCheck >= $this->config['check_interval']) {
                    $this->checkTorrentCompletions();
                    $this->lastCheck = $now;
                }
                if ($now - $this->lastTorrCheck >= ($this->config['torrserver_check_interval'] ?? 60)) {
                    $this->checkTorrServer();
                    $this->lastTorrCheck = $now;
                }
                $this->checkPendingLimits();
                $this->checkYtdlpProcesses();
                if ($now - $this->lastSave >= $this->config['state_save_interval']) {
                    $this->saveState();
                    $this->lastSave = $now;
                }
                foreach ($this->pendingDeletions as $k => $i) {
                    if ($now >= $i['expires']) {
                        $this->tgApiRequest('deleteMessage', ['chat_id' => $i['chat_id'], 'message_id' => $i['message_id']]);
                        unset($this->pendingDeletions[$k]);
                    }
                }
                foreach ($this->pendingDownloads as $cid => $pending) {
                    $expires = $pending['expires'] ?? 0;
                    if ($expires > 0 && $now >= $expires) {
                        $this->tgSendMessage($cid, "⏱ Download request expired. Please try again.");
                        unset($this->pendingDownloads[$cid]);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
                sleep(2);
            }
            usleep(100000);
        }
    }
}

$opts = getopt('v', ['version']);
if (isset($opts['v']) || isset($opts['version'])) {
    echo "QBittorrent Telegram Bot v" . QBittorrentBot::VERSION . "\n";
    exit(0);
}

try {
    (new QBittorrentBot())->run();
} catch (Throwable $e) {
    error_log($e->getMessage());
    exit(1);
}


