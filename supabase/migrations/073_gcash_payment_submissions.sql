-- Manual GCash payments with private receipt storage and organizer review.

alter table public.events
  add column if not exists gcash_account_name text,
  add column if not exists gcash_number text;

create table if not exists public.event_payment_submissions (
  id uuid primary key default gen_random_uuid(),
  event_id uuid not null references public.events(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  amount numeric(12, 2) not null check (amount > 0),
  reference_number text not null,
  receipt_path text not null,
  status text not null default 'pending'
    check (status in ('pending', 'approved', 'rejected')),
  review_note text,
  reviewed_by uuid references public.users(id) on delete set null,
  created_at timestamptz not null default now(),
  reviewed_at timestamptz
);

create index if not exists event_payment_submissions_event_status_idx
  on public.event_payment_submissions (event_id, status, created_at desc);

create index if not exists event_payment_submissions_student_idx
  on public.event_payment_submissions (student_id, event_id, created_at desc);

create unique index if not exists event_payment_submissions_one_pending_idx
  on public.event_payment_submissions (event_id, student_id)
  where status = 'pending';

alter table public.event_payment_submissions enable row level security;
revoke all on public.event_payment_submissions from anon, authenticated;
grant all on public.event_payment_submissions to service_role;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('event-payment-proofs', 'event-payment-proofs', false, 5242880,
        array['image/jpeg', 'image/png', 'image/webp'])
on conflict (id) do update set
  public = false,
  file_size_limit = excluded.file_size_limit,
  allowed_mime_types = excluded.allowed_mime_types;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception when others then
  null;
end $$;
