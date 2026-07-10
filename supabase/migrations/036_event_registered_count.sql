-- CCS PulseConnect migration 036
-- Denormalized registration count on events so mobile clients can read totals under RLS.

do $$
begin
  if to_regclass('public.events') is null
     or to_regclass('public.event_registrations') is null then
    return;
  end if;
end $$;

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

  select count(*)::integer
    into new_count
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

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
