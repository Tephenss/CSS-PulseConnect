-- Optional 1–3 day grace after the base registration close limit
-- (base = event start minus registration_close_weeks).
alter table public.events
    add column if not exists registration_close_extend_days integer;

alter table public.events
    drop constraint if exists events_registration_close_extend_days_check;

alter table public.events
    add constraint events_registration_close_extend_days_check
    check (
        registration_close_extend_days is null
        or (registration_close_extend_days >= 0 and registration_close_extend_days <= 3)
    );

comment on column public.events.registration_close_extend_days is
    'Extra days (0-3) added after the base registration close date from registration_close_weeks. Used when rescheduling published events.';
