alter table public.event_payment_checkouts
  add column if not exists receipt_email_sent_at timestamptz;
