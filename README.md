# Twilio Voice Demo App

A web-based agent console for handling phone calls using Twilio Voice SDK, built with Laravel 12, Vue 3, and Inertia.js.

Features: inbound/outbound calls, blind transfers, and warm (consultative) transfers.

## Prerequisites

- PHP 8.2+
- Composer
- Node.js & npm
- A Twilio account with:
  - Account SID & Auth Token
  - An API Key & Secret (create at https://console.twilio.com/us1/account/keys-credentials/api-keys)
  - A TwiML App SID (create at https://console.twilio.com/us1/develop/voice/manage/twiml-apps)
  - A Twilio phone number
- [Twilio CLI](https://www.twilio.com/docs/twilio-cli/getting-started/install) (for webhook configuration)

## Setup

```bash
composer run setup
```

This installs dependencies, generates the app key, runs migrations (SQLite), and builds frontend assets.

## Configure Environment

Edit `.env` and fill in your Twilio credentials:

```
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_API_KEY=SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_API_SECRET=your_api_secret
TWILIO_TWIML_APP_SID=APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Run

```bash
composer run dev
```

This starts the Laravel server, queue listener, and Vite dev server concurrently.

- Welcome page: http://localhost:8000
- Agent console: http://localhost:8000/agent

## Twilio Webhook Configuration

For Twilio to reach your local server, expose it via a tunnel (e.g. ngrok):

```bash
ngrok http 8000
```

Use the resulting HTTPS URL as your base URL below.

### Update TwiML App webhooks (voice URL + status callback)

```bash
twilio api:core:applications:update \
  --sid APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
  --voice-url https://YOUR_TUNNEL.ngrok.io/api/twilio/voice/outgoing \
  --voice-method POST \
  --status-callback https://YOUR_TUNNEL.ngrok.io/api/twilio/voice/status \
  --status-callback-method POST
```

### Update Twilio phone number webhook (for inbound calls)

First, find your phone number SID:

```bash
twilio phone-numbers:list
```

Then update it:

```bash
twilio phone-numbers:update PNxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
  --voice-url https://YOUR_TUNNEL.ngrok.io/api/twilio/voice/incoming \
  --voice-method POST
```

### Verify configuration

```bash
# Check TwiML App settings
twilio api:core:applications:fetch --sid APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Check phone number settings
twilio phone-numbers:list
```

## API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/twilio/token` | Generate Voice SDK access token |
| POST | `/api/twilio/voice/incoming` | TwiML for inbound calls |
| POST | `/api/twilio/voice/outgoing` | TwiML for outbound calls |
| POST | `/api/twilio/voice/status` | Call status callbacks |
| POST | `/api/twilio/transfer/blind` | Blind transfer |
| POST | `/api/twilio/transfer/start` | Start warm transfer |
| POST | `/api/twilio/transfer/complete` | Complete warm transfer |

## Testing

```bash
# Run linting + all tests
composer run test

# Run tests only
php artisan test --compact

# Run a specific test
php artisan test --compact --filter=testName
```
