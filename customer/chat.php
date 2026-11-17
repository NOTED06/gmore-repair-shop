<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customer    = $stmt->fetch();
$customer_id = $customer['customer_id'];

$conversation_id = isset($_GET['conversation']) ? (int)$_GET['conversation'] : null;

$stmt = $db->prepare("
    SELECT cc.*,
           (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id AND cm.is_read = 0 AND cm.sender_role = 'Admin') AS unread_count,
           (SELECT message FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) AS last_message,
           (SELECT created_at FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) AS last_message_time
    FROM chat_conversations cc
    WHERE cc.customer_id = ?
    ORDER BY cc.updated_at DESC
");
$stmt->execute([$customer_id]);
$conversations = $stmt->fetchAll();

$messages            = [];
$currentConversation = null;
if ($conversation_id) {
    $stmt = $db->prepare("SELECT * FROM chat_conversations WHERE conversation_id = ? AND customer_id = ?");
    $stmt->execute([$conversation_id, $customer_id]);
    $currentConversation = $stmt->fetch();

    if ($currentConversation) {
        $stmt = $db->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC");
        $stmt->execute([$conversation_id]);
        $messages = $stmt->fetchAll();

        
        $stmt = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_role = 'Admin' AND is_read = 0");
        $stmt->execute([$conversation_id]);
    }
}

$pageTitle    = 'Chat Support';
$activePage   = 'chat';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<div class="content" style="padding-top:16px;padding-bottom:0">

<div class="chat-layout">

  
  <aside class="chat-sidebar">
    <div class="chat-sidebar-header">
        <span class="online-dot"></span>
        <span class="chat-sidebar-title">Support Chats</span>
        <button class="btn-sm-ghost" style="margin-left:auto" onclick="openNewChatModal()"><i data-lucide="plus"></i> New</button>
    </div>
    <div class="chat-conv-list">
        <?php if (empty($conversations)): ?>
        <div style="padding:24px 16px;text-align:center">
            <p style="font-size:13px;color:var(--text-tertiary)">No conversations yet.</p>
            <button class="btn-primary" style="margin-top:12px;font-size:13px;padding:8px 16px" onclick="openNewChatModal()"><i data-lucide="plus"></i> Start Chat</button>
        </div>
        <?php else: ?>
        <?php foreach ($conversations as $conv): ?>
        <a href="chat.php?conversation=<?= $conv['conversation_id'] ?>"
           class="chat-conv-item <?= $conversation_id == $conv['conversation_id'] ? 'active' : '' ?>">
            <div class="chat-conv-avatar"><?= strtoupper(substr($conv['subject'] ?? 'S', 0, 1)) ?></div>
            <div class="chat-conv-info">
                <div class="chat-conv-name"><?= sanitize($conv['subject'] ?? 'Support Chat') ?></div>
                <div class="chat-conv-preview"><?= sanitize(mb_strimwidth($conv['last_message'] ?? 'No messages yet', 0, 40, '...')) ?></div>
            </div>
            <div class="chat-conv-meta">
                <?php if ($conv['last_message_time']): ?>
                <span class="chat-conv-time"><?= date('M j', strtotime($conv['last_message_time'])) ?></span>
                <?php endif; ?>
                <?php if ($conv['unread_count'] > 0): ?>
                <span class="chat-conv-unread"></span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
  </aside>

  
  <div class="chat-main">
    <?php if ($currentConversation): ?>

        
        <div class="chat-header">
            <div class="chat-header-avatar"><i data-lucide="headphones"></i></div>
            <div class="chat-header-info">
                <div class="chat-header-name"><?= sanitize($currentConversation['subject'] ?? 'Support Chat') ?></div>
                <div class="chat-header-status">Support Team &mdash; <span style="color:var(--emerald)">Online</span></div>
            </div>
        </div>

        
        <div class="chat-messages-area" id="chatMessagesArea">
            <?php if (empty($messages)): ?>
            <div style="text-align:center;padding:32px;color:var(--text-tertiary);font-size:13px">
                <i data-lucide="message-circle" style="width:32px;height:32px;margin:0 auto 8px"></i>
                <p>No messages yet. Start the conversation below.</p>
            </div>
            <?php else: ?>
            <?php foreach ($messages as $msg):
                $isSent = ($msg['sender_role'] === 'Customer');
            ?>
            <div class="chat-msg <?= $isSent ? 'sent' : 'received' ?>">
                <div class="chat-msg-bubble"><?= nl2br(sanitize($msg['message'])) ?></div>
                <div class="chat-msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        
        <?php if ($currentConversation['status'] !== 'Closed'): ?>
        <div class="chat-input-area">
            <div class="chat-input-row">
                <textarea class="chat-input" id="messageInput" placeholder="Type your message..." rows="1"></textarea>
                <button class="chat-send-btn" id="sendMessageBtn" onclick="sendMessage()"><i data-lucide="send"></i></button>
            </div>
        </div>
        <?php else: ?>
        <div class="chat-closed-notice"><i data-lucide="lock"></i> This conversation has been closed.</div>
        <?php endif; ?>

    <?php else: ?>

        
        <div class="chat-empty-state">
            <div class="chat-empty-circle"><i data-lucide="headphones"></i></div>
            <div class="chat-empty-title">How can we help?</div>
            <div class="chat-empty-sub">Select a conversation from the sidebar or start a new one.</div>
            <button class="btn-primary" style="margin-top:8px" onclick="openNewChatModal()"><i data-lucide="plus"></i> New Chat</button>
        </div>

    <?php endif; ?>
  </div>
</div>

</div>

<div class="modal-overlay" id="newChatModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-icon"><i data-lucide="message-square"></i></div>
            <div>
                <div class="modal-title">New Support Chat</div>
                <div class="modal-sub">Describe your issue so we can assist you faster.</div>
            </div>
            <button class="modal-close" onclick="closeNewChatModal()"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div class="field-group">
                <label class="field-label">Subject</label>
                <input type="text" id="chatSubject" class="input" placeholder="e.g. Repair status inquiry">
            </div>
            <div class="field-group">
                <label class="field-label">Message</label>
                <textarea id="chatMessage" class="input" rows="4" placeholder="Describe your concern..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-ghost" onclick="closeNewChatModal()">Cancel</button>
            <button class="btn-primary" onclick="createNewChat()"><i data-lucide="send"></i> Start Chat</button>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
<script>
const SITE_URL        = '<?= SITE_URL ?>';
const CONVERSATION_ID = <?= $conversation_id ?? 'null' ?>;

function scrollChatToBottom() {
    const area = document.getElementById('chatMessagesArea');
    if (area) area.scrollTop = area.scrollHeight;
}
document.addEventListener('DOMContentLoaded', scrollChatToBottom);

function sendMessage() {
    const input = document.getElementById('messageInput');
    if (!input) return;
    const message = input.value.trim();
    if (!message || !CONVERSATION_ID) return;

    fetch(SITE_URL + '/customer/send-message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `conversation_id=${CONVERSATION_ID}&message=${encodeURIComponent(message)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const area = document.getElementById('chatMessagesArea');
            const msgEl = document.createElement('div');
            msgEl.className = 'chat-msg sent';
            msgEl.innerHTML = `<div class="chat-msg-bubble">${data.message.replace(/\n/g,'<br>')}</div><div class="chat-msg-time">${data.time}</div>`;
            area.appendChild(msgEl);
            input.value = '';
            scrollChatToBottom();
        }
    })
    .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('messageInput');
    if (inp) {
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
    }
});

