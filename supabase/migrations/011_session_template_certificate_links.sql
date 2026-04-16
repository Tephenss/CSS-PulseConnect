-- CCS PulseConnect migration 011
-- Allow multiple seminar templates and link sent seminar certificates
-- to the exact selected seminar template.

do $$
begin
  if exists (
    select 1
    from pg_constraint
    where conname = 'event_session_certificate_templates_session_id_key'
      and conrelid = 'public.event_session_certificate_templates'::regclass
  ) then
    alter table public.event_session_certificate_templates
      drop constraint event_session_certificate_templates_session_id_key;
  end if;
exception
  when undefined_table then
    null;
end $$;

alter table public.event_session_certificates
  add column if not exists session_template_id uuid references public.event_session_certificate_templates(id) on delete set null;

create index if not exists event_session_certificates_session_template_idx
  on public.event_session_certificates(session_template_id);

create index if not exists event_session_certificate_templates_session_idx
  on public.event_session_certificate_templates(session_id);

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
