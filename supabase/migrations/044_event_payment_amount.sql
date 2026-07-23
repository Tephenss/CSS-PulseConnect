-- CCS PulseConnect migration 044
-- Paid-event partial payment amount on registration access rows.

alter table public.event_registration_access
  add column if not exists amount_paid numeric(12, 2);

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'event_registration_access_amount_paid_check'
  ) then
    alter table public.event_registration_access
      add constraint event_registration_access_amount_paid_check
      check (amount_paid is null or amount_paid >= 0);
  end if;
end $$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
