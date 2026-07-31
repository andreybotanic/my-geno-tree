import {
  BaseEdge,
  getSmoothStepPath,
  type EdgeProps,
} from '@xyflow/react'
import type {
  FamilyFlowEdge,
  SingleParentFlowEdge,
} from '../treeGraph'

const CARD_CONNECTION_OVERLAP = 5
const PAIR_CORNER_RADIUS = 8

export function FamilyEdge({
  data,
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
}: EdgeProps<FamilyFlowEdge>) {
  const pairY = Math.max(sourceY, targetY) + 24
  const pairPath = [
    `M ${sourceX} ${sourceY - CARD_CONNECTION_OVERLAP}`,
    `L ${sourceX} ${pairY - PAIR_CORNER_RADIUS}`,
    `Q ${sourceX} ${pairY} ${sourceX + PAIR_CORNER_RADIUS} ${pairY}`,
    `L ${targetX - PAIR_CORNER_RADIUS} ${pairY}`,
    `Q ${targetX} ${pairY} ${targetX} ${pairY - PAIR_CORNER_RADIUS}`,
    `L ${targetX} ${targetY - CARD_CONNECTION_OVERLAP}`,
  ].join(' ')
  const children = data?.childPoints ?? []
  const childPath = childrenPath(
    (sourceX + targetX) / 2,
    pairY,
    children,
  )
  const className = data?.married
    ? 'tree-edge tree-edge-marriage'
    : 'tree-edge tree-edge-coparent'

  return (
    <>
      <BaseEdge className={className} id={id} path={pairPath} />
      {childPath && (
        <path className="tree-edge tree-edge-child" d={childPath} />
      )}
    </>
  )
}

export function SingleParentEdge({
  id,
  sourcePosition,
  sourceX,
  sourceY,
  targetPosition,
  targetX,
  targetY,
}: EdgeProps<SingleParentFlowEdge>) {
  const [path] = getSmoothStepPath({
    borderRadius: 10,
    offset: 30,
    sourcePosition,
    sourceX,
    sourceY,
    targetPosition,
    targetX,
    targetY,
  })

  return (
    <BaseEdge
      className="tree-edge tree-edge-child"
      id={id}
      path={path}
    />
  )
}

function childrenPath(
  familyX: number,
  familyY: number,
  children: Array<{ topX: number; topY: number }>,
) {
  if (children.length === 0) {
    return ''
  }
  const bracketY = Math.min(...children.map((child) => child.topY)) - 28
  if (children.length === 1) {
    const child = children[0]
    if (!child) {
      return ''
    }
    return [
      `M ${familyX} ${familyY}`,
      `L ${familyX} ${bracketY}`,
      `L ${child.topX} ${bracketY}`,
      `L ${child.topX} ${child.topY}`,
    ].join(' ')
  }

  const first = children[0]
  const last = children[children.length - 1]
  if (!first || !last) {
    return ''
  }
  const firstX = first.topX
  const lastX = last.topX
  return [
    `M ${familyX} ${familyY}`,
    `L ${familyX} ${bracketY}`,
    `M ${firstX} ${bracketY}`,
    `L ${lastX} ${bracketY}`,
    ...children.map((child) => (
      `M ${child.topX} ${bracketY} L ${child.topX} ${child.topY}`
    )),
  ].join(' ')
}
