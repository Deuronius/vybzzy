<?php
/**
 * Threaded Chat Application with PHP
 */

$messages_file = "messages.json";
$messages_buffer_size = 1000;

// Handle POST request for new messages
if (isset($_POST["content"]) && isset($_POST["name"])) {
    if (!file_exists($messages_file))
        touch($messages_file);

    $buffer = fopen($messages_file, "r+b");
    flock($buffer, LOCK_EX);
    $buffer_data = stream_get_contents($buffer);

    $messages = $buffer_data ? json_decode($buffer_data, true) : [];
    
    // Check word count
    $word_count = str_word_count($_POST["content"]);
    if ($word_count > 50) {
        flock($buffer, LOCK_UN);
        fclose($buffer);
        header('Content-Type: application/json');
        echo json_encode(["error" => "Message exceeds 50 words"]);
        exit();
    }

    $next_id = (count($messages) > 0) ? max(array_column($messages, 'id')) + 1 : 0;
    
    $new_message = [
        "id" => $next_id,
        "time" => time(),
        "name" => $_POST["name"],
        "content" => $_POST["content"],
        "parentId" => isset($_POST["parentId"]) ? (int)$_POST["parentId"] : null,
        "replies" => []
    ];

    // If it's a reply, add to parent's replies array
    if ($new_message["parentId"] !== null) {
        $parent_found = false;
        foreach ($messages as &$msg) {
            if ($msg["id"] === $new_message["parentId"]) {
                $msg["replies"][] = $new_message;
                $parent_found = true;
                break;
            }
            // Check nested replies
            if (isset($msg["replies"])) {
                foreach ($msg["replies"] as &$reply) {
                    if ($reply["id"] === $new_message["parentId"]) {
                        $reply["replies"][] = $new_message;
                        $parent_found = true;
                        break 2;
                    }
                }
            }
        }
        unset($msg, $reply);
    } else {
        // Top-level message
        $messages[] = $new_message;
    }

    // Keep only last N messages
    if (count($messages) > $messages_buffer_size)
        $messages = array_slice($messages, count($messages) - $messages_buffer_size);

    ftruncate($buffer, 0);
    rewind($buffer);
    fwrite($buffer, json_encode($messages));
    flock($buffer, LOCK_UN);
    fclose($buffer);

    header('Content-Type: application/json');
    echo json_encode($new_message);
    exit();
}

