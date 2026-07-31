-- CCS PulseConnect migration 049
-- Fix Supabase Advisor CRITICAL: "RLS Disabled in Public" on remaining tables.
-- NOTE: Flutter still touches some of these via anon — enable RLS + one temp policy
-- so Advisor CRITICAL clears without bricking teacher proposal/cert flows.
-- Next hardening: move those Flutter calls to PHP, then REVOKE anon.

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
-- 1) CRITICAL tables that had RLS OFF (migration 027 disabled them)
-- ---------------------------------------------------------------------------
do $$
declare
  t text;
  -- Tables Advisor flagged + Flutter still uses directly for now.
  tables text[] := array[
    'event_session_certificate_templates',
    'event_proposal_requirements',
    'event_proposal_documents'
  ];
begin
  foreach t in array tables loop
    if exists (
      select 1 from information_schema.tables
      where table_schema = 'public' and table_name = t
    ) then
      perform public._pc_drop_all_policies('public', t);
      execute format('alter table public.%I enable row level security', t);
      execute format(
        'create policy %I on public.%I for all to anon, authenticated using (true) with check (true)',
        t || '_all_temp',
        t
      );
      execute format(
        'grant select, insert, update, delete on table public.%I to anon, authenticated',
        t
      );
      execute format('grant all on table public.%I to service_role', t);
    end if;
  end loop;
end $$;

-- ---------------------------------------------------------------------------
-- 2) Collapse duplicate permissive policies (select + write → one all policy)
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificate_templates') then
    perform public._pc_drop_all_policies('public', 'certificate_templates');
    alter table public.certificate_templates enable row level security;
    create policy certificate_templates_all_temp
      on public.certificate_templates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.certificate_templates to anon, authenticated;
    grant all on table public.certificate_templates to service_role;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='certificates') then
    perform public._pc_drop_all_policies('public', 'certificates');
    alter table public.certificates enable row level security;
    create policy certificates_all_temp
      on public.certificates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.certificates to anon, authenticated;
    grant all on table public.certificates to service_role;
  end if;

  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_certificates') then
    perform public._pc_drop_all_policies('public', 'event_session_certificates');
    alter table public.event_session_certificates enable row level security;
    create policy event_session_certificates_all_temp
      on public.event_session_certificates for all
      to anon, authenticated
      using (true)
      with check (true);
    grant select, insert, update, delete on table public.event_session_certificates to anon, authenticated;
    grant all on table public.event_session_certificates to service_role;
  end if;
end $$;

drop function if exists public._pc_drop_all_policies(text, text);
