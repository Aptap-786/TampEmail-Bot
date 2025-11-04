<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Telegram Bot Configuration
$bot_token = "8006290667:7373AAFrfrSsgNWDjuwqToSoGB9x-9nGyUIltyE";
$api_url = "https://api.telegram.org/bot{$bot_token}/";

// Database Configuration
$db_host = 'localhost';
$db_user = 'cztldhwx_tampemail';
$db_pass = 'Aptap786920';
$db_name = 'cztldhwx_tampemail';

// Connect to Database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create tables if not exist
$conn->query("
    CREATE TABLE IF NOT EXISTS bot_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNIQUE,
        username VARCHAR(255),
        first_name VARCHAR(255),
        last_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS user_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT,
        email VARCHAR(255),
        password VARCHAR(255),
        token TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_access TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_active BOOLEAN DEFAULT TRUE
    )
");

// New table for tracking notified messages (for real-time)
$conn->query("
    CREATE TABLE IF NOT EXISTS notified_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT,
        email VARCHAR(255),
        message_id VARCHAR(255),
        notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_notification (user_id, email, message_id)
    )
");

// Helper Functions
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

function getMainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '🌐 Generate New'], ['text' => '📥 Inbox']],
            [['text' => '🔄 Email Recovery'], ['text' => '📧 My Emails']],
            [['text' => '🔄 Refresh Inbox']]  // Added new button at the last
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}

function generateTempEmail($user_id) {
    global $conn;
    
    // Get available domains
    $domainData = @json_decode(file_get_contents("https://api.mail.tm/domains"), true);
    if (!$domainData || !isset($domainData['hydra:member'])) {
        return ['error' => 'Failed to get available domains'];
    }
    
    $domains = array_column($domainData['hydra:member'], 'domain');
    $domain = $domains[array_rand($domains)];
    
    // Generate username
    $prefixes = ['temp', 'quick', 'fast', 'instant', 'rapid', 'swift', 'flash'];
    $username = $prefixes[array_rand($prefixes)] . rand(100000, 999999);
    $email = $username . "@" . $domain;
    $password = "TempMail" . rand(100, 999) . "!";
    
    // Create account
    $ch = curl_init("https://api.mail.tm/accounts");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "address" => $email,
        "password" => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 201) {
        return ['error' => 'Failed to create email account'];
    }
    
    // Get token
    $ch = curl_init("https://api.mail.tm/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "address" => $email,
        "password" => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $tokenResp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (!isset($tokenResp['token'])) {
        return ['error' => 'Failed to get authentication token'];
    }
    
    // Removed deactivation - all emails stay active
    
    // Save to database
    $stmt = $conn->prepare("INSERT INTO user_emails (user_id, email, password, token, created_at, last_access, is_active) VALUES (?, ?, ?, ?, NOW(), NOW(), TRUE)");
    $stmt->bind_param("isss", $user_id, $email, $password, $tokenResp['token']);
    $stmt->execute();
    $stmt->close();
    
    return [
        'email' => $email,
        'password' => $password,
        'token' => $tokenResp['token']
    ];
}

function getInbox($user_id) {
    global $conn;
    
    // Get the most recent email (by last_access DESC) since all are active
    $stmt = $conn->prepare("SELECT email, password, token FROM user_emails WHERE user_id = ? ORDER BY last_access DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $emailData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$emailData) {
        return ['error' => 'No email found. Generate one first!'];
    }
    
    $token = $emailData['token'];
    $email = $emailData['email'];
    
    // Get messages
    $ch = curl_init("https://api.mail.tm/messages");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $resp = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 401) {
        // Token expired, refresh it
        $ch = curl_init("https://api.mail.tm/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "address" => $email,
            "password" => $emailData['password']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        $tokenResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($tokenResp['token'])) {
            $token = $tokenResp['token'];
            // Update token in database
            $stmt = $conn->prepare("UPDATE user_emails SET token = ?, last_access = NOW() WHERE user_id = ? AND email = ?");
            $stmt->bind_param("sis", $token, $user_id, $email);
            $stmt->execute();
            $stmt->close();
            
            // Retry getting messages
            $ch = curl_init("https://api.mail.tm/messages");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
        } else {
            return ['error' => 'Failed to refresh token'];
        }
    }
    
    $messages = [];
    if (isset($resp['hydra:member'])) {
        foreach ($resp['hydra:member'] as $msg) {
            $messages[] = [
                'id' => $msg['id'],
                'from' => $msg['from']['address'],
                'subject' => $msg['subject'] ?: 'No Subject',
                'date' => date('d/m/Y H:i', strtotime($msg['createdAt'])),
                'seen' => $msg['seen'],
                'hasAttachments' => !empty($msg['attachments'])
            ];
        }
    }
    
    return [
        'email' => $email,
        'messages' => $messages,
        'token' => $token
    ];
}

