import {
  useRef,
  useState,
  type FocusEvent,
  type FormEvent,
  type ReactNode,
} from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  GenealogicalEvent,
  KnownText,
  MarriagePeriod,
  ParentLink,
  Person,
  PersonDetails,
  PersonPatchRequest,
  TreeSnapshot,
} from '../api/generated'
import {
  StructuredDateInput,
} from './PersonFormDialog'
import {
  dateValue,
  unknownDate,
  type DateInputState,
} from '../personFormValues'
import {
  formatGenealogicalDate,
  formatPersonName,
} from '../personFormatting'
import { canDeletePerson } from '../relationshipPermissions'
import { PersonRelationships } from './PersonRelationships'
import type { RelativeAction } from './RelativeDialog'
import { EventTimeline } from './EventTimeline'
import { ActionIcon } from './ActionIcon'
import { AuthenticatedImage } from './AuthenticatedImage'
import { PersonPortraitPlaceholder } from './PersonPortraitPlaceholder'
import { UnknownToggleButton } from './UnknownToggleButton'
import { AddButton } from './AddButton'
import { PlaceTextButton } from './PlaceDialog'
import type { RelationshipNavigation } from '../pathDisplayPlan'

type PersonField = keyof Pick<
  Person,
  | 'birthSurname'
  | 'givenName'
  | 'patronymic'
  | 'gender'
  | 'birthDate'
  | 'deathDate'
>

type PersonDetailsPanelProps = {
  conflict: boolean
  events: GenealogicalEvent[]
  onAddRelative: (action: RelativeAction, secondParentId?: string) => void
  onClose: () => void
  onDeletePerson: () => void
  onDeletePhoto: () => void
  onCreateEvent: () => void
  onDeleteEvent: (event: GenealogicalEvent) => void
  onEditEvent: (event: GenealogicalEvent) => void
  onEditEventPlace?: ((event: GenealogicalEvent) => void) | undefined
  onEditMarriage: (marriage: MarriagePeriod) => void
  onOpenPhoto: () => void
  onPatch: (patch: PersonPatchRequest) => Promise<void>
  onReorderEvents: (eventIds: string[]) => Promise<void>
  onUploadPhoto: () => void
  onRemoveMarriage: (marriage: MarriagePeriod) => void
  onRemoveParentLink: (link: ParentLink) => void
  onSelectPerson: (navigation: RelationshipNavigation) => void
  person: Person
  details?: PersonDetails
  snapshot?: TreeSnapshot
}

type EditableRowProps = {
  children: ReactNode
  editing: boolean
  label: string
  onEdit: () => void
  value: string
}

function EditableRow({
  children,
  editing,
  label,
  onEdit,
  value,
}: EditableRowProps) {
  const { t } = useTranslation()

  return (
    <div className={`person-detail-row${editing ? ' person-detail-row-editing' : ''}`}>
      <span>{label}</span>
      {editing ? children : (
        <>
          <strong>{value}</strong>
          <button
            aria-label={t('person.editField', { field: label })}
            className="edit-field-button"
            onClick={onEdit}
            title={t('person.edit')}
            type="button"
          >
            <ActionIcon name="edit" />
          </button>
        </>
      )}
    </div>
  )
}

type InlineEditorProps<T> = {
  children: ReactNode
  compact?: boolean
  onCancel: () => void
  onCommit: () => Promise<T>
}

function InlineEditor<T>({
  children,
  compact = true,
  onCancel,
  onCommit,
}: InlineEditorProps<T>) {
  const { t } = useTranslation()
  const [error, setError] = useState('')
  const saving = useRef(false)

  const save = async () => {
    if (saving.current) {
      return
    }
    saving.current = true
    setError('')
    try {
      await onCommit()
    } catch {
      setError(t('person.saveFailed'))
    } finally {
      saving.current = false
    }
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    void save()
  }
  const blur = (event: FocusEvent<HTMLFormElement>) => {
    if (!event.currentTarget.contains(event.relatedTarget)) {
      void save()
    }
  }

  return (
    <form
      className={`inline-person-editor${compact ? ' inline-person-editor-compact' : ''}`}
      onBlur={blur}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          onCancel()
        }
      }}
      onSubmit={submit}
    >
      {children}
      <button className="sr-only" type="submit">{t('common.save')}</button>
      <button
        aria-label={t('common.cancel')}
        className="inline-cancel-button"
        onClick={onCancel}
        title={t('common.cancel')}
        type="button"
      >
        ×
      </button>
      {error && <small role="alert">{error}</small>}
    </form>
  )
}

function textDraft(value: KnownText) {
  return {
    unknown: value.state === 'unknown',
    value: value.state === 'known' ? value.value : '',
  }
}

function dateDraft(value: GenealogicalDate): DateInputState {
  if (value.state === 'unknown') {
    return unknownDate()
  }

  return {
    day: value.day ? String(value.day) : '',
    endDay: value.kind === 'between' && value.end?.day ? String(value.end.day) : '',
    endMonth: value.kind === 'between' && value.end?.month ? String(value.end.month) : '',
    endYear: value.kind === 'between' && value.end ? String(value.end.year) : '',
    kind: value.kind,
    month: value.month ? String(value.month) : '',
    unknown: false,
    year: String(value.year),
  }
}

