# PayMongo QR Ph Setup

This integration creates a one-time QR Ph payment for the event's exact fee. The QR is shown in the mobile app, and a signed PayMongo `payment.paid` webhook confirms payment before the existing paid-registration flow creates the student's registration and ticket.

## Server setup

1. Apply `supabase/migrations/074_paymongo_qr_checkout.sql` to the project's Supabase database.
2. Add the PayMongo **secret API key** and the webhook endpoint's **signing secret** to the server's private `.env` file. Do not put either value in Flutter `env.dart`, commit them, or send them in chat.

   ```dotenv
   PAYMONGO_SECRET_KEY=sk_test_...
   PAYMONGO_WEBHOOK_SECRET=whsk_...
   ```

3. In the PayMongo dashboard, register a webhook for the public HTTPS URL `https://<your-domain>/api/paymongo_webhook.php`. Subscribe to `payment.paid` and `payment.failed`, then save that endpoint's signing secret as `PAYMONGO_WEBHOOK_SECRET` on the same server. Configure separate matching keys/secrets for test and live mode.
4. Deploy the PHP backend and confirm the public webhook URL is reachable before testing a payment.
5. Build and distribute the updated mobile app. Students can tap **Pay with GCash**, scan the amount-encoded QR Ph code in GCash, and the app will wait for server confirmation.

## Important

- A real payment requires an activated PayMongo merchant account and live credentials. Settlement goes to that merchant account, not to the event creator's saved GCash number; provider processing fees may affect the merchant's net settlement.
- Test with PayMongo test credentials and test webhooks before enabling live keys. Never mark a payment successful from the app's return screen alone.
- QR Ph is single-use and expires after 30 minutes by default. The app offers a new checkout after an expired or failed attempt.
- QR Ph has a minimum payment amount of PHP 1.00.
