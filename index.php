<?php
	/* This file is part of a copyrighted work; 
	it is distributed with NO WARRANTY. 
	See LICENSE - Ferdinand Tumulak */

// Configuration
$json_file = 'session.jsonl';
$status_message = '';

// Helper function to calculate approximate tokens (approx 4 characters per token)
function estimate_tokens($text) {
    if (empty($text)) return 0;
    return ceil(mb_strlen($text, 'UTF-8') / 4);
}

// Function to read and parse the file into a structured array
function get_session_messages($file_path) {
    $messages = [];
    if (file_exists($file_path)) {
        $file_lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($file_lines as $index => $line) {
            $decoded = json_decode(trim($line), true);
            if ($decoded) {
                $turn_tokens = 0;
                if (isset($decoded['content'])) $turn_tokens += estimate_tokens($decoded['content']);
                if (isset($decoded['reasoning_content'])) $turn_tokens += estimate_tokens($decoded['reasoning_content']);
                if (isset($decoded['tool_calls'])) $turn_tokens += estimate_tokens(json_encode($decoded['tool_calls']));
                
                $decoded['estimated_tokens'] = $turn_tokens;
                $messages[$index] = $decoded;
            }
        }
    }
    return $messages;
}

// Handle Markdown Export Action (Triggers file download before any HTML renders)
if (isset($_GET['export']) && $_GET['export'] === 'markdown') {
    $messages = get_session_messages($json_file);
    
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="session_transcript.md"');
    
    echo "# AI Session Transcript\n";
    echo "*Generated on " . date('Y-m-d H:i:s') . "*\n\n";
    echo "---\n\n";
    
    foreach ($messages as $msg) {
        $role = strtoupper($msg['role'] ?? 'UNKNOWN');
        echo "## 👤 Role: $role\n";
        
        if (!empty($msg['tool_call_id'])) {
            echo "**Responding to Tool Call ID:** `" . $msg['tool_call_id'] . "`\n\n";
        }
        
        // Include reasoning chains inside a nice blockquote block
        if (!empty($msg['reasoning_content'])) {
            echo "### 🧠 Internal Reasoning Chain\n";
            echo "> " . str_replace("\n", "\n> ", trim($msg['reasoning_content'])) . "\n\n";
        }
        
        // Include main content block
        if (isset($msg['content'])) {
            echo "### 📝 Content\n" . trim($msg['content']) . "\n\n";
        }
        
        // Include structured tool calls if present
        if (!empty($msg['tool_calls'])) {
            echo "### 🛠️ Tool Calls\n";
            foreach ($msg['tool_calls'] as $tool) {
                echo "* **Function:** `" . ($tool['function']['name'] ?? 'unknown') . "`\n";
                echo "  **Arguments:** `" . ($tool['function']['arguments'] ?? '{}') . "`\n";
            }
            echo "\n";
        }
        
        echo "---\n\n";
    }
    exit;
}

