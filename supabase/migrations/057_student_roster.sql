-- CCS PulseConnect migration 057
-- School student roster (CSV import) — service_role only; never expose to anon.

create table if not exists public.student_roster (
  id uuid primary key default gen_random_uuid(),
  student_no text not null,
  full_name_raw text not null default '',
  first_name text not null default '',
  middle_name text,
  last_name text not null default '',
  suffix text,
  course_code text not null check (course_code in ('IT', 'CS')),
  program_label text not null default '',
  year_level smallint check (year_level is null or (year_level >= 1 and year_level <= 4)),
  block text,
  is_irregular boolean not null default false,
  section_id uuid references public.sections(id) on delete set null,
  user_id uuid references public.users(id) on delete set null,
  imported_at timestamptz not null default now(),
  imported_by uuid references public.users(id) on delete set null,
  updated_at timestamptz not null default now(),
  constraint student_roster_student_no_unique unique (student_no)
);

create index if not exists student_roster_section_id_idx
  on public.student_roster(section_id);

create index if not exists student_roster_user_id_idx
  on public.student_roster(user_id);

create index if not exists student_roster_unclaimed_idx
  on public.student_roster(student_no)
  where user_id is null;

-- Canonical section for irregular students (Block = --).
insert into public.sections (name)
select 'IRREGULAR'
where not exists (
  select 1 from public.sections where lower(trim(name)) = 'irregular'
);

-- Lockdown: PHP service_role only (same posture as users / OTP tables).
alter table public.student_roster enable row level security;

revoke all on table public.student_roster from anon, authenticated;
grant all on table public.student_roster to service_role;

do $$
begin
  if exists (
    select 1 from information_schema.tables
    where table_schema = 'public' and table_name = 'student_roster'
  ) then
    if exists (
      select 1 from pg_proc
      where proname = '_pc_drop_all_policies'
        and pg_function_is_visible(oid)
    ) then
      perform public._pc_drop_all_policies('public', 'student_roster');
    end if;

    drop policy if exists student_roster_deny_clients on public.student_roster;
    create policy student_roster_deny_clients
      on public.student_roster
      for all
      to anon, authenticated
      using (false)
      with check (false);
  end if;
end $$;
