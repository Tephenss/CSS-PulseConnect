-- Persisted per-user notification inbox for mobile bell catch-up (works even when logged out).

create table if not exists public.user_notifications (
    id uuid primary key default gen_random_uuid(),
    user_id uuid not null references public.users(id) on delete cascade,
    notification_type text not null default 'info',
    title text not null,
    body text not null,
    event_id uuid references public.events(id) on delete set null,
    data jsonb not null default '{}'::jsonb,
    dedupe_key text,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index if not exists user_notifications_user_created_idx
    on public.user_notifications (user_id, created_at desc);

create unique index if not exists user_notifications_user_dedupe_idx
    on public.user_notifications (user_id, dedupe_key)
    where dedupe_key is not null;

alter table public.user_notifications disable row level security;

grant all privileges on table public.user_notifications to anon, authenticated, service_role;
