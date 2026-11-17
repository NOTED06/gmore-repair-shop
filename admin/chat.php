<?php

$pageTitle = "Customer Chats";
require_once '../includes/header.php';

requireRole('Admin');

$db = getDB();

$conversation_id = isset($_GET['conversation']) ? (int)$_GET['conversation'] : null;

$filter = $_GET['filter'] ?? 'all';

$filterWhere = "";
if ($filter === 'open') {
    $filterWhere = "AND cc.status = 'Open'";
} elseif ($filter === 'closed') {
    $filterWhere = "AND cc.status = 'Closed'";
} elseif ($filter === 'unread') {
    $filterWhere = "AND EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id AND cm.is_read = 0 AND cm.sender_role = 'Customer')";
}

$stmt = $db->prepare("
    SELECT cc.*, 
           c.full_name, c.contact_no, u.email,
           (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id AND cm.is_read = 0 AND cm.sender_role = 'Customer') as unread_count,
           (SELECT message FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) as last_message,
           (SELECT created_at FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id ORDER BY cm.created_at DESC LIMIT 1) as last_message_time
    FROM chat_conversations cc
    JOIN customers c ON cc.customer_id = c.customer_id
    JOIN users u ON c.user_id = u.user_id
    WHERE 1=1 $filterWhere
    ORDER BY 
        CASE WHEN EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.conversation_id = cc.conversation_id AND cm.is_read = 0 AND cm.sender_role = 'Customer') THEN 0 ELSE 1 END,
        cc.updated_at DESC
");
$stmt->execute();
$conversations = $stmt->fetchAll();

$stmt = $db->query("SELECT COUNT(*) FROM chat_conversations WHERE status = 'Open'");
$openCount = $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(DISTINCT conversation_id) 
    FROM chat_messages 
    WHERE is_read = 0 AND sender_role = 'Customer'
");
$unreadCount = $stmt->fetchColumn();

$messages = [];
$currentConversation = null;
if ($conversation_id) {
    $stmt = $db->prepare("
        SELECT cc.*, c.full_name, c.contact_no, u.email
        FROM chat_conversations cc
        JOIN customers c ON cc.customer_id = c.customer_id
        JOIN users u ON c.user_id = u.user_id
        WHERE cc.conversation_id = ?
    ");
    $stmt->execute([$conversation_id]);
    $currentConversation = $stmt->fetch();
    
    if ($currentConversation) {
        
        $stmt = $db->prepare("
            SELECT * FROM chat_messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversation_id]);
        $messages = $stmt->fetchAll();
        
        
        $stmt = $db->prepare("
            UPDATE chat_messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_role = 'Customer' AND is_read = 0
        ");
        $stmt->execute([$conversation_id]);
    }
}
?>

<style>

.admin-page-wrapper {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
}

.page-header-modern {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
}
.page-header-modern h1 {
    margin: 0; font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);
    font-size: 28px; display: flex; align-items: center; gap: 12px;
}

.modern-filter-container {
    display: flex; gap: 16px; align-items: center; margin-bottom: 24px; flex-wrap: wrap;
}

.modern-view-toggle {
    display: flex; background: #fff; padding: 6px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid var(--border-color);
}
.modern-view-btn {
    padding: 8px 16px; border: none; background: transparent; color: var(--text-muted); font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px;
}
.modern-view-btn.active {
    background: var(--accent-indigo); color: #fff;
}

