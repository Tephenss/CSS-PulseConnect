alter table public.events
    add column if not exists registration_close_weeks integer;

alter table public.events
    drop constraint if exists events_registration_close_weeks_check;

alter table public.events
    add constraint events_registration_close_weeks_check
    check (registration_close_weeks is null or (registration_close_weeks >= 1 and registration_close_weeks <= 4));
