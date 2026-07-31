# CA certificates for PHP cURL

`cacert.pem` is the Mozilla CA bundle from https://curl.se/ca/cacert.pem

Upload this folder with the site so Hostinger PHP can verify HTTPS to Supabase
without setting `SUPABASE_DEV_SKIP_SSL_VERIFY=true`.
