# Mr. Cloak - WordPress Plugin API Documentation

> **Official API documentation for integrating the Mr. Cloak WordPress plugin with the SaaS backend.**
>
> **Base URL**: `https://mrcloak.com` (replace with your actual domain)
>
> **Version**: 1.0.0
>
> **Last Updated**: October 25, 2025

---

## Table of Contents

1. [Overview](#overview)
2. [Domain Security](#domain-security)
3. [Authentication Flow](#authentication-flow)
4. [Rate Limiting](#rate-limiting)
5. [API Endpoints](#api-endpoints)
   - [License Activation](#1-license-activation)
   - [Heartbeat (Keep-Alive)](#2-heartbeat-keep-alive)
   - [Get Masks Configuration](#3-get-masks-configuration)
   - [Submit Analytics](#4-submit-analytics)
   - [Get Subscription Status](#5-get-subscription-status)
   - [Enable Mask for Domain](#6-enable-mask-for-domain) ⭐ NEW
   - [Disable Mask for Domain](#7-disable-mask-for-domain) ⭐ NEW
6. [Error Handling](#error-handling)
7. [Code Examples](#code-examples)
8. [Common Workflows](#common-workflows)
9. [Best Practices](#best-practices)

---

## Overview

The Mr. Cloak WordPress plugin communicates with the SaaS backend to:
- ✅ Validate license keys and activate installations
- ✅ Retrieve traffic filtering configurations (masks)
- ✅ Submit visitor analytics data
- ✅ Check subscription status and trial information
- ✅ Maintain active session with rolling JWT tokens

**Key Concepts:**
- **License Key**: Format `MRC-XXXXXXXX-XXXXXXXX-XXXXXXXX` - Unique per user account
- **Access Token**: Short-lived JWT token (60 minutes) for authenticated requests
- **Rolling Tokens**: New token issued with each heartbeat to maintain session
- **Grace Period**: 48 hours after trial/payment expiry before service stops
- **Unlimited Installations**: No limit on number of domains per license
- **Mask-Based Pricing**: Plans allow 3, 10, or 50 active masks depending on tier
- **Domain Security**: Two security modes to prevent unauthorized license usage
  - **Flexible Mode** (Default): Track new domains and send notifications. Allows any domain to activate, but admins can revoke access per domain.
  - **Strict Mode** (Recommended): Only whitelisted domains can activate. New domains are blocked unless explicitly added to the whitelist.

---

## Domain Security

Mr. Cloak implements domain security features to protect license keys from unauthorized use. This is critical for preventing license key theft and abuse.

### Security Modes

Users can configure their license to operate in one of two modes:

#### Flexible Mode (Default)

**How it works:**
- Any domain can activate the license key
- All domain activations are tracked in the `sites` table
- User receives email notifications when new domains activate
- User can revoke access for specific domains from the dashboard
- Revoked domains cannot re-activate the license

**Use cases:**
- Testing on multiple development/staging environments
- Initial setup and configuration
- Flexibility during migration between domains

**Plugin behavior:**
```
1. Plugin calls POST /api/licenses/activate
2. Backend checks if domain has been revoked
3. If not revoked: Activation succeeds, domain tracked
4. If revoked: Returns 403 error "Domain access revoked"
```

#### Strict Mode (Recommended for Production)

**How it works:**
- Only whitelisted domains can activate the license
- User must manually add domains to whitelist before activation
- Supports subdomain wildcards (e.g., `example.com` allows `blog.example.com`)
- New domain activation attempts are blocked
- Maximum security against stolen license keys

**Use cases:**
- Production environments
- High-security requirements
- Known, fixed domain list

**Plugin behavior:**
```
1. Plugin calls POST /api/licenses/activate
2. Backend checks if domain is in whitelist
3. If in whitelist: Activation succeeds
4. If not in whitelist: Returns 403 error "Domain not authorized"
```

### Domain Normalization

The API normalizes domains for consistent matching:

```javascript
// These are considered the same domain:
"example.com"
"www.example.com"
"https://example.com"
"http://www.example.com/"

// Normalized to: "example.com"
```

### Subdomain Matching (Strict Mode Only)

When a domain is whitelisted, all its subdomains are automatically allowed:

```
Whitelisted: "example.com"

Allowed:
✅ example.com
✅ www.example.com
✅ blog.example.com
✅ shop.example.com
✅ api.example.com

Not allowed:
❌ different-site.com
❌ example.net
```

### Domain Status Lifecycle

```
NEW DOMAIN (Flexible Mode)
    ↓
[Active] → User can revoke → [Revoked]
                                ↓
                           User can restore
                                ↓
                             [Active]

NEW DOMAIN (Strict Mode)
    ↓
[Not in whitelist] → User adds to whitelist → [Allowed]
                                                  ↓
                                          User removes from whitelist
                                                  ↓
                                            [Not allowed]
```

### Important Notes for Plugin Development

1. **Handle 403 Errors Gracefully**
   - In Flexible Mode: 403 = Domain has been revoked by user
   - In Strict Mode: 403 = Domain not in whitelist
   - Show appropriate error messages to admin

2. **Domain Detection**
   - Always use the primary domain (not localhost, not IP address)
   - Normalize the domain before sending to API
   - Use `parse_url(home_url(), PHP_URL_HOST)` in WordPress

3. **Revocation Check on Heartbeat**
   - The heartbeat endpoint also checks domain revocation
   - If revoked during session: Returns `status: "revoked"`
   - Plugin should disable filtering immediately

4. **User Communication**
   - In strict mode, inform users to add domain to whitelist first
   - Provide link to dashboard settings page
   - Show clear error messages for unauthorized domains

---

### Initial Setup (First Time)

```
1. User enters License Key in WordPress plugin settings
2. Plugin calls POST /api/licenses/activate
3. Backend validates license and returns:
   - Access Token (JWT, 60-min lifetime)
   - Subscription status (trialing, active, grace, past_due)
   - Features enabled
   - Next check-in timestamp
4. Plugin stores Access Token locally
5. Plugin fetches masks configuration
6. Plugin ready to filter traffic
```

### Ongoing Operation

```
1. Every 30 minutes, plugin calls POST /api/licenses/heartbeat
2. Backend validates current Access Token
3. Backend returns:
   - NEW Access Token (rolling token)
   - Updated subscription status
   - Next check-in timestamp
4. Plugin replaces old token with new token
5. Repeat every 30 minutes
```

**Important**: Always use the latest token received from heartbeat. Old tokens expire after 60 minutes.

---

## Rate Limiting

All endpoints have rate limits to prevent abuse:

| Endpoint | Limit | Per | Identifier |
|----------|-------|-----|------------|
| `/api/licenses/activate` | 10 requests | 1 minute | IP Address |
| `/api/licenses/heartbeat` | 60 requests | 1 minute | License Key |
| `/api/plugin/masks` | No limit | - | - |
| `/api/plugin/analytics` | No limit | - | - |
| `/api/plugin/subscription` | No limit | - | - |

**Rate Limit Headers:**
```
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 2025-10-25T14:32:00.000Z
```

**429 Response:**
```json
{
  "error": "Too many requests"
}
```

---

## API Endpoints

### 1. License Activation

**Endpoint**: `POST /api/licenses/activate`

**Purpose**: Activate a license key on a WordPress domain (first-time setup).

**Authentication**: None (uses license key directly)

**Rate Limit**: 10 requests/minute per IP

#### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "licenseKey": "MRC-12345678-ABCDEFGH-12345678",
  "domain": "example.com",
  "pluginVersion": "1.0.0"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `licenseKey` | string | Yes | User's license key (format: `MRC-XXXXXXXX-XXXXXXXX-XXXXXXXX`) |
| `domain` | string | Yes | Domain where plugin is installed (e.g., `example.com`) |
| `pluginVersion` | string | No | Plugin version for tracking (e.g., `1.0.0`) |

#### Success Response (200 OK)

```json
{
  "accessToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJsaWMiOiJNUkMtMTIzNDU2NzgtQUJDREVGR0gtMTIzNDU2NzgiLCJkb20iOiJleGFtcGxlLmNvbSIsInVpZCI6InVzZXItdXVpZCIsInZlciI6IjEuMC4wIiwiaWF0IjoxNzMwMTIzNDU2LCJleHAiOjE3MzAxMjcwNTZ9.signature",
  "policy": {
    "status": "trialing",
    "trialEndsAt": "2025-11-01T12:00:00.000Z",
    "features": {
      "trafficRouting": true,
      "advancedAnalytics": true,
      "customRules": true
    }
  },
  "nextCheckAt": 1730125256000
}
```

| Field | Type | Description |
|-------|------|-------------|
| `accessToken` | string | JWT token for authenticated requests (expires in 60 minutes) |
| `policy.status` | string | Subscription status: `trialing`, `active`, `grace`, `past_due`, `revoked` |
| `policy.trialEndsAt` | string | ISO timestamp when trial ends (null if not trialing) |
| `policy.features` | object | Enabled features for this subscription |
| `nextCheckAt` | number | Unix timestamp (ms) when to call heartbeat next (30 min from now) |

#### Error Responses

**404 Not Found** - Invalid license key:
```json
{
  "error": "Invalid license key"
}
```

**403 Forbidden** - License revoked:
```json
{
  "error": "License has been revoked"
}
```

**403 Forbidden** - License suspended:
```json
{
  "error": "License is suspended"
}
```

**403 Forbidden** - No active subscription:
```json
{
  "error": "No active subscription"
}
```

**400 Bad Request** - Invalid request data:
```json
{
  "error": "Invalid request data",
  "details": [
    {
      "path": ["licenseKey"],
      "message": "Required"
    }
  ]
}
```

**403 Forbidden** - Domain not authorized (Strict Mode):
```json
{
  "error": "Domain not authorized"
}
```

**Explanation**: The license is in **Strict Mode** and this domain is not in the whitelist. User must log in to dashboard, go to Settings, and add this domain to the whitelist before activation.

**Plugin should display**:
```
⚠️ License Activation Failed

This license requires domain authorization.

Please log in to your Mr. Cloak dashboard and add this domain
to your whitelist under Settings > Domain Security.

Domain: example.com
License: MRC-****-****-XXXX

[Go to Dashboard]
```

**403 Forbidden** - Domain access revoked (Flexible Mode):
```json
{
  "error": "Domain access revoked"
}
```

**Explanation**: The license is in **Flexible Mode** but the user has revoked access for this specific domain from their dashboard.

**Plugin should display**:
```
⚠️ License Activation Failed

Access for this domain has been revoked by the license owner.

If this is your domain and you revoked access by mistake,
you can restore it from your dashboard under Settings >
Domain Security > Active Domains.

Domain: example.com
License: MRC-****-****-XXXX

[Go to Dashboard] [Contact Support]
```

---

### 2. Heartbeat (Keep-Alive)

**Endpoint**: `POST /api/licenses/heartbeat`

**Purpose**: Periodic check-in to verify subscription status and refresh access token.

**Authentication**: Access Token (from activation or previous heartbeat)

**Rate Limit**: 60 requests/minute per license

**Recommended Frequency**: Every 30 minutes

#### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "accessToken": "eyJhbGci...",
  "domain": "example.com",
  "metrics": {
    "totalRequests": 1543,
    "uniqueVisitors": 328,
    "botsBlocked": 45
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `accessToken` | string | Yes | Current JWT token (from activation or last heartbeat) |
| `domain` | string | Yes | Domain making the request (must match token) |
| `metrics` | object | No | Optional usage metrics for display in dashboard |

#### Success Response (200 OK)

**Active subscription:**
```json
{
  "status": "active",
  "message": "License is active",
  "accessToken": "eyJhbGci...",
  "nextCheckAt": 1730127056000,
  "subscription": {
    "trialEndsAt": null,
    "currentPeriodEndAt": "2025-11-30T12:00:00.000Z"
  }
}
```

**Trial with time remaining:**
```json
{
  "status": "trialing",
  "message": "Trial ends in 5 hours",
  "accessToken": "eyJhbGci...",
  "nextCheckAt": 1730127056000,
  "subscription": {
    "trialEndsAt": "2025-11-01T12:00:00.000Z",
    "currentPeriodEndAt": "2025-11-01T12:00:00.000Z"
  }
}
```

**Grace period (trial expired, 48hr window):**
```json
{
  "status": "grace",
  "message": "Trial expired. You have 47 hours left to renew.",
  "accessToken": "eyJhbGci...",
  "nextCheckAt": 1730127056000,
  "subscription": {
    "trialEndsAt": "2025-10-24T12:00:00.000Z",
    "currentPeriodEndAt": "2025-10-24T12:00:00.000Z"
  }
}
```

**Past due (grace period expired):**
```json
{
  "status": "past_due",
  "message": "Trial expired. Please upgrade to continue.",
  "accessToken": "eyJhbGci...",
  "nextCheckAt": 1730127056000,
  "subscription": {
    "trialEndsAt": "2025-10-22T12:00:00.000Z",
    "currentPeriodEndAt": "2025-10-22T12:00:00.000Z"
  }
}
```

**License revoked:**
```json
{
  "status": "revoked",
  "message": "License has been revoked. Please contact support.",
  "nextCheckAt": null
}
```

**License suspended:**
```json
{
  "status": "suspended",
  "message": "License is temporarily suspended.",
  "nextCheckAt": 1730130656000
}
```

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | Current status: `trialing`, `active`, `grace`, `past_due`, `suspended`, `revoked` |
| `message` | string | Human-readable status message |
| `accessToken` | string | **NEW** JWT token - replace your stored token with this |
| `nextCheckAt` | number | Unix timestamp (ms) when to call heartbeat again |
| `subscription.trialEndsAt` | string/null | Trial end timestamp (null if not trialing) |
| `subscription.currentPeriodEndAt` | string/null | Current billing period end timestamp |

#### Error Responses

**401 Unauthorized** - Invalid or expired token:
```json
{
  "error": "Invalid or expired access token"
}
```

**403 Forbidden** - Domain mismatch:
```json
{
  "error": "Domain mismatch"
}
```

**404 Not Found** - License not found:
```json
{
  "error": "License not found"
}
```

**403 Forbidden** - Domain access revoked:
```json
{
  "status": "revoked",
  "message": "Access for this domain has been revoked",
  "nextCheckAt": null
}
```

**Explanation**: The domain was active but has been revoked by the user during the session. This can happen in **Flexible Mode** when the user revokes access from the dashboard.

**Plugin should**:
1. Immediately disable traffic filtering
2. Clear the stored access token
3. Show admin notice explaining the situation
4. Provide link to dashboard for restoration

**Example admin notice**:
```
⚠️ Domain Access Revoked

The license owner has revoked access for this domain.
Traffic filtering has been disabled.

If this was a mistake, you can restore access from your
dashboard under Settings > Domain Security > Revoked Domains.

[Go to Dashboard] [Contact Support]
```

---

### 3. Get Masks Configuration

**Endpoint**: `GET /api/plugin/masks`

**Purpose**: Retrieve all traffic filtering configurations (masks) for the user.

**Authentication**: License Key (query parameter)

**Rate Limit**: None

#### Request

**Query Parameters:**
```
?license_key=MRC-12345678-ABCDEFGH-12345678
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `license_key` | string | Yes | User's license key |

**Example:**
```
GET /api/plugin/masks?license_key=MRC-12345678-ABCDEFGH-12345678
```

#### Success Response (200 OK)

```json
{
  "masks": [
    {
      "id": "mask-uuid-1",
      "name": "US Traffic to Offer A",
      "offer_page_url": "https://example.com/offer-a",
      "filter_vpn_proxy": true,
      "whitelisted_countries": ["US", "CA", "GB"],
      "whitelisted_languages": ["en", "es"],
      "whitelisted_os": ["Windows", "macOS", "Android"],
      "whitelisted_browsers": ["Chrome", "Firefox", "Safari"],
      "block_ad_review_bots": true,
      "block_other_bots": false,
      "bot_whitelist": ["googlebot", "bingbot"],
      "bot_blacklist": [],
      "active_domain": "example.com",
      "created_at": "2025-10-20T10:30:00.000Z",
      "updated_at": "2025-10-24T15:45:00.000Z"
    },
    {
      "id": "mask-uuid-2",
      "name": "EU Traffic Filter",
      "offer_page_url": "https://example.com/offer-b",
      "filter_vpn_proxy": false,
      "whitelisted_countries": ["DE", "FR", "IT", "ES"],
      "whitelisted_languages": ["de", "fr", "it", "es"],
      "whitelisted_os": [],
      "whitelisted_browsers": [],
      "block_ad_review_bots": false,
      "block_other_bots": true,
      "bot_whitelist": [],
      "bot_blacklist": ["facebook-ads-review"],
      "active_domain": null,
      "created_at": "2025-10-21T14:20:00.000Z",
      "updated_at": "2025-10-21T14:20:00.000Z"
    }
  ],
  "license_status": "active",
  "plan_limits": {
    "max_masks": 10
  }
}
```

**Mask Object Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | UUID of the mask |
| `name` | string | User-defined mask name |
| `offer_page_url` | string | URL to redirect whitelisted traffic |
| `filter_vpn_proxy` | boolean | If `true`, block VPN/proxy users |
| `whitelisted_countries` | string[] | ISO 3166-1 alpha-2 country codes (e.g., `["US", "GB"]`) |
| `whitelisted_languages` | string[] | ISO 639-1 language codes (e.g., `["en", "es"]`) |
| `whitelisted_os` | string[] | Operating systems: `Windows`, `macOS`, `Linux`, `Android`, `iOS`, `ChromeOS` |
| `whitelisted_browsers` | string[] | Browsers: `Chrome`, `Firefox`, `Safari`, `Edge`, `Opera`, `Brave`, etc. |
| `block_ad_review_bots` | boolean | **UPDATED**: If `true`, block ad review bots (Facebook Ads, Google Ads, TikTok Ads). SEO bots remain allowed. |
| `block_other_bots` | boolean | **UPDATED**: If `true`, block social media and messaging bots (Twitter, Telegram, WhatsApp, etc.) |
| `bot_whitelist` | string[] | Bots in this list will ALWAYS be allowed, overriding blocking rules |
| `bot_blacklist` | string[] | **NEW**: Bots in this list will ALWAYS be blocked, even SEO bots. Overrides whitelist. |
| `active_domain` | string/null | Domain where this mask is currently active. `null` if not bound to any site |
| `created_at` | string | ISO timestamp when mask was created |
| `updated_at` | string | ISO timestamp when mask was last updated |

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `masks` | array | Array of mask objects belonging to this user |
| `license_status` | string | Current license status (`active`, `suspended`, `revoked`) |
| `plan_limits` | object | **NEW**: Plan limitations for this user |
| `plan_limits.max_masks` | number | Maximum number of masks that can be active simultaneously per domain |

**Empty Masks Response:**
```json
{
  "masks": [],
  "license_status": "active"
}
```

#### Error Responses

**401 Unauthorized** - Missing license key:
```json
{
  "error": "License key is required"
}
```

**401 Unauthorized** - Invalid license:
```json
{
  "error": "Invalid or inactive license key"
}
```

**403 Forbidden** - Inactive subscription:
```json
{
  "error": "Subscription is not active"
}
```

---

### 4. Submit Analytics

**Endpoint**: `POST /api/plugin/analytics`

**Purpose**: Submit visitor analytics events to the backend for dashboard reporting.

**Authentication**: License Key (in request body)

**Rate Limit**: None

**Batch Support**: Yes (send multiple events in one request)

#### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "license_key": "MRC-12345678-ABCDEFGH-12345678",
  "events": [
    {
      "mask_id": "mask-uuid-1",
      "visitor_type": "whitelisted",
      "country_code": "US",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)...",
      "blocked_reason": null,
      "timestamp": "2025-10-25T14:32:15.000Z"
    },
    {
      "mask_id": "mask-uuid-1",
      "visitor_type": "blocked",
      "country_code": "CN",
      "user_agent": "Mozilla/5.0...",
      "blocked_reason": "country_not_whitelisted",
      "timestamp": "2025-10-25T14:33:22.000Z"
    },
    {
      "mask_id": "mask-uuid-2",
      "visitor_type": "bot",
      "country_code": "US",
      "user_agent": "Googlebot/2.1...",
      "blocked_reason": null,
      "timestamp": "2025-10-25T14:34:10.000Z"
    }
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `license_key` | string | Yes | User's license key |
| `events` | array | Yes | Array of analytics events (1-100 events per request) |
| `events[].mask_id` | string | Yes | UUID of the mask that processed this visitor |
| `events[].visitor_type` | string | Yes | Type: `bot`, `whitelisted`, `blocked` |
| `events[].country_code` | string | No | ISO 3166-1 alpha-2 country code (e.g., `US`) |
| `events[].user_agent` | string | No | Browser user agent string |
| `events[].blocked_reason` | string | No | Reason for blocking (if `visitor_type` = `blocked`) |
| `events[].timestamp` | string | No | ISO timestamp (defaults to current time if omitted) |

**Blocked Reason Values:**
- `country_not_whitelisted`
- `language_not_whitelisted`
- `os_not_whitelisted`
- `browser_not_whitelisted`
- `vpn_or_proxy_detected`
- `bot_not_whitelisted`

#### Success Response (200 OK)

**All events inserted:**
```json
{
  "success": true,
  "inserted": 3,
  "failed": 0
}
```

**Partial success (some events failed validation):**
```json
{
  "success": true,
  "inserted": 2,
  "failed": 1,
  "errors": [
    {
      "index": 1,
      "error": "Invalid visitor_type"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always `true` if request was processed |
| `inserted` | number | Number of events successfully inserted |
| `failed` | number | Number of events that failed validation |
| `errors` | array | Details of failed events (only present if `failed > 0`) |
| `errors[].index` | number | Index of failed event in the submitted array |
| `errors[].error` | string | Reason for failure |

#### Error Responses

**401 Unauthorized** - Missing license key:
```json
{
  "error": "License key is required"
}
```

**401 Unauthorized** - Invalid license:
```json
{
  "error": "Invalid license key"
}
```

**403 Forbidden** - Inactive license:
```json
{
  "error": "License is not active"
}
```

**400 Bad Request** - Invalid events array:
```json
{
  "error": "Events array is required"
}
```

**500 Internal Server Error** - Failed to insert:
```json
{
  "error": "Failed to insert analytics"
}
```

---

### 5. Get Subscription Status

**Endpoint**: `GET /api/plugin/subscription`

**Purpose**: Get detailed subscription and plan information.

**Authentication**: License Key (query parameter)

**Rate Limit**: None

#### Request

**Query Parameters:**
```
?license_key=MRC-12345678-ABCDEFGH-12345678
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `license_key` | string | Yes | User's license key |

**Example:**
```
GET /api/plugin/subscription?license_key=MRC-12345678-ABCDEFGH-12345678
```

#### Success Response (200 OK)

**Trial subscription:**
```json
{
  "license": {
    "key": "MRC-12345678-ABCDEFGH-12345678",
    "status": "active"
  },
  "subscription": {
    "status": "trialing",
    "trial_end_at": "2025-11-01T12:00:00.000Z",
    "current_period_end_at": "2025-11-01T12:00:00.000Z",
    "cancel_at_period_end": false,
    "trial_days_left": 5
  },
  "plan": {
    "name": "Pro",
    "features": {
      "max_masks": 10,
      "unlimited_sites": true,
      "analytics": true,
      "vpn_detection": true
    }
  },
  "is_active": true
}
```

**Active subscription:**
```json
{
  "license": {
    "key": "MRC-12345678-ABCDEFGH-12345678",
    "status": "active"
  },
  "subscription": {
    "status": "active",
    "trial_end_at": null,
    "current_period_end_at": "2025-11-30T12:00:00.000Z",
    "cancel_at_period_end": false,
    "trial_days_left": 0
  },
  "plan": {
    "name": "Agency",
    "features": {
      "max_masks": 50,
      "unlimited_sites": true,
      "analytics": true,
      "vpn_detection": true
    }
  },
  "is_active": true
}
```

| Field | Type | Description |
|-------|------|-------------|
| `license.key` | string | License key |
| `license.status` | string | License status: `active`, `suspended`, `revoked` |
| `subscription.status` | string | Subscription status: `trialing`, `active`, `past_due`, `canceled` |
| `subscription.trial_end_at` | string/null | Trial end timestamp (null if not trialing) |
| `subscription.current_period_end_at` | string/null | Current billing period end |
| `subscription.cancel_at_period_end` | boolean | Whether subscription will cancel at period end |
| `subscription.trial_days_left` | number | Days remaining in trial (0 if not trialing) |
| `plan.name` | string | Plan name: `Starter`, `Pro`, `Agency` |
| `plan.features` | object | Plan features and limits |
| `is_active` | boolean | Whether service is active (true if status is `active` or `trialing`) |

#### Error Responses

**401 Unauthorized** - Missing license key:
```json
{
  "error": "License key is required"
}
```

**401 Unauthorized** - Invalid license:
```json
{
  "error": "Invalid license key"
}
```

**404 Not Found** - No subscription:
```json
{
  "error": "No subscription found"
}
```

---

### 6. Enable Mask for Domain

**Endpoint**: `POST /api/plugin/masks/enable`

**Purpose**: Claim/activate a mask for a specific domain. Enforces the rule that one mask can only be active on one domain at a time.

**Authentication**: Access Token (JWT)

**Rate Limit**: None

#### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "accessToken": "eyJhbGci...",
  "maskId": "mask-uuid-1",
  "domain": "example.com"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `accessToken` | string | Yes | Current JWT token (from activation or heartbeat) |
| `maskId` | string | Yes | UUID of the mask to enable |
| `domain` | string | Yes | Domain making the request (must match token) |

#### Success Response (200 OK)

**Mask enabled successfully:**
```json
{
  "success": true,
  "message": "Mask enabled successfully",
  "mask": {
    "id": "mask-uuid-1",
    "name": "US Traffic to Offer A",
    "active_domain": "example.com"
  }
}
```

**Mask already enabled (idempotent):**
```json
{
  "success": true,
  "message": "Mask is already enabled on this domain",
  "mask": {
    "id": "mask-uuid-1",
    "name": "US Traffic to Offer A",
    "active_domain": "example.com"
  }
}
```

#### Error Responses

**401 Unauthorized** - Invalid token:
```json
{
  "error": "Invalid or expired access token"
}
```

**403 Forbidden** - Domain mismatch:
```json
{
  "error": "Domain mismatch"
}
```

**403 Forbidden** - Max masks reached:
```json
{
  "error": "Maximum active masks reached for this domain",
  "max_masks": 1,
  "current_active": 1,
  "message": "Your plan allows 1 active mask per domain. Please disable another mask first or upgrade your plan."
}
```

**404 Not Found** - Mask not found:
```json
{
  "error": "Mask not found or you don't have permission"
}
```

**409 Conflict** - Mask already active on another domain:
```json
{
  "error": "Mask is already active on another domain",
  "active_domain": "another-site.com",
  "message": "This mask is currently being used on another-site.com. Please disable it there first, or use a different mask."
}
```

---

### 7. Disable Mask for Domain

**Endpoint**: `POST /api/plugin/masks/disable`

**Purpose**: Release/deactivate a mask from a specific domain, making it available for use elsewhere.

**Authentication**: Access Token (JWT)

**Rate Limit**: None

#### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "accessToken": "eyJhbGci...",
  "maskId": "mask-uuid-1",
  "domain": "example.com"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `accessToken` | string | Yes | Current JWT token (from activation or heartbeat) |
| `maskId` | string | Yes | UUID of the mask to disable |
| `domain` | string | Yes | Domain making the request (must match token) |

#### Success Response (200 OK)

**Mask disabled successfully:**
```json
{
  "success": true,
  "message": "Mask disabled successfully",
  "mask": {
    "id": "mask-uuid-1",
    "name": "US Traffic to Offer A",
    "active_domain": null
  }
}
```

**Mask already disabled (idempotent):**
```json
{
  "success": true,
  "message": "Mask is already disabled",
  "mask": {
    "id": "mask-uuid-1",
    "name": "US Traffic to Offer A",
    "active_domain": null
  }
}
```

#### Error Responses

**401 Unauthorized** - Invalid token:
```json
{
  "error": "Invalid or expired access token"
}
```

**403 Forbidden** - Domain mismatch:
```json
{
  "error": "Domain mismatch"
}
```

**403 Forbidden** - Mask active on different domain:
```json
{
  "error": "Mask is not active on this domain",
  "active_domain": "another-site.com",
  "message": "This mask is currently active on another-site.com, not on example.com. Only the domain where it's active can disable it."
}
```

**404 Not Found** - Mask not found:
```json
{
  "error": "Mask not found or you don't have permission"
}
```

---

## Error Handling

### HTTP Status Codes

| Code | Meaning | Action |
|------|---------|--------|
| `200` | Success | Process response data |
| `400` | Bad Request | Fix request parameters/body |
| `401` | Unauthorized | Check license key or access token |
| `403` | Forbidden | License revoked/suspended or subscription inactive |
| `404` | Not Found | License or resource doesn't exist |
| `429` | Too Many Requests | Wait until `X-RateLimit-Reset` time |
| `500` | Internal Server Error | Retry after delay, contact support if persists |

### Error Response Format

All errors return JSON with an `error` field:

```json
{
  "error": "Human-readable error message"
}
```

Some endpoints include additional details:

```json
{
  "error": "Invalid request data",
  "details": [
    {
      "path": ["events", 0, "visitor_type"],
      "message": "Invalid visitor_type"
    }
  ]
}
```

### Recommended Error Handling

```php
$response = wp_remote_post($url, $args);

if (is_wp_error($response)) {
    // Network error
    error_log('Mr. Cloak API request failed: ' . $response->get_error_message());
    return false;
}

$status_code = wp_remote_retrieve_response_code($response);
$body = json_decode(wp_remote_retrieve_body($response), true);

if ($status_code === 200) {
    // Success
    return $body;
} elseif ($status_code === 401) {
    // Re-activate license
    $this->activate_license();
} elseif ($status_code === 429) {
    // Rate limited - back off
    $reset_time = wp_remote_retrieve_header($response, 'X-RateLimit-Reset');
    error_log('Rate limited until ' . $reset_time);
    return false;
} else {
    // Other error
    error_log('Mr. Cloak API error: ' . $body['error']);
    return false;
}
```

---

## Code Examples

### PHP (WordPress Plugin)

#### 1. Activate License

```php
<?php
function mr_cloak_activate_license($license_key, $domain) {
    $url = 'https://yourdomain.com/api/licenses/activate';

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'licenseKey' => $license_key,
            'domain' => $domain,
            'pluginVersion' => MR_CLOAK_VERSION, // e.g., '1.0.0'
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        // Store access token
        update_option('mr_cloak_access_token', $body['accessToken']);
        update_option('mr_cloak_next_check', $body['nextCheckAt']);
        update_option('mr_cloak_status', $body['policy']['status']);

        // Schedule heartbeat
        if (!wp_next_scheduled('mr_cloak_heartbeat')) {
            wp_schedule_event(time() + 1800, 'every_30_minutes', 'mr_cloak_heartbeat');
        }

        return $body;
    }

    return ['error' => $body['error'] ?? 'Unknown error'];
}
```

#### 2. Heartbeat Check

```php
<?php
function mr_cloak_heartbeat() {
    $access_token = get_option('mr_cloak_access_token');
    $domain = parse_url(home_url(), PHP_URL_HOST);

    if (!$access_token) {
        error_log('Mr. Cloak: No access token stored');
        return false;
    }

    $url = 'https://yourdomain.com/api/licenses/heartbeat';

    // Gather optional metrics
    $metrics = [
        'totalRequests' => get_option('mr_cloak_total_requests', 0),
        'uniqueVisitors' => get_option('mr_cloak_unique_visitors', 0),
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'accessToken' => $access_token,
            'domain' => $domain,
            'metrics' => $metrics,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('Mr. Cloak heartbeat failed: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        // Update stored token (rolling tokens!)
        update_option('mr_cloak_access_token', $body['accessToken']);
        update_option('mr_cloak_next_check', $body['nextCheckAt']);
        update_option('mr_cloak_status', $body['status']);

        // Handle different statuses
        if ($body['status'] === 'past_due' || $body['status'] === 'revoked') {
            // Disable filtering
            update_option('mr_cloak_filtering_enabled', false);

            // Show admin notice
            add_action('admin_notices', function() use ($body) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Mr. Cloak:</strong> ' . esc_html($body['message']);
                echo '</p></div>';
            });
        } elseif ($body['status'] === 'grace') {
            // Show warning
            add_action('admin_notices', function() use ($body) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Mr. Cloak:</strong> ' . esc_html($body['message']);
                echo '</p></div>';
            });
        }

        return $body;
    } elseif ($status_code === 401) {
        // Token expired, need to re-activate
        error_log('Mr. Cloak: Token expired, please re-activate license');
        delete_option('mr_cloak_access_token');
        return false;
    }

    return false;
}

// Register cron schedule
add_filter('cron_schedules', function($schedules) {
    $schedules['every_30_minutes'] = [
        'interval' => 1800,
        'display' => __('Every 30 minutes'),
    ];
    return $schedules;
});

// Hook heartbeat to cron
add_action('mr_cloak_heartbeat', 'mr_cloak_heartbeat');
```

#### 3. Fetch Masks

```php
<?php
function mr_cloak_get_masks($license_key) {
    $url = 'https://yourdomain.com/api/plugin/masks?license_key=' . urlencode($license_key);

    $response = wp_remote_get($url, [
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        // Cache masks locally
        update_option('mr_cloak_masks', $body['masks']);
        update_option('mr_cloak_masks_updated', time());

        return $body['masks'];
    }

    return ['error' => $body['error'] ?? 'Failed to fetch masks'];
}
```

#### 4. Submit Analytics (Batch)

```php
<?php
function mr_cloak_submit_analytics($license_key, $events) {
    $url = 'https://yourdomain.com/api/plugin/analytics';

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'license_key' => $license_key,
            'events' => $events,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('Failed to submit analytics: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        error_log("Mr. Cloak analytics: {$body['inserted']} inserted, {$body['failed']} failed");
        return true;
    }

    return false;
}

// Example usage: Queue events and submit in batches
function mr_cloak_queue_analytics_event($mask_id, $visitor_type, $country_code = null, $user_agent = null, $blocked_reason = null) {
    $queue = get_option('mr_cloak_analytics_queue', []);

    $queue[] = [
        'mask_id' => $mask_id,
        'visitor_type' => $visitor_type, // 'bot', 'whitelisted', 'blocked'
        'country_code' => $country_code,
        'user_agent' => $user_agent,
        'blocked_reason' => $blocked_reason,
        'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
    ];

    update_option('mr_cloak_analytics_queue', $queue);

    // Submit when queue reaches 50 events
    if (count($queue) >= 50) {
        $license_key = get_option('mr_cloak_license_key');

        if (mr_cloak_submit_analytics($license_key, $queue)) {
            delete_option('mr_cloak_analytics_queue');
        }
    }
}

// Cron job to flush queue hourly
add_action('mr_cloak_flush_analytics', function() {
    $queue = get_option('mr_cloak_analytics_queue', []);

    if (!empty($queue)) {
        $license_key = get_option('mr_cloak_license_key');

        if (mr_cloak_submit_analytics($license_key, $queue)) {
            delete_option('mr_cloak_analytics_queue');
        }
    }
});

if (!wp_next_scheduled('mr_cloak_flush_analytics')) {
    wp_schedule_event(time() + 3600, 'hourly', 'mr_cloak_flush_analytics');
}
```

#### 5. Traffic Filtering Logic

```php
<?php
function mr_cloak_filter_traffic() {
    // Get cached masks
    $masks = get_option('mr_cloak_masks', []);

    if (empty($masks)) {
        // No masks configured, allow all traffic
        return;
    }

    // Get visitor info
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    // Detect visitor properties (implement these functions)
    $country_code = mr_cloak_get_country_from_ip($ip_address); // Use GeoIP
    $is_bot = mr_cloak_detect_bot($user_agent);
    $is_vpn = mr_cloak_detect_vpn($ip_address); // Use VPN detection service
    $browser = mr_cloak_detect_browser($user_agent);
    $os = mr_cloak_detect_os($user_agent);
    $languages = mr_cloak_parse_languages($accept_language);

    // Check each mask
    foreach ($masks as $mask) {
        $should_block = false;
        $block_reason = null;
        $visitor_type = 'whitelisted';

        // Check if bot
        if ($is_bot) {
            $bot_name = mr_cloak_get_bot_name($user_agent);

            // Bot filtering logic (priority order):
            // 1. If bot is in blacklist -> block (highest priority)
            if (in_array($bot_name, $mask['bot_blacklist'])) {
                $should_block = true;
                $block_reason = 'bot_blacklisted';
            }
            // 2. If bot is in whitelist -> allow (overrides blocking)
            elseif (in_array($bot_name, $mask['bot_whitelist'])) {
                $should_block = false;
            }
            // 3. Check if bot is ad review bot and blocking is enabled
            elseif ($mask['block_ad_review_bots'] && mr_cloak_is_ad_review_bot($bot_name)) {
                $should_block = true;
                $block_reason = 'ad_review_bot_blocked';
            }
            // 4. Check if bot is other bot (social/messaging) and blocking is enabled
            elseif ($mask['block_other_bots'] && mr_cloak_is_other_bot($bot_name)) {
                $should_block = true;
                $block_reason = 'other_bot_blocked';
            }
            // 5. SEO bots (googlebot, bingbot) are always allowed by default

            $visitor_type = 'bot';
        }

        // Check VPN/Proxy
        if (!$should_block && $mask['filter_vpn_proxy'] && $is_vpn) {
            $should_block = true;
            $block_reason = 'vpn_or_proxy_detected';
            $visitor_type = 'blocked';
        }

        // Check country whitelist
        if (!$should_block && !empty($mask['whitelisted_countries'])) {
            if (!in_array($country_code, $mask['whitelisted_countries'])) {
                $should_block = true;
                $block_reason = 'country_not_whitelisted';
                $visitor_type = 'blocked';
            }
        }

        // Check language whitelist
        if (!$should_block && !empty($mask['whitelisted_languages'])) {
            $language_match = false;
            foreach ($languages as $lang) {
                if (in_array($lang, $mask['whitelisted_languages'])) {
                    $language_match = true;
                    break;
                }
            }
            if (!$language_match) {
                $should_block = true;
                $block_reason = 'language_not_whitelisted';
                $visitor_type = 'blocked';
            }
        }

        // Check OS whitelist
        if (!$should_block && !empty($mask['whitelisted_os'])) {
            if (!in_array($os, $mask['whitelisted_os'])) {
                $should_block = true;
                $block_reason = 'os_not_whitelisted';
                $visitor_type = 'blocked';
            }
        }

        // Check browser whitelist
        if (!$should_block && !empty($mask['whitelisted_browsers'])) {
            if (!in_array($browser, $mask['whitelisted_browsers'])) {
                $should_block = true;
                $block_reason = 'browser_not_whitelisted';
                $visitor_type = 'blocked';
            }
        }

        // Log analytics event
        $license_key = get_option('mr_cloak_license_key');
        mr_cloak_queue_analytics_event(
            $mask['id'],
            $visitor_type,
            $country_code,
            $user_agent,
            $block_reason
        );

        // Redirect whitelisted traffic
        if (!$should_block && $visitor_type === 'whitelisted') {
            wp_redirect($mask['offer_page_url'], 302);
            exit;
        }
    }

    // If we get here, show default page or safe page
}

// Hook early in WordPress execution
add_action('template_redirect', 'mr_cloak_filter_traffic', 1);
```

#### 6. Domain Security Error Handling

```php
<?php
/**
 * Complete activation flow with domain security error handling
 */
function mr_cloak_activate_with_domain_security($license_key) {
    // Normalize and detect domain
    $domain = mr_cloak_get_site_domain();

    // Validate domain format
    if (empty($domain) || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
        return [
            'success' => false,
            'error' => 'Invalid domain detected. Please use a proper domain name.',
            'show_settings' => true
        ];
    }

    // Call activation API
    $url = 'https://mrcloak.com/api/licenses/activate';
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'licenseKey' => $license_key,
            'domain' => $domain,
            'pluginVersion' => MR_CLOAK_VERSION,
        ]),
        'timeout' => 30,
    ]);

    // Handle network errors
    if (is_wp_error($response)) {
        return [
            'success' => false,
            'error' => 'Connection failed: ' . $response->get_error_message(),
            'show_settings' => false
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Success
    if ($status_code === 200) {
        // Store activation data
        update_option('mr_cloak_access_token', $body['accessToken']);
        update_option('mr_cloak_next_check', $body['nextCheckAt']);
        update_option('mr_cloak_status', $body['policy']['status']);
        update_option('mr_cloak_domain', $domain);
        update_option('mr_cloak_license_key', $license_key);

        // Schedule heartbeat
        if (!wp_next_scheduled('mr_cloak_heartbeat')) {
            wp_schedule_event(time() + 1800, 'every_30_minutes', 'mr_cloak_heartbeat');
        }

        return [
            'success' => true,
            'status' => $body['policy']['status'],
            'message' => 'License activated successfully on ' . $domain
        ];
    }

    // Handle domain security errors
    $error_message = $body['error'] ?? 'Unknown error';
    $masked_key = mr_cloak_mask_license_key($license_key);

    // Domain not authorized (Strict Mode)
    if ($status_code === 403 && strpos($error_message, 'Domain not authorized') !== false) {
        return [
            'success' => false,
            'error' => 'domain_not_authorized',
            'title' => 'Domain Authorization Required',
            'message' => 'This license is in Strict Mode and requires domain authorization.',
            'instructions' => [
                '1. Log in to your Mr. Cloak dashboard',
                '2. Go to Settings > Domain Security',
                '3. Add this domain to your whitelist:',
                "   <strong>{$domain}</strong>",
                '4. Come back here and click "Retry Activation"'
            ],
            'domain' => $domain,
            'license' => $masked_key,
            'dashboard_url' => 'https://mrcloak.com/dashboard/settings',
            'show_retry' => true
        ];
    }

    // Domain access revoked (Flexible Mode)
    if ($status_code === 403 && strpos($error_message, 'Domain access revoked') !== false) {
        return [
            'success' => false,
            'error' => 'domain_revoked',
            'title' => 'Domain Access Revoked',
            'message' => 'The license owner has revoked access for this domain.',
            'instructions' => [
                'If this is your domain and you revoked access by mistake:',
                '1. Log in to your Mr. Cloak dashboard',
                '2. Go to Settings > Domain Security',
                '3. Find this domain in "Revoked Domains":',
                "   <strong>{$domain}</strong>",
                '4. Click "Restore" to reactivate it',
                '5. Come back here and click "Retry Activation"'
            ],
            'domain' => $domain,
            'license' => $masked_key,
            'dashboard_url' => 'https://mrcloak.com/dashboard/settings',
            'show_retry' => true
        ];
    }

    // Other errors
    return [
        'success' => false,
        'error' => $error_message,
        'status_code' => $status_code,
        'show_settings' => false
    ];
}

/**
 * Helper: Get and normalize site domain
 */
function mr_cloak_get_site_domain() {
    static $cached_domain = null;

    if ($cached_domain !== null) {
        return $cached_domain;
    }

    $home_url = home_url();
    $domain = parse_url($home_url, PHP_URL_HOST);

    // Remove www. prefix for consistency
    $domain = preg_replace('/^www\./', '', $domain);

    $cached_domain = $domain;
    return $domain;
}

/**
 * Helper: Mask license key for display
 */
function mr_cloak_mask_license_key($license_key) {
    $parts = explode('-', $license_key);
    if (count($parts) === 4) {
        return $parts[0] . '-****-****-' . substr($parts[3], -4);
    }
    return 'MRC-****-****-****';
}

/**
 * Display activation error in admin
 */
function mr_cloak_display_activation_error($error_data) {
    ?>
    <div class="notice notice-error is-dismissible">
        <h3><?php echo esc_html($error_data['title'] ?? 'Activation Failed'); ?></h3>

        <?php if (!empty($error_data['message'])): ?>
            <p><?php echo esc_html($error_data['message']); ?></p>
        <?php endif; ?>

        <?php if (!empty($error_data['instructions'])): ?>
            <ol style="margin-left: 20px;">
                <?php foreach ($error_data['instructions'] as $instruction): ?>
                    <li><?php echo wp_kses_post($instruction); ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if (!empty($error_data['domain'])): ?>
            <p>
                <strong>Domain:</strong> <code><?php echo esc_html($error_data['domain']); ?></code><br>
                <strong>License:</strong> <code><?php echo esc_html($error_data['license']); ?></code>
            </p>
        <?php endif; ?>

        <?php if (!empty($error_data['dashboard_url'])): ?>
            <p>
                <a href="<?php echo esc_url($error_data['dashboard_url']); ?>"
                   class="button button-primary"
                   target="_blank">
                    Open Dashboard Settings
                </a>
                <?php if (!empty($error_data['show_retry'])): ?>
                    <button type="button"
                            class="button button-secondary"
                            onclick="mr_cloak_retry_activation()">
                        Retry Activation
                    </button>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($error_data['show_retry'])): ?>
        <script>
        function mr_cloak_retry_activation() {
            // Trigger form resubmission or AJAX retry
            if (confirm('Ready to retry activation? Make sure you\'ve added the domain to your whitelist first.')) {
                location.reload();
            }
        }
        </script>
    <?php endif; ?>
    <?php
}

/**
 * Enhanced heartbeat with domain revocation check
 */
function mr_cloak_heartbeat_with_domain_check() {
    $access_token = get_option('mr_cloak_access_token');
    $domain = mr_cloak_get_site_domain();

    if (!$access_token) {
        error_log('Mr. Cloak: No access token stored');
        return false;
    }

    $url = 'https://mrcloak.com/api/licenses/heartbeat';
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'accessToken' => $access_token,
            'domain' => $domain,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('Mr. Cloak heartbeat failed: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        // Update token
        update_option('mr_cloak_access_token', $body['accessToken']);
        update_option('mr_cloak_status', $body['status']);

        // Check for domain revocation
        if ($body['status'] === 'revoked') {
            // Domain was revoked!
            update_option('mr_cloak_filtering_enabled', false);
            delete_option('mr_cloak_access_token');

            // Show persistent admin notice
            set_transient('mr_cloak_domain_revoked_notice', [
                'domain' => $domain,
                'message' => $body['message'] ?? 'Domain access revoked'
            ], 0); // No expiration

            error_log('Mr. Cloak: Domain access revoked for ' . $domain);
            return false;
        }

        return true;
    }

    return false;
}

/**
 * Show domain revoked notice in admin
 */
add_action('admin_notices', function() {
    $notice = get_transient('mr_cloak_domain_revoked_notice');
    if ($notice) {
        ?>
        <div class="notice notice-error">
            <h3>⚠️ Mr. Cloak: Domain Access Revoked</h3>
            <p><?php echo esc_html($notice['message']); ?></p>
            <p>
                <strong>Domain:</strong> <code><?php echo esc_html($notice['domain']); ?></code>
            </p>
            <p>Traffic filtering has been disabled for security.</p>
            <p>
                If this was a mistake, you can restore access from your dashboard:
            </p>
            <p>
                <a href="https://mrcloak.com/dashboard/settings"
                   class="button button-primary"
                   target="_blank">
                    Restore Domain Access
                </a>
                <button type="button"
                        class="button button-secondary"
                        onclick="if(confirm('Dismiss this notice? You can retry activation from plugin settings.')) {
                            jQuery.post(ajaxurl, {action: 'mr_cloak_dismiss_revoked_notice'}, function() {
                                location.reload();
                            });
                        }">
                    Dismiss Notice
                </button>
            </p>
        </div>
        <?php
    }
});

/**
 * AJAX handler to dismiss revoked notice
 */
add_action('wp_ajax_mr_cloak_dismiss_revoked_notice', function() {
    delete_transient('mr_cloak_domain_revoked_notice');
    wp_send_json_success();
});
```


### Bot Categorization Helper Functions

Implement these helper functions to support the new bot filtering logic:

```php
/**
 * Check if bot is an ad review bot
 *
 * @param string $bot_name Bot name from user agent
 * @return bool True if ad review bot
 */
function mr_cloak_is_ad_review_bot($bot_name) {
    $ad_review_bots = [
        'google-ads-review',
        'facebook-ads-review',
        'tiktok-ads-review',
    ];

    return in_array($bot_name, $ad_review_bots);
}

/**
 * Check if bot is a social media or messaging bot
 *
 * @param string $bot_name Bot name from user agent
 * @return bool True if social/messaging bot
 */
function mr_cloak_is_other_bot($bot_name) {
    $other_bots = [
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'pinterestbot',
        'tiktok',
        'snapchat',
        'slackbot',
        'telegrambot',
        'whatsapp',
    ];

    return in_array($bot_name, $other_bots);
}

/**
 * Check if bot is an SEO bot (always allowed by default)
 *
 * @param string $bot_name Bot name from user agent
 * @return bool True if SEO bot
 */
function mr_cloak_is_seo_bot($bot_name) {
    $seo_bots = [
        'googlebot',
        'bingbot',
    ];

    return in_array($bot_name, $seo_bots);
}
```

**Bot Categories:**

- **SEO Bots** (`googlebot`, `bingbot`): Always allowed by default unless in blacklist
- **Ad Review Bots** (`google-ads-review`, `facebook-ads-review`, `tiktok-ads-review`): Blocked when `block_ad_review_bots` is true
- **Other Bots** (social media & messaging): Blocked when `block_other_bots` is true

**Bot Filtering Priority:**
1. **Blacklist** (highest priority) - Always blocked
2. **Whitelist** - Always allowed (overrides categories)
3. **Ad Review Bots** - Blocked if `block_ad_review_bots` is true
4. **Other Bots** - Blocked if `block_other_bots` is true
5. **SEO Bots** - Always allowed by default

---

## Common Workflows

### Initial Setup Workflow

```
1. User installs WordPress plugin
2. User enters license key in plugin settings
3. Plugin calls POST /api/licenses/activate
4. Plugin receives access token
5. Plugin fetches masks via GET /api/plugin/masks
6. Plugin caches masks locally
7. Plugin schedules heartbeat cron (every 30 minutes)
8. Plugin begins filtering traffic
```

### Ongoing Operation Workflow

```
Every 30 minutes:
1. Cron triggers heartbeat
2. Plugin calls POST /api/licenses/heartbeat with current access token
3. Backend validates token and returns NEW token
4. Plugin replaces stored token with new token
5. Plugin checks subscription status
6. If status is 'past_due' or 'revoked', disable filtering and show notice
7. If status is 'grace', show warning notice
8. If status is 'active' or 'trialing', continue normal operation
```

### Analytics Submission Workflow

```
On each visitor:
1. Plugin evaluates traffic against masks
2. Plugin queues analytics event (mask_id, visitor_type, country, etc.)
3. When queue reaches 50 events OR hourly cron triggers:
   - Plugin calls POST /api/plugin/analytics with batch of events
   - Backend validates and inserts events
   - Plugin clears queue

Daily (backend):
1. Backend runs aggregate_mask_analytics_daily() function
2. Raw events aggregated into daily summaries
3. Dashboard displays charts and metrics
```

### Mask Update Workflow

```
User updates masks in dashboard:
1. User saves changes in web dashboard
2. Plugin refreshes masks on next page load (or hourly cron)
3. Plugin calls GET /api/plugin/masks
4. Plugin replaces cached masks
5. New filtering rules take effect immediately
```

---

## Best Practices

### 1. Token Management

✅ **DO:**
- Always store the latest access token from heartbeat responses (rolling tokens)
- Replace old token immediately after receiving new token
- Clear stored token if you receive 401 Unauthorized
- Re-activate license if token is invalid

❌ **DON'T:**
- Don't reuse expired tokens
- Don't store tokens in cookies or client-side JavaScript
- Don't share tokens across multiple domains

### 2. Caching

✅ **DO:**
- Cache masks locally in WordPress options table
- Refresh masks hourly or when user clicks "Refresh" button
- Cache GeoIP and VPN detection results (1-hour TTL)
- Use WordPress transients for temporary data

❌ **DON'T:**
- Don't fetch masks on every page load
- Don't call API unnecessarily (respect rate limits)

### 3. Analytics Batching

✅ **DO:**
- Queue analytics events locally
- Submit in batches of 50-100 events
- Flush queue hourly via cron
- Handle partial success (some events may fail validation)

❌ **DON'T:**
- Don't submit analytics on every visitor (too many requests)
- Don't lose queued events if submission fails (retry later)

### 4. Error Handling

✅ **DO:**
- Log all API errors to WordPress debug log
- Show admin notices for critical errors (license revoked, expired)
- Gracefully degrade if API is unreachable (allow traffic, disable filtering temporarily)
- Retry failed requests with exponential backoff

❌ **DON'T:**
- Don't crash the site if API is down
- Don't expose error details to end users
- Don't retry indefinitely (max 3 retries)

### 5. Performance

✅ **DO:**
- Use `wp_remote_post()` and `wp_remote_get()` for HTTP requests
- Set reasonable timeouts (30 seconds max)
- Use WordPress cron for background tasks
- Cache expensive operations (GeoIP, VPN detection)

❌ **DON'T:**
- Don't block page rendering waiting for API responses
- Don't make synchronous API calls on frontend

### 6. Security

✅ **DO:**
- Validate and sanitize all input data
- Use nonces for admin form submissions
- Store sensitive data (license key, token) in wp_options table
- Use HTTPS for all API requests

❌ **DON'T:**
- Don't expose license keys or tokens in HTML/JavaScript
- Don't trust client-side data (always validate server-side)
- Don't skip WordPress capability checks in admin pages

### 7. Subscription Status Handling

| Status | Filtering | Action |
|--------|-----------|--------|
| `trialing` | ✅ Enabled | Show trial countdown in admin |
| `active` | ✅ Enabled | Normal operation |
| `grace` | ✅ Enabled | Show urgent renewal notice |
| `past_due` | ❌ Disabled | Show expired notice, link to billing |
| `suspended` | ❌ Disabled | Show suspended notice, contact support |
| `revoked` | ❌ Disabled | Show revoked notice, contact support |

### 8. Domain Security Handling

✅ **DO:**
- Normalize domain before sending to API (remove protocol, www, trailing slash)
- Cache the detected domain and reuse it consistently
- Show user-friendly error messages for domain authorization failures
- Provide direct links to dashboard settings for whitelist management
- Handle domain revocation gracefully during active sessions
- Mask the license key in error messages (show only first/last segments)
- Test activation on both main domain and subdomains

❌ **DON'T:**
- Don't use localhost or IP addresses as the domain
- Don't expose the full license key in frontend error messages
- Don't retry activation endlessly if domain is not authorized
- Don't ignore domain revocation status from heartbeat
- Don't assume activation will work without checking error codes
- Don't submit subdomains if parent domain would work

**Recommended Domain Detection:**
```php
function mr_cloak_get_site_domain() {
    // Get the home URL
    $home_url = home_url();

    // Parse the domain
    $domain = parse_url($home_url, PHP_URL_HOST);

    // Normalize: remove www. prefix
    $domain = preg_replace('/^www\./', '', $domain);

    // Cache for consistency
    return $domain;
}
```

**Error Message Helper:**
```php
function mr_cloak_mask_license_key($license_key) {
    $parts = explode('-', $license_key);
    if (count($parts) === 4) {
        return $parts[0] . '-****-****-' . substr($parts[3], -4);
    }
    return 'MRC-****-****-****';
}
```

**Domain Authorization Check:**
```php
function mr_cloak_handle_activation_error($error_message, $license_key, $domain) {
    $masked_key = mr_cloak_mask_license_key($license_key);

    if (strpos($error_message, 'Domain not authorized') !== false) {
        // Strict mode - not in whitelist
        return [
            'title' => 'Domain Authorization Required',
            'message' => 'This license requires domain authorization. Please add this domain to your whitelist in the dashboard.',
            'domain' => $domain,
            'license' => $masked_key,
            'action' => 'whitelist',
            'link' => 'https://mrcloak.com/dashboard/settings'
        ];
    }

    if (strpos($error_message, 'Domain access revoked') !== false) {
        // Flexible mode - revoked
        return [
            'title' => 'Domain Access Revoked',
            'message' => 'Access for this domain has been revoked. You can restore it from your dashboard.',
            'domain' => $domain,
            'license' => $masked_key,
            'action' => 'restore',
            'link' => 'https://mrcloak.com/dashboard/settings'
        ];
    }

    // Generic error
    return [
        'title' => 'Activation Failed',
        'message' => $error_message,
        'domain' => $domain,
        'license' => $masked_key,
        'action' => 'support',
        'link' => 'https://mrcloak.com/support'
    ];
}
```

---

## Support

For technical support or questions about the API:

- 📧 Email: support@mrcloak.com
- 📚 Documentation: https://docs.mrcloak.com
- 🐛 Bug Reports: https://github.com/mrcloak/issues

---

**Last Updated**: October 26, 2025
**API Version**: 1.0.0
**Status**: ✅ **Production Ready**
