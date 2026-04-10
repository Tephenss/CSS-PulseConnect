-- CCS PulseConnect migration 003
-- Event-level teacher assignment for scanner and assistant management access.

create table if not exists public.event_teacher_assignments (
  id uuid primary key default gen_random_uuid(),
  event_id uuid not null references public.events(id) on delete cascade,
  teacher_id uuid not null references public.users(id) on delete cascade,
  can_scan boolean not null default false,
  can_manage_assistants boolean not null default false,
  assigned_by uuid references public.users(id) on delete set null,
  assigned_at timestamptz not null default now(),
  unique (event_id, teacher_id)
);

alter table public.event_teacher_assignments
  alter column can_scan set default false;

alter table public.event_teacher_assignments
  alter column can_manage_assistants set default false;

-- Safety reset for deployments that previously used broad QR access defaults.
-- Admin can re-enable QR access explicitly per event from the QR assignment page.
update public.event_teacher_assignments
set
  can_scan = false,
  can_manage_assistants = false
where can_scan = true
   or can_manage_assistants = true;

create index if not exists event_teacher_assignments_event_idx
  on public.event_teacher_assignments(event_id);

create index if not exists event_teacher_assignments_teacher_idx
  on public.event_teacher_assignments(teacher_id);

grant all privileges on table public.event_teacher_assignments to anon, authenticated, service_role;

alter table public.event_assistants
  add column if not exists assigned_by_teacher_id uuid references public.users(id) on delete set null;

create index if not exists event_assistants_assigned_by_teacher_idx
  on public.event_assistants(assigned_by_teacher_id);

-- Backfill existing teacher-created events so current behavior keeps working
-- until admin fine-tunes assignments per event or per batch.
insert into public.event_teacher_assignments (
  event_id,
  teacher_id,
  can_scan,
  can_manage_assistants,
  assigned_by
)
select
  e.id,
  e.created_by,
  false,
  false,
  e.approved_by
from public.events e
join public.users u
  on u.id = e.created_by
where coalesce(u.role, 'student') = 'teacher'
on conflict (event_id, teacher_id) do nothing;
