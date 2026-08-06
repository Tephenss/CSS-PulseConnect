-- CCS PulseConnect migration 058
-- Soft-archive for student roster (+ users.archived_at for linked accounts).
-- Service-role / PHP BFF only — no anon grants.

alter table public.student_roster
  add column if not exists archived_at timestamptz,
  add column if not exists archived_by uuid references public.users(id) on delete set null;

create index if not exists student_roster_archived_at_idx
  on public.student_roster(archived_at)
  where archived_at is not null;

create index if not exists student_roster_active_section_idx
  on public.student_roster(section_id)
  where archived_at is null;

alter table public.users
  add column if not exists archived_at timestamptz;

create index if not exists users_archived_at_idx
  on public.users(archived_at)
  where archived_at is not null;
