<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Telegram Bot Configuration (same as index.php)
$bot_token = "8006290667:AAdhhdhdFrfrSsgNWDjuwqToSoGB9x-9nGyUIltyE";
$api_url = "https://api.telegram.org/bot{$bot_token}/";

// Database Configuration (same as index.php)
$db_host = 'localhost';
$db_user = 'cztldhwxxhhx_tampemail';
$db_pass = 'Aptap786920';
$db_name = 'cztldhwx_tampemail';

// Connect to Database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// sendMessage function (copied from index.php)
function sendMessage($chat_id, $text, $reply_markup = null) {
    global $api_url;
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    $ch = curl_init($api_url . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

// Polling logic: Check all users' all emails for new messages
$users_result = $conn->query("SELECT user_id FROM bot_users");
while ($user = $users_result->fetch_assoc()) {
    $user_id = $user['user_id'];
    
    $emails_result = $conn->query("SELECT * FROM user_emails WHERE user_id = " . intval($user_id));
    while ($emailData = $emails_result->fetch_assoc()) {
        $email = $emailData['email'];
        $password = $emailData['password'];
        $token = $emailData['token'];
        
        // Get messages (first page for recent ones)
        $ch = curl_init("https://api.mail.tm/messages?page=1");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        $resp = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 401) {
            // Refresh token
            $ch = curl_init("https://api.mail.tm/token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                "address" => $email,
                "password" => $password
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            $tokenResp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (isset($tokenResp['token'])) {
                $token = $tokenResp['token'];
                // Update token
                $stmt = $conn->prepare("UPDATE user_emails SET token = ? WHERE id = ?");
                $stmt->bind_param("si", $token, $emailData['id']);
                $stmt->execute();
                $stmt->close();
                
                // Retry getting messages
                $ch = curl_init("https://api.mail.tm/messages?page=1");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
                $resp = json_decode(curl_exec($ch), true);
                curl_close($ch);
            } else {
                continue;  // Skip this email if refresh fails
            }
        }
        
        if (isset($resp['hydra:member'])) {
            foreach ($resp['hydra:member'] as $msg) {
                $msg_id = $msg['id'];
                
                // Check if already notified
                $stmt = $conn->prepare("SELECT COUNT(*) FROM notified_messages WHERE user_id = ? AND email = ? AND message_id = ?");
                $stmt->bind_param("iss", $user_id, $email, $msg_id);
                $stmt->execute();
                $count = $stmt->get_result()->fetch_row()[0];
                $stmt->close();
                
                if ($count == 0) {
                    // Fetch full message
                    $ch = curl_init("https://api.mail.tm/messages/$msg_id");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
                    $msg_data = json_decode(curl_exec($ch), true);
                    curl_close($ch);
                    
                    if ($msg_data) {
                        // Build notification
                        $notify = "🔔 <b>New Email Received in:</b> <code>$email</code>\n\n";
                        $notify .= "👤 <b>From:</b> " . $msg_data['from']['address'] . "\n";
                        $notify .= "📝 <b>Subject:</b> " . ($msg_data['subject'] ?: 'No Subject') . "\n";
                        $notify .= "💬 <b>Content:</b>\n" . substr(strip_tags($msg_data['html'] ?: $msg_data['text'] ?: 'No content'), 0, 500) . "...\n\n";
                        $notify .= "🆔 <b>Message ID:</b> <code>$msg_id</code>\n";
                        $notify .= "Use /read $msg_id to read full (after recovering the email if needed).";
                        
                        sendMessage($user_id, $notify);
                        
                        // Mark as notified
                        $stmt = $conn->prepare("INSERT INTO notified_messages (user_id, email, message_id) VALUES (?, ?, ?)");
                        $stmt->bind_param("iss", $user_id, $email, $msg_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // Update last_access
        $conn->query("UPDATE user_emails SET last_access = NOW() WHERE id = " . intval($emailData['id']));
    }
}

$conn->close();
echo "Polling completed.\n";  // For cron logging
?>
