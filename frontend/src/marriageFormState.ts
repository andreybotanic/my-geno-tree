import type {
  GenealogicalDate,
  ResolvedEventPlace,
} from './api/generated'
import {
  dateValue,
  emptyDate,
  type DateInputState,
} from './personFormValues'

export type MarriageFormState = {
  dissolved: boolean
  dissolutionDate: DateInputState
  dissolutionPlace: ResolvedEventPlace | null
  femaleNewSurname: string
  maleNewSurname: string
  marriageDate: DateInputState
  marriagePlace: ResolvedEventPlace | null
}

export type MarriageFormValue = {
  dissolutionDate: GenealogicalDate | null
  dissolutionPlace: ResolvedEventPlace | null
  femaleNewSurname: string | null
  maleNewSurname: string | null
  marriageDate: GenealogicalDate
  marriagePlace: ResolvedEventPlace | null
}

export function emptyMarriageForm(): MarriageFormState {
  return {
    dissolved: false,
    dissolutionDate: emptyDate(),
    dissolutionPlace: null,
    femaleNewSurname: '',
    maleNewSurname: '',
    marriageDate: emptyDate(),
    marriagePlace: null,
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
    dissolutionPlace: state.dissolved ? state.dissolutionPlace : null,
    femaleNewSurname: optionalText(state.femaleNewSurname),
    maleNewSurname: optionalText(state.maleNewSurname),
    marriageDate,
    marriagePlace: state.marriagePlace,
  }
}

function optionalText(value: string) {
  const trimmed = value.trim()
  return trimmed || null
}
