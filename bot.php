<?php
// Telegram Bot - Channel Join Verification with Image & Proper Design

const BOT_TOKEN = '8539631877:AAHQqWwmjvAj2Vaitrj2aETx_e3JweH0CwA';
const LOG_FILE = 'logs.json';
const ADMIN_FILE = 'admin_data.json';

// Admin user IDs - replace with your actual admin IDs
const ADMIN_IDS = [7598553230];

// Load admin data
function loadAdminData() {
    if (!file_exists(ADMIN_FILE)) {
        $defaultData = [
            'channels' => [],
            'gift_codes' => [],
            'user_coins' => [],
            'banned_users' => [],
            'grant_search_users' => [],
            'referrals' => [],
            'referral' => [
                'enabled' => true,
                'coins' => 1
            ],
            'links' => [],
            'buttons' => [],
            'caption' => 'Join all channels below to continue!',
            'photo' => 'https://i.postimg.cc/tZSyWY6z/1000452610.jpg',
            'button_text' => 'Verify',
            'referral_link' => 'https://t.me/PANTHERKEYGENERATEBOT',
            'mods_link' => 'https://t.me/+IQXBTAwVCoFjMWE9',
            'mods_required_refs' => 10,
            'show_all_channels' => false
        ];
        file_put_contents(ADMIN_FILE, json_encode($defaultData, JSON_PRETTY_PRINT));
        return $defaultData;
    }
    $data = json_decode(file_get_contents(ADMIN_FILE), true);
    // Ensure all required keys exist
    if (!isset($data['channels'])) $data['channels'] = [];
    if (!isset($data['referrals'])) $data['referrals'] = [];
    if (!isset($data['user_coins'])) $data['user_coins'] = [];
    if (!isset($data['gift_codes'])) $data['gift_codes'] = [];
    if (!isset($data['banned_users'])) $data['banned_users'] = [];
    if (!isset($data['grant_search_users'])) $data['grant_search_users'] = [];
    return $data;
}

