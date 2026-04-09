-- CCS PulseConnect - Supabase migration 002
-- Adds event_assistants table used by mobile teacher assistant assignment.

create table if not exists public.event_assistants (
  id uuid primary key default gen_random_uuid(),
  event_id uuid not null references public.events(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  allow_scan boolean not null default true,
  assigned_at timestamptz not null default now(),
  unique (event_id, student_id)
);

create index if not exists event_assistants_event_idx
  on public.event_assistants(event_id);

create index if not exists event_assistants_student_idx
  on public.event_assistants(student_id);

grant all privileges on table public.event_assistants to anon, authenticated, service_role;
