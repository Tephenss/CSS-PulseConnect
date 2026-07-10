alter table public.events
    drop constraint if exists events_registration_limit_check;

alter table public.events
    add constraint events_registration_limit_check
    check (registration_limit is null or (registration_limit > 0 and registration_limit <= 9999));