// Save admin data
function saveAdminData($data) {
    return file_put_contents(ADMIN_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: BOT_TOKEN;

// Debug logging
function debug_log($message) {
    file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Check if user is admin
function isAdmin($userId) {
    return in_array($userId, ADMIN_IDS);
}

// Read update from Telegram webhook
$rawInput = file_get_contents('php://input');
debug_log("Raw input received: " . substr($rawInput, 0, 500));

if ($rawInput === false || $rawInput === '') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "OK - Webhook is active";
        debug_log("GET request received");
        exit;
    }
    http_response_code(400);
    echo 'No input';
    debug_log("No input received");
    exit;
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    http_response_code(400);
    echo 'Invalid JSON';
    debug_log("Invalid JSON received: " . $rawInput);
    exit;
}

debug_log("Update type: " . (isset($update['message']) ? 'message' : (isset($update['callback_query']) ? 'callback' : 'unknown')));

// Helper: send a text message
function tg_send_message(string $botToken, int $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): bool {
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if ($parseMode) {
        $payload['parse_mode'] = $parseMode;
    }
    if ($replyMarkup) {
        $payload['reply_markup'] = $replyMarkup;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    debug_log("Sent message to {$chatId}. Response: " . $response . " Error: " . $error);
    
    return $httpCode === 200;
}

// Check if bot is admin in channel
function is_bot_admin_in_channel($botToken, $channelId) {
    $botId = explode(':', BOT_TOKEN)[0];
    $url = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id={$channelId}&user_id={$botId}";
    $resp = http_get_json($url);
    
    if (!$resp || !isset($resp['result'])) {
        debug_log("Bot admin check failed for channel {$channelId}");
        return false;
    }
    
    $status = $resp['result']['status'] ?? 'left';
    $isAdmin = in_array($status, ['creator', 'administrator']);
    debug_log("Bot admin status in channel {$channelId}: {$status} - " . ($isAdmin ? 'ADMIN' : 'NOT ADMIN'));
    return $isAdmin;
}

// Send photo with caption and buttons (2 buttons per line)
function tg_send_verification_message(string $botToken, int $chatId, int $userId, array $channelsToShow = []): bool {
    $adminData = loadAdminData();
    $photo = $adminData['photo'] ?? 'https://i.postimg.cc/tZSyWY6z/1000452610.jpg';
    $caption = $adminData['caption'] ?? 'Join all channels below to continue!';
    $buttonText = $adminData['button_text'] ?? 'Verify';
    
    // If no channels specified, get all channels
    if (empty($channelsToShow)) {
        $channelsToShow = $adminData['channels'] ?? [];
    }
    
    // Check which channels user hasn't joined
    $notJoined = [];
    $channelsWithIssues = [];
    
    foreach ($channelsToShow as $ch) {
        $channelId = $ch['id'];
        $channelName = $ch['name'];
        
        // Check if bot is admin in the channel
        if (!is_bot_admin_in_channel($botToken, $channelId)) {
            $channelsWithIssues[] = $ch;
            continue;
        }
        
        // Check user membership
        $url = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id={$channelId}&user_id={$userId}";
        $resp = http_get_json($url);
        $status = $resp['result']['status'] ?? 'left';
        
        if (!in_array($status, ['creator','administrator','member','restricted'])) {
            $notJoined[] = $ch;
        }
    }
    
    // Create buttons (2 buttons per line)
    $buttons = [];
    $showAllChannels = $adminData['show_all_channels'] ?? false;
    
    if ($showAllChannels) {
        // Show ALL channels mode (2 buttons per line)
        $tempButtons = [];
        foreach ($channelsToShow as $ch) {
            $channelId = $ch['id'];
            $channelName = $ch['name'];
            
            // Check if bot is admin
            if (!is_bot_admin_in_channel($botToken, $channelId)) {
                $tempButtons[] = ['text' => '❌ ' . $ch['name'], 'url' => $ch['url']];
                continue;
            }
            
            // Check user membership
            $url = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id={$channelId}&user_id={$userId}";
            $resp = http_get_json($url);
            $status = $resp['result']['status'] ?? 'left';
            
            if (in_array($status, ['creator','administrator','member','restricted'])) {
                $tempButtons[] = ['text' => '✅ ' . $ch['name'], 'url' => $ch['url']];
            } else {
                $tempButtons[] = ['text' => '📢 ' . $ch['name'], 'url' => $ch['url']];
            }
        }
        
        // Group buttons 2 per line
        for ($i = 0; $i < count($tempButtons); $i += 2) {
            $line = [];
            if (isset($tempButtons[$i])) $line[] = $tempButtons[$i];
            if (isset($tempButtons[$i + 1])) $line[] = $tempButtons[$i + 1];
            $buttons[] = $line;
        }
    } else {
        // Show only NOT joined channels mode (2 buttons per line)
        $tempButtons = [];
        foreach ($notJoined as $ch) {
            $tempButtons[] = ['text' => '📢 ' . $ch['name'], 'url' => $ch['url']];
        }
        
        // Also show channels where bot is not admin
        foreach ($channelsWithIssues as $ch) {
            $tempButtons[] = ['text' => '❌ ' . $ch['name'], 'url' => $ch['url']];
        }
        
        // Group buttons 2 per line
        for ($i = 0; $i < count($tempButtons); $i += 2) {
            $line = [];
            if (isset($tempButtons[$i])) $line[] = $tempButtons[$i];
            if (isset($tempButtons[$i + 1])) $line[] = $tempButtons[$i + 1];
            $buttons[] = $line;
        }
    }
    
    // Add verify button
    if (!empty($buttons)) {
        $buttons[] = [['text' => '✅ ' . $buttonText, 'callback_data' => 'check_joined']];
    } else {
        // If no buttons, add a dummy button to avoid error
        $buttons[] = [['text' => '✅ All Channels Joined', 'callback_data' => 'check_joined']];
    }
    
    $markup = ['inline_keyboard' => $buttons];
    
    $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";
    $payload = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'reply_markup' => $markup,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

// Send user dashboard with buttons
function tg_send_user_dashboard(string $botToken, int $chatId, int $userId): bool {
    $adminData = loadAdminData();
    $userCoins = $adminData['user_coins'][$userId] ?? 0;
    $referralCount = count($adminData['referrals'][$userId] ?? []);
    $requiredRefs = $adminData['mods_required_refs'] ?? 10;
    $referralLink = $adminData['referral_link'] ?? 'https://t.me/PANTHERKEYGENERATEBOT';
    
    $message = "🤖 <b>USER DASHBOARD</b>\n\n";
    $message .= "💰 <b>Your Coins:</b> {$userCoins}\n";
    $message .= "👥 <b>Your Referrals:</b> {$referralCount}/{$requiredRefs}\n\n";
    
    if ($referralCount >= $requiredRefs) {
        $message .= "🎉 <b>CONGRATULATIONS!</b>\n";
        $message .= "You have unlocked MODS access!\n";
    } else {
        $remaining = $requiredRefs - $referralCount;
        $message .= "📊 Need <b>{$remaining}</b> more referrals to unlock MODS\n";
    }
    
    // Create buttons for user dashboard (2 buttons per line)
    $buttons = [
        [['text' => '🎮 MODS ACCESS', 'callback_data' => 'mods_access'], ['text' => '🎁 GIFT CODE', 'callback_data' => 'gift_code']],
        [['text' => '💰 MY COINS', 'callback_data' => 'my_coins'], ['text' => '📊 MY STATS', 'callback_data' => 'my_stats']],
        [['text' => '👥 REFERRAL & EARN', 'callback_data' => 'referral_earn']]
    ];
    
    $markup = ['inline_keyboard' => $buttons];
    
    return tg_send_message($botToken, $chatId, $message, 'HTML', $markup);
}

// HTTP GET helper
function http_get_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TelegramBot/1.0)',
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    debug_log("HTTP GET to: " . $url . " Code: " . $code . " Response: " . $response . " Error: " . $error);
    
    if ($response === false) {
        return null;
    }
    if ($code !== 200) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

// Add referral for user
function addReferral($referrerId, $referredId) {
    $adminData = loadAdminData();
    
    // Initialize user data if not exists
    if (!isset($adminData['user_coins'][$referrerId])) {
        $adminData['user_coins'][$referrerId] = 0;
    }
    if (!isset($adminData['referrals'][$referrerId])) {
        $adminData['referrals'][$referrerId] = [];
    }
    
    // Add referral if not already exists
    if (!in_array($referredId, $adminData['referrals'][$referrerId])) {
        $adminData['referrals'][$referrerId][] = $referredId;
        
        // Add referral coins (1 coin per referral)
        $referralCoins = $adminData['referral']['coins'] ?? 1;
        $adminData['user_coins'][$referrerId] += $referralCoins;
        
        saveAdminData($adminData);
        return $referralCoins;
    }
    
    return 0;
}

// Check channel membership with bot admin verification
function tg_require_channels(string $botToken, int $userId, int $chatId): bool {
    debug_log("Checking channels for user: {$userId} in chat: {$chatId}");
    
    $adminData = loadAdminData();
    $channels = $adminData['channels'] ?? [];
    
    if (empty($channels)) { 
        tg_send_user_dashboard($botToken, $chatId, $userId);
        return true; 
    }
    
    $notJoined = [];
    $allJoined = true;
    
    foreach ($channels as $index => $ch) {
        $channelId = $ch['id'];
        $channelName = $ch['name'];
        
        // Check if bot is admin in the channel
        if (!is_bot_admin_in_channel($botToken, $channelId)) {
            debug_log("Bot is not admin in channel: {$channelName}");
            continue; // Skip channels where bot is not admin
        }
        
        // Check user membership
        $url = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id={$channelId}&user_id={$userId}";
        $resp = http_get_json($url);
        $status = $resp['result']['status'] ?? 'left';
        
        debug_log("Channel {$channelName} status: {$status}");
        
        if (!in_array($status, ['creator','administrator','member','restricted'])) {
            $notJoined[] = $ch;
            $allJoined = false;
        }
    }
    
    if (!$allJoined && !empty($notJoined)) {
        // Send image with join buttons
        tg_send_verification_message($botToken, $chatId, $userId, $channels);
        return false;
    }
    
    // User has joined all channels - Send user dashboard
    tg_send_user_dashboard($botToken, $chatId, $userId);
    return true;
}

// ================= NEW COMMANDS FOR CHANNEL DISPLAY MODES =================

function handleAllChannelsMode($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $adminData['show_all_channels'] = true;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ <b>ALL CHANNELS MODE ACTIVATED</b>\n\nNow /start will show ALL channels (even already joined ones)");
    
    // Show verification message in all channels mode
    $channels = $adminData['channels'] ?? [];
    tg_send_verification_message($botToken, $chatId, $userId, $channels);
}

function handleOnlyNotJoinedMode($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $adminData['show_all_channels'] = false;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ <b>ONLY NOT JOINED MODE ACTIVATED</b>\n\nNow /start will show only channels you haven't joined yet");
    
    // Show verification message in only not joined mode
    $channels = $adminData['channels'] ?? [];
    tg_send_verification_message($botToken, $chatId, $userId, $channels);
}

// ================= USER COMMAND HANDLERS =================

function handleModsAccess($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $referralCount = count($adminData['referrals'][$userId] ?? []);
    $requiredRefs = $adminData['mods_required_refs'] ?? 10;
    $modsLink = $adminData['mods_link'] ?? 'https://t.me/+IQXBTAwVCoFjMWE9';
    
    if ($referralCount >= $requiredRefs) {
        $message = "🎉 <b>MODS ACCESS GRANTED!</b>\n\n";
        $message .= "You have successfully referred {$referralCount} users.\n";
        $message .= "Here is your MODS access link:\n\n";
        $message .= "🔗 <b>{$modsLink}</b>\n\n";
        $message .= "Enjoy your MODS! 🎮";
    } else {
        $remaining = $requiredRefs - $referralCount;
        $message = "❌ <b>MODS ACCESS DENIED</b>\n\n";
        $message .= "You need <b>{$remaining}</b> more referrals to unlock MODS.\n";
        $message .= "Current progress: <b>{$referralCount}/{$requiredRefs}</b>\n\n";
        $message .= "Use the 'REFERRAL & EARN' button to get your referral link!";
    }
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleGiftCode($botToken, $chatId, $userId) {
    $message = "🎁 <b>GIFT CODE SYSTEM</b>\n\n";
    $message .= "To redeem a gift code, use the command:\n";
    $message .= "<code>/redeem CODE</code>\n\n";
    $message .= "Example: <code>/redeem WELCOME10</code>\n\n";
    $message .= "💡 <i>Contact admin for gift codes</i>";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleMyCoins($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $userCoins = $adminData['user_coins'][$userId] ?? 0;
    
    $message = "💰 <b>YOUR COINS</b>\n\n";
    $message .= "Current Balance: <b>{$userCoins} coins</b>\n\n";
    $message .= "💡 <i>Earn more coins by referring friends!</i>";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleMyStats($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $userCoins = $adminData['user_coins'][$userId] ?? 0;
    $referralCount = count($adminData['referrals'][$userId] ?? []);
    $requiredRefs = $adminData['mods_required_refs'] ?? 10;
    
    $message = "📊 <b>YOUR STATISTICS</b>\n\n";
    $message .= "💰 <b>Coins:</b> {$userCoins}\n";
    $message .= "👥 <b>Referrals:</b> {$referralCount}/{$requiredRefs}\n";
    $message .= "🎯 <b>Progress:</b> " . round(($referralCount/$requiredRefs)*100, 1) . "%\n\n";
    
    if ($referralCount >= $requiredRefs) {
        $message .= "✅ <b>MODS Status:</b> UNLOCKED\n";
        $message .= "🎮 You can access MODS now!";
    } else {
        $remaining = $requiredRefs - $referralCount;
        $message .= "❌ <b>MODS Status:</b> LOCKED\n";
        $message .= "📈 Need {$remaining} more referrals";
    }
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleReferralEarn($botToken, $chatId, $userId) {
    $adminData = loadAdminData();
    $referralLink = $adminData['referral_link'] ?? 'https://t.me/PANTHERKEYGENERATEBOT';
    $referralCount = count($adminData['referrals'][$userId] ?? []);
    $requiredRefs = $adminData['mods_required_refs'] ?? 10;
    
    $message = "👥 <b>REFERRAL & EARN</b>\n\n";
    $message .= "🔗 <b>Your Referral Link:</b>\n";
    $message .= "<code>{$referralLink}?start={$userId}</code>\n\n";
    $message .= "💰 <b>Rewards:</b>\n";
    $message .= "• 1 coin per successful referral\n";
    $message .= "• MODS access after {$requiredRefs} referrals\n\n";
    $message .= "📊 <b>Your Progress:</b> {$referralCount}/{$requiredRefs} referrals\n\n";
    $message .= "💡 <i>Share your link with friends to earn coins and unlock MODS!</i>";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleHelp($botToken, $chatId) {
    $message = "🤖 <b>USER COMMANDS</b>\n\n";
    $message .= "🎮 <b>MODS ACCESS</b> - Get MODS access link (requires 10 referrals)\n";
    $message .= "🎁 <b>GIFT CODE</b> - Redeem gift codes for coins\n";
    $message .= "💰 <b>MY COINS</b> - Check your coin balance\n";
    $message .= "📊 <b>MY STATS</b> - View your statistics\n";
    $message .= "👥 <b>REFERRAL & EARN</b> - Get your referral link\n";
    $message .= "🔄 <b>/verify</b> - Re-verify channel membership\n";
    $message .= "📋 <b>/allseastarttimechannel</b> - Show ALL channels\n";
    $message .= "🎯 <b>/wherewealreadyjionnotshow</b> - Show only NOT joined channels\n";
    $message .= "ℹ️ <b>/help</b> - Show this help message\n\n";
    $message .= "💡 <i>Use the buttons below or type the commands!</i>";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleHelpOwner($botToken, $chatId) {
    $message = "👑 <b>OWNER COMMANDS</b>\n\n";
    $message .= "📊 /stats - View bot statistics\n";
    $message .= "📢 /broadcast - Send message to all users\n";
    $message .= "💰 /addcoins USER_ID AMOUNT - Add coins to user\n";
    $message .= "💰 /removecoins USER_ID AMOUNT - Remove coins from user\n";
    $message .= "🎁 /setgift USER_ID AMOUNT - Set user's coin balance\n";
    $message .= "🎁 /makegiftcode CODE AMOUNT - Create gift codes\n";
    $message .= "📢 /set_channel NAME URL CHANNEL_ID - Set verification channel\n";
    $message .= "📝 /editcaption TEXT - Change bot caption\n";
    $message .= "🖼️ /editphoto URL - Change bot photo\n";
    $message .= "🔗 /editlink NUMBER URL - Edit links\n";
    $message .= "📢 /addchannel NUMBER NAME URL - Add channels\n";
    $message .= "📢 /removechannel NUMBER - Remove channels\n";
    $message .= "🔗 /viewlink - View all links\n";
    $message .= "🔘 /editbutton NUMBER URL - Edit button URLs\n";
    $message .= "🔘 /editbuttonname NUMBER NAME - Edit button names\n";
    $message .= "👥 /editreferral on/off - Enable/disable referrals\n";
    $message .= "💰 /editreferralcoins AMOUNT - Set referral reward\n";
    $message .= "🔍 /grant_search USER_ID - Grant search access\n";
    $message .= "🚫 /ban_user USER_ID - Ban users\n";
    $message .= "✅ /unban_user USER_ID - Unban users\n";
    $message .= "👀 /preview - Preview verification message\n";
    $message .= "🔘 /editbuttontext TEXT - Change button text\n\n";
    $message .= "💡 <i>These commands are for bot owners only</i>";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

// ================= ADMIN COMMANDS =================

function handleStats($botToken, $chatId) {
    $adminData = loadAdminData();
    $totalUsers = count($adminData['user_coins'] ?? []);
    $totalCoins = array_sum($adminData['user_coins'] ?? []);
    $bannedUsers = count($adminData['banned_users'] ?? []);
    $giftCodes = count($adminData['gift_codes'] ?? []);
    $channels = $adminData['channels'] ?? [];
    
    $totalReferrals = 0;
    foreach ($adminData['referrals'] ?? [] as $refs) {
        $totalReferrals += count($refs);
    }
    
    // Check bot admin status for each channel
    $adminChannels = 0;
    $nonAdminChannels = [];
    
    foreach ($channels as $channel) {
        if (is_bot_admin_in_channel($botToken, $channel['id'])) {
            $adminChannels++;
        } else {
            $nonAdminChannels[] = $channel['name'];
        }
    }
    
    $message = "📊 <b>Bot Statistics</b>\n\n";
    $message .= "👥 Total Users: <b>{$totalUsers}</b>\n";
    $message .= "💰 Total Coins: <b>{$totalCoins}</b>\n";
    $message .= "📨 Total Referrals: <b>{$totalReferrals}</b>\n";
    $message .= "🚫 Banned Users: <b>{$bannedUsers}</b>\n";
    $message .= "🎁 Active Gift Codes: <b>{$giftCodes}</b>\n";
    $message .= "📢 Total Channels: <b>" . count($channels) . "</b>\n";
    $message .= "✅ Bot Admin Channels: <b>{$adminChannels}</b>\n";
    
    if (!empty($nonAdminChannels)) {
        $message .= "❌ Non-Admin Channels: <b>" . count($nonAdminChannels) . "</b>\n";
        $message .= "📋 " . implode(', ', $nonAdminChannels) . "\n";
    }
    
    $message .= "\n🎨 <b>Current Design</b>\n";
    $message .= "🖼️ Photo: " . (isset($adminData['photo']) ? "✅ Set" : "❌ Not set") . "\n";
    $message .= "📝 Caption: " . ($adminData['caption'] ?? 'Not set') . "\n";
    $message .= "🔘 Button: " . ($adminData['button_text'] ?? 'Verify') . "\n\n";
    $message .= "Use /preview to see current design";
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleBroadcast($botToken, $chatId, $text) {
    $adminData = loadAdminData();
    $users = array_keys($adminData['user_coins'] ?? []);
    $message = substr($text, 11); // Remove "/broadcast "
    
    if (empty($message)) {
        tg_send_message($botToken, $chatId, "❌ Usage: /broadcast Your message here");
        return;
    }
    
    $success = 0;
    $failed = 0;
    
    foreach ($users as $userId) {
        if (tg_send_message($botToken, $userId, "📢 <b>Broadcast Message</b>\n\n{$message}", 'HTML')) {
            $success++;
        } else {
            $failed++;
        }
        usleep(500000); // 0.5 second delay
    }
    
    tg_send_message($botToken, $chatId, "✅ Broadcast completed!\n✅ Successful: {$success}\n❌ Failed: {$failed}");
}

function handleAddCoins($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /addcoins USER_ID AMOUNT");
        return;
    }
    
    $userId = intval($parts[1]);
    $amount = intval($parts[2]);
    
    debug_log("Adding {$amount} coins to user {$userId}");
    
    $adminData = loadAdminData();
    $oldBalance = $adminData['user_coins'][$userId] ?? 0;
    $adminData['user_coins'][$userId] = $oldBalance + $amount;
    
    if (saveAdminData($adminData)) {
        $newBalance = $adminData['user_coins'][$userId];
        tg_send_message($botToken, $chatId, "✅ Added {$amount} coins to user {$userId}\nOld balance: {$oldBalance}\nNew balance: {$newBalance}");
        debug_log("Successfully added coins. New balance: {$newBalance}");
    } else {
        tg_send_message($botToken, $chatId, "❌ Failed to save data. Check file permissions.");
        debug_log("Failed to save admin data");
    }
}

function handleRemoveCoins($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /removecoins USER_ID AMOUNT");
        return;
    }
    
    $userId = intval($parts[1]);
    $amount = intval($parts[2]);
    
    $adminData = loadAdminData();
    $currentCoins = $adminData['user_coins'][$userId] ?? 0;
    $newCoins = max(0, $currentCoins - $amount);
    $adminData['user_coins'][$userId] = $newCoins;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Removed {$amount} coins from user {$userId}\nNew balance: {$newCoins}");
}

function handleSetGift($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /setgift USER_ID AMOUNT");
        return;
    }
    
    $userId = intval($parts[1]);
    $amount = intval($parts[2]);
    
    $adminData = loadAdminData();
    $adminData['user_coins'][$userId] = $amount;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Set user {$userId} coins to {$amount}");
}

function handleMakeGiftCode($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /makegiftcode CODE AMOUNT");
        return;
    }
    
    $code = $parts[1];
    $amount = intval($parts[2]);
    
    $adminData = loadAdminData();
    $adminData['gift_codes'][$code] = [
        'amount' => $amount,
        'uses' => 0,
        'max_uses' => 100,
        'created_at' => time()
    ];
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Created gift code: {$code}\n💰 Amount: {$amount} coins\n🎯 Max uses: 100");
}

function handleSetChannel($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 4);
    if (count($parts) < 4) {
        tg_send_message($botToken, $chatId, "❌ Usage: /set_channel NAME URL CHANNEL_ID");
        return;
    }
    
    $name = $parts[1];
    $url = $parts[2];
    $channelId = intval($parts[3]);
    
    $adminData = loadAdminData();
    $adminData['channels'] = [[
        'name' => $name,
        'url' => $url,
        'id' => $channelId
    ]];
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Channel set successfully!\n📢 Name: {$name}\n🔗 URL: {$url}\n🆔 ID: {$channelId}");
}

function handleEditCaption($botToken, $chatId, $text) {
    $caption = substr($text, 13); // Remove "/editcaption "
    
    if (empty($caption)) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editcaption Your new caption text");
        return;
    }
    
    $adminData = loadAdminData();
    $adminData['caption'] = $caption;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Caption updated successfully!");
}

function handleEditPhoto($botToken, $chatId, $text) {
    $photoUrl = substr($text, 11); // Remove "/editphoto "
    
    if (empty($photoUrl)) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editphoto PHOTO_URL");
        return;
    }
    
    $adminData = loadAdminData();
    $adminData['photo'] = $photoUrl;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Verification photo updated successfully!");
}

function handleEditLink($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editlink NUMBER URL");
        return;
    }
    
    $number = intval($parts[1]);
    $url = $parts[2];
    
    $adminData = loadAdminData();
    $adminData['links'][$number] = $url;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Link {$number} updated to: {$url}");
}

// ================= FIXED ADDCHANNEL FUNCTION =================
function handleAddChannel($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 4);
    if (count($parts) < 4) {
        tg_send_message($botToken, $chatId, 
            "❌ <b>Usage:</b> /addchannel NUMBER NAME URL\n\n" .
            "📝 <b>Example:</b>\n" .
            "<code>/addchannel 1 SYTEAM https://t.me/syteamofficial</code>\n\n" .
            "🔢 <b>Number:</b> 1-20\n" .
            "📢 <b>Name:</b> Channel display name\n" .
            "🔗 <b>URL:</b> Full channel URL", 'HTML');
        return;
    }
    
    $number = intval($parts[1]);
    $name = $parts[2];
    $url = $parts[3];
    
    // AUTO-FIX: If name contains URL, separate them
    if (strpos($name, 'http') !== false) {
        $nameParts = explode('http', $name, 2);
        $name = trim($nameParts[0]);
        $url = 'http' . $nameParts[1];
        debug_log("Auto-fixed channel: name='{$name}', url='{$url}'");
    }
    
    // Validate number range
    if ($number < 1 || $number > 20) {
        tg_send_message($botToken, $chatId, "❌ Channel number must be between 1 and 20");
        return;
    }
    
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        tg_send_message($botToken, $chatId, "❌ Invalid URL format: {$url}");
        return;
    }
    
    $adminData = loadAdminData();
    
    // Check if channel number already exists
    if (isset($adminData['channels'][$number])) {
        $existingName = $adminData['channels'][$number]['name'];
        tg_send_message($botToken, $chatId, 
            "❌ Channel number <b>{$number}</b> already exists!\n" .
            "📢 Current: <b>{$existingName}</b>\n\n" .
            "Use <code>/removechannel {$number}</code> first or choose a different number.", 'HTML');
        return;
    }
    
    $adminData['channels'][$number] = [
        'name' => $name,
        'url' => $url,
        'id' => -1000000000000 + $number // Temporary ID
    ];
    
    if (saveAdminData($adminData)) {
        $message = "✅ <b>Channel {$number} Added Successfully!</b>\n\n";
        $message .= "📢 <b>Name:</b> {$name}\n";
        $message .= "🔗 <b>URL:</b> {$url}\n";
        $message .= "🆔 <b>Number:</b> {$number}\n\n";
        $message .= "🚀 <b>Next Steps:</b>\n";
        $message .= "1. Add bot to channel as admin\n";
        $message .= "2. Use /start to test verification\n";
        $message .= "3. Use /preview to see how it looks";
        
        tg_send_message($botToken, $chatId, $message, 'HTML');
        debug_log("Channel {$number} added successfully: {$name} - {$url}");
    } else {
        tg_send_message($botToken, $chatId, "❌ Failed to save channel. Check file permissions.");
    }
}

