import { render, screen } from '@testing-library/react'
import { beforeEach, expect, it } from 'vitest'
import i18n from '../i18n'
import { ChatBudget } from './ChatBudget'

beforeEach(async () => { await i18n.changeLanguage('en') })

it(
  'shows the actual remaining daily percentage with accessible quota details',
  () => {
    const { rerender, container } = render(<ChatBudget budget={{ remaining: 750, limit: 1000 }} />)
    expect(screen.getByRole('img', { name: '75% of tokens remaining' })).toHaveAttribute('tabindex', '0')
    expect(screen.getByRole('tooltip')).toHaveTextContent('750 of 1,000')
    expect(container.querySelector('[pathLength]')).toHaveAttribute('stroke-dasharray', '75 100')
    rerender(<ChatBudget budget={{ remaining: 0, limit: 1000 }} />)
    expect(screen.getByRole('img')).toHaveAccessibleName('0% of tokens remaining')
    rerender(<ChatBudget budget={null} />)
    expect(screen.getByRole('img')).toHaveAccessibleName('Token allowance is unavailable')
  }
)

it(
  'localizes fractional percentages and the tooltip in Russian',
  async () => {
    await i18n.changeLanguage('ru')
    render(<ChatBudget budget={{ remaining: 9970, limit: 10000 }} />)
    expect(screen.getByRole('img')).toHaveAccessibleName('Осталось 99,7% токенов')
    expect(screen.getByRole('tooltip')).toHaveTextContent('для всех чатов')
  }
)