// Handle GET request for messages
if (isset($_GET["fetch"])) {
    if (file_exists($messages_file)) {
        $buffer_data = file_get_contents($messages_file);
        $messages = $buffer_data ? json_decode($buffer_data, true) : [];
        header('Content-Type: application/json');
        echo json_encode($messages);
    } else {
        header('Content-Type: application/json');
        echo json_encode([]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chat Room</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      background: #1a1a1a;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }

    #app {
      width: 100%;
      max-width: 380px;
      height: 95vh;
      max-height: 750px;
      background: #2d2d2d;
      border-radius: 8px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }

    #search-bar {
      padding: 15px;
      background: #1f1f1f;
      border-bottom: 1px solid #444;
    }

    #search-input {
      width: 100%;
      padding: 10px;
      background: #3a3a3a;
      border: 1px solid #555;
      border-radius: 6px;
      color: #fff;
      font-size: 14px;
    }

    #search-input::placeholder {
      color: #888;
    }

    #timeline {
      flex: 1;
      overflow-y: auto;
      padding: 15px;
      background: #2d2d2d;
    }

    #timeline::-webkit-scrollbar {
      width: 6px;
    }

    #timeline::-webkit-scrollbar-track {
      background: #1f1f1f;
    }

    #timeline::-webkit-scrollbar-thumb {
      background: #555;
      border-radius: 3px;
    }

    .message {
      margin-bottom: 12px;
      padding: 12px;
      background: #3a3a3a;
      border-radius: 8px;
      border-left: 3px solid #5a5a5a;
      cursor: pointer;
      transition: all 0.2s;
    }

    .message:hover {
      background: #424242;
      border-left-color: #777;
    }

    .message.selected {
      background: #4a4a4a;
      border-left-color: #888;
    }

    .message-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 6px;
    }

    .message-name {
      color: #4a9eff;
      font-weight: bold;
      font-size: 13px;
    }

    .message-time {
      color: #888;
      font-size: 11px;
    }

    .message-text {
      color: #e0e0e0;
      font-size: 14px;
      line-height: 1.4;
      word-wrap: break-word;
    }

    .message-text a {
      color: #4a9eff;
      text-decoration: underline;
    }

    .replies {
      margin-top: 8px;
      margin-left: 20px;
      padding-left: 12px;
      border-left: 2px solid #555;
      display: none;
    }

    .replies.show {
      display: block;
    }

    .reply {
      margin-top: 8px;
      padding: 8px;
      background: #333;
      border-radius: 6px;
    }

    .reply .message-name {
      color: #ffa94a;
    }

    #input-area {
      padding: 15px;
      background: #1f1f1f;
      border-top: 1px solid #444;
    }

    #reply-indicator {
      padding: 8px;
      background: #3a3a3a;
      border-radius: 6px;
      margin-bottom: 8px;
      display: none;
      font-size: 12px;
      color: #888;
    }

    #reply-indicator.show {
      display: block;
    }

    #reply-indicator span {
      color: #4a9eff;
      font-weight: bold;
    }

    #reply-indicator button {
      float: right;
      background: none;
      border: none;
      color: #888;
      cursor: pointer;
      font-size: 14px;
    }

    #name-input {
      width: 100%;
      padding: 8px;
      margin-bottom: 8px;
      background: #3a3a3a;
      border: 1px solid #555;
      border-radius: 6px;
      color: #fff;
      font-size: 13px;
    }

    #message-input {
      width: 100%;
      padding: 10px;
      background: #3a3a3a;
      border: 1px solid #555;
      border-radius: 6px;
      color: #fff;
      font-size: 14px;
      resize: none;
      height: 60px;
      margin-bottom: 8px;
    }

    #message-input::placeholder, #name-input::placeholder {
      color: #888;
    }

    .input-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    #word-count {
      color: #888;
      font-size: 12px;
    }

    #word-count.warning {
      color: #ff6b6b;
    }

    #send-btn {
      padding: 10px 24px;
      background: #e63946;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.2s;
    }

    #send-btn:hover {
      background: #d62839;
    }

    #send-btn:disabled {
      background: #555;
      cursor: not-allowed;
    }

    .star-rating {
      color: #ffd700;
      font-size: 12px;
      margin-right: 5px;
    }
  </style>
