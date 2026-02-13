# Twilio Voice Demo App

A web-based agent console for handling phone calls using Twilio Voice SDK, built with Laravel 12, Vue 3, and Inertia.js.

Features: inbound/outbound calls, blind transfers, and warm (consultative) transfers.

## Prerequisites

- Docker
- A Twilio account with:
  - Account SID & Auth Token
  - An API Key & Secret (create at https://console.twilio.com/us1/account/keys-credentials/api-keys)
  - A TwiML App SID (create at https://console.twilio.com/us1/develop/voice/manage/twiml-apps)
  - A Twilio phone number
- [Twilio CLI](https://www.twilio.com/docs/twilio-cli/getting-started/install) (for webhook configuration)

## Local Development

```bash
composer run setup
composer run dev
```

- Welcome page: http://localhost:8000
- Agent console: http://localhost:8000/agent

## Docker

### Build and run

```bash
docker compose up --build
```

App runs on http://localhost:8080.

### Configure Environment

Copy `.env.example` to `.env` and fill in your Twilio credentials:

```
APP_KEY=base64:... (generate with: php artisan key:generate --show)
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_API_KEY=SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_API_SECRET=your_api_secret
TWILIO_TWIML_APP_SID=APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_FROM_NUMBER=+1234567890
```

### Run migrations inside container

```bash
docker compose exec php php artisan migrate --force
```

## Deploy to Sevalla

Push the repo to your Sevalla app. Sevalla builds from the `Dockerfile` and serves on port 8080.

Set the following environment variables in Sevalla's dashboard:

```
APP_NAME=TwilioDemo
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://your-app.sevalla.app
DB_CONNECTION=sqlite
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_API_KEY=SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_API_SECRET=your_api_secret
TWILIO_TWIML_APP_SID=APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_FROM_NUMBER=+1234567890
```

## Twilio Webhook Configuration

Replace `YOUR_DOMAIN` with your Sevalla URL (e.g. `your-app.sevalla.app`).

### Update TwiML App webhooks (voice URL + status callback)

```bash
twilio api:core:applications:update \
  --sid APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
  --voice-url https://YOUR_DOMAIN/api/twilio/voice/outgoing \
  --voice-method POST \
  --status-callback https://YOUR_DOMAIN/api/twilio/voice/status \
  --status-callback-method POST
```

### Update Twilio phone number webhook (for inbound calls)

Find your phone number SID:

```bash
twilio phone-numbers:list
```

Then update it:

```bash
twilio phone-numbers:update PNxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
  --voice-url https://YOUR_DOMAIN/api/twilio/voice/incoming \
  --voice-method POST
```

### Verify configuration

```bash
twilio api:core:applications:fetch --sid APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
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
