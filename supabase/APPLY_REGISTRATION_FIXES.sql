-- Run this once in Supabase SQL Editor to enable registration limits on mobile + web.
-- Safe to re-run (uses IF NOT EXISTS / CREATE OR REPLACE).

-- 031 prerequisite columns (skip if already applied)
alter table public.events
  add column if not exists is_free_event boolean not null default true;

alter table public.events
  add column if not exists registration_limit integer;

alter table public.events
  drop constraint if exists events_registration_limit_check;

alter table public.events
  add constraint events_registration_limit_check
  check (registration_limit is null or (registration_limit > 0 and registration_limit <= 9999));

alter table public.events
  add column if not exists registration_close_weeks integer;

alter table public.events
  drop constraint if exists events_registration_close_weeks_check;

alter table public.events
  add constraint events_registration_close_weeks_check
  check (registration_close_weeks is null or (registration_close_weeks >= 1 and registration_close_weeks <= 4));

-- 036 registered_count on events
alter table public.events
  add column if not exists registered_count integer not null default 0;

update public.events e
set registered_count = coalesce(src.total_count, 0)
from (
  select event_id, count(*)::integer as total_count
  from public.event_registrations
  group by event_id
) as src
where e.id = src.event_id;

create or replace function public.sync_event_registered_count_from_registrations()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  target_event_id uuid;
  new_count integer;
begin
  target_event_id := coalesce(new.event_id, old.event_id);
  if target_event_id is null then
    return coalesce(new, old);
  end if;

  select count(*)::integer into new_count
  from public.event_registrations
  where event_id = target_event_id;

  update public.events
    set registered_count = new_count,
        updated_at = now()
  where id = target_event_id;

  return coalesce(new, old);
end;
$$;

drop trigger if exists event_registrations_sync_registered_count_insert on public.event_registrations;
create trigger event_registrations_sync_registered_count_insert
  after insert on public.event_registrations
  for each row
  execute function public.sync_event_registered_count_from_registrations();

drop trigger if exists event_registrations_sync_registered_count_delete on public.event_registrations;
create trigger event_registrations_sync_registered_count_delete
  after delete on public.event_registrations
  for each row
  execute function public.sync_event_registered_count_from_registrations();

-- 034 capacity enforcement
create or replace function public.event_registration_count(p_event_id uuid)
returns integer
language sql
security definer
stable
set search_path = public
as $$
  select count(*)::integer
  from public.event_registrations
  where event_id = p_event_id;
$$;

grant execute on function public.event_registration_count(uuid) to anon, authenticated;

create or replace function public.enforce_event_registration_limit()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  reg_limit integer;
  reg_count integer;
begin
  select registration_limit into reg_limit
  from public.events
  where id = new.event_id
  for update;

  if reg_limit is null then
    return new;
  end if;

  select count(*)::integer into reg_count
  from public.event_registrations
  where event_id = new.event_id;

  if reg_count >= reg_limit then
    raise exception 'Registration is full for this event'
      using errcode = 'P0001';
  end if;

  return new;
end;
$$;

create or replace function public.close_event_registration_at_capacity()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  reg_limit integer;
  reg_count integer;
begin
  select registration_limit into reg_limit
  from public.events
  where id = new.event_id;

  if reg_limit is null then
    return new;
  end if;

  select count(*)::integer into reg_count
  from public.event_registrations
  where event_id = new.event_id;

  if reg_count >= reg_limit then
    update public.events
      set allow_registration = false,
          updated_at = now()
    where id = new.event_id;
  end if;

  return new;
end;
$$;

drop trigger if exists event_registrations_limit_trigger on public.event_registrations;
create trigger event_registrations_limit_trigger
  before insert on public.event_registrations
  for each row
  execute function public.enforce_event_registration_limit();

drop trigger if exists event_registrations_close_at_capacity_trigger on public.event_registrations;
create trigger event_registrations_close_at_capacity_trigger
  after insert on public.event_registrations
  for each row
  execute function public.close_event_registration_at_capacity();

-- 037 snapshot RPC used by the mobile app
create or replace function public.get_event_registration_snapshot(p_event_id uuid)
returns jsonb
language plpgsql
security definer
stable
set search_path = public
as $$
declare
  reg_count integer := 0;
  reg_limit integer;
  allow_reg boolean := false;
  is_free boolean := true;
  event_found boolean := false;
begin
  if p_event_id is null then
    return jsonb_build_object('ok', false, 'error', 'event_id_required');
  end if;

  select registration_limit, coalesce(allow_registration, false), coalesce(is_free_event, true), true
  into reg_limit, allow_reg, is_free, event_found
  from public.events
  where id = p_event_id;

  if not coalesce(event_found, false) then
    return jsonb_build_object('ok', false, 'error', 'event_not_found');
  end if;

  select count(*)::integer into reg_count
  from public.event_registrations
  where event_id = p_event_id;

  return jsonb_build_object(
    'ok', true,
    'registration_count', reg_count,
    'registered_count', reg_count,
    'registration_limit', reg_limit,
    'allow_registration', allow_reg,
    'is_free_event', is_free,
    'is_full', reg_limit is not null and reg_count >= reg_limit
  );
end;
$$;

grant execute on function public.get_event_registration_snapshot(uuid) to anon, authenticated;

notify pgrst, 'reload schema';
