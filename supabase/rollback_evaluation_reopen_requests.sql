-- Rollback cleanup for the abandoned evaluation reopen-request feature.
-- Run this in Supabase SQL Editor only if you want to remove the DB objects
-- created by the old reopen-evaluation setup.
--
-- Note:
-- Supabase blocks direct SQL deletion from storage.objects.
-- So this script removes the database-side feature objects only.
-- After running it, manually delete the Storage bucket
-- "evaluation-reopen-proofs" from the Supabase Storage UI
-- if you also want to remove the uploaded proof files.

begin;

drop policy if exists evaluation_reopen_proofs_public_read
  on storage.objects;

drop policy if exists evaluation_reopen_proofs_authenticated_insert
  on storage.objects;

drop policy if exists evaluation_reopen_proofs_authenticated_update
  on storage.objects;

drop policy if exists evaluation_reopen_proofs_authenticated_delete
  on storage.objects;

drop table if exists public.evaluation_reopen_requests cascade;

commit;
