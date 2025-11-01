# Mr. Cloak - Changelog

## Version 3.0.1 (2025-10-28) - SECURITY UPDATE

### 🚨 **Critical Security Fix**

Fixed a security vulnerability where revoked/expired licenses would continue working indefinitely using cached data.

#### **What Changed:**

1. **Smart Error Handling** ([class-api-client.php:153-200](includes/class-api-client.php#L153-L200))
   - Plugin now distinguishes between authentication errors (401/403) and network errors
   - **401/403 errors**: License immediately revoked, cache cleared, filtering disabled
   - **Network errors**: Temporary fail-open behavior (safe)

2. **Revoked Status Enforcement** ([class-api-client.php:241-275](includes/class-api-client.php#L241-L275))
   - Added pre-cache check for revoked/expired status
   - Prevents cached "valid" status from overriding revoked licenses
   - Removed caching of invalid status (forces immediate re-check)

3. **Version Bump**
   - Updated to version 3.0.1
   - All plugin installations should be updated immediately

#### **Before This Fix:**
- ❌ Revoked license: Plugin continues working with cached data indefinitely
- ❌ Message: "API connection issues detected. Using cached data."

#### **After This Fix:**
- ✅ Revoked license: Plugin stops working immediately (within 5 minutes max)
- ✅ Cache cleared, filtering disabled
- ✅ Clear error message: "License validation failed"

#### **Impact:**

**For Users with Valid Licenses:**
- ✅ No impact - continues working normally
- ✅ Still resilient to temporary API outages
- ✅ Performance unchanged (5-minute cache still active)

**For Revoked/Expired Licenses:**
- ✅ Plugin enforcement works correctly now
- ✅ Can no longer use service after cancellation

### **Testing:**

See [SECURITY_FIX.md](SECURITY_FIX.md) for detailed testing instructions.

---

## Version 3.0.0 (2025-10-27)

### 🎉 Major Release - SaaS Platform Launch

Complete rewrite from Facebook Bot Detector to Mr. Cloak SaaS platform.

#### **New Features:**

1. **SaaS-Based License Management**
   - Cloud-based license activation and validation
   - Automatic subscription status sync
   - Rolling JWT tokens for security
   - Domain whitelisting and security

2. **Backend API Integration**
   - RESTful API communication
   - Batch analytics submission
   - Real-time mask synchronization
   - Heartbeat monitoring

3. **Hybrid Validation Approach**
   - Server-side license validation every 5 minutes
   - Local caching for performance
   - Fail-open for network resilience
   - Graceful degradation

4. **Advanced Bot Detection**
   - Browser fingerprinting (Canvas, WebGL, Audio)
   - Headless browser detection
   - Behavioral analysis
   - Honeypot traps
   - Multi-layer confidence scoring

5. **Analytics Queue System**
   - Local event queuing
   - Batch submission (50 events or hourly)
   - Retry logic for failed submissions
   - Real-time dashboard sync

6. **Domain Security Enforcement**
   - Strict mode: Domain whitelisting required
   - Flexible mode: Auto-approve with revocation
   - Domain normalization
   - IP whitelisting

7. **Multi-Mask Support**
   - Multiple campaign masks per license
   - Page-specific mask assignment
   - Per-mask analytics
   - Individual mask configuration

#### **Technical Improvements:**

- Request signature verification (HMAC-SHA256)
- Device fingerprinting for token theft prevention
- Plugin integrity checking
- Comprehensive API audit logging
- Rate limiting handling
- Enhanced security headers

#### **Migration from v2.x:**

- Automatic database cleanup of old tables
- Settings migration
- Seamless upgrade process
- No manual configuration required

---

## Version 2.0.0 (2025-08-16) - Facebook Bot Detector Pro

### Major Features Added

**Smart Redirection System:**
- Auto-redirect non-whitelisted visitors
- Multiple redirect methods
- Comprehensive redirect logging

**Advanced Whitelist Management:**
- Default Facebook bot patterns
- Auto-whitelisting
- Import/export functionality

**Enhanced Admin Interface:**
- New Whitelist management page
- Redirect Settings page
- Real-time statistics

---

## Version 1.0.0 (2025-08-15) - Facebook Bot Detector

### Initial Release
- Facebook bot detection
- IP verification (AS32934)
- User agent pattern matching
- Admin dashboard
- Export functionality

---

## Upgrade Instructions

### From Version 3.0.0 to 3.0.1

1. **Backup your WordPress site** (recommended)
2. **Upload updated plugin files** via FTP or WordPress admin
3. **Activate the plugin** (or reload if already active)
4. **Test validation**: Visit your admin dashboard to verify license status
5. **Done!** No database changes required.

### From Version 2.x to 3.0.1

1. **Deactivate** Facebook Bot Detector plugin
2. **Upload** Mr. Cloak plugin
3. **Activate** and configure license key
4. Old settings will be migrated automatically
5. Old database tables will be cleaned up

### Important Notes:

- Version 3.0.1 is a **security update** - all sites should update
- No breaking changes between 3.0.0 and 3.0.1
- Existing configurations remain intact
- License keys do not need to be re-entered

---

## Developer Notes

### API Backend Requirements

Ensure your validation endpoint returns proper HTTP status codes:

- **200**: License valid and active
- **401**: License invalid/revoked/expired (triggers immediate enforcement)
- **403**: Domain not authorized
- **429**: Rate limited
- **500/503**: Server error (triggers temporary fail-open)

### Testing Revocation Flow

```bash
# 1. Revoke license in backend
# 2. Wait max 5 minutes (cache TTL)
# 3. Check WordPress site
# Expected: Filtering disabled, cache cleared
```

### Version History

- **3.0.1**: Security fix for license enforcement
- **3.0.0**: Initial SaaS release
- **2.0.0**: Facebook Bot Detector Pro with redirects
- **1.0.0**: Initial Facebook Bot Detector

---

## Support

For issues or questions:
- GitHub: [github.com/mrcloak/wp-plugin](https://github.com/mrcloak/wp-plugin)
- Documentation: [docs.mrcloak.com](https://docs.mrcloak.com)
- Dashboard: [mrcloak.com/dashboard](https://mrcloak.com/dashboard)
- Support: support@mrcloak.com
