-- CCS PulseConnect migration 004
-- Adds seminar-based event support on top of the existing simple event flow.

alter table public.events
  add column if not exists event_type text not null default 'Event';

alter table public.events
  add column if not exists event_for text not null default 'All';

alter table public.events
  add column if not exists grace_time integer not null default 15;

alter table public.events
  add column if not exists event_span text not null default 'single_day';

alter table public.events
  add column if not exists event_mode text not null default 'simple';

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'events_event_mode_check'
  ) then
    alter table public.events
      add constraint events_event_mode_check
      check (event_mode in ('simple', 'seminar_based'));
  end if;
end $$;

create index if not exists events_event_mode_idx
  on public.events(event_mode);

create table if not exists public.event_sessions (
  id uuid primary key default gen_random_uuid(),
  event_id uuid not null references public.events(id) on delete cascade,
  title text not null,
  topic text,
  description text,
  location text,
  start_at timestamptz not null,
  end_at timestamptz not null,
  scan_window_minutes integer not null default 30,
  sort_order integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists event_sessions_event_idx
  on public.event_sessions(event_id);

create index if not exists event_sessions_start_idx
  on public.event_sessions(start_at);

create unique index if not exists event_sessions_event_sort_unique
  on public.event_sessions(event_id, sort_order);

grant all privileges on table public.event_sessions to anon, authenticated, service_role;

create table if not exists public.event_session_attendance (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  registration_id uuid not null references public.event_registrations(id) on delete cascade,
  ticket_id uuid not null references public.tickets(id) on delete cascade,
  status text not null default 'present',
  check_in_at timestamptz not null default now(),
  last_scanned_by uuid references public.users(id) on delete set null,
  last_scanned_at timestamptz,
  updated_at timestamptz not null default now(),
  unique (session_id, registration_id),
  unique (session_id, ticket_id)
);

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'event_session_attendance_status_check'
  ) then
    alter table public.event_session_attendance
      add constraint event_session_attendance_status_check
      check (status in ('present', 'invalid'));
  end if;
end $$;

create index if not exists event_session_attendance_session_idx
  on public.event_session_attendance(session_id);

create index if not exists event_session_attendance_registration_idx
  on public.event_session_attendance(registration_id);

grant all privileges on table public.event_session_attendance to anon, authenticated, service_role;

create table if not exists public.event_session_evaluation_questions (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  question_text text not null,
  field_type text not null default 'text',
  required boolean not null default false,
  sort_order integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'event_session_evaluation_field_type_check'
  ) then
    alter table public.event_session_evaluation_questions
      add constraint event_session_evaluation_field_type_check
      check (field_type in ('text', 'rating'));
  end if;
end $$;

create index if not exists event_session_questions_session_idx
  on public.event_session_evaluation_questions(session_id);

grant all privileges on table public.event_session_evaluation_questions to anon, authenticated, service_role;

create table if not exists public.event_session_evaluation_answers (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  question_id uuid not null references public.event_session_evaluation_questions(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  answer_text text,
  submitted_at timestamptz not null default now(),
  unique (question_id, student_id)
);

create index if not exists event_session_answers_session_idx
  on public.event_session_evaluation_answers(session_id);

create index if not exists event_session_answers_student_idx
  on public.event_session_evaluation_answers(student_id);

grant all privileges on table public.event_session_evaluation_answers to anon, authenticated, service_role;

create table if not exists public.event_session_certificate_templates (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null unique references public.event_sessions(id) on delete cascade,
  title text not null default 'Certificate of Participation',
  body_text text not null default 'This certifies that {{name}} participated in {{session}}.',
  footer_text text,
  canvas_state jsonb,
  thumbnail_url text,
  created_by uuid references public.users(id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

grant all privileges on table public.event_session_certificate_templates to anon, authenticated, service_role;

create table if not exists public.event_session_certificates (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  certificate_code text not null unique,
  issued_at timestamptz not null default now(),
  issued_by uuid references public.users(id) on delete set null,
  unique (session_id, student_id)
);

create index if not exists event_session_certificates_session_idx
  on public.event_session_certificates(session_id);

create index if not exists event_session_certificates_student_idx
  on public.event_session_certificates(student_id);

grant all privileges on table public.event_session_certificates to anon, authenticated, service_role;

do $$
begin
  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_sessions'
  ) and not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'event_sessions'
      and policyname = 'event_sessions_read_app'
  ) then
    create policy event_sessions_read_app
      on public.event_sessions
      for select
      to anon, authenticated
      using (true);
  end if;

  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_attendance'
  ) and not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'event_session_attendance'
      and policyname = 'event_session_attendance_read_app'
  ) then
    create policy event_session_attendance_read_app
      on public.event_session_attendance
      for select
      to authenticated
      using (true);
  end if;

  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_attendance'
  ) and not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'event_session_attendance'
      and policyname = 'event_session_attendance_insert_app'
  ) then
    create policy event_session_attendance_insert_app
      on public.event_session_attendance
      for insert
      to authenticated
      with check (true);
  end if;

  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_attendance'
  ) and not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'event_session_attendance'
      and policyname = 'event_session_attendance_update_app'
  ) then
    create policy event_session_attendance_update_app
      on public.event_session_attendance
      for update
      to authenticated
      using (true)
      with check (true);
  end if;
end $$;