let lastMessageId = <?= !empty($messages) ? end($messages)['message_id'] : 0 ?>;
function pollMessages() {
    if (!CONVERSATION_ID) return;
    fetch(`${SITE_URL}/customer/get-messages.php?conversation_id=${CONVERSATION_ID}&last_id=${lastMessageId}`)
        .then(r => r.json())
        .then(data => {
            if (data.messages && data.messages.length) {
                const area = document.getElementById('chatMessagesArea');
                data.messages.forEach(msg => {
                    if (msg.sender_role === 'Admin') {
                        const el = document.createElement('div');
                        el.className = 'chat-msg received';
                        el.innerHTML = `<div class="chat-msg-bubble">${msg.message.replace(/\n/g,'<br>')}</div><div class="chat-msg-time">${msg.time}</div>`;
                        area.appendChild(el);
                        lastMessageId = msg.message_id;
                    }
                });
                scrollChatToBottom();
            }
        })
        .catch(() => {});
}
if (CONVERSATION_ID) setInterval(pollMessages, 5000);

function openNewChatModal()  { document.getElementById('newChatModal').classList.add('open'); }
function closeNewChatModal() { document.getElementById('newChatModal').classList.remove('open'); }

function createNewChat() {
    const subject = document.getElementById('chatSubject').value.trim();
    const message = document.getElementById('chatMessage').value.trim();
    if (!subject || !message) { alert('Please enter both a subject and a message.'); return; }

    fetch(SITE_URL + '/customer/send-message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `new_conversation=1&subject=${encodeURIComponent(subject)}&message=${encodeURIComponent(message)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.conversation_id) {
            window.location.href = `chat.php?conversation=${data.conversation_id}`;
        } else {
            alert(data.error || 'Failed to create conversation.');
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

document.getElementById('newChatModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeNewChatModal();
});
</script>
</body>
</html>
