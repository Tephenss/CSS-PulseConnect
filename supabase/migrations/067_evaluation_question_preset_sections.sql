-- Allow presets to store event-level and per-seminar question sets.
-- Legacy top-level question arrays remain valid for compatibility.

alter table public.evaluation_question_presets
  drop constraint if exists evaluation_question_presets_questions_check;

alter table public.evaluation_question_presets
  add constraint evaluation_question_presets_questions_check check (
    case
      when jsonb_typeof(questions) = 'array'
        then jsonb_array_length(questions) between 1 and 50
      when jsonb_typeof(questions) = 'object' then
        case
          when jsonb_typeof(questions->'event_questions') = 'array'
            and jsonb_typeof(questions->'seminars') = 'array'
            then jsonb_array_length(questions->'event_questions') > 0
              or jsonb_array_length(questions->'seminars') > 0
          else false
        end
      else false
    end
  );
