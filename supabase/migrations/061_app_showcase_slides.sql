-- Admin-managed showcase slides (mobile home + web manage events banner).
-- Reads go through PHP BFF only; no anon/authenticated table grants.

create table if not exists public.app_showcase_slides (
  id uuid primary key default gen_random_uuid(),
  label text not null default '',
  image_url text not null default '',
  storage_path text not null default '',
  sort_order integer not null default 0,
  is_active boolean not null default true,
  created_by uuid references public.users(id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists app_showcase_slides_active_sort_idx
  on public.app_showcase_slides (is_active, sort_order, created_at);

alter table public.app_showcase_slides enable row level security;

revoke all on table public.app_showcase_slides from anon, authenticated;
grant all on table public.app_showcase_slides to service_role;

do $$
begin
  if not exists (
    select 1 from pg_policies
    where schemaname = 'public'
      and tablename = 'app_showcase_slides'
      and policyname = 'app_showcase_slides_deny_clients'
  ) then
    create policy app_showcase_slides_deny_clients
      on public.app_showcase_slides
      for all
      using (false)
      with check (false);
  end if;
end $$;

-- Public showcase images (marketing only — no PII).
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
  'showcase-slides',
  'showcase-slides',
  true,
  5242880,
  array['image/jpeg', 'image/png', 'image/webp']
)
on conflict (id) do update
set
  public = excluded.public,
  file_size_limit = excluded.file_size_limit,
  allowed_mime_types = excluded.allowed_mime_types;

do $$
begin
  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'showcase_slides_public_read'
  ) then
    create policy showcase_slides_public_read
      on storage.objects
      for select
      using (bucket_id = 'showcase-slides');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'showcase_slides_service_write'
  ) then
    create policy showcase_slides_service_write
      on storage.objects
      for insert
      with check (bucket_id = 'showcase-slides');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'showcase_slides_service_update'
  ) then
    create policy showcase_slides_service_update
      on storage.objects
      for update
      using (bucket_id = 'showcase-slides')
      with check (bucket_id = 'showcase-slides');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'showcase_slides_service_delete'
  ) then
    create policy showcase_slides_service_delete
      on storage.objects
      for delete
      using (bucket_id = 'showcase-slides');
  end if;
end $$;
