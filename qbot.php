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
    // =================== CONFIGURATION ===================
    private array $config;

    // =================== STATE ===================
    private array $apiBase;
    private array $pendingDownloads = [];
    private array $lastStatusMessageIds = [];
    private array $knownChatIds = [];
    private array $notifiedTorrentIds = [];
    private array $pendingDeletions = [];
    private ?string $qbCookie = null;

    private int $offset = 0;
    private int $lastCheck = 0;
    private int $lastStatus = 0;
    private int $lastSave = 0;
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

        $logFile = $this->config['log_file'] ?? __DIR__ . '/bot.log';
        $this->logger = new class ($logFile) implements LoggerInterface {
            private string $logFile;
            public function __construct(string $logFile)
            {
                $this->logFile = $logFile; }
            public function error(string $msg): void
            {
                error_log("[" . date('Y-m-d H:i:s') . "] ERROR: $msg\n", 3, $this->logFile);
                echo "ERROR: $msg\n"; }
            public function info(string $msg): void
            {
                error_log("[" . date('Y-m-d H:i:s') . "] INFO: $msg\n", 3, $this->logFile);
                echo "INFO: $msg\n"; }
        };

        $this->loadState();
        $this->lastStatus = time();
    }

    private function loadState(): void
    {
        $stateFile = $this->config['state_file'] ?? __DIR__ . '/bot_state.json';
        if (!file_exists($stateFile))
            return;
        $state = json_decode(file_get_contents($stateFile), true);
        if (is_array($state)) {
            $this->knownChatIds = $state['known_chats'] ?? [];
            $this->notifiedTorrentIds = $state['notified_torrents'] ?? [];
            // Handle both old format (single ID) and new format (array of IDs)
            $statusIds = $state['last_status_ids'] ?? [];
            foreach ($statusIds as $chatId => $ids) {
                $this->lastStatusMessageIds[$chatId] = is_array($ids) ? $ids : [$ids];
            }
        }
    }

    private function saveState(): void
    {
        $state = [
            'known_chats' => array_values(array_unique($this->knownChatIds)),
            'notified_torrents' => $this->notifiedTorrentIds,
            'last_status_ids' => $this->lastStatusMessageIds,
            'timestamp' => time()
        ];
        $stateFile = $this->config['state_file'] ?? __DIR__ . '/bot_state.json';
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
        return ($data && isset($data['ok']) && $data['ok']) ? $data['result'] : null;
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

    private function tgCategoryKeyboard(int $currentDiskIdx): array
    {
        $buttons = [];
        foreach ($this->config['categories'] as $key => $label) {
            $buttons[] = [['text' => $label, 'callback_data' => "dl:$key"]];
        }
        $diskRow = [];
        foreach ($this->config['disks'] as $idx => $path) {
            $label = ($idx === $currentDiskIdx) ? "✅ Disk " . ($idx + 1) : "💾 D" . ($idx + 1);
            $diskRow[] = ['text' => $label, 'callback_data' => "set_disk:$idx"];
        }
        $buttons[] = $diskRow;
        return ['inline_keyboard' => $buttons];
    }

    // =================== QB API METHODS ===================

    private function qbLogin(): bool
    {
        $ch = curl_init($this->config['qb_url'] . '/api/v2/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => $this->config['qb_user'], 'password' => $this->config['qb_pass']]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true
        ]);
        $resp = (string) curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $headerSize);
        curl_close($ch);
        if (preg_match('/set-cookie:\s*(SID=[^;\s]+)/i', $header, $matches)) {
            $this->qbCookie = $matches[1];
            return true;
        }
        return false;
    }

    private function qbRequest(string $endpoint, array $params = [], bool $isPost = false, bool $isFile = false)
    {
        if (!$this->qbCookie && !$this->qbLogin())
            return null;
        $url = $this->config['qb_url'] . $endpoint;
        $ch = curl_init($url . (!$isPost ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->qbCookie
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
        curl_close($ch);
        if ($code === 403) {
            $this->qbCookie = null;
            return $this->qbRequest($endpoint, $params, $isPost, $isFile);
        }
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
            $this->pendingDownloads[$chatId] = ['type' => 'magnet', 'magnet' => $text, 'disk_idx' => $this->config['default_disk_idx']];
            $this->tgSendMessage($chatId, "🔗 Magnet detected. Choose destination:", 'Markdown', $this->tgCategoryKeyboard($this->config['default_disk_idx']));
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
            $this->pendingDownloads[$chatId] = ['type' => $type, 'file_id' => $fileId, 'name' => $name, 'disk_idx' => $this->config['default_disk_idx']];
            $this->tgSendMessage($chatId, "📥 Received: `{$name}`\nChoose destination:", 'Markdown', $this->tgCategoryKeyboard($this->config['default_disk_idx']));
        }
    }

    private function handleCallback(array $cb): void
    {
        $chatId = $cb['message']['chat']['id'];
        $data = $cb['data'];
        $this->tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

        if (str_starts_with($data, 'set_disk:')) {
            $idx = (int) substr($data, 9);
            $this->pendingDownloads[$chatId]['disk_idx'] = $idx;
            $this->tgApiRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $cb['message']['message_id'],
                'text' => "💿 Disk: " . $this->config['disks'][$idx] . "\nChoose category:",
                'reply_markup' => json_encode($this->tgCategoryKeyboard($idx))
            ]);
        } elseif (str_starts_with($data, 'dl:')) {
            $sub = substr($data, 3);
            $path = $this->config['disks'][$this->pendingDownloads[$chatId]['disk_idx'] ?? 0] . '/' . $sub;
            $this->tgApiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $cb['message']['message_id']]);
            $this->finalizeDownload($chatId, $path);
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
        if ($p['type'] === 'magnet') {
            $this->qbRequest('/api/v2/torrents/add', ['urls' => $p['magnet'], 'savepath' => $dir], true);
            $msgText = "✅ Magnet added to qBit.\nDir: `{$dir}`";
        } else {
            $fileInfo = $this->tgApiRequest('getFile', ['file_id' => $p['file_id']]);
            if (!$fileInfo) {
                $this->tgSendMessage($chatId, "❌ Could not get file from Telegram (Check 20MB limit).");
                return;
            }

            $local = __DIR__ . '/' . preg_replace('/[^a-zA-Z0-9\._\-]/', '_', $p['name']);
            $fp = fopen($local, 'w+');
            $ch = curl_init($this->apiBase['file'] . $fileInfo['file_path']);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            fclose($fp);

            if ($p['type'] === 'file') {
                $this->qbRequest('/api/v2/torrents/add', ['torrents' => new CURLFile($local), 'savepath' => $dir], true, true);
                $msgText = "✅ Torrent added.\nDir: `{$dir}`";
                @unlink($local);
            } else {
                @rename($local, $dir . '/' . basename($local));
                $msgText = "✅ Media saved to `{$dir}`";
            }
        }

        $res = $this->tgSendMessage($chatId, $msgText, 'Markdown');
        if ($res && isset($res['message_id'])) {
            $this->pendingDeletions[] = ['chat_id' => $chatId, 'message_id' => $res['message_id'], 'expires' => time() + $this->config['notification_cleanup_time']];
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
            $this->qbRequest('/api/v2/torrents/' . ($this->config['action_on_complete'] === 'remove' ? 'delete' : 'pause'), ['hashes' => $t['hash']], true);
            foreach ($this->knownChatIds as $cid) {
                $this->tgSendMessage($cid, "✅ *Finished:* `{$t['name']}`", 'Markdown');
            }
            $this->notifiedTorrentIds[] = $t['hash'];
            $this->saveState();
        }
    }

    public function run(): void
    {
        $this->logger->info("Bot started.");
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
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
                sleep(2);
            }
            usleep(100000);
        }
    }
}

try {
    (new QBittorrentBot())->run();
} catch (Throwable $e) {
    error_log($e->getMessage());
    exit(1);
}

