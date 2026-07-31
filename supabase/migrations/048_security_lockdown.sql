-- CCS PulseConnect migration 048
-- Security lockdown: mobile sessions + revoke anon access on sensitive tables + private docs.

-- ---------------------------------------------------------------------------
-- 1) Mobile sessions (opaque tokens hashed at rest)
-- ---------------------------------------------------------------------------
create table if not exists public.mobile_sessions (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.users(id) on delete cascade,
  token_hash text not null unique,
  platform text,
  device_label text,
  expires_at timestamptz not null,
  last_seen_at timestamptz not null default now(),
  revoked_at timestamptz,
  created_at timestamptz not null default now()
);

create index if not exists mobile_sessions_user_id_idx
  on public.mobile_sessions(user_id);

create index if not exists mobile_sessions_expires_at_idx
  on public.mobile_sessions(expires_at);

alter table public.mobile_sessions enable row level security;

revoke all on table public.mobile_sessions from anon, authenticated;
grant all on table public.mobile_sessions to service_role;

-- ---------------------------------------------------------------------------
-- Helper: drop all policies on a table (idempotent)
-- ---------------------------------------------------------------------------
create or replace function public._pc_drop_all_policies(p_schema text, p_table text)
returns void
language plpgsql
as $$
declare
  r record;
begin
  for r in
    select policyname
    from pg_policies
    where schemaname = p_schema and tablename = p_table
  loop
    execute format('drop policy if exists %I on %I.%I', r.policyname, p_schema, p_table);
  end loop;
end;
$$;

-- ---------------------------------------------------------------------------
-- 2) Revoke anon/authenticated on sensitive tables (PHP service_role only)
-- ---------------------------------------------------------------------------
do $$
declare
  t text;
  tables text[] := array[
    'users',
    'password_reset_codes',
    'email_verification_codes',
    'trusted_devices',
    'admin_login_daily_verifications',
    'attendance',
    'event_session_attendance',
    'tickets',
    'event_registrations',
    'event_student_requirements',
    'event_student_documents',
    'event_student_submissions',
    'fcm_tokens',
    'user_notifications',
    'user_notification_reads',
    'user_notification_watermarks',
    'attendance_absence_reasons',
    'evaluation_answers',
    'event_session_evaluation_answers',
    'mobile_sessions'
  ];
begin
  foreach t in array tables loop
    if exists (
      select 1 from information_schema.tables
      where table_schema = 'public' and table_name = t
    ) then
      perform public._pc_drop_all_policies('public', t);
      execute format('alter table public.%I enable row level security', t);
      execute format('revoke all on table public.%I from anon, authenticated', t);
      execute format('grant all on table public.%I to service_role', t);
    end if;
  end loop;
end $$;

-- Catalog / non-PII tables: keep SELECT for anon (mobile list UIs), revoke writes.
do $$
declare
  t text;
  catalog text[] := array[
    'events',
    'event_sessions',
    'sections',
    'evaluation_questions',
    'event_session_evaluation_questions',
    'event_teacher_assignments',
    'event_assistants',
    'event_registration_access',
    'certificate_templates',
    'certificates',
    'event_session_certificates'
  ];
begin
  foreach t in array catalog loop
    if exists (
      select 1 from information_schema.tables
      where table_schema = 'public' and table_name = t
    ) then
      perform public._pc_drop_all_policies('public', t);
      execute format('alter table public.%I enable row level security', t);
      -- Read-only for anon/authenticated (UI catalog). Writes via service_role/PHP.
      execute format('revoke insert, update, delete on table public.%I from anon, authenticated', t);
      execute format('grant select on table public.%I to anon, authenticated', t);
      execute format('grant all on table public.%I to service_role', t);
      execute format(
        'create policy %I on public.%I for select to anon, authenticated using (true)',
        t || '_select_public',
        t
      );
    end if;
  end loop;
end $$;