export function PersonDetailsPanel({
  conflict,
  events,
  onAddRelative,
  onClose,
  onCreateEvent,
  onDeleteEvent,
  onDeletePerson,
  onDeletePhoto,
  onEditEvent,
  onEditEventPlace,
  onEditMarriage,
  onOpenPhoto,
  onPatch,
  onReorderEvents,
  onUploadPhoto,
  onRemoveMarriage,
  onRemoveParentLink,
  onSelectPerson,
  person,
  details,
  snapshot,
}: PersonDetailsPanelProps) {
  const { t, i18n } = useTranslation()
  const [editing, setEditing] = useState<PersonField | null>(null)
  const [text, setText] = useState(() => textDraft(person.birthSurname))
  const [gender, setGender] = useState(person.gender)
  const [date, setDate] = useState(() => dateDraft(person.birthDate))
  const dateRef = useRef(date)
  const [living, setLiving] = useState(person.deathDate === null)
  const language = i18n.resolvedLanguage ?? 'en'
  const birthEvent = events.find((event) => event.type === 'birth')
  const deathEvent = events.find((event) => event.type === 'death')

  const beginText = (
    field: 'birthSurname' | 'givenName' | 'patronymic',
  ) => {
    setText({ ...textDraft(person[field]), unknown: false })
    setEditing(field)
  }
  const beginDate = (field: 'birthDate' | 'deathDate') => {
    setLiving(field === 'deathDate' && person.deathDate === null)
    const value = field === 'deathDate'
      ? person.deathDate
      : person.birthDate
    const draft = {
      ...dateDraft(value ?? { state: 'unknown' }),
      unknown: value === null,
    }
    dateRef.current = draft
    setDate(draft)
    setEditing(field)
  }
  const updateDate = (value: DateInputState) => {
    dateRef.current = value
    setDate(value)
  }
  const saveText = async (
    field: 'birthSurname' | 'givenName' | 'patronymic',
  ) => {
    const value = text.value.trim()
    if (!text.unknown && (!value || value.length > 100)) {
      throw new Error('invalid text')
    }
    await onPatch({
      [field]: text.unknown
        ? { state: 'unknown' }
        : { state: 'known', value },
    })
    setEditing(null)
  }
  const saveDate = async (field: 'birthDate' | 'deathDate') => {
    if (field === 'deathDate' && living) {
      await onPatch({ deathDate: null })
      setEditing(null)
      return
    }
    const value = dateValue(dateRef.current)
    if (value === null) {
      throw new Error('invalid date')
    }
    await onPatch({ [field]: value })
    setEditing(null)
  }
  const copyUid = async () => {
    try {
      await navigator.clipboard.writeText(person.id)
    } catch {
      // Clipboard access can be disabled by the browser.
    }
  }

  const dateLabel = (value: GenealogicalDate | null) => value === null
    ? t('person.present')
    : value.state === 'unknown'
      ? t('person.unknown')
      : formatGenealogicalDate(value, language, t)

  return (
    <aside className="person-details-panel" aria-label={t('person.details')}>
      <div className="person-panel-heading">
        <div className="person-panel-title">
          <h2>{formatPersonName(person, t)}</h2>
          {import.meta.env.DEV && (
            <button
              aria-label={t('person.copyUid')}
              className="dev-copy-uid-button"
              onClick={() => void copyUid()}
              title={t('person.copyUid')}
              type="button"
            >
              UID
            </button>
          )}
        </div>
        <div className="person-panel-actions">
          <button
            aria-label={t('common.close')}
            className="dialog-close"
            onClick={onClose}
            type="button"
          >
            <CloseIcon />
          </button>
        </div>
      </div>

      {person.photoThumbnailUrl ? (
        <div className="person-photo-editor">
          <button
            aria-label={t('media.openPhoto')}
            className="panel-photo-placeholder"
            onClick={onOpenPhoto}
            type="button"
          >
            <AuthenticatedImage
              alt=""
              fallback={<PersonPortraitPlaceholder />}
              url={person.photoThumbnailUrl}
            />
          </button>
          <div className="person-photo-actions">
            <button
              className="secondary-button compact-button"
              disabled={conflict}
              onClick={onUploadPhoto}
              type="button"
            >
              {t('media.replacePhoto')}
            </button>
            <button
              aria-label={t('media.deletePhoto')}
              className="danger-link"
              disabled={conflict}
              onClick={onDeletePhoto}
              title={t('media.deletePhoto')}
              type="button"
            >
              <ActionIcon name="delete" />
            </button>
          </div>
        </div>
      ) : (
        <div className="person-photo-actions person-photo-actions-empty">
          <AddButton
            disabled={conflict}
            label={t('media.addPhoto')}
            onClick={onUploadPhoto}
          />
        </div>
      )}

      <section className="person-details-section">
        <h3>{t('person.personalData')}</h3>
        {([
          ['birthSurname', 'birthSurname'],
          ['givenName', 'givenName'],
          ['patronymic', 'patronymic'],
        ] as const).map(([field, translation]) => (
          <EditableRow
            editing={editing === field}
            key={field}
            label={t(`person.${translation}`)}
            onEdit={() => !conflict && beginText(field)}
            value={person[field].state === 'known'
              ? person[field].value
              : t('person.unknown')}
          >
            <InlineEditor
              onCancel={() => setEditing(null)}
              onCommit={() => saveText(field)}
            >
              <div className="unknown-field-control">
                <input
                  aria-label={t(`person.${translation}`)}
                  autoFocus
                  disabled={text.unknown}
                  maxLength={101}
                  onChange={(event) => setText({
                    ...text,
                    value: event.target.value,
                  })}
                  value={text.value}
                />
                <UnknownToggleButton
                  active={text.unknown}
                  onChange={(unknown) => setText({
                    unknown,
                    value: unknown ? '' : text.value,
                  })}
                  title={t('person.unknown')}
                />
              </div>
            </InlineEditor>
          </EditableRow>
        ))}

        <EditableRow
          editing={editing === 'gender'}
          label={t('person.gender')}
          onEdit={() => {
            if (!conflict) {
              setGender(person.gender)
              setEditing('gender')
            }
          }}
          value={t(`person.${person.gender}`)}
        >
          <InlineEditor
            onCancel={() => setEditing(null)}
            onCommit={async () => {
              await onPatch({ gender })
              setEditing(null)
            }}
          >
            <select
              aria-label={t('person.gender')}
              autoFocus
              onChange={(event) => setGender(
                event.target.value as Person['gender'],
              )}
              value={gender}
            >
              <option value="unknown">{t('person.unknown')}</option>
              <option value="male">{t('person.male')}</option>
              <option value="female">{t('person.female')}</option>
            </select>
          </InlineEditor>
        </EditableRow>

        {(['birthDate', 'deathDate'] as const).map((field) => (
          <EditableRow
            editing={editing === field}
            key={field}
            label={t(`person.${field}`)}
            onEdit={() => !conflict && beginDate(field)}
            value={dateLabel(person[field])}
          >
            <InlineEditor
              compact={false}
              onCancel={() => setEditing(null)}
              onCommit={() => saveDate(field)}
            >
              {field === 'deathDate' && (
                <label className="unknown-toggle choice-control">
                  <input
                    checked={living}
                    onChange={(event) => setLiving(event.target.checked)}
                    type="checkbox"
                  />
                  <span>{t('person.living')}</span>
                </label>
              )}
              {!(field === 'deathDate' && living) && (
                <StructuredDateInput
                  autoFocus
                  grouped={false}
                  label={t(`person.${field}`)}
                  onChange={updateDate}
                  state={date}
                />
              )}
            </InlineEditor>
          </EditableRow>
        ))}
        <div className="person-detail-row person-place-row">
          <span>{t('person.birthPlace')}</span>
          <PlaceTextButton
            disabled={conflict || !birthEvent}
            onClick={() => birthEvent && onEditEventPlace?.(birthEvent)}
            place={person.birthPlace}
          />
        </div>
        {person.deathDate !== null && (
          <div className="person-detail-row person-place-row">
            <span>{t('person.deathPlace')}</span>
            <PlaceTextButton
              disabled={conflict || !deathEvent}
              onClick={() => deathEvent && onEditEventPlace?.(deathEvent)}
              place={person.deathPlace}
            />
          </div>
        )}
      </section>

      <PersonRelationships
        {...(details ? { context: details.relationships } : {})}
        onAdd={onAddRelative}
        onEditMarriage={onEditMarriage}
        onRemoveMarriage={onRemoveMarriage}
        onRemoveParentLink={onRemoveParentLink}
        onSelectPerson={onSelectPerson}
        person={person}
        {...(details ? { permissions: details.permissions } : {})}
        {...(snapshot ? { snapshot } : {})}
      />

      <EventTimeline
        conflict={conflict}
        events={events}
        onCreate={onCreateEvent}
        onDelete={onDeleteEvent}
        onEdit={onEditEvent}
        onEditPlace={onEditEventPlace}
        onReorder={onReorderEvents}
      />

      {(details?.permissions.canDelete ?? (snapshot && canDeletePerson(snapshot, person.id))) && (
        <section className="person-details-section danger-zone">
          <button
            className="danger-button"
            onClick={onDeletePerson}
            type="button"
          >
            {t('relationships.deletePerson')}
          </button>
        </section>
      )}
    </aside>
  )
}

function CloseIcon() {
  return (
    <svg
      aria-hidden="true"
      className="dialog-close-icon"
      focusable="false"
      viewBox="0 0 24 24"
    >
      <path d="m5 5 14 14M19 5 5 19" />
    </svg>
  )
}
