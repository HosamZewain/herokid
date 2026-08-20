# HeroKid Mobile

Arabic-first React Native/Expo customer application for Android 9+ and iOS 15.1+. It uses the existing HeroKid Laravel backend at `/api/v1`; pricing, inventory, payments, orders, Production Studio state, preview approval, consent, and child media authorization stay server-side.

## Local development

1. Start the Laravel Docker stack from the repository root.
2. Copy `.env.example` to `.env` in this directory and set a reachable API URL.
3. Run `npm install`, then `npm start`.

Android emulators use `http://10.0.2.2:8088/api/v1`; iOS Simulator uses `http://localhost:8088/api/v1`. Physical devices and release builds must use an HTTPS staging or production API.

## Verification

```bash
npm run typecheck
npm run doctor
npm audit --audit-level=moderate
npx expo export --platform all
```

## Production configuration

- Replace `extra.eas.projectId` in `app.json` by running `eas init` under the HeroKid Expo account.
- Configure Google iOS, Android, and web client IDs in the build environment and use the same IDs in Laravel `GOOGLE_MOBILE_CLIENT_IDS`.
- Configure the Apple app identifier in Laravel `APPLE_MOBILE_CLIENT_IDS` and enable Sign in with Apple for `com.herokid.app`.
- Configure APNs/FCM credentials for Expo push.
- Add the Apple App Site Association file and Android `assetlinks.json` to `hero-kid.com` after the Apple Team ID and Android signing certificate are known.
- Configure Twilio and set `MOBILE_OTP_DRIVER=twilio` for phone OTP.
- Select and configure the real backend payment provider before enabling card or wallet payments. Cash on delivery already uses the production order pipeline.
- Configure production analytics/crash projects without child names, image URLs, or uploaded media.

Never commit signing, push, payment, analytics, SMS, OAuth, or API credentials.
