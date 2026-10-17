-- Replace an event's Event Questions and Seminar Questions as one transaction.
-- Keep submitted feedback safe: question deletion cascades to its answers.
create or replace function public.replace_event_evaluation_questions_from_preset(
  p_event_id uuid,
  p_event_questions jsonb,
  p_seminar_questions jsonb
)
returns jsonb
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
  v_seminar jsonb;
  v_session_id uuid;
  v_expected_sessions integer;
  v_provided_sessions integer;
  v_inserted_seminar_questions integer := 0;
  v_inserted_rows integer := 0;
begin
  if p_event_id is null
     or p_event_questions is null
     or jsonb_typeof(p_event_questions) is distinct from 'array'
     or p_seminar_questions is null
     or jsonb_typeof(p_seminar_questions) is distinct from 'array' then
    raise exception using errcode = '22023', message = 'INVALID_QUESTION_SET';
  end if;

  if exists (
    select 1
    from jsonb_array_elements(p_event_questions) as question(item)
    where jsonb_typeof(question.item) is distinct from 'object'
       or btrim(coalesce(question.item ->> 'question_text', '')) = ''
       or coalesce(question.item ->> 'field_type', 'text') not in ('text', 'rating')
  ) then
    raise exception using errcode = '22023', message = 'INVALID_QUESTION_SET';
  end if;

  if exists (
    select 1
    from jsonb_array_elements(p_seminar_questions) as seminar(item)
    where jsonb_typeof(seminar.item -> 'questions') is distinct from 'array'
       or coalesce(seminar.item ->> 'session_id', '') = ''
  ) or exists (
    select 1
    from jsonb_array_elements(p_seminar_questions) as seminar(item)
    cross join lateral jsonb_array_elements(seminar.item -> 'questions') as question(item)
    where jsonb_typeof(question.item) is distinct from 'object'
       or btrim(coalesce(question.item ->> 'question_text', '')) = ''
       or coalesce(question.item ->> 'field_type', 'text') not in ('text', 'rating')
  ) then
    raise exception using errcode = '22023', message = 'INVALID_QUESTION_SET';
  end if;

  perform 1 from public.events where id = p_event_id for update;
  if not found then
    raise exception using errcode = 'P0002', message = 'EVENT_NOT_FOUND';
  end if;

  perform session.id
  from public.event_sessions as session
  where session.event_id = p_event_id
  order by session.sort_order, session.id
  for update;

  select count(*)::integer
    into v_expected_sessions
  from public.event_sessions
  where event_id = p_event_id;

  select count(distinct (seminar.item ->> 'session_id')::uuid)::integer
    into v_provided_sessions
  from jsonb_array_elements(p_seminar_questions) as seminar(item);

  if v_provided_sessions <> v_expected_sessions or exists (
    select 1
    from jsonb_array_elements(p_seminar_questions) as seminar(item)
    where not exists (
      select 1
      from public.event_sessions as session
      where session.id = (seminar.item ->> 'session_id')::uuid
        and session.event_id = p_event_id
    )
  ) then
    raise exception using errcode = 'P0001', message = 'SEMINAR_STRUCTURE_MISMATCH';
  end if;

  perform question.id
  from public.evaluation_questions as question
  where question.event_id = p_event_id
  order by question.id
  for update;

  perform question.id
  from public.event_session_evaluation_questions as question
  join public.event_sessions as session on session.id = question.session_id
  where session.event_id = p_event_id
  order by question.session_id, question.id
  for update of question;

  if exists (
    select 1 from public.evaluation_answers where event_id = p_event_id
  ) or exists (
    select 1
    from public.event_session_evaluation_answers as answer
    join public.event_sessions as session on session.id = answer.session_id
    where session.event_id = p_event_id
  ) then
    raise exception using errcode = 'P0001', message = 'FEEDBACK_EXISTS';
  end if;

  delete from public.evaluation_questions where event_id = p_event_id;
  delete from public.event_session_evaluation_questions as question
  using public.event_sessions as session
  where question.session_id = session.id
    and session.event_id = p_event_id;

  insert into public.evaluation_questions (
    event_id, question_text, field_type, required, sort_order
  )
  select
    p_event_id,
    question.item ->> 'question_text',
    coalesce(question.item ->> 'field_type', 'text'),
    coalesce((question.item ->> 'required')::boolean, false),
    question.ordinality::integer
  from jsonb_array_elements(p_event_questions) with ordinality as question(item, ordinality);

  for v_seminar in
    select seminar.item from jsonb_array_elements(p_seminar_questions) as seminar(item)
  loop
    v_session_id := (v_seminar ->> 'session_id')::uuid;
    insert into public.event_session_evaluation_questions (
      session_id, question_text, field_type, required, sort_order
    )
    select
      v_session_id,
      question.item ->> 'question_text',
      coalesce(question.item ->> 'field_type', 'text'),
      coalesce((question.item ->> 'required')::boolean, false),
      question.ordinality::integer
    from jsonb_array_elements(v_seminar -> 'questions') with ordinality as question(item, ordinality);

    get diagnostics v_inserted_rows = row_count;
    v_inserted_seminar_questions := v_inserted_seminar_questions + v_inserted_rows;
  end loop;

  return jsonb_build_object(
    'ok', true,
    'event_questions', jsonb_array_length(p_event_questions),
    'seminar_questions', v_inserted_seminar_questions
  );
end;
$$;

revoke all on function public.replace_event_evaluation_questions_from_preset(uuid, jsonb, jsonb)
  from public, anon, authenticated;
grant execute on function public.replace_event_evaluation_questions_from_preset(uuid, jsonb, jsonb)
  to service_role;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
