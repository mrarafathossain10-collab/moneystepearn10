<?php
// ================= CONFIG =================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'PLACE_YOUR_TOKEN_HERE');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', __DIR__ . '/users.json');
define('ERROR_LOG', __DIR__ . '/error.log');

// ================= UTILITIES =================
function logError($msg) {
    file_put_contents(ERROR_LOG, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

function apiRequest($method, $data = []) {
    $url = API_URL . $method;
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json",
            'method'  => 'POST',
            'content' => json_encode($data),
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        logError("API Request failed: $method");
    }
    
    return $result;
}

function loadUsers() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, '{}');
    }
    $content = file_get_contents(USERS_FILE);
    return json_decode($content, true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

// ================= KEYBOARDS =================
function mainKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text'=>'💰 Earn','callback_data'=>'earn'],
                ['text'=>'💳 Balance','callback_data'=>'balance']
            ],
            [
                ['text'=>'👥 Referral','callback_data'=>'ref'],
                ['text'=>'🏧 Withdraw','callback_data'=>'withdraw']
            ],
            [
                ['text'=>'⭐ VIP','callback_data'=>'vip']
            ]
        ]
    ];
}

// ================= WEBHOOK HANDLER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the POST data from Telegram
    $update = json_decode(file_get_contents("php://input"), true);
    
    if (!$update) {
        http_response_code(400);
        echo "Invalid request";
        exit;
    }
    
    $users = loadUsers();
    
    // ================= MESSAGE =================
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'vip' => false,
                'ref_code' => substr(md5($chat_id), 0, 8),
                'referrals' => 0,
                'activated' => false
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            apiRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "👋 Welcome!\n\n💎 Earning Bot\n🆔 ID: $chat_id\n\n⚠️ Account inactive\nActivate to earn.",
                'reply_markup' => mainKeyboard()
            ]);
        }
    }
    
    // ================= CALLBACK =================
    if (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        
        // Ensure user exists
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'vip' => false,
                'ref_code' => substr(md5($chat_id), 0, 8),
                'referrals' => 0,
                'activated' => false
            ];
        }
        
        $u = &$users[$chat_id];
        
        switch($data) {
            case 'earn':
                if (!$u['activated']) {
                    $msg = "❌ Account not activated.";
                } else {
                    $earn = $u['vip'] ? 20 : 10;
                    $u['balance'] += $earn;
                    $msg = "✅ Earned $earn points!";
                }
                break;
                
            case 'balance':
                $msg = "💳 Balance: {$u['balance']}\n⭐ VIP: " . ($u['vip'] ? 'YES' : 'NO');
                break;
                
            case 'ref':
                $msg = "👥 Referral Code: {$u['ref_code']}\nBonus per referral!";
                break;
                
            case 'withdraw':
                $msg = $u['balance'] < 100 
                    ? "❌ Minimum 100 required."
                    : "✅ Withdraw request received.";
                break;
                
            case 'vip':
                $msg = "⭐ VIP Plan\n✔ Double earning\n✔ Priority withdraw\n\nContact Admin.";
                break;
                
            default:
                $msg = "Unknown command";
        }
        
        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'reply_markup' => mainKeyboard()
        ]);
    }
    
    // Save users data
    saveUsers($users);
    
    // Send OK response to Telegram
    http_response_code(200);
    echo "OK";
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Health check endpoint for Render
    if ($_GET['health'] ?? '' === 'check') {
        echo "Bot is running";
        exit;
    }
    
    // Webhook setup endpoint
    if (isset($_GET['setwebhook'])) {
        $webhookUrl = $_GET['url'] ?? '';
        if ($webhookUrl) {
            $result = apiRequest('setWebhook', ['url' => $webhookUrl]);
            echo "Webhook set: $webhookUrl<br>Response: $result";
        } else {
            echo "Please provide ?url=https://your-domain.onrender.com";
        }
    } else {
        echo "Telegram Bot Webhook Handler";
    }
}