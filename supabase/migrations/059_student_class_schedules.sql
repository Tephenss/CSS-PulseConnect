-- CCS PulseConnect migration 059
-- Student class schedules parsed from LU registration-form PDFs.
-- Service role only — never expose to anon/authenticated (same posture as student_roster).

create table if not exists public.student_class_schedules (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.users(id) on delete cascade,
  student_no text not null default '',
  course_code text not null default '',
  course_description text not null default '',
  instructor text not null default '',
  days text not null default '',
  time_label text not null default '',
  meetings jsonb not null default '[]'::jsonb,
  updated_at timestamptz not null default now()
);

create index if not exists student_class_schedules_user_id_idx
  on public.student_class_schedules(user_id);

create index if not exists student_class_schedules_student_no_idx
  on public.student_class_schedules(student_no);

alter table public.student_class_schedules enable row level security;

revoke all on table public.student_class_schedules from anon, authenticated;
grant all on table public.student_class_schedules to service_role;

do $$
begin
  if exists (
    select 1 from information_schema.tables
    where table_schema = 'public' and table_name = 'student_class_schedules'
  ) then
    if exists (
      select 1 from pg_proc
      where proname = '_pc_drop_all_policies'
        and pg_function_is_visible(oid)
    ) then
      perform public._pc_drop_all_policies('public', 'student_class_schedules');
    end if;

    drop policy if exists student_class_schedules_deny_clients on public.student_class_schedules;
    create policy student_class_schedules_deny_clients
      on public.student_class_schedules
      for all
      to anon, authenticated
      using (false)
      with check (false);
  end if;
end $$;