.modern-status-tabs {
    display: flex; gap: 12px;
}
.modern-tab {
    padding: 8px 16px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px;
    color: var(--text-muted); font-weight: 600; font-size: 14px; text-decoration: none; transition: all 0.2s;
    display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
.modern-tab:hover { background: #f8fafc; color: var(--text-dark); }
.modern-tab.active { background: #e0e7ff; border-color: #c7d2fe; color: #4338ca; }
.modern-tab-badge { background: #cbd5e1; color: #fff; padding: 2px 6px; border-radius: 6px; font-size: 11px; }
.modern-tab.active .modern-tab-badge { background: #4f46e5; }

.modern-admin-card {
    background: #fff; border-radius: 20px; box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 32px;
}
.modern-table { width: 100%; border-collapse: collapse; }
.modern-table th {
    background: #f8fafc; text-align: left; padding: 16px 24px;
    font-size: 12px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
}
.modern-table td {
    padding: 20px 24px; border-bottom: 1px solid var(--border-color); vertical-align: middle;
}
.modern-table tr:hover td { background: #f8fafc; }
.modern-table tr.unread-row { background: #fefce8; }
.modern-table tr.unread-row:hover td { background: #fef9c3; }

.table-id { font-weight: 700; color: var(--text-dark); font-size: 15px; }
.table-main-text { font-weight: 600; color: var(--text-dark); margin-bottom: 4px; display: block; }
.table-meta-text { font-size: 13px; color: var(--text-muted); }

.action-btn-group { display: flex; gap: 6px; flex-wrap: nowrap; justify-content: flex-end; }
.admin-action-btn {
    width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
    border: none; cursor: pointer; transition: all 0.2s; color: #fff; font-size: 14px; text-decoration: none;
}
.btn-view { background: #e0f2fe; color: #0284c7; }
.btn-view:hover { background: #bae6fd; }
.btn-close { background: #fee2e2; color: #dc2626; }
.btn-close:hover { background: #fecaca; }
.btn-reopen { background: #dcfce7; color: #16a34a; }
.btn-reopen:hover { background: #bbf7d0; }

.admin-badge {
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block;
}
.bg-open { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.bg-closed { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

.chat-preview { font-size: 13px; color: var(--text-muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<div class="admin-page-wrapper">
    <div class="page-header-modern">
        <h1><i class="fas fa-comments" style="color: var(--accent-indigo);"></i> Customer Chats</h1>
    </div>

    <div class="modern-filter-container">
        <div class="modern-view-toggle">
            <button class="modern-view-btn <?= !$conversation_id ? 'active' : '' ?>" onclick="showTableView()">
                <i class="fas fa-table"></i> Table
            </button>
            <button class="modern-view-btn <?= $conversation_id ? 'active' : '' ?>" onclick="showChatView()">
                <i class="fas fa-comments"></i> Chat
            </button>
        </div>
        
        <div class="modern-status-tabs">
            <a href="chat.php?filter=all" class="modern-tab <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="chat.php?filter=unread" class="modern-tab <?= $filter === 'unread' ? 'active' : '' ?>">
                Unread <span class="modern-tab-badge"><?= $unreadCount ?></span>
            </a>
            <a href="chat.php?filter=open" class="modern-tab <?= $filter === 'open' ? 'active' : '' ?>">
                Open <span class="modern-tab-badge"><?= $openCount ?></span>
            </a>
            <a href="chat.php?filter=closed" class="modern-tab <?= $filter === 'closed' ? 'active' : '' ?>">Closed</a>
        </div>
    </div>
    
    <div id="tableView" style="<?= $conversation_id ? 'display:none;' : '' ?>">
        <div class="modern-admin-card">
            <?php if (empty($conversations)): ?>
                <div style="text-align: center; padding: 64px 24px;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 32px; color: #94a3b8;">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3 style="margin: 0 0 8px; font-size: 20px; font-weight: 700; color: var(--text-dark);">No Conversations Found</h3>
                    <p style="margin: 0; color: var(--text-muted);">There are no chat conversations matching your criteria.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Customer</th>
                                <th>Subject</th>
                                <th style="width: 300px;">Last Message</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conversations as $index => $conv): ?>
                                <tr class="<?= $conv['unread_count'] > 0 ? 'unread-row' : '' ?>">
                                    <td><span class="table-id"><?= $index + 1 ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 40px; height: 40px; border-radius: 10px; background: var(--accent-indigo); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;">
                                                <?= strtoupper(substr($conv['full_name'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <span class="table-main-text"><?= htmlspecialchars($conv['full_name']) ?></span>
                                                <span class="table-meta-text"><?= htmlspecialchars($conv['email']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="table-main-text" style="font-weight: 500;"><?= htmlspecialchars($conv['subject'] ?: 'No Subject') ?></span>
                                    </td>
                                    <td>
                                        <div class="chat-preview">
                                            <?= htmlspecialchars($conv['last_message'] ?? 'No messages') ?>
                                        </div>
                                        <?php if ($conv['unread_count'] > 0): ?>
                                            <span style="display: inline-block; background: #f59e0b; color: white; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-top: 4px;"><?= $conv['unread_count'] ?> new</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="admin-badge <?= strtolower($conv['status']) === 'open' ? 'bg-open' : 'bg-closed' ?>">
                                            <?= $conv['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--text-dark); font-size: 14px; font-weight: 500; display: block;"><?= date('M j, Y', strtotime($conv['created_at'])) ?></span>
                                        <span class="table-meta-text" style="font-size: 12px;"><?= date('g:i A', strtotime($conv['updated_at'])) ?></span>
                                    </td>
                                    <td>
                                        <div class="action-btn-group">
                                            <a href="chat.php?conversation=<?= $conv['conversation_id'] ?>&filter=<?= $filter ?>" class="admin-action-btn btn-view" title="View Chat">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($conv['status'] === 'Open'): ?>
                                                <button type="button" class="admin-action-btn btn-close" onclick="closeConversation(<?= $conv['conversation_id'] ?>)" title="Close Chat">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="admin-action-btn btn-reopen" onclick="reopenConversation(<?= $conv['conversation_id'] ?>)" title="Reopen Chat">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="chat-container" id="chatView" style="<?= !$conversation_id ? 'display:none;' : '' ?>">
        
        <div class="chat-sidebar <?= $conversation_id ? 'hidden-mobile' : '' ?>">
            <div class="chat-sidebar-header">
                <h3>Conversations</h3>
                <span class="chat-count"><?= count($conversations) ?> chats</span>
            </div>
            <div class="conversations-list" id="conversationsList">
                <?php if (empty($conversations)): ?>
                <div class="no-conversations">
                    <i class="fas fa-inbox"></i>
                    <p>No conversations yet</p>
                </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                    <a href="chat.php?conversation=<?= $conv['conversation_id'] ?>&filter=<?= $filter ?>" 
                       class="conversation-item <?= $conversation_id == $conv['conversation_id'] ? 'active' : '' ?> <?= $conv['unread_count'] > 0 ? 'unread' : '' ?>">
                        <div class="conversation-avatar">
                            <?= strtoupper(substr($conv['full_name'], 0, 2)) ?>
                        </div>
                        <div class="conversation-info">
                            <div class="conversation-header">
                                <span class="conversation-name"><?= htmlspecialchars($conv['full_name']) ?></span>
                                <span class="conversation-time">
                                    <?= $conv['last_message_time'] ? timeAgo($conv['last_message_time']) : '' ?>
                                </span>
                            </div>
                            <div class="conversation-subject">
                                <?= htmlspecialchars($conv['subject'] ?: 'No Subject') ?>
                            </div>
                            <div class="conversation-preview">
                                <span class="preview-text"><?= htmlspecialchars(substr($conv['last_message'] ?? 'No messages yet', 0, 40)) ?>...</span>
                                <?php if ($conv['unread_count'] > 0): ?>
                                <span class="unread-badge"><?= $conv['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="status-dot <?= strtolower($conv['status']) ?>"></span>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="chat-main <?= !$conversation_id ? 'hidden-mobile' : '' ?>">
            <?php if ($currentConversation): ?>
            
            <div class="chat-header">
                <a href="chat.php?filter=<?= $filter ?>" class="btn-back-mobile">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="chat-header-info">
                    <div class="chat-avatar customer">
                        <?= strtoupper(substr($currentConversation['full_name'], 0, 2)) ?>
                    </div>
                    <div class="chat-header-text">
                        <h4><?= htmlspecialchars($currentConversation['full_name']) ?></h4>
                        <span class="customer-email"><?= htmlspecialchars($currentConversation['email']) ?></span>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <?php if ($currentConversation['status'] === 'Open'): ?>
                    <button class="btn btn-sm btn-outline-danger" onclick="closeConversation(<?= $conversation_id ?>)">
                        <i class="fas fa-times-circle"></i> Close Chat
                    </button>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline-success" onclick="reopenConversation(<?= $conversation_id ?>)">
                        <i class="fas fa-check-circle"></i> Reopen Chat
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            
            <div class="chat-subject-bar">
                <strong>Subject:</strong> <?= htmlspecialchars($currentConversation['subject'] ?: 'No Subject') ?>
                <span class="status-badge <?= strtolower($currentConversation['status']) ?>">
                    <?= $currentConversation['status'] ?>
                </span>
            </div>

            
            <div class="chat-messages" id="chatMessages">
                <?php if (empty($messages)): ?>
                <div class="chat-empty">
                    <i class="fas fa-comments"></i>
                    <p>No messages in this conversation</p>
                </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <div class="message <?= $msg['sender_role'] === 'Admin' ? 'sent' : 'received' ?>">
                        <div class="message-content">
                            <p><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                            <span class="message-time"><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            
            <?php if ($currentConversation['status'] === 'Open'): ?>
            <div class="chat-input-area">
                <form id="messageForm" onsubmit="sendMessage(event)">
                    <input type="hidden" id="conversationId" value="<?= $conversation_id ?>">
                    <div class="input-group">
                        <textarea id="messageInput" placeholder="Type your reply..." rows="1" required></textarea>
                        <button type="submit" class="btn-send">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="chat-closed-notice">
                <i class="fas fa-lock"></i>
                <span>This conversation is closed. Reopen to reply.</span>
            </div>
            <?php endif; ?>

            <?php else: ?>
            
            <div class="chat-placeholder">
                <div class="placeholder-content">
                    <i class="fas fa-comments"></i>
                    <h3>Select a conversation</h3>
                    <p>Choose a customer conversation to view and respond</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>

.view-toggle {
    display: flex;
    background: #f3f4f6;
    border-radius: 10px;
    padding: 4px;
    margin-right: 1rem;
}

.view-btn {
    padding: 0.5rem 1rem;
    border: none;
    background: transparent;
    color: #6b7280;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s;
}

.view-btn:hover {
    color: #374151;
}

.view-btn.active {
    background: white;
    color: #6366f1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.chat-table-view {
    margin-top: 0;
}

.chat-table-view .card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    overflow: hidden;
}

.chat-table-view .card-body {
    padding: 0;
}

.chat-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.chat-table thead {
    background: linear-gradient(135deg, #272343 0%, #3d3a5c 100%);
}

.chat-table thead th {
    padding: 1rem 1.25rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.8rem;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
}

.chat-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.2s;
}

.chat-table tbody tr:hover {
    background: #f9fafb;
}

.chat-table tbody tr.unread-row {
    background: linear-gradient(90deg, #fef3c7 0%, #fffbeb 100%);
}

.chat-table tbody tr.unread-row:hover {
    background: linear-gradient(90deg, #fde68a 0%, #fef3c7 100%);
}

.chat-table tbody td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #374151;
}

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: #f3f4f6;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.8rem;
    color: #6b7280;
}

.customer-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.customer-avatar-sm {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}

.customer-info-cell {
    display: flex;
    flex-direction: column;
}

.customer-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.9rem;
}

.customer-email-sm {
    font-size: 0.75rem;
    color: #9ca3af;
}

.subject-text {
    font-weight: 500;
    color: #374151;
}

.message-preview-cell {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.preview-msg {
    color: #6b7280;
    font-size: 0.85rem;
}

.unread-count {
    display: inline-flex;
    align-items: center;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    font-size: 0.65rem;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-weight: 700;
    width: fit-content;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pill i {
    font-size: 0.5rem;
}

.status-pill.open {
    background: #dcfce7;
    color: #15803d;
}

.status-pill.closed {
    background: #fee2e2;
    color: #dc2626;
}

.date-cell {
    display: block;
    font-weight: 500;
    color: #374151;
    font-size: 0.85rem;
}

.time-cell {
    display: block;
    font-size: 0.75rem;
    color: #9ca3af;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-action {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-view {
    background: #eef2ff;
    color: #6366f1;
}

.btn-view:hover {
    background: #6366f1;
    color: white;
}

.btn-close-chat {
    background: #fee2e2;
    color: #dc2626;
}

.btn-close-chat:hover {
    background: #dc2626;
    color: white;
}

.btn-reopen {
    background: #dcfce7;
    color: #16a34a;
}

.btn-reopen:hover {
    background: #16a34a;
    color: white;
}

.empty-state {
    padding: 3rem;
    text-align: center;
    color: #9ca3af;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state p {
    margin: 0;
    font-size: 0.95rem;
}

.filter-tabs {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.filter-tab {
    padding: 0.6rem 1.25rem;
    border-radius: 10px;
    text-decoration: none;
    color: #6b7280;
    font-size: 0.875rem;
    font-weight: 600;
    transition: all 0.2s;
    background: #f3f4f6;
    border: 2px solid transparent;
}

.filter-tab:hover {
    background: #e5e7eb;
    color: #374151;
}

.filter-tab.active {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.filter-tab .badge {
    background: rgba(255,255,255,0.25);
    padding: 0.15rem 0.5rem;
    border-radius: 8px;
    font-size: 0.7rem;
    margin-left: 0.35rem;
    font-weight: 700;
}

.filter-tab.active .badge {
    background: rgba(255,255,255,0.35);
}

.chat-container {
    display: flex;
    height: calc(100vh - 200px);
    min-height: 550px;
    background: #ffffff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.chat-sidebar {
    width: 360px;
    background: #fafbfc;
    border-right: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
}

.chat-sidebar-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #272343 0%, #3d3a5c 100%);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-sidebar-header h3 {
    margin: 0;
    font-size: 1.1rem;
    color: white;
    font-weight: 600;
}

.chat-count {
    font-size: 0.8rem;
    color: rgba(255,255,255,0.7);
    background: rgba(255,255,255,0.1);
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
}

.conversations-list {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
}

.no-conversations {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 1.5rem;
    text-align: center;
}

.no-conversations i {
    font-size: 3.5rem;
    color: #d1d5db;
    margin-bottom: 1rem;
}

.no-conversations p {
    margin: 0;
    color: #6b7280;
}

.conversation-item {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 1rem;
    margin-bottom: 0.375rem;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.2s ease;
    background: white;
    border: 1px solid transparent;
    position: relative;
}

.conversation-item:hover {
    background: #f3f4f6;
    border-color: #e5e7eb;
}

.conversation-item.active {
    background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
    border-color: #6366f1;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
}

.conversation-item.unread {
    background: linear-gradient(135deg, #fefce8 0%, #fef9c3 100%);
    border-color: #fbbf24;
}

.conversation-item.unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 70%;
    background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
    border-radius: 0 4px 4px 0;
}

.conversation-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.85rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
}

.conversation-info {
    flex: 1;
    min-width: 0;
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.2rem;
}

.conversation-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 0.9rem;
}

.conversation-time {
    font-size: 0.7rem;
    color: #9ca3af;
    font-weight: 500;
}

.conversation-subject {
    font-size: 0.78rem;
    color: #6366f1;
    font-weight: 600;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-preview {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.preview-text {
    font-size: 0.8rem;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.unread-badge {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    font-size: 0.65rem;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    border-radius: 9px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(255,255,255,0.8);
}

.status-dot.open {
    background: #22c55e;
}

.status-dot.closed {
    background: #ef4444;
}

.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #ffffff;
}

.chat-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    background: #ffffff;
}

.btn-back-mobile {
    display: none;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f3f4f6;
    color: #374151;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.chat-header-info {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex: 1;
}

.chat-avatar.customer {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.85rem;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
}

.chat-header-text h4 {
    margin: 0 0 0.2rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
}

.customer-email {
    font-size: 0.8rem;
    color: #6b7280;
}

.chat-header-actions {
    display: flex;
    gap: 0.5rem;
}

.chat-subject-bar {
    padding: 0.75rem 1.5rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    color: #374151;
}

.chat-subject-bar strong {
    color: #6b7280;
}

.status-badge {
    font-size: 0.65rem;
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.status-badge.open {
    background: #dcfce7;
    color: #15803d;
}

.status-badge.closed {
    background: #fee2e2;
    color: #dc2626;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: linear-gradient(180deg, #fafbfc 0%, #ffffff 100%);
}

.chat-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #9ca3af;
}

.chat-empty i {
    font-size: 4rem;
    margin-bottom: 1.25rem;
    opacity: 0.4;
    color: #6366f1;
}

.chat-empty p {
    font-size: 0.95rem;
    color: #6b7280;
}

.message {
    display: flex;
    max-width: 70%;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.message.sent {
    align-self: flex-end;
}

.message.received {
    align-self: flex-start;
}

.message-content {
    padding: 0.875rem 1.125rem;
    border-radius: 18px;
    position: relative;
}

.message.sent .message-content {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    border-bottom-right-radius: 6px;
    box-shadow: 0 2px 12px rgba(99, 102, 241, 0.3);
}

.message.received .message-content {
    background: #f3f4f6;
    color: #1f2937;
    border-bottom-left-radius: 6px;
    border: 1px solid #e5e7eb;
}

.message-content p {
    margin: 0 0 0.35rem 0;
    line-height: 1.5;
    font-size: 0.9rem;
}

.message-time {
    font-size: 0.65rem;
    opacity: 0.7;
    display: block;
    text-align: right;
    margin-top: 0.25rem;
}

.chat-input-area {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    background: #ffffff;
}

.input-group {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
}

.input-group textarea {
    flex: 1;
    resize: none;
    border: 2px solid #e5e7eb;
    border-radius: 24px;
    padding: 0.875rem 1.25rem;
    font-size: 0.9rem;
    max-height: 120px;
    line-height: 1.5;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.input-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.input-group textarea::placeholder {
    color: #9ca3af;
}

.btn-send {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
    flex-shrink: 0;
}

.btn-send:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
}

.btn-send:active {
    transform: scale(0.95);
}

.chat-closed-notice {
    padding: 1rem 1.5rem;
    text-align: center;
    background: #fef3c7;
    color: #92400e;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    font-weight: 500;
}

.chat-placeholder {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(180deg, #fafbfc 0%, #ffffff 100%);
}

.placeholder-content {
    text-align: center;
    padding: 2rem;
}

.placeholder-content i {
    font-size: 5rem;
    margin-bottom: 1.5rem;
    color: #e5e7eb;
}

.placeholder-content h3 {
    margin: 0 0 0.5rem 0;
    color: #374151;
    font-size: 1.25rem;
    font-weight: 600;
}

.placeholder-content p {
    margin-bottom: 1.75rem;
    color: #6b7280;
    font-size: 0.95rem;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
    border-radius: 8px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-outline-danger {
    color: #dc2626;
    border: 2px solid #dc2626;
    background: transparent;
}

.btn-outline-danger:hover {
    background: #dc2626;
    color: white;
}

.btn-outline-success {
    color: #16a34a;
    border: 2px solid #16a34a;
    background: transparent;
}

.btn-outline-success:hover {
    background: #16a34a;
    color: white;
}

@media (max-width: 768px) {
    .chat-container {
        height: calc(100vh - 150px);
        border-radius: 12px;
    }
    
    .chat-sidebar {
        width: 100%;
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        z-index: 10;
        background: #fafbfc;
    }
    
    .chat-sidebar.hidden-mobile {
        display: none;
    }
    
    .chat-main.hidden-mobile {
        display: none;
    }
    
    .btn-back-mobile {
        display: flex;
    }
    
    .message {
        max-width: 85%;
    }
    
    .chat-messages {
        padding: 1rem;
    }
    
    .filter-tabs {
        gap: 0.35rem;
    }
    
    .filter-tab {
        padding: 0.5rem 0.75rem;
        font-size: 0.8rem;
    }
}
</style>

<script>

function showTableView() {
    document.getElementById('tableView').style.display = 'block';
    document.getElementById('chatView').style.display = 'none';
    
    
    document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.view-btn').classList.add('active');
    
    
    const url = new URL(window.location);
    url.searchParams.delete('conversation');
    window.history.pushState({}, '', url);
}

function showChatView() {
    document.getElementById('tableView').style.display = 'none';
    document.getElementById('chatView').style.display = 'grid';
    
    
    document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.view-btn').classList.add('active');
}

document.getElementById('messageInput')?.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});

function scrollToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

scrollToBottom();

async function sendMessage(e) {
    e.preventDefault();
    
    const messageInput = document.getElementById('messageInput');
    const conversationId = document.getElementById('conversationId').value;
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    messageInput.disabled = true;
    
    try {
        const response = await fetch('<?= SITE_URL ?>/admin/send-message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                message: message
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            const chatMessages = document.getElementById('chatMessages');
            const emptyMsg = chatMessages.querySelector('.chat-empty');
            if (emptyMsg) emptyMsg.remove();
            
            const msgDiv = document.createElement('div');
            msgDiv.className = 'message sent';
            msgDiv.innerHTML = `
                <div class="message-content">
                    <p>${escapeHtml(message)}</p>
                    <span class="message-time">Just now</span>
                </div>
            `;
            chatMessages.appendChild(msgDiv);
            
            messageInput.value = '';
            messageInput.style.height = 'auto';
            scrollToBottom();
        } else {
            Swal.fire({icon:'error',title:'Error',text:data.message || 'Failed to send message',confirmButtonColor:'#272343'});
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({icon:'error',title:'Error',text:'An error occurred. Please try again.',confirmButtonColor:'#272343'});
    }
    
    messageInput.disabled = false;
    messageInput.focus();
}

async function closeConversation(conversationId) {
    const swalResult = await Swal.fire({
        title: 'Close Conversation',
        text: 'Are you sure you want to close this conversation?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#272343',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, close it!'
    });
    if (!swalResult.isConfirmed) return;
    
    try {
        const response = await fetch('<?= SITE_URL ?>/admin/update-conversation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                action: 'close'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            await Swal.fire({icon:'success',title:'Closed!',text:'Conversation has been closed.',confirmButtonColor:'#272343',timer:2000,timerProgressBar:true});
            location.reload();
        } else {
            Swal.fire({icon:'error',title:'Error',text:data.message || 'Failed to close conversation',confirmButtonColor:'#272343'});
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({icon:'error',title:'Error',text:'An error occurred',confirmButtonColor:'#272343'});
    }
}

async function reopenConversation(conversationId) {
    try {
        const response = await fetch('<?= SITE_URL ?>/admin/update-conversation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                action: 'reopen'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            await Swal.fire({icon:'success',title:'Reopened!',text:'Conversation has been reopened.',confirmButtonColor:'#272343',timer:2000,timerProgressBar:true});
            location.reload();
        } else {
            Swal.fire({icon:'error',title:'Error',text:data.message || 'Failed to reopen conversation',confirmButtonColor:'#272343'});
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({icon:'error',title:'Error',text:'An error occurred',confirmButtonColor:'#272343'});
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

<?php if ($conversation_id): ?>
setInterval(async function() {
    try {
        const response = await fetch('<?= SITE_URL ?>/admin/get-messages.php?conversation_id=<?= $conversation_id ?>');
        const data = await response.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            const chatMessages = document.getElementById('chatMessages');
            const emptyMsg = chatMessages.querySelector('.chat-empty');
            if (emptyMsg) emptyMsg.remove();
            
            data.messages.forEach(msg => {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'message ' + (msg.sender_role === 'Admin' ? 'sent' : 'received');
                msgDiv.innerHTML = `
                    <div class="message-content">
                        <p>${escapeHtml(msg.message)}</p>
                        <span class="message-time">${msg.time}</span>
                    </div>
                `;
                chatMessages.appendChild(msgDiv);
            });
            
            scrollToBottom();
        }
    } catch (error) {
        console.error('Poll error:', error);
    }
}, 5000);
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
