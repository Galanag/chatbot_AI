<?php
/**
 * IJA Full AI Chatbot – with Conversation Logging & Admin Dashboard
 * Version: 2.0
 */

// ---------- 1. CREATE DATABASE TABLE ON ADMIN INIT ----------
add_action('admin_init', function () {
    $version = get_option('ija_chatbot_db_version', '0');
    if (version_compare($version, '2.0', '<')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ija_chat_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 0,
            user_name VARCHAR(100),
            user_email VARCHAR(100),
            session_id VARCHAR(64),
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            sources TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (session_id),
            INDEX (timestamp)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('ija_chatbot_db_version', '2.0');
    }
});

// ---------- 2. REST API ROUTE (with logging) ----------
add_action('rest_api_init', function () {
    register_rest_route('ija-github/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'ija_full_chat_handler',
        'permission_callback' => '__return_true',
    ]);
});

function ija_full_chat_handler($request) {
    $body = $request->get_json_params();
    $messages = $body['messages'] ?? [];
    if (empty($messages)) {
        return new WP_REST_Response(['reply' => 'Please ask something.'], 400);
    }

    // Get last user message
    $user_message = '';
    foreach (array_reverse($messages) as $msg) {
        if ($msg['role'] === 'user') {
            $user_message = $msg['content'];
            break;
        }
    }

    // Get context
    $context = ija_gather_context($user_message);
    
    // Ask GitHub AI
    $ai_reply = ija_ask_github_ai($user_message, $context);
    
    if ($ai_reply === false) {
        $ai_reply = "Our AI is busy. Please call +251967333999 for help.";
    }
    
    // ---------- LOG CONVERSATION ----------
    ija_log_conversation($user_message, $ai_reply, $context);
    
    return new WP_REST_Response(['reply' => $ai_reply]);
}

// ---------- 3. LOGGING FUNCTION ----------
function ija_log_conversation($question, $answer, $context_docs) {
    global $wpdb;
    $table = $wpdb->prefix . 'ija_chat_logs';
    
    // Get user info
    $user_id = 0;
    $user_name = '';
    $user_email = '';
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_name = $current_user->display_name;
        $user_email = $current_user->user_email;
    }
    
    // Session ID (for guests) – use a cookie or IP + user agent
    $session_id = '';
    if (!is_user_logged_in()) {
        if (isset($_COOKIE['ija_session'])) {
            $session_id = sanitize_text_field($_COOKIE['ija_session']);
        } else {
            $session_id = md5(uniqid('ija_', true));
            setcookie('ija_session', $session_id, time() + 30 * DAY_IN_SECONDS, '/');
        }
    } else {
        $session_id = wp_get_session_token(); // only works if logged in and sessions are active
        if (!$session_id) $session_id = '';
    }
    
    // Serialize sources (URLs used)
    $sources = [];
    foreach ($context_docs as $doc) {
        $sources[] = $doc['source'];
    }
    $sources_json = json_encode(array_unique($sources));
    
    $wpdb->insert($table, [
        'user_id'    => $user_id,
        'user_name'  => $user_name,
        'user_email' => $user_email,
        'session_id' => $session_id,
        'question'   => $question,
        'answer'     => $answer,
        'sources'    => $sources_json,
        'timestamp'  => current_time('mysql'),
    ]);
}

// ---------- 4. CONTEXT GATHERING (same as before) ----------
function ija_gather_context($query) {
    $context = [];
    $lower = strtolower($query);
    
    $dest_map = [
        'wanchi'  => 'https://wanchi-dandi.com/',
        'dandi'   => 'https://wanchi-dandi.com/',
        'langano' => 'https://langano.ijadevelopers.com/',
        'boku'    => 'https://boku.ijadevelopers.com/',
        'steam'   => 'https://boku.ijadevelopers.com/',
        'wabe'    => 'http://wabeshabale.ijadevelopers.com/',
        'shabale' => 'http://wabeshabale.ijadevelopers.com/',
    ];
    
    $urls = [];
    foreach ($dest_map as $kw => $url) {
        if (strpos($lower, $kw) !== false) {
            $urls[] = $url;
            break;
        }
    }
    $urls[] = 'https://ijadevelopers.com/';
    $urls = array_unique($urls);
    
    foreach ($urls as $url) {
        $text = ija_fetch_text($url);
        if ($text) {
            $snippet = ija_extract_snippet($text, $query, 800);
            if ($snippet) {
                $context[] = ['source' => $url, 'snippet' => $snippet];
            }
        }
    }
    
    // Local WordPress search (fallback)
    $local = ija_local_search($query);
    $context = array_merge($context, $local);
    return array_slice($context, 0, 5);
}

