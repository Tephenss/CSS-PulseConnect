-- Performance indexes for PulseConnect web polling and list queries.
-- Run in Supabase SQL Editor if migrations are not auto-applied.

CREATE INDEX IF NOT EXISTS events_created_by_idx
  ON public.events (created_by);

CREATE INDEX IF NOT EXISTS events_status_updated_at_idx
  ON public.events (status, updated_at DESC);

CREATE INDEX IF NOT EXISTS events_status_proposal_stage_idx
  ON public.events (status, proposal_stage)
  WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS events_published_end_at_idx
  ON public.events (end_at)
  WHERE status = 'published';

CREATE INDEX IF NOT EXISTS users_pending_app_idx
  ON public.users (role, registration_source, account_status, email_verified)
  WHERE role = 'student'
    AND registration_source = 'app'
    AND account_status = 'pending';

CREATE INDEX IF NOT EXISTS event_proposal_documents_event_visible_idx
  ON public.event_proposal_documents (event_id, admin_visible);
