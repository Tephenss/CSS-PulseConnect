-- CCS PulseConnect migration 018
-- Add explicit finished event status and move CCS Summit 2026 out of archive.

do $$
declare
  constraint_row record;
begin
  for constraint_row in
    select conname
    from pg_constraint
    where conrelid = 'public.events'::regclass
      and contype = 'c'
      and pg_get_constraintdef(oid) ilike '%status%'
  loop
    execute format(
      'alter table public.events drop constraint if exists %I',
      constraint_row.conname
    );
  end loop;
end $$;

alter table public.events
  add constraint events_status_check
  check (status in ('draft', 'pending', 'approved', 'published', 'finished', 'archived'));

update public.events
set
  status = 'finished',
  updated_at = now()
where lower(trim(title)) = 'ccs summit 2026';

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

