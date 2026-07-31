-- Allow larger stored extend offsets (days after base close).
-- App still caps the resulting close date at (start_date - 3 days).
alter table public.events
    drop constraint if exists events_registration_close_extend_days_check;

alter table public.events
    add constraint events_registration_close_extend_days_check
    check (
        registration_close_extend_days is null
        or (registration_close_extend_days >= 0 and registration_close_extend_days <= 60)
    );

comment on column public.events.registration_close_extend_days is
    'Days added after the base registration close date (start minus registration_close_weeks). Resulting close date must not pass start_date minus 3 days.';