function ija_fetch_text($url) {
    $resp = wp_remote_get($url, ['timeout' => 12, 'user-agent' => 'IJA-Chatbot/1.0']);
    if (is_wp_error($resp)) return '';
    $html = wp_remote_retrieve_body($resp);
    if (!$html) return '';
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    $text = wp_strip_all_tags($html);
    return preg_replace('/\s+/', ' ', trim($text));
}

function ija_extract_snippet($text, $query, $len = 800) {
    $pos = stripos($text, $query);
    if ($pos === false) {
        return substr($text, 0, $len) . (strlen($text) > $len ? '…' : '');
    }
    $start = max(0, $pos - 200);
    $snippet = substr($text, $start, $len);
    if ($start > 0) $snippet = '…' . $snippet;
    if (strlen($text) > $start + $len) $snippet .= '…';
    return $snippet;
}

function ija_local_search($query) {
    $results = [];
    $wp_query = new WP_Query([
        'post_type'      => ['post', 'page'],
        's'              => $query,
        'posts_per_page' => 2,
    ]);
    if ($wp_query->have_posts()) {
        while ($wp_query->have_posts()) {
            $wp_query->the_post();
            $content = wp_strip_all_tags(strip_shortcodes(get_the_content()));
            $snippet = substr($content, 0, 400) . (strlen($content) > 400 ? '…' : '');
            $results[] = ['source' => get_permalink(), 'snippet' => $snippet];
        }
        wp_reset_postdata();
    }
    return $results;
}

// ---------- 5. GITHUB AI (75/25) ----------
function ija_ask_github_ai($question, $context_docs) {
    $api_key = defined('GITHUB_API_KEY') ? GITHUB_API_KEY : '';
    if (!$api_key) return false;
    
    $context_str = '';
    if (!empty($context_docs)) {
        foreach ($context_docs as $doc) {
            $context_str .= "Source: {$doc['source']}\n{$doc['snippet']}\n\n";
        }
        $prompt = "You are an assistant for IJA Developer. Use 75% from this website content and 25% general knowledge. Clearly indicate when using general knowledge. Never say 'page not available'. Contact: +251967333999, https://ijadevelopers.com.\n\nWEBSITE CONTENT:\n$context_str\n\nQUESTION: $question\n\nANSWER:";
    } else {
        $prompt = "Answer using general knowledge (100%). Include IJA contact: +251967333999, https://ijadevelopers.com. QUESTION: $question\n\nANSWER:";
    }
    
    $response = wp_remote_post('https://models.github.ai/inference/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => json_encode([
            'model'       => 'openai/gpt-4o',
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7,
            'max_tokens'  => 700,
        ]),
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['choices'][0]['message']['content'] ?? false;
}

// ---------- 6. ADMIN DASHBOARD ----------
add_action('admin_menu', function () {
    add_menu_page(
        'IJA Chatbot Logs',
        'IJA Chatbot',
        'manage_options',
        'ija-chatbot-logs',
        'ija_render_admin_page',
        'dashicons-format-chat',
        30
    );
});