function handleRemoveChannel($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        tg_send_message($botToken, $chatId, "❌ Usage: /removechannel NUMBER");
        return;
    }
    
    $number = intval($parts[1]);
    
    $adminData = loadAdminData();
    if (isset($adminData['channels'][$number])) {
        $channelName = $adminData['channels'][$number]['name'];
        unset($adminData['channels'][$number]);
        saveAdminData($adminData);
        tg_send_message($botToken, $chatId, "✅ Channel {$number} ({$channelName}) removed");
    } else {
        tg_send_message($botToken, $chatId, "❌ Channel {$number} not found");
    }
}

function handleViewLink($botToken, $chatId) {
    $adminData = loadAdminData();
    $links = $adminData['links'] ?? [];
    
    if (empty($links)) {
        tg_send_message($botToken, $chatId, "❌ No links configured");
        return;
    }
    
    $message = "🔗 <b>Configured Links</b>\n\n";
    foreach ($links as $number => $url) {
        $message .= "{$number}: {$url}\n";
    }
    
    tg_send_message($botToken, $chatId, $message, 'HTML');
}

function handleEditButton($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editbutton NUMBER URL");
        return;
    }
    
    $number = intval($parts[1]);
    $url = $parts[2];
    
    $adminData = loadAdminData();
    $adminData['buttons'][$number] = ['url' => $url];
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Button {$number} URL updated to: {$url}");
}

