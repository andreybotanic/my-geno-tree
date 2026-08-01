import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { Position, type EdgeProps } from '@xyflow/react'
import type {
  FamilyFlowEdge,
  SingleParentFlowEdge,
} from '../treeGraph'
import { FamilyEdge, SingleParentEdge } from './TreeEdges'

function familyProps(
  children: NonNullable<FamilyFlowEdge['data']>['childPoints'],
  married = true,
): EdgeProps<FamilyFlowEdge> {
  return {
    id: 'family',
    source: 'a',
    target: 'b',
    sourceX: 100,
    sourceY: 100,
    targetX: 400,
    targetY: 100,
    sourcePosition: Position.Bottom,
    targetPosition: Position.Bottom,
    markerStart: undefined,
    markerEnd: undefined,
    interactionWidth: 20,
    data: {
      bracketDepth: 24,
      childPoints: children,
      married,
      sourceOffset: 0,
      targetOffset: 0,
    },
    selected: false,
    animated: false,
    label: undefined,
    labelStyle: undefined,
    labelShowBg: undefined,
    labelBgStyle: undefined,
    labelBgPadding: undefined,
    labelBgBorderRadius: undefined,
    style: undefined,
  } as unknown as EdgeProps<FamilyFlowEdge>
}

describe('TreeEdges', () => {
  it('draws marriage, co-parent and multi-child brackets', () => {
    const { container, rerender } = render(
      <svg>
        <FamilyEdge
          {...familyProps([
            { id: 'child-a', topX: 150, topY: 400 },
            { id: 'child-b', topX: 350, topY: 400 },
          ])}
        />
      </svg>,
    )
    expect(container.querySelector('.tree-edge-marriage')).toHaveAttribute(
      'd',
      [
        'M 100 95',
        'L 100 116',
        'Q 100 124 108 124',
        'L 392 124',
        'Q 400 124 400 116',
        'L 400 95',
      ].join(' '),
    )
    expect(container.querySelector('.tree-edge-child')).toHaveAttribute(
      'd',
      expect.stringContaining('Q 150 372 150 380'),
    )
    expect(container.querySelector('.tree-edge-child')).toHaveAttribute(
      'd',
      expect.stringContaining('Q 350 372 350 380'),
    )

    rerender(
      <svg>
        <FamilyEdge
          {...familyProps(
            [{ id: 'child-a', topX: 220, topY: 400 }],
            false,
          )}
        />
      </svg>,
    )
    expect(container.querySelector('.tree-edge-coparent')).toBeInTheDocument()
    expect(container.querySelector('.tree-edge-child')).toHaveAttribute(
      'd',
      expect.stringContaining('L 220 400'),
    )
  })

  it('omits an empty child bracket and draws a single-parent edge', () => {
    const { container, rerender } = render(
      <svg><FamilyEdge {...familyProps([])} /></svg>,
    )
    expect(container.querySelector('.tree-edge-child')).not.toBeInTheDocument()

    rerender(
      <svg>
        <SingleParentEdge
          {...({
            ...familyProps([]),
            id: 'single',
            data: {},
            sourcePosition: Position.Bottom,
            targetPosition: Position.Top,
            sourceX: 100,
            sourceY: 100,
            targetX: 100,
            targetY: 300,
          } as unknown as EdgeProps<SingleParentFlowEdge>)}
        />
      </svg>,
    )
    expect(container.querySelector('.tree-edge-child')).toHaveAttribute(
      'd',
      expect.stringContaining('100'),
    )
  })

  it('uses displaced outlets and separate bracket heights for multiple partners', () => {
    const { container } = render(
      <svg>
        <FamilyEdge
          {...{
            ...familyProps([]),
            data: {
              bracketDepth: 42,
              childPoints: [],
              married: true,
              sourceOffset: 16,
              targetOffset: -16,
            },
          }}
        />
      </svg>,
    )

    expect(container.querySelector('.tree-edge-marriage')).toHaveAttribute(
      'd',
      expect.stringContaining('M 116 95 L 116 134'),
    )
    expect(container.querySelector('.tree-edge-marriage')).toHaveAttribute(
      'd',
      expect.stringContaining('Q 384 142 384 134'),
    )
  })

  it('nests brackets without intersections when every partner is on one side', () => {
    const { container } = render(
      <svg>
        <FamilyEdge
          {...{
            ...familyProps([]),
            id: 'near',
            target: 'near-partner',
            targetX: 450,
            data: {
              bracketDepth: 24,
              childPoints: [],
              married: true,
              sourceOffset: 32,
              targetOffset: 0,
            },
          }}
        />
        <FamilyEdge
          {...{
            ...familyProps([]),
            id: 'middle',
            target: 'middle-partner',
            targetX: 770,
            data: {
              bracketDepth: 42,
              childPoints: [],
              married: true,
              sourceOffset: 0,
              targetOffset: 0,
            },
          }}
        />
        <FamilyEdge
          {...{
            ...familyProps([]),
            id: 'far',
            target: 'far-partner',
            targetX: 1090,
            data: {
              bracketDepth: 60,
              childPoints: [],
              married: true,
              sourceOffset: -32,
              targetOffset: 0,
            },
          }}
        />
      </svg>,
    )

    expect(container.querySelectorAll('.tree-edge-marriage')).toHaveLength(3)
    expect(container.querySelectorAll('.tree-edge-marriage').item(0)).toHaveAttribute(
      'd',
      'M 132 95 L 132 116 Q 132 124 140 124 L 442 124 Q 450 124 450 116 L 450 95',
    )
    expect(container.querySelectorAll('.tree-edge-marriage').item(1)).toHaveAttribute(
      'd',
      'M 100 95 L 100 134 Q 100 142 108 142 L 762 142 Q 770 142 770 134 L 770 95',
    )
    expect(container.querySelectorAll('.tree-edge-marriage').item(2)).toHaveAttribute(
      'd',
      'M 68 95 L 68 152 Q 68 160 76 160 L 1082 160 Q 1090 160 1090 152 L 1090 95',
    )
  })
})
