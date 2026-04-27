alter table public.events
    add column if not exists allow_registration boolean not null default false;

create table if not exists public.event_registration_access (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    student_id uuid not null references public.users(id) on delete cascade,
    payment_status text not null default 'pending',
    approved boolean not null default false,
    payment_note text,
    imported_at timestamptz not null default now(),
    imported_by uuid references public.users(id) on delete set null,
    updated_at timestamptz not null default now(),
    constraint event_registration_access_unique unique (event_id, student_id),
    constraint event_registration_access_payment_status_check check (
        payment_status in ('pending', 'paid', 'waived', 'rejected')
    )
);

create index if not exists idx_event_registration_access_event_approved
    on public.event_registration_access (event_id, approved);

grant all privileges on table public.event_registration_access to anon, authenticated, service_role;