-- Teacher app still creates/updates events via Flutter until full BFF.
-- Allow authenticated+anon INSERT/UPDATE only on events / event_sessions for staff workflows.
-- NOTE: Prefer moving these to PHP later. Temporary write policies are scoped loosely;
-- sensitive PII tables above remain locked.
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='events') then
    drop policy if exists events_write_temp on public.events;
    create policy events_write_temp
      on public.events for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.events to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_sessions') then
    drop policy if exists event_sessions_write_temp on public.event_sessions;
    create policy event_sessions_write_temp
      on public.event_sessions for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_sessions to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_teacher_assignments') then
    drop policy if exists event_teacher_assignments_write_temp on public.event_teacher_assignments;
    create policy event_teacher_assignments_write_temp
      on public.event_teacher_assignments for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_teacher_assignments to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_assistants') then
    drop policy if exists event_assistants_write_temp on public.event_assistants;
    create policy event_assistants_write_temp
      on public.event_assistants for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_assistants to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_registration_access') then
    drop policy if exists event_registration_access_write_temp on public.event_registration_access;
    create policy event_registration_access_write_temp
      on public.event_registration_access for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_registration_access to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='evaluation_questions') then
    drop policy if exists evaluation_questions_write_temp on public.evaluation_questions;
    create policy evaluation_questions_write_temp
      on public.evaluation_questions for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.evaluation_questions to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_evaluation_questions') then
    drop policy if exists event_session_evaluation_questions_write_temp on public.event_session_evaluation_questions;
    create policy event_session_evaluation_questions_write_temp
      on public.event_session_evaluation_questions for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_session_evaluation_questions to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificate_templates') then
    drop policy if exists certificate_templates_write_temp on public.certificate_templates;
    create policy certificate_templates_write_temp
      on public.certificate_templates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.certificate_templates to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificates') then
    drop policy if exists certificates_write_temp on public.certificates;
    create policy certificates_write_temp
      on public.certificates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.certificates to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_certificates') then
    drop policy if exists event_session_certificates_write_temp on public.event_session_certificates;
    create policy event_session_certificates_write_temp
      on public.event_session_certificates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant insert, update, delete on table public.event_session_certificates to anon, authenticated;
  end if;

  -- TEMP: teacher/student scan + ticket repair until Flutter scan uses mobile_secure_write fully.
  -- users / OTP / trusted_devices / docs / fcm remain locked (no anon access).
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='attendance') then
    drop policy if exists attendance_write_temp on public.attendance;
    create policy attendance_write_temp
      on public.attendance for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.attendance to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_attendance') then
    drop policy if exists event_session_attendance_write_temp on public.event_session_attendance;
    create policy event_session_attendance_write_temp
      on public.event_session_attendance for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.event_session_attendance to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='tickets') then
    drop policy if exists tickets_write_temp on public.tickets;
    create policy tickets_write_temp
      on public.tickets for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.tickets to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_registrations') then
    drop policy if exists event_registrations_select_temp on public.event_registrations;
    create policy event_registrations_select_temp
      on public.event_registrations for select
      to anon, authenticated
      using (true);
    grant select on table public.event_registrations to anon, authenticated;
    -- Writes stay service_role / PHP only (mobile_register_event).
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='attendance_absence_reasons') then
    drop policy if exists attendance_absence_reasons_write_temp on public.attendance_absence_reasons;
    create policy attendance_absence_reasons_write_temp
      on public.attendance_absence_reasons for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.attendance_absence_reasons to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='evaluation_answers') then
    drop policy if exists evaluation_answers_write_temp on public.evaluation_answers;
    create policy evaluation_answers_write_temp
      on public.evaluation_answers for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.evaluation_answers to anon, authenticated;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_evaluation_answers') then
    drop policy if exists event_session_evaluation_answers_write_temp on public.event_session_evaluation_answers;
    create policy event_session_evaluation_answers_write_temp
      on public.event_session_evaluation_answers for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.event_session_evaluation_answers to anon, authenticated;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- 3) Private document buckets (PII). event-covers may stay public.
-- ---------------------------------------------------------------------------
update storage.buckets
set public = false
where id in ('student-documents', 'proposal-documents', 'avatars');

-- Drop open storage object policies for private buckets
do $$
declare
  r record;
begin
  for r in
    select policyname
    from pg_policies
    where schemaname = 'storage' and tablename = 'objects'
  loop
    -- Only drop policies that mention private buckets in definition is hard;
    -- drop known policy names from migrations 026/038/042 if present.
    null;
  end loop;
end $$;

drop policy if exists "student_documents_select" on storage.objects;
drop policy if exists "student_documents_insert" on storage.objects;
drop policy if exists "student_documents_update" on storage.objects;
drop policy if exists "student_documents_delete" on storage.objects;
drop policy if exists student_documents_select on storage.objects;
drop policy if exists student_documents_insert on storage.objects;
drop policy if exists student_documents_update on storage.objects;
drop policy if exists student_documents_delete on storage.objects;
drop policy if exists proposal_documents_select on storage.objects;
drop policy if exists proposal_documents_insert on storage.objects;
drop policy if exists proposal_documents_update on storage.objects;
drop policy if exists proposal_documents_delete on storage.objects;

-- Service role bypasses RLS; PHP uploads use service role key.
-- No anon policies on private buckets = no public dump.

drop function if exists public._pc_drop_all_policies(text, text);
