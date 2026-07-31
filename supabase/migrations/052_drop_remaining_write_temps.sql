-- 052: Drop remaining ALWAYS-TRUE write policies (events/assistants/certs/proposals).
-- Flutter teacher writes now go through PHP (mobile_secure_write / proposal upload).
-- Keep SELECT for anon so existing list/scan/cert screens still load.

begin;

-- Helper pattern repeated per table: drop write/all temps, add select-only, revoke mutations.

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='events') then
    alter table public.events enable row level security;
    drop policy if exists events_write_temp on public.events;
    drop policy if exists events_select_temp on public.events;
    drop policy if exists events_select_public on public.events;
    create policy events_select_temp on public.events for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.events from anon, authenticated;
    grant select on table public.events to anon, authenticated;
    grant all on table public.events to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_sessions') then
    alter table public.event_sessions enable row level security;
    drop policy if exists event_sessions_write_temp on public.event_sessions;
    drop policy if exists event_sessions_select_temp on public.event_sessions;
    drop policy if exists event_sessions_select_public on public.event_sessions;
    create policy event_sessions_select_temp on public.event_sessions for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_sessions from anon, authenticated;
    grant select on table public.event_sessions to anon, authenticated;
    grant all on table public.event_sessions to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_assistants') then
    alter table public.event_assistants enable row level security;
    drop policy if exists event_assistants_write_temp on public.event_assistants;
    drop policy if exists event_assistants_select_temp on public.event_assistants;
    drop policy if exists event_assistants_select_public on public.event_assistants;
    create policy event_assistants_select_temp on public.event_assistants for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_assistants from anon, authenticated;
    grant select on table public.event_assistants to anon, authenticated;
    grant all on table public.event_assistants to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_teacher_assignments') then
    alter table public.event_teacher_assignments enable row level security;
    drop policy if exists event_teacher_assignments_write_temp on public.event_teacher_assignments;
    drop policy if exists event_teacher_assignments_select_temp on public.event_teacher_assignments;
    drop policy if exists event_teacher_assignments_select_public on public.event_teacher_assignments;
    create policy event_teacher_assignments_select_temp on public.event_teacher_assignments for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_teacher_assignments from anon, authenticated;
    grant select on table public.event_teacher_assignments to anon, authenticated;
    grant all on table public.event_teacher_assignments to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_registration_access') then
    alter table public.event_registration_access enable row level security;
    drop policy if exists event_registration_access_write_temp on public.event_registration_access;
    drop policy if exists event_registration_access_select_temp on public.event_registration_access;
    drop policy if exists event_registration_access_select_public on public.event_registration_access;
    create policy event_registration_access_select_temp on public.event_registration_access for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_registration_access from anon, authenticated;
    grant select on table public.event_registration_access to anon, authenticated;
    grant all on table public.event_registration_access to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='evaluation_questions') then
    alter table public.evaluation_questions enable row level security;
    drop policy if exists evaluation_questions_write_temp on public.evaluation_questions;
    drop policy if exists evaluation_questions_select_temp on public.evaluation_questions;
    drop policy if exists evaluation_questions_select_public on public.evaluation_questions;
    create policy evaluation_questions_select_temp on public.evaluation_questions for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.evaluation_questions from anon, authenticated;
    grant select on table public.evaluation_questions to anon, authenticated;
    grant all on table public.evaluation_questions to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_evaluation_questions') then
    alter table public.event_session_evaluation_questions enable row level security;
    drop policy if exists event_session_evaluation_questions_write_temp on public.event_session_evaluation_questions;
    drop policy if exists event_session_evaluation_questions_select_temp on public.event_session_evaluation_questions;
    create policy event_session_evaluation_questions_select_temp
      on public.event_session_evaluation_questions for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_session_evaluation_questions from anon, authenticated;
    grant select on table public.event_session_evaluation_questions to anon, authenticated;
    grant all on table public.event_session_evaluation_questions to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificate_templates') then
    alter table public.certificate_templates enable row level security;
    drop policy if exists certificate_templates_all_temp on public.certificate_templates;
    drop policy if exists certificate_templates_write_temp on public.certificate_templates;
    drop policy if exists certificate_templates_select_temp on public.certificate_templates;
    create policy certificate_templates_select_temp on public.certificate_templates for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.certificate_templates from anon, authenticated;
    grant select on table public.certificate_templates to anon, authenticated;
    grant all on table public.certificate_templates to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificates') then
    alter table public.certificates enable row level security;
    drop policy if exists certificates_all_temp on public.certificates;
    drop policy if exists certificates_write_temp on public.certificates;
    drop policy if exists certificates_select_temp on public.certificates;
    create policy certificates_select_temp on public.certificates for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.certificates from anon, authenticated;
    grant select on table public.certificates to anon, authenticated;
    grant all on table public.certificates to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_certificates') then
    alter table public.event_session_certificates enable row level security;
    drop policy if exists event_session_certificates_all_temp on public.event_session_certificates;
    drop policy if exists event_session_certificates_write_temp on public.event_session_certificates;
    drop policy if exists event_session_certificates_select_temp on public.event_session_certificates;
    create policy event_session_certificates_select_temp on public.event_session_certificates for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_session_certificates from anon, authenticated;
    grant select on table public.event_session_certificates to anon, authenticated;
    grant all on table public.event_session_certificates to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_certificate_templates') then
    alter table public.event_session_certificate_templates enable row level security;
    drop policy if exists event_session_certificate_templates_all_temp on public.event_session_certificate_templates;
    drop policy if exists event_session_certificate_templates_select_temp on public.event_session_certificate_templates;
    create policy event_session_certificate_templates_select_temp
      on public.event_session_certificate_templates for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_session_certificate_templates from anon, authenticated;
    grant select on table public.event_session_certificate_templates to anon, authenticated;
    grant all on table public.event_session_certificate_templates to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_proposal_documents') then
    alter table public.event_proposal_documents enable row level security;
    drop policy if exists event_proposal_documents_all_temp on public.event_proposal_documents;
    drop policy if exists event_proposal_documents_select_temp on public.event_proposal_documents;
    create policy event_proposal_documents_select_temp on public.event_proposal_documents for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_proposal_documents from anon, authenticated;
    grant select on table public.event_proposal_documents to anon, authenticated;
    grant all on table public.event_proposal_documents to service_role;
  end if;
end $$;

do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_proposal_requirements') then
    alter table public.event_proposal_requirements enable row level security;
    drop policy if exists event_proposal_requirements_all_temp on public.event_proposal_requirements;
    drop policy if exists event_proposal_requirements_select_temp on public.event_proposal_requirements;
    create policy event_proposal_requirements_select_temp on public.event_proposal_requirements for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_proposal_requirements from anon, authenticated;
    grant select on table public.event_proposal_requirements to anon, authenticated;
    grant all on table public.event_proposal_requirements to service_role;
  end if;
end $$;

-- Proposal documents bucket: uploads via service role only (PHP).
do $$
begin
  -- Drop broad anon write policies if present (names vary by project).
  begin
    execute 'drop policy if exists "Allow All 1oj01fe_0" on storage.objects';
  exception when others then null;
  end;
end $$;

commit;