function handleEditButtonName($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 3);
    if (count($parts) < 3) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editbuttonname NUMBER NAME");
        return;
    }
    
    $number = intval($parts[1]);
    $name = $parts[2];
    
    $adminData = loadAdminData();
    if (isset($adminData['buttons'][$number])) {
        $adminData['buttons'][$number]['name'] = $name;
    } else {
        $adminData['buttons'][$number] = ['name' => $name];
    }
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Button {$number} name updated to: {$name}");
}

function handleEditReferral($botToken, $chatId, $text) {
    $enabled = strtolower(substr($text, 15)); // Remove "/editreferral "
    $enabled = in_array($enabled, ['on', 'true', '1', 'yes']);
    
    $adminData = loadAdminData();
    $adminData['referral']['enabled'] = $enabled;
    saveAdminData($adminData);
    
    $status = $enabled ? 'enabled' : 'disabled';
    tg_send_message($botToken, $chatId, "✅ Referral system {$status}");
}

function handleEditReferralCoins($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editreferralcoins AMOUNT");
        return;
    }
    
    $amount = intval($parts[1]);
    
    $adminData = loadAdminData();
    $adminData['referral']['coins'] = $amount;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Referral reward set to {$amount} coins");
}

function handleGrantSearch($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        tg_send_message($botToken, $chatId, "❌ Usage: /grant_search USER_ID");
        return;
    }
    
    $userId = intval($parts[1]);
    
    $adminData = loadAdminData();
    $adminData['grant_search_users'][$userId] = true;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Search access granted to user {$userId}");
}

