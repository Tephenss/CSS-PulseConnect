<?php
declare(strict_types=1);

// Supabase REST config.
// IMPORTANT: Keep your Service Role Key PRIVATE (server-side only).
// Copy this file as config.php and fill in your real keys.

define('SUPABASE_URL', 'https://YOUR_PROJECT.supabase.co');
define('SUPABASE_KEY', 'YOUR_SUPABASE_SERVICE_ROLE_KEY');

// Table name where we store app users (custom table, not auth.users).
define('SUPABASE_TABLE_USERS', 'users');

// DEV ONLY: workaround for local SSL errors like "unable to get local issuer certificate".
// When you deploy to production, set this to false and configure proper CA bundle.
define('SUPABASE_DEV_SKIP_SSL_VERIFY', true);

// Google Gemini AI Key
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');

// Groq Whisper AI Key (For highly accurate Speech-To-Text)
define('GROQ_API_KEY', 'YOUR_GROQ_API_KEY');
