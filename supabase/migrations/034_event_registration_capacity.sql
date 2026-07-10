-- CCS PulseConnect migration 034
-- Accurate registration counts for clients (bypasses RLS) and server-side capacity enforcement.

do $$
begin
  if to_regclass('public.event_registrations') is null
     or to_regclass('public.events') is null then
    return;
  end if;
end $$;

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
  select registration_limit
    into reg_limit
  from public.events
  where id = new.event_id
  for update;

  if reg_limit is null then
    return new;
  end if;

  select count(*)::integer
    into reg_count
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
  select registration_limit
    into reg_limit
  from public.events
  where id = new.event_id;

  if reg_limit is null then
    return new;
  end if;

  select count(*)::integer
    into reg_count
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

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
