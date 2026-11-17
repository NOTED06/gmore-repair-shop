<?php

$_chatConvs = [];
$_chatCustomerId = null;
try {
    $_cDb = getDB();
    $_cStmt = $_cDb->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $_cStmt->execute([$_SESSION['user_id']]);
    $_cRow = $_cStmt->fetch();
    $_chatCustomerId = $_cRow['customer_id'] ?? null;

    if ($_chatCustomerId) {
        $_cStmt = $_cDb->prepare("
            SELECT cc.*,
                   (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id AND cm.is_read = 0 AND cm.sender_role = 'Admin') AS unread_count,
                   (SELECT message FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) AS last_message_time
            FROM chat_conversations cc
            WHERE cc.customer_id = ?
            ORDER BY cc.updated_at DESC LIMIT 20
        ");
        $_cStmt->execute([$_chatCustomerId]);
        $_chatConvs = $_cStmt->fetchAll();
    }
} catch (Exception $e) {  }

$_chatUnread = 0;
foreach ($_chatConvs as $c) $_chatUnread += (int)$c['unread_count'];
?>

<button class="cw-fab" id="cwFab" aria-label="Chat Support">
    <i data-lucide="message-circle"></i>
    <?php if ($_chatUnread > 0): ?>
    <span class="cw-fab-badge"><?= $_chatUnread > 9 ? '9+' : $_chatUnread ?></span>
    <?php endif; ?>
</button>

<div class="cw-panel" id="cwPanel">
    
    <div class="cw-header">
        <div class="cw-header-left">
            <button class="cw-back-btn" id="cwBackBtn" style="display:none" aria-label="Back"><i data-lucide="arrow-left"></i></button>
            <div class="cw-header-avatar"><i data-lucide="headphones"></i></div>
            <div>
                <div class="cw-header-title" id="cwHeaderTitle">Chat Support</div>
                <div class="cw-header-sub" id="cwHeaderSub">We typically reply in minutes</div>
            </div>
        </div>
        <div class="cw-header-actions">
            <button class="cw-new-btn" id="cwNewBtn" title="New Chat"><i data-lucide="edit-3"></i></button>
            <button class="cw-close-btn" id="cwCloseBtn" aria-label="Close"><i data-lucide="x"></i></button>
        </div>
    </div>

    
    <div class="cw-body" id="cwBody">

        
        <div class="cw-view" id="cwViewList">
            <?php if (empty($_chatConvs)): ?>
            <div class="cw-empty">
                <div class="cw-empty-icon"><i data-lucide="message-circle"></i></div>
                <p class="cw-empty-title">How can we help?</p>
                <p class="cw-empty-sub">Start a conversation with our support team.</p>
                <button class="cw-start-btn" id="cwStartNew"><i data-lucide="plus"></i> New Chat</button>
            </div>
            <?php else: ?>
            <div class="cw-conv-list" id="cwConvList">
                <?php foreach ($_chatConvs as $conv): ?>
                <button class="cw-conv-item" data-cid="<?= $conv['conversation_id'] ?>" data-status="<?= $conv['status'] ?>">
                    <div class="cw-conv-avatar"><?= strtoupper(substr($conv['subject'] ?? 'S', 0, 1)) ?></div>
                    <div class="cw-conv-info">
                        <div class="cw-conv-name"><?= sanitize($conv['subject'] ?? 'Support Chat') ?></div>
                        <div class="cw-conv-preview"><?= sanitize(mb_strimwidth($conv['last_message'] ?? 'No messages yet', 0, 45, '…')) ?></div>
                    </div>
                    <div class="cw-conv-meta">
                        <?php if ($conv['last_message_time']): ?>
                        <span class="cw-conv-time"><?= timeAgo($conv['last_message_time']) ?></span>
                        <?php endif; ?>
                        <?php if ($conv['unread_count'] > 0): ?>
                        <span class="cw-conv-dot"></span>
                        <?php endif; ?>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        
        <div class="cw-view" id="cwViewChat" style="display:none">
            <div class="cw-messages" id="cwMessages">
                <div class="cw-loading"><i data-lucide="loader-2"></i> Loading…</div>
            </div>
            <div class="cw-input-area" id="cwInputArea">
                <textarea class="cw-input" id="cwInput" placeholder="Type a message…" rows="1"></textarea>
                <button class="cw-send" id="cwSendBtn"><i data-lucide="send"></i></button>
            </div>
            <div class="cw-closed-notice" id="cwClosedNotice" style="display:none">
                <i data-lucide="lock"></i> This conversation is closed.
            </div>
        </div>

        
        <div class="cw-view" id="cwViewNew" style="display:none">
            <div class="cw-new-form">
                <div class="cw-new-icon"><i data-lucide="message-square"></i></div>
                <h3>New Support Chat</h3>
                <p>Describe your issue so we can assist faster.</p>
                <label>Subject</label>
                <input type="text" id="cwNewSubject" class="cw-field" placeholder="e.g. Repair status inquiry">
                <label>Message</label>
                <textarea id="cwNewMessage" class="cw-field" rows="3" placeholder="Describe your concern…"></textarea>
                <button class="cw-start-btn" id="cwCreateBtn"><i data-lucide="send"></i> Start Chat</button>
            </div>
        </div>
    </div>
</div>

<style>

.cw-fab{position:fixed;bottom:28px;right:28px;width:56px;height:56px;border-radius:50%;background:var(--primary);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 24px rgba(30,58,138,.35);z-index:900;transition:transform .2s,box-shadow .2s}
.cw-fab:hover{transform:scale(1.08);box-shadow:0 8px 32px rgba(30,58,138,.45)}
.cw-fab svg{width:26px;height:26px}
.cw-fab-badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 5px;background:#EF4444;color:#fff;font-size:10px;font-weight:700;border-radius:9px;display:flex;align-items:center;justify-content:center;border:2px solid var(--primary)}
.cw-fab.hidden{display:none}

.cw-panel{display:none;position:fixed;bottom:96px;right:28px;width:400px;height:560px;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(30,58,138,.22);z-index:901;overflow:hidden;flex-direction:column;animation:cw-up .25s ease}
.cw-panel.open{display:flex}
@keyframes cw-up{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

.cw-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--navbar-gradient);color:#fff;flex-shrink:0}
.cw-header-left{display:flex;align-items:center;gap:10px}
.cw-header-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.cw-header-avatar svg{width:18px;height:18px;color:#fff}
.cw-header-title{font-family:'Syne',sans-serif;font-weight:700;font-size:15px}
.cw-header-sub{font-size:11px;opacity:.8}
.cw-header-actions{display:flex;gap:4px}
.cw-header-actions button{width:32px;height:32px;border-radius:6px;background:rgba(255,255,255,.15);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.cw-header-actions button:hover{background:rgba(255,255,255,.3)}
.cw-header-actions button svg{width:16px;height:16px}
.cw-back-btn{width:28px!important;height:28px!important;border-radius:50%!important;background:rgba(255,255,255,.12)!important;margin-right:2px}
.cw-back-btn svg{width:16px;height:16px}

.cw-body{flex:1;overflow:hidden;display:flex;flex-direction:column}
.cw-view{flex:1;display:flex;flex-direction:column;overflow:hidden}

.cw-conv-list{flex:1;overflow-y:auto;padding:6px 0}
.cw-conv-item{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;width:100%;border:none;background:none;cursor:pointer;text-align:left;transition:background .12s;border-bottom:1px solid #F5F5F0;font-family:inherit}
.cw-conv-item:hover{background:#FAFAF5}
.cw-conv-avatar{width:36px;height:36px;border-radius:50%;background:var(--gold-light);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
.cw-conv-info{flex:1;min-width:0}
.cw-conv-name{font-size:13px;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cw-conv-preview{font-size:12px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.cw-conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.cw-conv-time{font-size:10px;color:#aaa}
.cw-conv-dot{width:8px;height:8px;background:var(--gold);border-radius:50%}

.cw-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#FAFAF5}
.cw-messages::-webkit-scrollbar{width:4px}
.cw-messages::-webkit-scrollbar-thumb{background:var(--gold-mid);border-radius:2px}
.cw-msg{max-width:78%;display:flex;flex-direction:column;gap:2px}
.cw-msg.sent{align-self:flex-end}
.cw-msg.received{align-self:flex-start}
.cw-msg-bubble{padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5}
.cw-msg.sent .cw-msg-bubble{background:var(--primary);color:#fff;border-bottom-right-radius:4px}
.cw-msg.received .cw-msg-bubble{background:#fff;color:#1a1a1a;border:1px solid var(--border);border-bottom-left-radius:4px}
.cw-msg-time{font-size:10px;color:#aaa;padding:0 4px}
.cw-msg.sent .cw-msg-time{text-align:right}
.cw-loading{display:flex;align-items:center;justify-content:center;gap:6px;padding:40px;color:#aaa;font-size:13px}
.cw-loading svg{width:16px;height:16px;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.cw-input-area{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--border);background:#fff;align-items:flex-end;flex-shrink:0}
.cw-input{flex:1;border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:13px;font-family:inherit;resize:none;outline:none;max-height:80px;transition:border-color .15s}
.cw-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.cw-send{width:38px;height:38px;border-radius:10px;background:var(--primary);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;flex-shrink:0}
.cw-send:hover{background:var(--primary-dark)}
.cw-send svg{width:16px;height:16px}
.cw-closed-notice{padding:12px;text-align:center;font-size:12px;color:#999;background:#F9F5E7;display:flex;align-items:center;justify-content:center;gap:6px;flex-shrink:0}
.cw-closed-notice svg{width:13px;height:13px}

.cw-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px;text-align:center;gap:8px}
.cw-empty-icon{width:64px;height:64px;border-radius:50%;background:var(--gold-light);display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.cw-empty-icon svg{width:28px;height:28px;color:var(--gold)}
.cw-empty-title{font-family:'Syne',sans-serif;font-weight:700;font-size:18px;color:#000;margin:0}
.cw-empty-sub{font-size:13px;color:#888;margin:0;max-width:220px}

.cw-new-form{padding:24px 20px;display:flex;flex-direction:column;gap:10px;flex:1;overflow-y:auto}
.cw-new-form h3{font-family:'Syne',sans-serif;font-weight:700;font-size:18px;color:#000;margin:0}
.cw-new-form p{font-size:13px;color:#888;margin:0 0 6px}
.cw-new-form label{font-size:12px;font-weight:600;color:#555}
.cw-field{width:100%;border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;transition:border-color .15s}
.cw-field:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.cw-new-icon{width:56px;height:56px;border-radius:50%;background:var(--gold-light);display:flex;align-items:center;justify-content:center;margin:0 auto 8px}
.cw-new-icon svg{width:24px;height:24px;color:var(--gold)}
.cw-start-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 24px;border-radius:8px;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;transition:background .15s;margin-top:6px}
.cw-start-btn:hover{background:var(--primary-dark)}
.cw-start-btn svg{width:14px;height:14px}

@media(max-width:480px){.cw-panel{width:calc(100vw - 16px);right:8px;bottom:8px;height:calc(100vh - 80px);border-radius:12px}.cw-fab{bottom:16px;right:16px}}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const SITE   = '<?= SITE_URL ?>';
    const fab    = document.getElementById('cwFab');
    const panel  = document.getElementById('cwPanel');
    const closeB = document.getElementById('cwCloseBtn');
    const backB  = document.getElementById('cwBackBtn');
    const newB   = document.getElementById('cwNewBtn');
    const viewList = document.getElementById('cwViewList');
    const viewChat = document.getElementById('cwViewChat');
    const viewNew  = document.getElementById('cwViewNew');
    const msgArea  = document.getElementById('cwMessages');
    const inputArea= document.getElementById('cwInputArea');
    const closedN  = document.getElementById('cwClosedNotice');
    const hdrTitle = document.getElementById('cwHeaderTitle');
    const hdrSub   = document.getElementById('cwHeaderSub');
    let activeConvId = null, pollTimer = null;

    if (!fab || !panel) return;

    function showView(v) {
        [viewList, viewChat, viewNew].forEach(el => { if(el) el.style.display = 'none'; });
        if (v) v.style.display = 'flex';
        backB.style.display = (v === viewChat || v === viewNew) ? '' : 'none';
        newB.style.display  = (v === viewList) ? '' : 'none';
    }

    
    fab.addEventListener('click', () => {
        const open = panel.classList.toggle('open');
        fab.classList.toggle('hidden', open);
        if (open && typeof lucide !== 'undefined') lucide.createIcons();
    });
    closeB.addEventListener('click', () => { panel.classList.remove('open'); fab.classList.remove('hidden'); stopPoll(); });

    
    backB.addEventListener('click', () => {
        showView(viewList); activeConvId = null; stopPoll();
        hdrTitle.textContent = 'Chat Support';
        hdrSub.textContent = 'We typically reply in minutes';
    });

    
    [newB, document.getElementById('cwStartNew')].forEach(b => {
        if (b) b.addEventListener('click', () => { showView(viewNew); hdrTitle.textContent = 'New Chat'; hdrSub.textContent = 'Tell us what you need'; });
    });

    
    document.querySelectorAll('.cw-conv-item').forEach(item => {
        item.addEventListener('click', () => loadConversation(item.dataset.cid, item.dataset.status));
    });

    function loadConversation(cid, status) {
        activeConvId = cid;
        showView(viewChat);
        msgArea.innerHTML = '<div class="cw-loading"><i data-lucide="loader-2"></i> Loading…</div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        fetch(`${SITE}/customer/get-messages.php?conversation_id=${cid}&all=1`)
            .then(r => r.json())
            .then(data => {
                if (!data.success && data.messages === undefined) {
                    
                    loadConvFallback(cid, status);
                    return;
                }
                renderMessages(data.messages || []);
                if (status === 'Closed') { inputArea.style.display='none'; closedN.style.display=''; }
                else { inputArea.style.display=''; closedN.style.display='none'; }
            })
            .catch(() => loadConvFallback(cid, status));

        
        const item = document.querySelector(`.cw-conv-item[data-cid="${cid}"]`);
        hdrTitle.textContent = item ? item.querySelector('.cw-conv-name').textContent : 'Chat';
        hdrSub.textContent = 'Support Team — Online';
        startPoll();
    }

    function loadConvFallback(cid, status) {
        fetch(`${SITE}/customer/chat.php?conversation=${cid}`)
            .then(r => r.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const msgs = doc.querySelectorAll('.chat-msg');
                const arr = [];
                msgs.forEach(m => {
                    arr.push({
                        sender_role: m.classList.contains('sent') ? 'Customer' : 'Admin',
                        message: m.querySelector('.chat-msg-bubble')?.textContent || '',
                        time: m.querySelector('.chat-msg-time')?.textContent || ''
                    });
                });
                renderMessages(arr);
                if (status === 'Closed') { inputArea.style.display='none'; closedN.style.display=''; }
                else { inputArea.style.display=''; closedN.style.display='none'; }
            });
    }

    function renderMessages(msgs) {
        msgArea.innerHTML = '';
        if (!msgs.length) {
            msgArea.innerHTML = '<div style="text-align:center;padding:40px;color:#aaa;font-size:13px">No messages yet. Start the conversation below.</div>';
            return;
        }
        msgs.forEach(m => {
            const role = (m.sender_role === 'Customer') ? 'sent' : 'received';
            const el = document.createElement('div');
            el.className = 'cw-msg ' + role;
            el.innerHTML = `<div class="cw-msg-bubble">${escHtml(m.message).replace(/\n/g,'<br>')}</div><div class="cw-msg-time">${escHtml(m.time)}</div>`;
            msgArea.appendChild(el);
        });
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function escHtml(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

    
    document.getElementById('cwSendBtn')?.addEventListener('click', sendMsg);
    document.getElementById('cwInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
    });

    function sendMsg() {
        const inp = document.getElementById('cwInput');
        const msg = inp?.value.trim();
        if (!msg || !activeConvId) return;

        fetch(`${SITE}/customer/send-message.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_message', conversation_id: activeConvId, message: msg })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const el = document.createElement('div');
                el.className = 'cw-msg sent';
                el.innerHTML = `<div class="cw-msg-bubble">${escHtml(msg).replace(/\n/g,'<br>')}</div><div class="cw-msg-time">Just now</div>`;
                msgArea.appendChild(el);
                inp.value = '';
                msgArea.scrollTop = msgArea.scrollHeight;
            }
        });
    }

    
    document.getElementById('cwCreateBtn')?.addEventListener('click', () => {
        const subj = document.getElementById('cwNewSubject')?.value.trim();
        const msg  = document.getElementById('cwNewMessage')?.value.trim();
        if (!subj || !msg) { alert('Please enter both a subject and message.'); return; }

        fetch(`${SITE}/customer/send-message.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'new_conversation', subject: subj, message: msg })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.conversation_id) {
                
                const listEl = document.getElementById('cwConvList') || viewList;
                const btn = document.createElement('button');
                btn.className = 'cw-conv-item';
                btn.dataset.cid = data.conversation_id;
                btn.dataset.status = 'Open';
                btn.innerHTML = `<div class="cw-conv-avatar">${subj[0].toUpperCase()}</div><div class="cw-conv-info"><div class="cw-conv-name">${escHtml(subj)}</div><div class="cw-conv-preview">${escHtml(msg.substring(0,45))}</div></div><div class="cw-conv-meta"><span class="cw-conv-time">Just now</span></div>`;
                btn.addEventListener('click', () => loadConversation(data.conversation_id, 'Open'));
                if (listEl.tagName === 'DIV' && listEl.id === 'cwConvList') listEl.prepend(btn);

                
                document.getElementById('cwNewSubject').value = '';
                document.getElementById('cwNewMessage').value = '';
                loadConversation(data.conversation_id, 'Open');
            } else {
                alert(data.message || 'Failed to create conversation.');
            }
        })
        .catch(() => alert('Network error. Please try again.'));
    });

    
    function startPoll() { stopPoll(); pollTimer = setInterval(pollNew, 5000); }
    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function pollNew() {
        if (!activeConvId) return;
        fetch(`${SITE}/customer/get-messages.php?conversation_id=${activeConvId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.messages?.length) {
                    data.messages.forEach(m => {
                        if (m.sender_role === 'Admin') {
                            const el = document.createElement('div');
                            el.className = 'cw-msg received';
                            el.innerHTML = `<div class="cw-msg-bubble">${escHtml(m.message).replace(/\n/g,'<br>')}</div><div class="cw-msg-time">${escHtml(m.time)}</div>`;
                            msgArea.appendChild(el);
                        }
                    });
                    msgArea.scrollTop = msgArea.scrollHeight;
                }
            }).catch(() => {});
    }
});
</script>
