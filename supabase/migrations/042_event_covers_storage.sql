-- Event cover images for mobile/web event details headers.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
  'event-covers',
  'event-covers',
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
      and policyname = 'event_covers_public_read'
  ) then
    create policy event_covers_public_read
      on storage.objects
      for select
      using (bucket_id = 'event-covers');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'event_covers_service_write'
  ) then
    create policy event_covers_service_write
      on storage.objects
      for insert
      with check (bucket_id = 'event-covers');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'event_covers_service_update'
  ) then
    create policy event_covers_service_update
      on storage.objects
      for update
      using (bucket_id = 'event-covers')
      with check (bucket_id = 'event-covers');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage'
      and tablename = 'objects'
      and policyname = 'event_covers_service_delete'
  ) then
    create policy event_covers_service_delete
      on storage.objects
      for delete
      using (bucket_id = 'event-covers');
  end if;
end $$;
