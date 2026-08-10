import { describe, expect, it } from 'vitest'
import {
  emptyMarriageForm,
  marriageFormValue,
} from './marriageFormState'
import {
  emptyPersonForm,
  personFormValue,
} from './personFormState'
import { unknownDate } from './personFormValues'

const place = {
  name: 'Novosibirsk',
  coordinates: { latitude: 55.0302, longitude: 82.9204 },
}

describe('form places', () => {
  it('keeps coordinates when a person date is explicitly unknown', () => {
    const state = emptyPersonForm()
    state.birthSurname.unknown = true
    state.givenName.unknown = true
    state.patronymic.unknown = true
    state.birthDate = unknownDate()
    state.birthPlace = place

    expect(personFormValue(state)).toEqual({
      birthSurname: { state: 'unknown' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
      gender: 'unknown',
      birthDate: { state: 'unknown' },
      birthPlace: place,
      deathDate: null,
      deathPlace: null,
    })
  })

  it('keeps marriage and dissolution places with unknown dates', () => {
    const state = emptyMarriageForm()
    state.marriageDate = unknownDate()
    state.marriagePlace = place
    state.dissolved = true
    state.dissolutionDate = unknownDate()
    state.dissolutionPlace = place

    expect(marriageFormValue(state)).toEqual({
      marriageDate: { state: 'unknown' },
      marriagePlace: place,
      dissolutionDate: { state: 'unknown' },
      dissolutionPlace: place,
      maleNewSurname: null,
      femaleNewSurname: null,
    })
  })
})
