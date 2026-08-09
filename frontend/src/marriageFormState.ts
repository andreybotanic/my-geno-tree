import type { GenealogicalDate } from './api/generated'
import {
  dateValue,
  emptyDate,
  type DateInputState,
} from './personFormValues'

export type MarriageFormState = {
  dissolved: boolean
  dissolutionDate: DateInputState
  femaleNewSurname: string
  maleNewSurname: string
  marriageDate: DateInputState
}

export type MarriageFormValue = {
  dissolutionDate: GenealogicalDate | null
  femaleNewSurname: string | null
  maleNewSurname: string | null
  marriageDate: GenealogicalDate
}

export function emptyMarriageForm(): MarriageFormState {
  return {
    dissolved: false,
    dissolutionDate: emptyDate(),
    femaleNewSurname: '',
    maleNewSurname: '',
    marriageDate: emptyDate(),
  }
}

export function marriageFormValue(
  state: MarriageFormState,
): MarriageFormValue | null {
  const marriageDate = dateValue(state.marriageDate)
  const dissolutionDate = state.dissolved
    ? dateValue(state.dissolutionDate)
    : null
  if (marriageDate === null || (state.dissolved && dissolutionDate === null)) {
    return null
  }

  return {
    dissolutionDate,
    femaleNewSurname: optionalText(state.femaleNewSurname),
    maleNewSurname: optionalText(state.maleNewSurname),
    marriageDate,
  }
}

function optionalText(value: string) {
  const trimmed = value.trim()
  return trimmed || null
}