</head>
<body>
  <div id="app">
    <div id="search-bar">
      <input type="text" id="search-input" placeholder="Search...">
    </div>

    <div id="timeline"></div>

    <div id="input-area">
      <div id="reply-indicator">
        Replying to <span id="reply-to"></span>
        <button id="cancel-reply">✕</button>
      </div>
      <input type="text" id="name-input" placeholder="Name:" value="Anonymous">
      <textarea id="message-input" placeholder="Message (50 Words Max)"></textarea>
      <div class="input-footer">
        <span id="word-count">0 / 50 words</span>
        <button id="send-btn">SEND</button>
      </div>
    </div>
  </div>

  <script>
    let allMessages = [];
    let selectedMessageId = null;
    let searchTerm = '';

    const timeline = document.getElementById('timeline');
    const searchInput = document.getElementById('search-input');
    const nameInput = document.getElementById('name-input');
    const messageInput = document.getElementById('message-input');
    const sendBtn = document.getElementById('send-btn');
    const wordCountEl = document.getElementById('word-count');
    const replyIndicator = document.getElementById('reply-indicator');
    const replyToEl = document.getElementById('reply-to');
    const cancelReplyBtn = document.getElementById('cancel-reply');

    // Load messages
    async function loadMessages() {
      try {
        const response = await fetch('?fetch=1');
        allMessages = await response.json();
        renderMessages();
      } catch (err) {
        console.error('Error loading messages:', err);
      }
    }

    // Linkify text
    function linkify(text) {
      const urlRegex = /(https?:\/\/[^\s]+)/g;
      return text.replace(urlRegex, '<a href="$1" target="_blank">$1</a>');
    }

    // Highlight search terms
    function highlightText(text, term) {
      if (!term) return linkify(text);
      const regex = new RegExp(`(${term})`, 'gi');
      return linkify(text).replace(regex, '<mark style="background: #ffd700; color: #000;">$1</mark>');
    }

    // Check if message matches search
    function matchesSearch(msg, term) {
      if (!term) return true;
      const lowerTerm = term.toLowerCase();
      return msg.name.toLowerCase().includes(lowerTerm) || 
             msg.content.toLowerCase().includes(lowerTerm);
    }

    // Render messages
    function renderMessages() {
      timeline.innerHTML = '';
      
      const filteredMessages = searchTerm 
        ? allMessages.filter(msg => matchesSearch(msg, searchTerm))
        : allMessages;

      filteredMessages.forEach(msg => {
        renderMessage(msg, timeline);
      });

      scrollToBottom();
    }

    // Render single message
    function renderMessage(msg, container) {
      const messageDiv = document.createElement('div');
      messageDiv.className = 'message';
      messageDiv.dataset.id = msg.id;
      
      if (selectedMessageId === msg.id) {
        messageDiv.classList.add('selected');
      }

      const stars = '⭐'.repeat(Math.min(Math.floor(msg.content.split(' ').length / 200), 5));
      
      const time = new Date(msg.time * 1000).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
      });

      messageDiv.innerHTML = `
        <div class="message-header">
          <span class="message-name">${stars ? '<span class="star-rating">' + stars + '</span>' : ''}${msg.name}</span>
          <span class="message-time">${time}</span>
        </div>
        <div class="message-text">${highlightText(msg.content, searchTerm)}</div>
        <div class="replies" id="replies-${msg.id}"></div>
      `;

      messageDiv.addEventListener('click', (e) => {
        if (e.target.tagName === 'A') return;
        toggleMessage(msg.id, msg.name);
      });

      container.appendChild(messageDiv);

      // Render replies if expanded
      if (selectedMessageId === msg.id && msg.replies && msg.replies.length > 0) {
        const repliesDiv = document.getElementById(`replies-${msg.id}`);
        repliesDiv.classList.add('show');
        msg.replies.forEach(reply => {
          const replyDiv = document.createElement('div');
          replyDiv.className = 'reply';
          const replyTime = new Date(reply.time * 1000).toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
          });
          replyDiv.innerHTML = `
            <div class="message-header">
              <span class="message-name">${reply.name}</span>
              <span class="message-time">${replyTime}</span>
            </div>
            <div class="message-text">${linkify(reply.content)}</div>
          `;
          repliesDiv.appendChild(replyDiv);
        });
      }
    }

    // Toggle message selection
    function toggleMessage(id, name) {
      const repliesDiv = document.getElementById(`replies-${id}`);
      
      if (selectedMessageId === id) {
        selectedMessageId = null;
        replyIndicator.classList.remove('show');
        if (repliesDiv) repliesDiv.classList.remove('show');
      } else {
        selectedMessageId = id;
        replyToEl.textContent = name;
        replyIndicator.classList.add('show');
      }
      
      renderMessages();
    }

    // Cancel reply
    cancelReplyBtn.addEventListener('click', () => {
      selectedMessageId = null;
      replyIndicator.classList.remove('show');
      renderMessages();
    });

    // Word count
    messageInput.addEventListener('input', () => {
      const text = messageInput.value.trim();
      const words = text ? text.split(/\s+/).length : 0;
      wordCountEl.textContent = `${words} / 50 words`;
      
      if (words > 50) {
        wordCountEl.classList.add('warning');
        sendBtn.disabled = true;
      } else {
        wordCountEl.classList.remove('warning');
        sendBtn.disabled = false;
      }
    });

    // Search
    searchInput.addEventListener('input', () => {
      searchTerm = searchInput.value.trim();
      renderMessages();
    });

    // Send message
    sendBtn.addEventListener('click', async () => {
      const name = nameInput.value.trim() || 'Anonymous';
      const content = messageInput.value.trim();
      
      if (!content) return;

      const words = content.split(/\s+/).length;
      if (words > 50) {
        alert('Message exceeds 50 words!');
        return;
      }

      try {
        const formData = new URLSearchParams();
        formData.append('name', name);
        formData.append('content', content);
        if (selectedMessageId !== null) {
          formData.append('parentId', selectedMessageId);
        }

        const response = await fetch('', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: formData
        });

        if (response.ok) {
          messageInput.value = '';
          wordCountEl.textContent = '0 / 50 words';
          selectedMessageId = null;
          replyIndicator.classList.remove('show');
          await loadMessages();
        }
      } catch (err) {
        console.error('Error sending message:', err);
      }
    });

    // Allow Enter to send (Shift+Enter for new line)
    messageInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendBtn.click();
      }
    });

    // Scroll to bottom
    function scrollToBottom() {
      timeline.scrollTop = timeline.scrollHeight;
    }

    // Auto-refresh every 2 seconds
    setInterval(loadMessages, 2000);

    // Initial load
    loadMessages();
  </script>
</body>
</html>