function readMessage($user_id, $message_id) {
    global $conn;
    
    // Get the most recent email's token (by last_access DESC)
    $stmt = $conn->prepare("SELECT token, email FROM user_emails WHERE user_id = ? ORDER BY last_access DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $emailData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$emailData) {
        return ['error' => 'No email found'];
    }
    
    $ch = curl_init("https://api.mail.tm/messages/$message_id");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $emailData['token']]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    return $resp;
}

function getUserEmails($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT email, created_at, is_active FROM user_emails WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $emails = [];
    while ($row = $result->fetch_assoc()) {
        $emails[] = $row;
    }
    $stmt->close();
    
    return $emails;
}

function recoverEmail($user_id, $email) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT email, password, token FROM user_emails WHERE user_id = ? AND email = ?");
    $stmt->bind_param("is", $user_id, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $emailData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$emailData) {
        return ['error' => 'Email not found in your history'];
    }
    
    // Try to refresh token
    $ch = curl_init("https://api.mail.tm/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "address" => $emailData['email'],
        "password" => $emailData['password']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $tokenResp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (!isset($tokenResp['token'])) {
        return ['error' => 'Failed to recover email. It may have expired.'];
    }
    
    // Removed deactivation of other emails - all stay active
    
    // Update this email (set as most recent by updating last_access, and refresh token)
    $stmt = $conn->prepare("UPDATE user_emails SET is_active = TRUE, token = ?, last_access = NOW() WHERE user_id = ? AND email = ?");
    $stmt->bind_param("sis", $tokenResp['token'], $user_id, $email);
    $stmt->execute();
    $stmt->close();
    
    return ['success' => true, 'email' => $email];
}

