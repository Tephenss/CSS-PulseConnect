-- CCS PulseConnect migration 043
-- Trusted IPs for OTP re-verification (device_key = ip:<address>).
-- Same public IP trusts any browser/app until Manila midnight daily reset.

create table if not exists public.trusted_devices (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.users(id) on delete cascade,
  device_key text not null,
  platform text,
  label text,
  last_seen_at timestamptz not null default now(),
  trusted_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  unique (user_id, device_key)
);

create index if not exists trusted_devices_user_id_idx
  on public.trusted_devices(user_id);

create index if not exists trusted_devices_last_seen_at_idx
  on public.trusted_devices(last_seen_at);

alter table public.trusted_devices enable row level security;

do $$
begin
  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'trusted_devices'
      and policyname = 'trusted_devices_select'
  ) then
    create policy trusted_devices_select
      on public.trusted_devices
      for select
      to anon, authenticated
      using (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'trusted_devices'
      and policyname = 'trusted_devices_insert'
  ) then
    create policy trusted_devices_insert
      on public.trusted_devices
      for insert
      to anon, authenticated
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'trusted_devices'
      and policyname = 'trusted_devices_update'
  ) then
    create policy trusted_devices_update
      on public.trusted_devices
      for update
      to anon, authenticated
      using (true)
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'trusted_devices'
      and policyname = 'trusted_devices_delete'
  ) then
    create policy trusted_devices_delete
      on public.trusted_devices
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
