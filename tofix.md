Verify each finding against the current code and only fix it if needed.

Inline comments:
In @.coderabbit.yaml:
- Around line 33-35: The auto_review block is at the top level and is ignored by
the config; move the auto_review mapping under the existing reviews mapping so
its keys (enabled and drafts) take effect. Locate the auto_review entry and
place it as a child of reviews (i.e., reviews: { auto_review: { enabled: ...,
drafts: ... } }) ensuring the symbols auto_review, reviews, enabled, and drafts
remain intact and properly indented in the YAML structure.

In `@config.php.example`:
- Around line 69-72: The default ytdlp_binary path is too specific and breaks
native installs; update the 'ytdlp_binary' default (and its comment) so the app
uses the system PATH by default (e.g., set to 'yt-dlp' instead of
'/usr/local/bin/yt-dlp') and document common install locations (/usr/bin,
~/.local/bin, /usr/local/bin) and how to override via 'ytdlp_binary' or disable
with 'ytdlp_enabled'; ensure references in comments mention 'ytdlp_enabled',
'ytdlp_binary', and 'ytdlp_extra_args' so users know how to configure or provide
explicit paths.

In `@qbot.php`:
- Around line 130-145: saveState() currently omits the in-memory ytdlpProcesses,
so active yt-dlp jobs are lost across restarts; update saveState() to include
ytdlpProcesses (store per-job metadata like pid, command, temp log path, output
path, start time, and any retry/progress flags) and update loadState() to
restore that structure from the same state_file, validating entries and handling
missing/old formats; on restore, reattach monitoring for each restored job in
ytdlpProcesses by checking whether the PID is still running (or the output file
exists) and either resume polling/completion handling or mark the job
failed/cleanup logs, and ensure backward compatibility if the saved JSON lacks
the new key. ---FIXED
- Around line 942-963: The code currently pushes $hash into notifiedTorrHashes
even when all tgSendPhoto()/tgSendMessage() calls fail; change the logic in the
loop over $this->knownChatIds so you track a local success flag (e.g.,
$delivered = false), set it true whenever a send returns a valid ['message_id']
and you append to $this->torrServerMsgIds[$hash], then after the foreach only
push $hash into $this->notifiedTorrHashes and call $this->saveState() if
$delivered is true (otherwise log the failure and leave the hash unmarked so
next poll can retry). Ensure you reference the existing methods/arrays
(tgSendPhoto, tgSendMessage, torrServerMsgIds, notifiedTorrHashes, saveState)
and don't change their signatures.

---

Outside diff comments:
In `@qbot.php`:
- Around line 248-289: qbRequest currently recurses indefinitely on 403 because
it clears $this->qbCookie and calls itself; add a retry limiter (e.g., an extra
parameter like int $retryCount = 0 or bool $hasRetried = false) to qbRequest and
only allow one automatic re-login attempt: when $code === 403 and retryCount ==
0 (or hasRetried is false) clear $this->qbCookie, set the flag/increment the
counter, log the re-login attempt, and call qbRequest again with the updated
flag/counter; if a 403 is received and the flag/counter indicates a retry was
already performed, log the permanent failure and return null instead of
recursing.
- Around line 367-415: handleCallback currently accepts any inline callback and
mutates shared state without verifying the callback sender or the freshness of
pending data; add authorization and validity checks up front: verify
$cb['from']['id'] (callback_query.from.id) matches the user allowed to control
the current pending download (e.g. compare to
$this->pendingDownloads[$chatId]['user_id'] or the original requester stored
when creating pendingDownloads) and reject unauthorized callbacks by calling
tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' =>
'Not authorized', 'show_alert' => false]) and returning; additionally, before
reading $this->pendingDownloads[$chatId] or using disk indexes in the
'set_disk:' and 'dl:' branches, validate that pendingDownloads[$chatId] exists
and that the disk_idx is numeric and within bounds of $this->config['disks']; if
invalid/stale, answerCallbackQuery with an appropriate message and return
instead of dereferencing or mutating state. Also apply similar validation for
ts_dl:/ts_ignore: flows (ensure $hash is expected or not already processed)
before calling finalizeDownload, handleTorrServerDownload,
deleteOtherTorrServerMessages, or mutating $this->notifiedTorrHashes, and
log/answer callbacks for rejected/stale actions.