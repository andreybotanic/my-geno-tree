import type { KnownText, PersonInput } from './api/generated'
import {
  dateValue,
  emptyDate,
  type DateInputState,
} from './personFormValues'

export type TextInputState = {
  unknown: boolean
  value: string
}

export type PersonFormState = {
  birthSurname: TextInputState
  givenName: TextInputState
  patronymic: TextInputState
  gender: PersonInput['gender']
  birthDate: DateInputState
  deathDate: DateInputState
  deceased: boolean
}

const emptyText = (): TextInputState => ({ unknown: false, value: '' })

export function emptyPersonForm(
  gender: PersonInput['gender'] = 'unknown',
): PersonFormState {
  return {
    birthSurname: emptyText(),
    givenName: emptyText(),
    patronymic: emptyText(),
    gender,
    birthDate: emptyDate(),
    deathDate: emptyDate(),
    deceased: false,
  }
}

export function personFormValue(state: PersonFormState): PersonInput | null {
  const birthSurname = textValue(state.birthSurname)
  const givenName = textValue(state.givenName)
  const patronymic = textValue(state.patronymic)
  const birthDate = dateValue(state.birthDate)
  const deathDate = state.deceased ? dateValue(state.deathDate) : null
  if (
    birthSurname === null
    || givenName === null
    || patronymic === null
    || birthDate === null
    || (state.deceased && deathDate === null)
  ) {
    return null
  }

  return {
    birthSurname,
    givenName,
    patronymic,
    gender: state.gender,
    birthDate,
    deathDate,
  }
}

function textValue(state: TextInputState): KnownText | null {
  const value = state.value.trim()
  if (state.unknown) {
    return { state: 'unknown' }
  }

  return value && value.length <= 100
    ? { state: 'known', value }
    : null
}