// Main Bot Logic
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'] ?? '';
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    $last_name = $message['from']['last_name'] ?? '';
    
    // Save user to database
    $stmt = $conn->prepare("INSERT IGNORE INTO bot_users (user_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $username, $first_name, $last_name);
    $stmt->execute();
    $stmt->close();
    
    // Handle commands
    if ($text === '/start') {
        $welcome = "🎉 <b>Welcome to Advanced Temp Email Bot!</b>\n\n";
        $welcome .= "📧 Generate temporary email addresses instantly\n";
        $welcome .= "📥 Receive and read emails\n";
        $welcome .= "🔄 Recover your previous emails\n";
        $welcome .= "📱 Easy to use interface\n\n";
        $welcome .= "Choose an option below to get started:";
        
        sendMessage($chat_id, $welcome, getMainKeyboard());
        
    } elseif ($text === '🌐 Generate New') {
        sendMessage($chat_id, "⏳ Generating new temporary email...");
        
        $result = generateTempEmail($user_id);
        if (isset($result['error'])) {
            sendMessage($chat_id, "❌ Error: " . $result['error']);
        } else {
            $response = "✅ <b>New Email Generated!</b>\n\n";
            $response .= "📧 <b>Email:</b> <code>" . $result['email'] . "</code>\n";
            $response .= "🔐 <b>Password:</b> <code>" . $result['password'] . "</code>\n\n";
            $response .= "🎯 Click on the email to copy it!\n";
            $response .= "📥 Use 'Inbox' button to check messages.";
            
            sendMessage($chat_id, $response, getMainKeyboard());
        }
        
    } elseif ($text === '📥 Inbox' || $text === '🔄 Refresh Inbox') {  // Handle new button same as Inbox for refresh
        sendMessage($chat_id, "📬 Checking your inbox...");
        
        $result = getInbox($user_id);
        if (isset($result['error'])) {
            sendMessage($chat_id, "❌ " . $result['error'], getMainKeyboard());
        } else {
            $response = "📥 <b>Inbox for:</b> <code>" . $result['email'] . "</code>\n\n";
            
            if (empty($result['messages'])) {
                $response .= "📭 <b>No messages yet</b>\n";
                $response .= "Wait for incoming emails...";
            } else {
                $response .= "📊 <b>Total Messages:</b> " . count($result['messages']) . "\n\n";
                
                foreach ($result['messages'] as $index => $msg) {
                    $status = $msg['seen'] ? '✅' : '🔔';
                    $attachment = $msg['hasAttachments'] ? '📎' : '';
                    
                    $response .= "{$status} <b>From:</b> {$msg['from']}\n";
                    $response .= "📝 <b>Subject:</b> " . substr($msg['subject'], 0, 30) . ($msg['hasAttachments'] ? ' 📎' : '') . "\n";
                    $response .= "🕒 <b>Date:</b> {$msg['date']}\n";
                    $response .= "🆔 <b>ID:</b> <code>{$msg['id']}</code>\n\n";
                    
                    if ($index >= 4) {  // Show only first 5 messages
                        $response .= "... and " . (count($result['messages']) - 5) . " more messages\n";
                        break;
                    }
                }
                
                $response .= "\n💡 <b>To read a message:</b>\n";
                $response .= "Send: <code>/read MESSAGE_ID</code>";
            }
            
            sendMessage($chat_id, $response, getMainKeyboard());
        }
        
    } elseif ($text === '🔄 Email Recovery') {
        $response = "🔄 <b>Email Recovery</b>\n\n";
        $response .= "To recover a previous email,\n";
        $response .= "Send /recover your_email@domain.com\n";
        $response .= "📋 Or check your email history with '📧 My Emails' button.";
        
        sendMessage($chat_id, $response, getMainKeyboard());
        
    } elseif ($text === '📧 My Emails') {
        $emails = getUserEmails($user_id);
        
        if (empty($emails)) {
            $response = "📧 <b>Your Email History</b>\n\n";
            $response .= "❌ No emails found\n";
            $response .= "Generate your first email using '🌐 Generate New' button!";
        } else {
            $response = "📧 <b>Your Email History</b>\n\n";
            
            foreach ($emails as $index => $email) {
                $status = $email['is_active'] ? '🟢 Active' : '⚪ Inactive';
                $date = date('d/m/Y H:i', strtotime($email['created_at']));
                
                $response .= "{$status} <code>{$email['email']}</code>\n";
                $response .= "📅 Created: {$date}\n\n";
                
                if ($index >= 4) {  // Show only first 5 emails
                    $response .= "... and " . (count($emails) - 5) . " more emails\n";
                    break;
                }
            }
            
            $response .= "\n🔄 <b>To recover:</b> <code>/recover EMAIL_ADDRESS</code>";
        }
        
        sendMessage($chat_id, $response, getMainKeyboard());
        
    } elseif (strpos($text, '/read ') === 0) {
        $message_id = trim(substr($text, 6));
        
        if (empty($message_id)) {
            sendMessage($chat_id, "❌ Please provide message ID\nUsage: <code>/read MESSAGE_ID</code>");
        } else {
            sendMessage($chat_id, "📖 Reading message...");
            
            $message_data = readMessage($user_id, $message_id);
            
            if (isset($message_data['error'])) {
                sendMessage($chat_id, "❌ " . $message_data['error']);
            } elseif (!$message_data) {
                sendMessage($chat_id, "❌ Message not found or expired");
            } else {
                $response = "📬 <b>Message Details</b>\n\n";
                $response .= "👤 <b>From:</b> " . $message_data['from']['address'] . "\n";
                $response .= "👥 <b>To:</b> " . $message_data['to'][0]['address'] . "\n";
                $response .= "📝 <b>Subject:</b> " . ($message_data['subject'] ?: 'No Subject') . "\n";
                $response .= "🕒 <b>Date:</b> " . date('d/m/Y H:i', strtotime($message_data['createdAt'])) . "\n\n";
                
                if (!empty($message_data['attachments'])) {
                    $response .= "📎 <b>Attachments:</b>\n";
                    foreach ($message_data['attachments'] as $att) {
                        $response .= "• {$att['filename']} ({$att['size']} bytes)\n";
                    }
                    $response .= "\n";
                }
                
                $response .= "💬 <b>Content:</b>\n";
                $content = strip_tags($message_data['html'] ?: $message_data['text'] ?: 'No content');
                $response .= substr($content, 0, 1000) . (strlen($content) > 1000 ? '...' : '');
                
                sendMessage($chat_id, $response, getMainKeyboard());
            }
        }
        
    } elseif (strpos($text, '/recover ') === 0) {
        $email_to_recover = trim(substr($text, 9));
        
        if (empty($email_to_recover) || !filter_var($email_to_recover, FILTER_VALIDATE_EMAIL)) {
            sendMessage($chat_id, "❌ Please provide a valid email address\nUsage: <code>/recover your-email@domain.com</code>");
        } else {
            sendMessage($chat_id, "🔄 Recovering email...");
            
            $result = recoverEmail($user_id, $email_to_recover);
            
            if (isset($result['error'])) {
                sendMessage($chat_id, "❌ " . $result['error']);
            } else {
                $response = "✅ <b>Email Recovered Successfully!</b>\n\n";
                $response .= "📧 <b>Active Email:</b> <code>" . $result['email'] . "</code>\n\n";
                $response .= "🎯 Use '📥 Inbox' button to check messages!";
                
                sendMessage($chat_id, $response, getMainKeyboard());
            }
        }
        
    } else {
        $response = "❓ <b>Unknown command</b>\n\n";
        $response .= "Please use the buttons below or these commands:\n";
        $response .= "• <code>/read MESSAGE_ID</code> - Read a message\n";
        $response .= "• <code>/recover EMAIL</code> - Recover an email\n";
        $response .= "• <code>/start</code> - Show main menu";
        
        sendMessage($chat_id, $response, getMainKeyboard());
    }
}

// Callback query handling (if needed)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    $user_id = $callback['from']['id'];
    
    // Handle callback queries here if needed
    // For now, we'll just acknowledge them
    file_get_contents($api_url . "answerCallbackQuery?callback_query_id=" . $callback['id']);
}

$conn->close();
?>
