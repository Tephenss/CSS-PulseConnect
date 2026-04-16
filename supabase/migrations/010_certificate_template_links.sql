-- CCS PulseConnect migration 010
-- Link generated certificates to the exact saved canvas template selected by admin.

alter table public.certificates
  add column if not exists template_id uuid references public.certificate_templates(id) on delete set null;

create index if not exists certificates_template_idx
  on public.certificates(template_id);

do $$
begin
  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_certificates'
  ) then
    alter table public.event_session_certificates
      add column if not exists template_id uuid references public.certificate_templates(id) on delete set null;

    create index if not exists event_session_certificates_template_idx
      on public.event_session_certificates(template_id);
  end if;
end $$;

-- Refresh PostgREST schema cache so the new columns are visible immediately.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
