-- CCS PulseConnect migration 045
-- Event settlement fee for paid events.

alter table public.events
  add column if not exists event_fee numeric(12, 2);

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'events_event_fee_check'
  ) then
    alter table public.events
      add constraint events_event_fee_check
      check (event_fee is null or event_fee >= 0);
  end if;
end $$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
