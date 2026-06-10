-- CCS PulseConnect migration 029
-- Track admin web login email verification once per PH calendar day.

create table if not exists public.admin_login_daily_verifications (
  user_id uuid primary key references public.users(id) on delete cascade,
  verified_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists admin_login_daily_verifications_verified_at_idx
  on public.admin_login_daily_verifications(verified_at);

alter table public.admin_login_daily_verifications enable row level security;

do $$
begin
  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'admin_login_daily_verifications'
      and policyname = 'admin_login_daily_verifications_select'
  ) then
    create policy admin_login_daily_verifications_select
      on public.admin_login_daily_verifications
      for select
      to anon, authenticated
      using (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'admin_login_daily_verifications'
      and policyname = 'admin_login_daily_verifications_insert'
  ) then
    create policy admin_login_daily_verifications_insert
      on public.admin_login_daily_verifications
      for insert
      to anon, authenticated
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'admin_login_daily_verifications'
      and policyname = 'admin_login_daily_verifications_update'
  ) then
    create policy admin_login_daily_verifications_update
      on public.admin_login_daily_verifications
      for update
      to anon, authenticated
      using (true)
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'admin_login_daily_verifications'
      and policyname = 'admin_login_daily_verifications_delete'
  ) then
    create policy admin_login_daily_verifications_delete
      on public.admin_login_daily_verifications
      for delete
      to anon, authenticated
      using (true);
  end if;
end $$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
