<?php
// Bot configuration - Get from environment variable on Render
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Initialize users.json if not exists
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode([]));
    chmod(USERS_FILE, 0777);
}

// Initialize error.log if not exists
if (!file_exists(ERROR_LOG)) {
    file_put_contents(ERROR_LOG, '');
    chmod(ERROR_LOG, 0777);
}

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Data management
function loadUsers() {
    try {
        $data = file_get_contents(USERS_FILE);
        return $data ? json_decode($data, true) ?: [] : [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        return file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT)) !== false;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response !== false;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Answer callback query
function answerCallbackQuery($callback_query_id) {
    try {
        $url = API_URL . 'answerCallbackQuery';
        $params = ['callback_query_id' => $callback_query_id];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        logError("Answer callback failed: " . $e->getMessage());
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    $response_sent = false;
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
            $response_sent = true;
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        $chat_id = $callback_query['message']['chat']['id'];
        $data = $callback_query['data'];
        $callback_id = $callback_query['id'];
        
        // Answer callback query first
        answerCallbackQuery($callback_id);
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $now = time();
                if ($now - $users[$chat_id]['last_earn'] >= 3600) { // 1 hour cooldown
                    $users[$chat_id]['balance'] += 10;
                    $users[$chat_id]['last_earn'] = $now;
                    $msg = "✅ +10 points added!\nCurrent balance: {$users[$chat_id]['balance']} points";
                } else {
                    $remaining = 3600 - ($now - $users[$chat_id]['last_earn']);
                    $msg = "⏰ Please wait " . gmdate("i", $remaining) . " minutes before earning again.";
                }
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
                
            case 'balance':
                $msg = "💰 Your Balance: {$users[$chat_id]['balance']} points";
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
                
            case 'leaderboard':
                arsort($users);
                $top = array_slice($users, 0, 10, true);
                $msg = "🏆 Top 10 Users:\n";
                $i = 1;
                foreach ($top as $id => $user) {
                    $msg .= "{$i}. User {$id}: {$user['balance']} points\n";
                    $i++;
                }
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
                
            case 'referrals':
                $msg = "👥 Your Referrals: {$users[$chat_id]['referrals']}\nYour referral link: https://t.me/" . explode(':', BOT_TOKEN)[0] . "?start={$users[$chat_id]['ref_code']}";
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
                
            case 'withdraw':
                if ($users[$chat_id]['balance'] >= 100) {
                    $msg = "🏧 Withdrawal request submitted!\n100 points deducted.\nAdmin will process your payment shortly.";
                    $users[$chat_id]['balance'] -= 100;
                } else {
                    $msg = "❌ Minimum withdrawal: 100 points\nYour balance: {$users[$chat_id]['balance']} points";
                }
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
                
            case 'help':
                $msg = "❓ Help Guide:\n• Click 'Earn' every hour for 10 points\n• Invite friends using your referral link\n• Withdraw at 100 points minimum\n• Check leaderboard for top earners";
                sendMessage($chat_id, $msg, getMainKeyboard());
                $response_sent = true;
                break;
        }
    }
    
    if ($response_sent) {
        saveUsers($users);
    }
    
    return $response_sent;
}

// Webhook setup function
function setWebhook($webhook_url) {
    try {
        $url = API_URL . 'setWebhook';
        $params = ['url' => $webhook_url];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    } catch (Exception $e) {
        logError("Set webhook failed: " . $e->getMessage());
        return false;
    }
}

// Main webhook handler
$input = file_get_contents("php://input");
$update = json_decode($input, true);

if ($update) {
    // Process the update
    processUpdate($update);
    
    // Send OK response
    http_response_code(200);
    echo "OK";
} elseif (isset($_GET['setup'])) {
    // Manual webhook setup endpoint
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . "/";
    $result = setWebhook($webhook_url);
    echo "Webhook set to: " . $webhook_url . "<br>";
    echo "Result: " . $result;
} else {
    // Default response for browser requests
    echo "Telegram Bot is running!<br>";
    echo "To set webhook, visit: " . $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "?setup=1";
}
?>