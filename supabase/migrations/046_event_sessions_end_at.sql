-- Ensure seminar sessions have end_at (and related columns) even if
-- event_sessions was created from an older partial schema.

alter table public.event_sessions
  add column if not exists end_at timestamptz;

alter table public.event_sessions
  add column if not exists topic text;

alter table public.event_sessions
  add column if not exists description text;

alter table public.event_sessions
  add column if not exists location text;

alter table public.event_sessions
  add column if not exists scan_window_minutes integer not null default 30;

alter table public.event_sessions
  add column if not exists sort_order integer not null default 0;

alter table public.event_sessions
  add column if not exists updated_at timestamptz not null default now();

-- Backfill missing end times before enforcing NOT NULL.
update public.event_sessions
set end_at = start_at + interval '1 hour'
where end_at is null
  and start_at is not null;

do $$
begin
  if exists (
    select 1
    from information_schema.columns
    where table_schema = 'public'
      and table_name = 'event_sessions'
      and column_name = 'end_at'
  ) then
    alter table public.event_sessions
      alter column end_at set not null;
  end if;
exception
  when others then
    -- Leave nullable if existing rows still cannot satisfy NOT NULL.
    null;
end $$;

create index if not exists event_sessions_start_idx
  on public.event_sessions(start_at);

notify pgrst, 'reload schema';