function handleBanUser($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        tg_send_message($botToken, $chatId, "❌ Usage: /ban_user USER_ID");
        return;
    }
    
    $userId = intval($parts[1]);
    
    $adminData = loadAdminData();
    $adminData['banned_users'][$userId] = true;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ User {$userId} has been banned");
}

function handleUnbanUser($botToken, $chatId, $text) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        tg_send_message($botToken, $chatId, "❌ Usage: /unban_user USER_ID");
        return;
    }
    
    $userId = intval($parts[1]);
    
    $adminData = loadAdminData();
    if (isset($adminData['banned_users'][$userId])) {
        unset($adminData['banned_users'][$userId]);
        saveAdminData($adminData);
        tg_send_message($botToken, $chatId, "✅ User {$userId} has been unbanned");
    } else {
        tg_send_message($botToken, $chatId, "❌ User {$userId} is not banned");
    }
}

function handlePreview($botToken, $chatId) {
    $adminData = loadAdminData();
    $channels = $adminData['channels'] ?? [];
    
    if (empty($channels)) {
        tg_send_message($botToken, $chatId, "❌ No channels added yet. Use /addchannel first.");
        return;
    }
    
    // Send preview to admin
    $photo = $adminData['photo'] ?? 'https://i.postimg.cc/tZSyWY6z/1000452610.jpg';
    $caption = "🔍 PREVIEW: " . ($adminData['caption'] ?? 'Join all channels below to continue!');
    $buttonText = $adminData['button_text'] ?? 'Verify';
    
    // Create buttons (2 per line)
    $tempButtons = [];
    foreach ($channels as $ch) {
        $tempButtons[] = ['text' => '📢 ' . $ch['name'], 'url' => $ch['url']];
    }
    
    $buttons = [];
    for ($i = 0; $i < count($tempButtons); $i += 2) {
        $line = [];
        if (isset($tempButtons[$i])) $line[] = $tempButtons[$i];
        if (isset($tempButtons[$i + 1])) $line[] = $tempButtons[$i + 1];
        $buttons[] = $line;
    }
    
    $buttons[] = [['text' => '✅ ' . $buttonText, 'callback_data' => 'check_joined']];
    
    $markup = ['inline_keyboard' => $buttons];
    
    $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";
    $payload = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'reply_markup' => $markup,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        tg_send_message($botToken, $chatId, "✅ Preview sent successfully!");
    } else {
        tg_send_message($botToken, $chatId, "❌ Failed to send preview");
    }
}