// Handle Save Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $updated_data = [];
    
    if (isset($_POST['messages']) && is_array($_POST['messages'])) {
        foreach ($_POST['messages'] as $msg) {
            $item = [
                'role' => $msg['role'] ?? ''
            ];

            if (isset($msg['content'])) {
                $item['content'] = str_replace("[NEWLINE]\n", "\n", $msg['content']);
            }
            
            if (isset($msg['reasoning_content'])) {
                $item['reasoning_content'] = str_replace("[NEWLINE]\n", "\n", $msg['reasoning_content']);
            }

            if (!empty($msg['tool_calls_json'])) {
                $item['tool_calls'] = json_decode($msg['tool_calls_json'], true);
            }
            if (isset($msg['tool_call_id'])) {
                $item['tool_call_id'] = $msg['tool_call_id'];
            }

            $updated_data[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    file_put_contents($json_file, implode("\n", $updated_data));
    $status_message = "Session successfully optimized and saved!";
}

// Read raw file and transform for web textareas visual display
$messages = get_session_messages($json_file);
$total_session_tokens = 0;

foreach ($messages as $index => $msg) {
    $total_session_tokens += $msg['estimated_tokens'];
    if (isset($messages[$index]['content'])) {
        $messages[$index]['content'] = str_replace("\n", "[NEWLINE]\n", $messages[$index]['content']);
    }
    if (isset($messages[$index]['reasoning_content'])) {
        $messages[$index]['reasoning_content'] = str_replace("\n", "[NEWLINE]\n", $messages[$index]['reasoning_content']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clawnitizer: Context Trimmer & Token Estimator</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f8; color: #333; margin: 20px; }
        .container { max-width: 950px; margin: 0 auto; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .header-actions { display: flex; align-items: center; gap: 15px; }
        .token-counter-badge { background: #343a40; color: #fff; padding: 8px 14px; border-radius: 20px; font-weight: bold; font-size: 14px; }
        .btn-export { background: #007bff; color: white; padding: 8px 14px; text-decoration: none; border-radius: 20px; font-weight: bold; font-size: 14px; transition: background 0.2s;}
        .btn-export:hover { background: #0056b3; }
        .alert { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: bold;}
        .card { background: #fff; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .role-user { border-left-color: #007bff; background: #fdfeff; }
        .role-assistant { border-left-color: #28a745; background: #f9fff9; }
        .role-tool { border-left-color: #ffc107; background: #fffdf5; }
        .meta { font-weight: bold; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
        .badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; text-transform: uppercase; color: #fff; margin-right: 5px;}
        .bg-user { background: #007bff; } .bg-assistant { background: #28a745; } .bg-tool { background: #856404; }
        .turn-actions { display: flex; align-items: center; gap: 12px; }
        .turn-tokens { font-size: 12px; color: #666; font-family: monospace; background: #e9ecef; padding: 2px 6px; border-radius: 4px; }
        textarea { width: 100%; height: 120px; font-family: monospace; font-size: 13px; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px;}
        .reasoning-box { background-color: #fff5f5; border: 1px solid #fab6b6;}
        .btn-save { background: #218838; color: white; padding: 12px 24px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; float: right; margin-bottom: 40px;}
        .btn-save:hover { background: #1e7e34; }
        .delete-btn { background: #dc3545; color: #fff; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer; font-size: 11px;}
        .delete-btn:hover { background: #bd2130; }
    </style>
    <script>
        function removeRow(button, turnTokens) {
            if(confirm("Are you sure you want to drop this entire turn from the context?")) {
                const totalBadge = document.getElementById('total-token-count');
                if (totalBadge) {
                    let currentTotal = parseInt(totalBadge.innerText.replace(/,/g, ''), 10);
                    let updatedTotal = Math.max(0, currentTotal - turnTokens);
                    totalBadge.innerText = updatedTotal.toLocaleString();
                }
                button.closest('.card').remove();
            }
        }
    </script>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <div>
            <h2 style="margin:0; font-size: 22px;">Clawnitizer: Context Trimmer & Token Estimator</h2>
            <p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Optimize agent history payloads and save API execution costs.</p>
        </div>
        <div class="header-actions">
            <a href="?export=markdown" class="btn-export">📥 Export to Markdown</a>
            <div class="token-counter-badge">
                Est. Total: <span id="total-token-count"><?= number_format($total_session_tokens) ?></span> tokens
            </div>
        </div>
    </div>
    
    <?php if (!empty($status_message)): ?>
        <div class="alert"><?= htmlspecialchars($status_message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php foreach ($messages as $i => $msg): 
            $role = $msg['role'] ?? 'unknown';
            $turn_tokens = $msg['estimated_tokens'] ?? 0;
        ?>
            <div class="card role-<?= htmlspecialchars($role) ?>">
                <div class="meta">
                    <span>
                        <span class="badge bg-<?= htmlspecialchars($role) ?>"><?= htmlspecialchars($role) ?></span>
                        <?php if(!empty($msg['tool_call_id'])): ?>
                            <small style="color:#666;">(ID: <?= htmlspecialchars($msg['tool_call_id']) ?>)</small>
                        <?php endif; ?>
                    </span>
                    
                    <div class="turn-actions">
                        <span class="turn-tokens">~<?= number_format($turn_tokens) ?> tokens</span>
                        <button type="button" class="delete-btn" onclick="removeRow(this, <?= $turn_tokens ?>)">✕ Delete Turn</button>
                    </div>
                </div>

                <!-- Hidden data preservation tags -->
                <input type="hidden" name="messages[<?= $i ?>][role]" value="<?= htmlspecialchars($role) ?>">
                <?php if(isset($msg['tool_call_id'])): ?>
                    <input type="hidden" name="messages[<?= $i ?>][tool_call_id]" value="<?= htmlspecialchars($msg['tool_call_id']) ?>">
                <?php endif; ?>
                <?php if(!empty($msg['tool_calls'])): ?>
                    <input type="hidden" name="messages[<?= $i ?>][tool_calls_json]" value="<?= htmlspecialchars(json_encode($msg['tool_calls'])) ?>">
                <?php endif; ?>

                <!-- Content Input -->
                <?php if(isset($msg['content'])): ?>
                    <label style="font-size:12px; color:#555;">Main Content:</label>
                    <textarea name="messages[<?= $i ?>][content]"><?= htmlspecialchars($msg['content']) ?></textarea>
                <?php endif; ?>

                <!-- Reasoning Content Input -->
                <?php if(isset($msg['reasoning_content'])): ?>
                    <label style="font-size:12px; color:#c0392b; margin-top:8px; display:block;">Reasoning Chain:</label>
                    <textarea class="reasoning-box" name="messages[<?= $i ?>][reasoning_content]"><?= htmlspecialchars($msg['reasoning_content']) ?></textarea>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" name="save" class="btn-save">Save Optimized JSONL</button>
    </form>
</div>

</body>
</html>