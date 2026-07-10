alter table public.events
    add column if not exists is_free_event boolean not null default true;

alter table public.events
    add column if not exists registration_limit integer;

alter table public.events
    drop constraint if exists events_registration_limit_check;

alter table public.events
    add constraint events_registration_limit_check
    check (registration_limit is null or registration_limit > 0);
