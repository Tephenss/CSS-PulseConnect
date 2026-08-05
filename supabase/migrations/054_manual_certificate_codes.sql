-- CCS PulseConnect migration 054
-- Allow manual registrar code entry (no PPTX/PDF scan).

do $$
begin
  if exists (
    select 1 from information_schema.tables
    where table_schema = 'public' and table_name = 'event_certificate_imports'
  ) then
    alter table public.event_certificate_imports
      drop constraint if exists event_certificate_imports_source_kind_check;

    alter table public.event_certificate_imports
      add constraint event_certificate_imports_source_kind_check
      check (source_kind in ('pptx', 'pdf', 'manual'));
  end if;
end $$;