function ija_render_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ija_chat_logs';
    
    // Handle search/filter
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $where = '';
    if ($search) {
        $where = $wpdb->prepare(" WHERE question LIKE %s OR answer LIKE %s OR user_name LIKE %s ", 
            '%' . $wpdb->esc_like($search) . '%',
            '%' . $wpdb->esc_like($search) . '%',
            '%' . $wpdb->esc_like($search) . '%'
        );
    }
    
    $logs = $wpdb->get_results("SELECT * FROM $table $where ORDER BY timestamp DESC LIMIT 100");
    ?>
    <div class="wrap">
        <h1>IJA Chatbot Conversations</h1>
        <form method="get">
            <input type="hidden" name="page" value="ija-chatbot-logs">
            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search questions, answers, or user name">
            <button type="submit" class="button">Search</button>
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr><th>Date</th><th>User</th><th>Question</th><th>Answer</th><th>Sources</th></tr>
            </thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <?php 
                                if ($log->user_id) {
                                    echo esc_html($log->user_name) . ' (' . esc_html($log->user_email) . ')';
                                } else {
                                    echo 'Guest (ID: ' . esc_html(substr($log->session_id, 0, 8)) . '…)';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html(substr($log->question, 0, 100)); ?></td>
                            <td><?php echo esc_html(substr($log->answer, 0, 150)); ?></td>
                            <td><?php 
                                $sources = json_decode($log->sources, true);
                                if ($sources) {
                                    foreach ($sources as $src) {
                                        echo '<a href="' . esc_url($src) . '" target="_blank">' . esc_html(parse_url($src, PHP_URL_HOST)) . '</a><br>';
                                    }
                                } else {
                                    echo '—';
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5">No conversations yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ---------- 7. FRONTEND CHAT UI (same as before, no changes needed) ----------
add_action('wp_enqueue_scripts', function () {
    if (!is_front_page() && !is_home()) return;
    
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css');
    wp_enqueue_script('tesseract', 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', [], null, true);
    
    wp_add_inline_style('font-awesome', '
        .ija-chatbot { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
        .ija-chatbot-toggle { width: 60px; height: 60px; border-radius: 50%; background: #007bff; color: white; border: none; cursor: pointer; font-size: 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.2); }
        .ija-chatbot-window { position: absolute; bottom: 80px; right: 0; width: 380px; max-width: calc(100vw - 40px); height: 500px; background: white; border-radius: 16px; box-shadow: 0 5px 30px rgba(0,0,0,0.2); display: none; flex-direction: column; overflow: hidden; }
        .ija-chatbot-header { background: #007bff; color: white; padding: 12px; display: flex; justify-content: space-between; }
        .ija-chatbot-messages { flex: 1; padding: 12px; overflow-y: auto; background: #f8f9fa; display: flex; flex-direction: column; gap: 8px; }
        .message { max-width: 80%; padding: 8px 12px; border-radius: 18px; font-size: 14px; }
        .user-message { background: #007bff; color: white; align-self: flex-end; }
        .bot-message { background: #e9ecef; color: black; align-self: flex-start; }
        .ija-chatbot-input { display: flex; gap: 8px; padding: 12px; background: white; border-top: 1px solid #ddd; align-items: center; flex-wrap: wrap; }
        .ija-chatbot-input input { flex: 1; padding: 8px 12px; border-radius: 24px; border: 1px solid #ccc; }
        .ija-chatbot-input button { background: none; border: none; cursor: pointer; font-size: 20px; color: #6c757d; }
        .ija-chatbot-input button:hover { color: #007bff; }
        .voice-active { color: #dc3545 !important; }
        #ija-image-input { display: none; }
        .ija-ocr-status { font-size: 12px; text-align: center; width: 100%; }
    ');
    
    wp_add_inline_script('tesseract', '
        document.addEventListener("DOMContentLoaded", function() {
            var container = document.createElement("div");
            container.className = "ija-chatbot";
            container.innerHTML = \'<button class="ija-chatbot-toggle" id="ija-toggle-btn"><i class="fas fa-comment-dots"></i></button><div class="ija-chatbot-window" id="ija-chat-window"><div class="ija-chatbot-header"><span>🤖 IJA Assistant</span><button id="ija-close-btn">&times;</button></div><div class="ija-chatbot-messages" id="ija-messages"></div><div class="ija-chatbot-input"><input type="text" id="ija-msg-input" placeholder="Ask about Wanchi, Langano, Boku..."><button id="ija-send-btn"><i class="fas fa-paper-plane"></i></button><button id="ija-voice-btn"><i class="fas fa-microphone"></i></button><label for="ija-image-input"><i class="fas fa-image"></i></label><input type="file" id="ija-image-input" accept="image/*"><div id="ija-ocr-status" class="ija-ocr-status"></div></div></div>\';
            document.body.appendChild(container);
            
            var toggle = document.getElementById("ija-toggle-btn");
            var win = document.getElementById("ija-chat-window");
            var close = document.getElementById("ija-close-btn");
            var input = document.getElementById("ija-msg-input");
            var send = document.getElementById("ija-send-btn");
            var voiceBtn = document.getElementById("ija-voice-btn");
            var imageInput = document.getElementById("ija-image-input");
            var ocrStatus = document.getElementById("ija-ocr-status");
            var messagesDiv = document.getElementById("ija-messages");
            
            var conversation = [];
            var recognition = null;
            var isVoiceActive = false;
            
            if ("webkitSpeechRecognition" in window) {
                recognition = new webkitSpeechRecognition();
                recognition.lang = "en-US";
                recognition.onresult = function(e) {
                    input.value = e.results[0][0].transcript;
                    sendUserMessage();
                    stopVoice();
                };
                recognition.onend = function() { stopVoice(); };
            }
            
            function startVoice() {
                if (!recognition) { alert("Voice not supported"); return; }
                if (isVoiceActive) return;
                isVoiceActive = true;
                voiceBtn.classList.add("voice-active");
                recognition.start();
            }
            function stopVoice() {
                if (recognition && isVoiceActive) recognition.stop();
                isVoiceActive = false;
                voiceBtn.classList.remove("voice-active");
            }
            
            function addMessage(role, content) {
                var div = document.createElement("div");
                div.className = "message " + (role === "user" ? "user-message" : "bot-message");
                div.innerHTML = content.replace(/\\*\\*(.*?)\\*\\*/g, "<strong>$1</strong>").replace(/\\n/g, "<br>");
                messagesDiv.appendChild(div);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
                conversation.push({role: role === "assistant" ? "assistant" : "user", content: content});
                if (conversation.length > 20) conversation.shift();
            }
            
            async function sendToBot(text) {
                addMessage("user", text);
                var typing = document.createElement("div");
                typing.className = "message bot-message";
                typing.innerHTML = "🌐 Scanning live websites...";
                messagesDiv.appendChild(typing);
                try {
                    var res = await fetch("/wp-json/ija-github/v1/chat", {
                        method: "POST",
                        headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({messages: conversation})
                    });
                    var data = await res.json();
                    typing.remove();
                    var reply = data.reply || "Please call +251967333999 for help.";
                    addMessage("assistant", reply);
                    if (window.speechSynthesis) {
                        window.speechSynthesis.cancel();
                        window.speechSynthesis.speak(new SpeechSynthesisUtterance(reply));
                    }
                } catch(e) {
                    typing.remove();
                    addMessage("assistant", "Network error. Please call +251967333999.");
                }
            }
            
            async function sendUserMessage() {
                var txt = input.value.trim();
                if (!txt) return;
                input.value = "";
                await sendToBot(txt);
            }
            
            async function handleImageUpload(file) {
                if (!file) return;
                ocrStatus.innerText = "📷 Reading image...";
                try {
                    if (typeof Tesseract === "undefined") throw "Tesseract not loaded";
                    var result = await Tesseract.recognize(file, "eng");
                    var text = result.data.text.trim();
                    if (text) {
                        ocrStatus.innerText = "✅ Extracted. Sending...";
                        input.value = text;
                        await sendUserMessage();
                        ocrStatus.innerText = "";
                    } else {
                        ocrStatus.innerText = "⚠️ No text found";
                        setTimeout(function() { ocrStatus.innerText = ""; }, 2000);
                    }
                } catch(e) {
                    ocrStatus.innerText = "❌ OCR failed";
                    setTimeout(function() { ocrStatus.innerText = ""; }, 2000);
                }
            }
            
            toggle.onclick = function() {
                win.style.display = "flex";
                if (conversation.length === 0) {
                    addMessage("assistant", "👋 Hello! I can scan our live destination websites: Wanchi-Dandi, Langano, Boku Natural Steam, and Wabe Shabale. I use 75% from our live sites and 25% general knowledge. Ask me anything!");
                }
            };
            close.onclick = function() { win.style.display = "none"; };
            send.onclick = sendUserMessage;
            input.addEventListener("keypress", function(e) { if (e.key === "Enter") sendUserMessage(); });
            voiceBtn.onclick = function() { isVoiceActive ? stopVoice() : startVoice(); };
            imageInput.onchange = function(e) { if (e.target.files[0]) handleImageUpload(e.target.files[0]); imageInput.value = ""; };
        });
    ');
});
