# Mr. Cloak WordPress Plugin v3.0

> **SaaS-powered traffic filtering and bot detection for affiliate marketers.**
> Cloak your campaigns from ad review bots (Facebook Ads, Google Ads, TikTok Ads, etc.)

---

## 🚀 Features

- **Bot Detection**: Detects ad review bots, social media bots, and SEO crawlers
- **Traffic Filtering**: Filter by country, language, OS, browser, VPN/proxy
- **Mask-Based Routing**: Redirect qualified traffic to offer pages
- **Analytics**: Real-time visitor analytics sent to Mr. Cloak dashboard
- **Security**: Encrypted license keys, obfuscated bot patterns, request signing
- **Flexible Redirects**: PHP header (301/302/307), JavaScript, or Meta refresh

---

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- HTTPS enabled (required for API communication)
- Mr. Cloak account with active license

---

## 📦 Installation

### Method 1: WordPress Admin

1. Download `mr-cloak.zip`
2. Go to **WordPress Admin > Plugins > Add New**
3. Click **Upload Plugin**
4. Choose `mr-cloak.zip` and click **Install Now**
5. Click **Activate Plugin**

### Method 2: Manual Upload

1. Extract `mr-cloak.zip`
2. Upload `mr-cloak` folder to `/wp-content/plugins/`
3. Go to **WordPress Admin > Plugins**
4. Find **Mr. Cloak** and click **Activate**

---

## ⚙️ Quick Start

### 1. Activate License

1. Go to **Mr. Cloak > Settings** in WordPress admin
2. Enter your license key (format: `MRC-XXXXXXXX-XXXX-XXXX`)
3. Click **Activate License**

### 2. Enable a Mask

1. Create a mask in your [Mr. Cloak Dashboard](https://mrcloak.com/dashboard/masks)
2. In WordPress: **Mr. Cloak > Settings**
3. Click **Enable Mask** on the mask you want to use

### 3. Test

- Open your site in incognito window
- You should see safe page (if blocked) or get redirected to offer (if whitelisted)

---

## 🎯 How It Works

```
Visitor arrives at your site
    ↓
Plugin checks:
├─ Is this a bot? (Google Ads, Facebook Ads, TikTok Ads, etc.)
├─ Country allowed?
├─ Language allowed?
├─ VPN/Proxy?
├─ OS/Browser allowed?
    ↓
Decision:
├─ ✅ Whitelisted → Redirect to offer page
├─ ⛔ Blocked → Show safe page
└─ 🤖 Bot → Show safe page
    ↓
Analytics → Mr. Cloak Dashboard
```

---

## 🤖 Bot Detection

### Ad Review Bots (Blocked when enabled)
- Google Ads Review Crawler
- Facebook Ads Review Bot
- TikTok Ads Review Bot
- Snapchat, Twitter, LinkedIn, Pinterest Ads Bots

### Social/Messaging Bots (Blocked when enabled)
- Facebook, Twitter, LinkedIn link previews
- Telegram, WhatsApp, Slack bots

### SEO Bots (Always allowed unless blacklisted)
- Googlebot
- Bingbot

---

## 📊 Analytics

Events are automatically sent to your Mr. Cloak dashboard:

- Visitor type (bot, whitelisted, blocked)
- Country, browser, OS
- Block reason
- Timestamp

View detailed analytics at [mrcloak.com/dashboard/analytics](https://mrcloak.com/dashboard/analytics)

---

## 🔧 Configuration

### Redirect Methods

Choose from:
- **PHP Header (301)**: Fast, SEO-friendly
- **JavaScript**: Works if headers already sent
- **Meta Refresh**: Most compatible

Set in **Mr. Cloak > Settings > Redirect Settings**

### Cron Jobs

Background tasks run automatically:
- **Heartbeat**: Every 30 min (refreshes token)
- **Analytics**: Every hour (submits queued events)

---

## 🔐 Security Features

- ✅ AES-256 encrypted license keys
- ✅ Request signing (HMAC-SHA256)
- ✅ Device fingerprinting
- ✅ Plugin integrity checks
- ✅ Obfuscated bot patterns
- ✅ IP anonymization (GDPR compliant)

See `SECURITY.md` for details.

---

## 🐛 Troubleshooting

**License won't activate?**
- Check format: `MRC-XXXXXXXX-XXXX-XXXX`
- Verify license active at [mrcloak.com/dashboard](https://mrcloak.com/dashboard)

**Masks not loading?**
- Click **Refresh Masks** button
- Check you have masks in dashboard

**Redirects not working?**
- Verify mask is enabled
- Check visitor matches mask filters
- Try different redirect method

More help: [mrcloak.com/docs](https://mrcloak.com/docs)

---

## 📞 Support

- **Docs**: [mrcloak.com/docs](https://mrcloak.com/docs)
- **Email**: support@mrcloak.com
- **Dashboard**: [mrcloak.com/dashboard](https://mrcloak.com/dashboard)

---

## 🔄 Upgrading from v2.x

Plugin automatically migrates:
- ✅ Removes old database tables
- ✅ Cleans up old settings
- ✅ Migrates redirect method

**Action required:**
1. Activate your license
2. Create masks in dashboard
3. Enable a mask

---

## 📄 Files

- `mr-cloak.php` - Main plugin file
- `includes/` - Core classes
- `admin/` - Admin pages
- `SECURITY.md` - Security documentation
- `WORDPRESS_PLUGIN_API.md` - API reference

---

## 📜 License

GPL v2 or later

© 2025 Mr. Cloak. All rights reserved.

---

**Ready to cloak your campaigns?** 🚀
[Get Started →](https://mrcloak.com/pricing)
