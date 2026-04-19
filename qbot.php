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
    public function error(string $msg): void;
    public function info(string $msg): void;
}

final class QBittorrentBot
{
    public const VERSION = '1.2.4';

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
    private int $lastStatus = 0;
    private int $lastSave = 0;
    private int $torrServerFailCount = 0;
    private LoggerInterface $logger;

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
            public function __construct(string $logFile)
            {
                $this->logFile = $logFile;
            }
            public function error(string $msg): void
            {
                @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: $msg\n", FILE_APPEND);
                echo "ERROR: $msg\n";
            }
            public function info(string $msg): void
            {
                @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] INFO: $msg\n", FILE_APPEND);
                echo "INFO: $msg\n";
            }
        };

        $this->loadState();
        $this->reattachYtdlpProcesses();
        $this->lastStatus = time();
    }

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
        $state = json_decode(file_get_contents($stateFile), true);
        if (is_array($state)) {
            $this->knownChatIds = $state['known_chats'] ?? [];
            $this->notifiedTorrentIds = $state['notified_torrents'] ?? [];
            $this->notifiedTorrHashes = $state['notified_torr_hashes'] ?? [];
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

    private function saveState(): void
    {
        $state = [
            'known_chats' => array_values(array_unique($this->knownChatIds)),
            'notified_torrents' => $this->notifiedTorrentIds,
            'notified_torr_hashes' => $this->notifiedTorrHashes,
            'last_status_ids' => $this->lastStatusMessageIds,
            'ytdlp_processes' => $this->ytdlpProcesses,
            'timestamp' => time()
        ];
        $stateFile = $this->config['state_file'] ?? __DIR__ . '/data/bot_state.json';
        $stateDir = dirname($stateFile);
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0775, true);
        }
        file_put_contents($stateFile, json_encode($state));
    }

    // =================== TELEGRAM HELPERS ===================

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

    private function tgSendMessage(int $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): mixed
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($parseMode)
            $params['parse_mode'] = $parseMode;
        if ($replyMarkup)
            $params['reply_markup'] = json_encode($replyMarkup);
        return $this->tgApiRequest('sendMessage', $params);
    }

    private function tgSendPhoto(int $chatId, string $photo, string $caption, ?string $parseMode = null, ?array $replyMarkup = null): mixed
    {
        $params = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption];
        if ($parseMode)
            $params['parse_mode'] = $parseMode;
        if ($replyMarkup)
            $params['reply_markup'] = json_encode($replyMarkup);
        return $this->tgApiRequest('sendPhoto', $params);
    }

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

    // =================== QB API METHODS ===================

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

    // =================== HANDLERS ===================

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

    private function handleCallback(array $cb): void
    {
        $chatId = $cb['message']['chat']['id'];
        $data = $cb['data'];
        $this->logger->info("Received callback data: $data from chat: $chatId");

        // Verify authorization for dl / set_disk
        if (str_starts_with($data, 'set_disk:') || str_starts_with($data, 'dl:')) {
            if (!isset($this->pendingDownloads[$chatId])) {
                $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "No pending download or session expired.", 'show_alert' => true]);
                return;
            }
            if (($this->pendingDownloads[$chatId]['user_id'] ?? $cb['from']['id']) !== $cb['from']['id']) {
                $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Not authorized. This isn't your download.", 'show_alert' => true]);
                return;
            }
        }

        // Validate ts_dl and ts_ignore freshness
        if (str_starts_with($data, 'ts_dl:') || str_starts_with($data, 'ts_ignore:')) {
            $hash = str_starts_with($data, 'ts_dl:') ? substr($data, 6) : substr($data, 10);
            if (!isset($this->torrServerMsgIds[$hash]) && in_array($hash, $this->notifiedTorrHashes)) {
                $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "This TorrServer item was already processed.", 'show_alert' => true]);
                return;
            }
        }

        $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

        if (str_starts_with($data, 'set_disk:')) {
            $idx = (int) substr($data, 9);
            if (!isset($this->config['disks'][$idx])) {
                return;
            }
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
            $this->handleTorrServerDownload($chatId, $hash, $cb['message'], $cb['message']['message_id']);
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

    private function handleTorrServerDownload(int $chatId, string $hash, array $message, int $messageId): void
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

                // qBittorrent often ignores dlLimit when adding magnets. Enforce it directly:
                if ($limitBytesPerSec > 0 && preg_match('/urn:btih:([a-zA-Z0-9]+)/i', $p['magnet'], $m)) {
                    $hash = strtolower($m[1]);
                    sleep(2); // Wait for qBittorrent to catch up
                    for ($i = 0; $i < 3; $i++) {
                        $info = $this->qbRequest('/api/v2/torrents/info', ['hashes' => $hash]);
                        if (is_array($info) && count($info) > 0) {
                            $this->qbRequest('/api/v2/torrents/setDownloadLimit', ['hashes' => $hash, 'limit' => $limitBytesPerSec], true);
                            $this->logger->info("Applied manual download limit $limitBytesPerSec to $hash");
                            $limitInfo = "\nSpeed Limit: {$limitMbit} Mbit/s";
                            break;
                        }
                        sleep(1);
                    }
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
            $ch = curl_init($this->apiBase['file'] . $fileInfo['file_path']);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
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

    private function startYtdlpDownload(string $url, string $dir, int $chatId): bool
    {
        $binary = $this->config['ytdlp_binary'] ?? '/usr/local/bin/yt-dlp';
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
            $cmd .= ' ' . escapeshellarg($arg);
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

        return true;
    }

    private function checkYtdlpProcesses(): void
    {
        if (empty($this->ytdlpProcesses)) {
            return;
        }

        foreach ($this->ytdlpProcesses as $k => $proc) {
            // Check if process is still running via /proc/{pid}
            if (file_exists("/proc/{$proc['pid']}")) {
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

        $this->ytdlpProcesses = array_values($this->ytdlpProcesses);
    }

    private function reattachYtdlpProcesses(): void
    {
        if (empty($this->ytdlpProcesses)) {
            return;
        }

        $this->logger->info("Reattaching " . count($this->ytdlpProcesses) . " restored yt-dlp process(es).");

        $stillActive = [];
        foreach ($this->ytdlpProcesses as $proc) {
            $pid = (int) $proc['pid'];

            if (file_exists("/proc/{$pid}")) {
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

    private function checkTorrentCompletions(): void
    {
        $torrents = $this->qbRequest('/api/v2/torrents/info', ['filter' => 'completed']);
        if (!is_array($torrents))
            return;
        foreach ($torrents as $t) {
            if (in_array($t['hash'], $this->notifiedTorrentIds))
                continue;

            $action = strtolower(trim((string)($this->config['action_on_complete'] ?? 'stop')));
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

    private function torrServerRequest(string $endpoint, array $data = []): mixed
    {
        $url = rtrim($this->config['torrserver_url'], '/') . $endpoint;
        $this->logger->info("Requesting TorrServer: $url " . (empty($data) ? "POST action=list" : json_encode($data)));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if (!empty($this->config['torrserver_user'])) {
            $this->logger->info("Using TorrServer credentials: " . $this->config['torrserver_user']);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['torrserver_user'] . ":" . ($this->config['torrserver_pass'] ?? ''));
        }
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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
                    if ($this->pendingTorrents[$hash]['attempts'] > 10 || 
                        (time() - $this->pendingTorrents[$hash]['first_seen']) > 600) {
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

            $msg = "🎬 *New in TorrServer:*\n\n`{$name}`{$fileInfoStr}\n\nDownload to qBit?";

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


