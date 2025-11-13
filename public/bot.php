<?php
// Simple health check for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "🤖 Telegram Bot - Channel Join Verification\n";
    echo "✅ Bot is running successfully!\n";
    echo "🕒 Server Time: " . date('Y-m-d H:i:s') . "\n";
    echo "🌐 Webhook Ready: Yes\n";
    exit;
}

// Your existing bot code starts here
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

// ... rest of your existing code continues exactly as is