function handleEditButtonText($botToken, $chatId, $text) {
    $buttonText = substr($text, 17); // Remove "/editbuttontext "
    
    if (empty($buttonText)) {
        tg_send_message($botToken, $chatId, "❌ Usage: /editbuttontext BUTTON_TEXT");
        return;
    }
    
    $adminData = loadAdminData();
    $adminData['button_text'] = $buttonText;
    saveAdminData($adminData);
    
    tg_send_message($botToken, $chatId, "✅ Button text updated to: {$buttonText}");
}

function log_event(array $payload): void {
    $line = json_encode(['time' => date('c'), 'event' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// Extract message or callback query
$message = $update['message'] ?? $update['edited_message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

if ($callbackQuery) {
    debug_log("Callback query received: " . json_encode($callbackQuery));
    
    $chatId = (int)($callbackQuery['message']['chat']['id'] ?? $callbackQuery['from']['id'] ?? 0);
    $userId = (int)($callbackQuery['from']['id'] ?? 0);
    $data = $callbackQuery['data'] ?? '';
    
    if ($data === 'check_joined') {
        if (tg_require_channels($botToken, $userId, $chatId)) {
            // Answer callback query
            $answerUrl = "https://api.telegram.org/bot{$botToken}/answerCallbackQuery";
            $payload = ['callback_query_id' => $callbackQuery['id']];
            $ch = curl_init($answerUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    // Handle user dashboard buttons
    else if ($data === 'mods_access') {
        handleModsAccess($botToken, $chatId, $userId);
    }
    else if ($data === 'gift_code') {
        handleGiftCode($botToken, $chatId, $userId);
    }
    else if ($data === 'my_coins') {
        handleMyCoins($botToken, $chatId, $userId);
    }
    else if ($data === 'my_stats') {
        handleMyStats($botToken, $chatId, $userId);
    }
    else if ($data === 'referral_earn') {
        handleReferralEarn($botToken, $chatId, $userId);
    }
    
    http_response_code(200);
    echo 'OK';
    exit;
}

if (!$message) {
    debug_log("No message in update: " . json_encode($update));
    http_response_code(200);
    echo 'Ignored update type';
    exit;
}

$chatId = (int)($message['chat']['id'] ?? 0);
$messageId = (int)($message['message_id'] ?? 0);
$text = trim((string)($message['text'] ?? ''));
$from = $message['from'] ?? [];
$userId = (int)($from['id'] ?? 0);

debug_log("Message received - Chat: {$chatId}, User: {$userId}, Text: {$text}");

log_event(['chatId' => $chatId, 'userId' => $userId, 'text' => $text]);

if ($chatId === 0) {
    http_response_code(200);
    echo 'No chat id';
    exit;
}

// Check if user is banned
$adminData = loadAdminData();
if (isset($adminData['banned_users'][$userId])) {
    tg_send_message($botToken, $chatId, "🚫 You have been banned from using this bot.");
    http_response_code(200);
    echo 'OK';
    exit;
}

// Handle referral from start parameter
if (strpos($text, '/start') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) > 1 && is_numeric($parts[1])) {
        $referrerId = intval($parts[1]);
        if ($referrerId != $userId) {
            $coinsEarned = addReferral($referrerId, $userId);
            if ($coinsEarned > 0) {
                tg_send_message($botToken, $referrerId, 
                    "🎉 <b>New Referral!</b>\n\n" .
                    "You earned <b>{$coinsEarned} coin</b> from referral!\n" .
                    "Total coins: <b>{$adminData['user_coins'][$referrerId]}</b>\n" .
                    "Total referrals: <b>" . count($adminData['referrals'][$referrerId] ?? []) . "</b>", 'HTML');
            }
        }
    }
}

// Handle commands
$handled = false;
if ($text !== '') {
    $parts = preg_split('/\s+/', $text);
    $command = strtolower($parts[0]);

    // Admin commands
    if (isAdmin($userId)) {
        switch ($command) {
            case '/stats': handleStats($botToken, $chatId); $handled = true; break;
            case '/broadcast': handleBroadcast($botToken, $chatId, $text); $handled = true; break;
            case '/addcoins': handleAddCoins($botToken, $chatId, $text); $handled = true; break;
            case '/removecoins': handleRemoveCoins($botToken, $chatId, $text); $handled = true; break;
            case '/setgift': handleSetGift($botToken, $chatId, $text); $handled = true; break;
            case '/makegiftcode': handleMakeGiftCode($botToken, $chatId, $text); $handled = true; break;
            case '/set_channel': handleSetChannel($botToken, $chatId, $text); $handled = true; break;
            case '/editcaption': handleEditCaption($botToken, $chatId, $text); $handled = true; break;
            case '/editphoto': handleEditPhoto($botToken, $chatId, $text); $handled = true; break;
            case '/editlink': handleEditLink($botToken, $chatId, $text); $handled = true; break;
            case '/addchannel': handleAddChannel($botToken, $chatId, $text); $handled = true; break;
            case '/removechannel': handleRemoveChannel($botToken, $chatId, $text); $handled = true; break;
            case '/viewlink': handleViewLink($botToken, $chatId); $handled = true; break;
            case '/editbutton': handleEditButton($botToken, $chatId, $text); $handled = true; break;
            case '/editbuttonname': handleEditButtonName($botToken, $chatId, $text); $handled = true; break;
            case '/editreferral': handleEditReferral($botToken, $chatId, $text); $handled = true; break;
            case '/editreferralcoins': handleEditReferralCoins($botToken, $chatId, $text); $handled = true; break;
            case '/grant_search': handleGrantSearch($botToken, $chatId, $text); $handled = true; break;
            case '/ban_user': handleBanUser($botToken, $chatId, $text); $handled = true; break;
            case '/unban_user': handleUnbanUser($botToken, $chatId, $text); $handled = true; break;
            case '/preview': handlePreview($botToken, $chatId); $handled = true; break;
            case '/editbuttontext': handleEditButtonText($botToken, $chatId, $text); $handled = true; break;
            case '/helpowner': handleHelpOwner($botToken, $chatId); $handled = true; break;
            case '/allseastarttimechannel': handleAllChannelsMode($botToken, $chatId, $userId); $handled = true; break;
            case '/wherewealreadyjionnotshow': handleOnlyNotJoinedMode($botToken, $chatId, $userId); $handled = true; break;
        }
    }

    // Regular user commands
    if (!$handled) {
        switch ($command) {
            case '/start':
                $adminData = loadAdminData();
                $channels = $adminData['channels'] ?? [];
                
                if (empty($channels)) {
                    // No channels added yet
                    tg_send_message($botToken, $chatId, "🤖 <b>Welcome to the Bot!</b>\n\nNo verification channels set up yet.\nPlease check back later!");
                } else {
                    // Always show verification message with current mode
                    tg_send_verification_message($botToken, $chatId, $userId, $channels);
                }
                $handled = true;
                break;
                
            case '/verify':
                tg_require_channels($botToken, $userId, $chatId);
                $handled = true;
                break;
                
            case '/help':
                handleHelp($botToken, $chatId);
                $handled = true;
                break;
                
            case '/helpowner':
                if (isAdmin($userId)) {
                    handleHelpOwner($botToken, $chatId);
                } else {
                    tg_send_message($botToken, $chatId, "❌ This command is for bot owners only.");
                }
                $handled = true;
                break;
                
            case '/allseastarttimechannel':
                handleAllChannelsMode($botToken, $chatId, $userId);
                $handled = true;
                break;
                
            case '/wherewealreadyjionnotshow':
                handleOnlyNotJoinedMode($botToken, $chatId, $userId);
                $handled = true;
                break;
        }
    }
}

if (!$handled) {
    tg_require_channels($botToken, $userId, $chatId);
}

http_response_code(200);
echo 'OK';