-- 053: Clear Advisor INFO "RLS Enabled No Policy" on lockdown tables
-- WITHOUT reopening anon/authenticated access.
--
-- These tables are PHP service_role only (048). No policy = deny for clients
-- (already secure). Explicit USING (false) documents that intent and clears
-- the Advisor INFO suggestion.
--
-- DO NOT change these to using (true) — that would undo the security lockdown.

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

do $$
declare
  t text;
  locked text[] := array[
    'admin_login_daily_verifications',
    'email_verification_codes',
    'evaluation_reopen_requests',
    'event_student_documents',
    'event_student_requirements',
    'event_student_submissions',
    'fcm_tokens',
    'mobile_sessions',
    'password_reset_codes',
    'trusted_devices',
    'user_notification_reads',
    'user_notification_watermarks',
    'user_notifications',
    'users'
  ];
begin
  foreach t in array locked loop
    if exists (
      select 1 from information_schema.tables
      where table_schema = 'public' and table_name = t
    ) then
      perform public._pc_drop_all_policies('public', t);
      execute format('alter table public.%I enable row level security', t);

      -- Explicit deny for API roles (anon / authenticated).
      execute format(
        'create policy %I on public.%I for all to anon, authenticated using (false) with check (false)',
        t || '_deny_clients',
        t
      );

      execute format('revoke all on table public.%I from anon, authenticated', t);
      execute format('grant all on table public.%I to service_role', t);
    end if;
  end loop;
end $$;

notify pgrst, 'reload schema';
