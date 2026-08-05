-- CCS PulseConnect migration 053
-- Standalone certificate templates + registrar code pools (PPTX/PDF import).

-- 1) Allow design-only templates (not linked to an event yet).
do $$
begin
  if exists (
    select 1 from information_schema.tables
    where table_schema = 'public' and table_name = 'certificate_templates'
  ) then
    -- Drop unique(event_id) if present so multiple templates (and nulls) are allowed.
    if exists (
      select 1 from pg_constraint
      where conname = 'certificate_templates_event_id_key'
        and conrelid = 'public.certificate_templates'::regclass
    ) then
      alter table public.certificate_templates drop constraint certificate_templates_event_id_key;
    end if;

    alter table public.certificate_templates alter column event_id drop not null;

    alter table public.certificate_templates
      add column if not exists canvas_state jsonb,
      add column if not exists thumbnail_url text;

    create index if not exists certificate_templates_created_by_idx
      on public.certificate_templates(created_by);
    create index if not exists certificate_templates_event_id_idx
      on public.certificate_templates(event_id);
  end if;
end $$;

-- 2) Import batches (one upload → one batch).
create table if not exists public.event_certificate_imports (
  id uuid primary key default gen_random_uuid(),
  event_id uuid not null references public.events(id) on delete cascade,
  session_id uuid references public.event_sessions(id) on delete cascade,
  source_filename text not null default '',
  source_kind text not null default 'pptx'
    check (source_kind in ('pptx', 'pdf')),
  status text not null default 'ready'
    check (status in ('ready', 'error', 'archived')),
  codes_found integer not null default 0,
  error_message text,
  created_by uuid references public.users(id) on delete set null,
  created_at timestamptz not null default now()
);

create index if not exists event_certificate_imports_event_idx
  on public.event_certificate_imports(event_id);
create index if not exists event_certificate_imports_session_idx
  on public.event_certificate_imports(session_id);

-- 3) Scanned / pooled registrar codes (FIFO assign on eval).
create table if not exists public.event_certificate_codes (
  id uuid primary key default gen_random_uuid(),
  import_id uuid not null references public.event_certificate_imports(id) on delete cascade,
  event_id uuid not null references public.events(id) on delete cascade,
  session_id uuid references public.event_sessions(id) on delete cascade,
  code text not null,
  sort_order integer not null default 0,
  status text not null default 'available'
    check (status in ('available', 'assigned', 'invalid')),
  assigned_to uuid references public.users(id) on delete set null,
  assigned_at timestamptz,
  scanned_from text,
  created_at timestamptz not null default now(),
  unique (code)
);

create index if not exists event_certificate_codes_pool_idx
  on public.event_certificate_codes(event_id, session_id, status, sort_order);
create index if not exists event_certificate_codes_import_idx
  on public.event_certificate_codes(import_id);
create index if not exists event_certificate_codes_assigned_idx
  on public.event_certificate_codes(assigned_to);

-- 4) RLS lockdown: select ok for anon/authenticated; writes service_role only.
alter table public.event_certificate_imports enable row level security;
alter table public.event_certificate_codes enable row level security;

drop policy if exists event_certificate_imports_select_temp on public.event_certificate_imports;
create policy event_certificate_imports_select_temp
  on public.event_certificate_imports for select to anon, authenticated using (true);

drop policy if exists event_certificate_codes_select_temp on public.event_certificate_codes;
create policy event_certificate_codes_select_temp
  on public.event_certificate_codes for select to anon, authenticated using (true);

revoke insert, update, delete on table public.event_certificate_imports from anon, authenticated;
revoke insert, update, delete on table public.event_certificate_codes from anon, authenticated;
grant select on table public.event_certificate_imports to anon, authenticated;
grant select on table public.event_certificate_codes to anon, authenticated;
grant all on table public.event_certificate_imports to service_role;
grant all on table public.event_certificate_codes to service_role;

notify pgrst, 'reload schema';
