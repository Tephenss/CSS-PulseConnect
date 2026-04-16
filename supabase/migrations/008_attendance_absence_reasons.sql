-- CCS PulseConnect migration 008
-- Store student reasons for missed scan windows (event-level or seminar-level).

create table if not exists public.attendance_absence_reasons (
  id uuid primary key default gen_random_uuid(),
  student_id uuid not null references public.users(id) on delete cascade,
  event_id uuid not null references public.events(id) on delete cascade,
  session_id uuid references public.event_sessions(id) on delete cascade,
  reason_text text not null,
  review_status text not null default 'pending',
  admin_note text,
  submitted_at timestamptz not null default now(),
  reviewed_at timestamptz,
  reviewed_by uuid references public.users(id) on delete set null
);

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'attendance_absence_reasons_review_status_check'
  ) then
    alter table public.attendance_absence_reasons
      add constraint attendance_absence_reasons_review_status_check
      check (review_status in ('pending', 'approved', 'rejected'));
  end if;
end $$;

create index if not exists attendance_absence_reasons_student_idx
  on public.attendance_absence_reasons(student_id);

create index if not exists attendance_absence_reasons_event_idx
  on public.attendance_absence_reasons(event_id);

create index if not exists attendance_absence_reasons_session_idx
  on public.attendance_absence_reasons(session_id);

create index if not exists attendance_absence_reasons_submitted_idx
  on public.attendance_absence_reasons(submitted_at desc);

create unique index if not exists attendance_absence_reasons_student_event_session_unique_idx
  on public.attendance_absence_reasons(student_id, event_id, session_id)
  where session_id is not null;

create unique index if not exists attendance_absence_reasons_student_event_unique_idx
  on public.attendance_absence_reasons(student_id, event_id)
  where session_id is null;

grant all privileges on table public.attendance_absence_reasons to anon, authenticated, service_role;

-- Refresh PostgREST schema cache so the new table is visible immediately.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
